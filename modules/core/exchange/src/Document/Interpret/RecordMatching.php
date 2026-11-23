<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\CanonicalRowText;
use Shipard\Module\Core\Exchange\Document\PayloadContext;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;

/**
 * Hledání záznamů k dokladu z **papírové podoby** (#166 D51, etapa 5):
 * strany a položky podle údajů (`ref` má přednost), bankovní spojení
 * dodavatele, účet řádku holým číslem, jednotka zkratkou a aliasem
 * ({@see resolveAll()} → bloky `_resolve`), a rozhodnutí nad nimi podle
 * voleb uživatele z `_resolve` ({@see reconcile()} → plán: co je spárované,
 * co se má založit, které řádky se vynechají).
 *
 * Nic nezapisuje — zakládání je {@see PendingRecords}. Pokyn hledá jen přes
 * reference a tuhle třídu k tomu nepotřebuje.
 */
final class RecordMatching
{
    /**
     * States an explicit `useExisting:<id>` pin may target. Archived (70)
     * records are legitimate historical references — migrated documents
     * routinely point at partners/items archived since. Only Deleted (90)
     * is rejected.
     */
    private const LINKABLE_STATES = [10, 40, 70, 80];

    /** `applyOptions.autoCreateMode` posledního {@see reconcile()}. */
    private mixed $autoCreateMode = 'strict';

    public function __construct(
        private readonly Connection $db,
        private readonly PayloadContext $payload,
        private readonly PayloadResolve $resolve,
        private readonly VatInterpretation $vat,
        private readonly PartyResolver $partyResolver,
        private readonly ItemResolver $itemResolver,
        private readonly UnitResolver $unitResolver,
        private readonly BankAccountResolver $bankAccountResolver,
        private readonly AccountResolver $accountResolver,
    ) {
    }

    /**
     * Strana dokladu (#166 D33): `ref` má přednost a hledání podle údajů se
     * přeskočí; bez něj se hledá podle údajů (IČO, DIČ, název).
     *
     * @param array<string, mixed> $party
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>|null blok `_resolve`; null = strana se nehledá
     */
    private function party(string $path, array $party, bool $optional, array &$issues): ?array
    {
        if (CanonicalReferences::isSet($party['ref'] ?? null)) {
            return $this->resolve->referenceBlock('base_persons_persons', $party['ref'], $path, $issues);
        }
        if ($optional && $party === []) {
            return null;
        }
        return $this->partyResolver->resolve($party)->toArray();
    }

    /**
     * Položka řádku (#166 D33) — `ref`, jinak hledání podle údajů (náš kód,
     * kód dodavatele, EAN, název).
     *
     * @param array<string, mixed> $item
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function rowItem(string $path, array $item, ?int $supplierPersonId, array &$issues): array
    {
        if (CanonicalReferences::isSet($item['ref'] ?? null)) {
            return $this->resolve->referenceBlock('economy_items', $item['ref'], $path, $issues);
        }
        return $this->itemResolver->resolve($item, $supplierPersonId)->toArray();
    }

    /**
     * Účet řádku: reference (číslo = id, `"@518100"`), nebo holé číslo —
     * signál návrhu (D39), který se hledá v rozvrhu a nenalezený jen varuje.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{number: ?string, matchedId: ?int, status: string}|null
     */
    private function rowAccount(int|string $idx, mixed $account, array &$issues): ?array
    {
        if (!CanonicalReferences::isSet($account)) {
            return null;
        }
        if (!CanonicalReferences::isAccountSignal($account)) {
            return $this->resolve->rowAccountReference($idx, $account, $issues);
        }
        // Nenalezeno → warning, řádek skončí jako chybový až při účtování.
        $accountId = $this->accountResolver->resolve((string) $account);
        if ($accountId === null) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => "rows.{$idx}.account",
                'code'     => 'account_not_found',
                'message'  => "Účet „{$account}\" nebyl v rozvrhu nalezen; řádek bude bez účtu.",
            ];
        }
        return [
            'number'    => (string) $account,
            'matchedId' => $accountId,
            'status'    => $accountId !== null ? 'matched' : 'notFound',
        ];
    }

    // ── Resolve all references ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     *        Reference; resolvers append issues for missing references.
     * @return array<string, mixed>  `_resolve`-shaped resolve data.
     */
    public function resolveAll(array $canonical, array &$issues): array
    {
        // Účetní doklad (cmnbkp): nemá supplier/customer/selfParty — saldo
        // identita žije per řádek. Resolvujeme jen řádky (item + účet z čísla).
        $docTypeCode = $this->payload->mapDocType($canonical);
        $isAccountingDoc = $docTypeCode === 'cmnbkp';
        // Pokladní doklad / prodejka: strany nepovinné (anonymní doklad) —
        // chybějící strana se neresolvuje, jinak by skončila unresolved_required.
        $partiesOptional = $this->payload->isCashDeskBoundDocType($docTypeCode);

        $supplierResult = null;
        $customerResult = null;
        $supplierBankResult = null;
        $supplierPersonId = null;
        $supplierCountry = '';

        if (!$isAccountingDoc) {
            $selfParty = $canonical['selfParty'] ?? null;
            $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
            $customer = is_array($canonical['customer'] ?? null) ? $canonical['customer'] : [];
            $supplierCountry = strtolower((string) ($supplier['country'] ?? ''));

            // Strana vlastní firmy referenci nenese; druhá strana: `ref`,
            // jinak v návrhu hledání podle údajů ({@see party()}).
            $supplierResult = $selfParty === 'supplier'
                ? $this->partyResolver->resolveSelfParty()->toArray()
                : $this->party('supplier', $supplier, $partiesOptional, $issues);
            $customerResult = $selfParty === 'customer'
                ? $this->partyResolver->resolveSelfParty()->toArray()
                : $this->party('customer', $customer, $partiesOptional, $issues);

            $supplierPersonId = ($supplierResult['status'] ?? null) === ResolveStatus::Matched->value
                ? ($supplierResult['matchedId'] ?? null)
                : null;

            // Objekt bez IBAN i čísla účtu = doklad bez bankovního spojení
            // (účtenka, karta) — není to reference k rozhodnutí (#103 F3).
            // Reference `bankAccount.ref` (#166 D61) má přednost; bez ní se
            // účet hledá podle čísla mezi účty dodavatele a nenalezený jde
            // k založení. Prázdná reference (i výslovné null z předlohy)
            // v návrhu nic neříká.
            $bank = is_array($supplier['bankAccount'] ?? null) ? $supplier['bankAccount'] : [];
            if (CanonicalReferences::isSet($bank['ref'] ?? null)) {
                $supplierBankResult = $this->resolve->partnerBankReference(
                    $bank['ref'],
                    $supplierPersonId !== null ? (int) $supplierPersonId : null,
                    $this->bankAccountResolver->partnerAccountBelongsTo(...),
                    $issues,
                );
            } elseif (BankAccountResolver::hasIdentifier($bank)) {
                $supplierBankResult = $this->bankAccountResolver->resolvePartnerBank($bank, $supplierPersonId)->toArray();
            }
        }

        // Osoba pro saldokonto (#72): ruční plátce z payloadu — resolvuje se
        // jako strana (bez selfParty), u každého typu dokladu; chybějící =
        // plátce odvodí DocDocument při uložení.
        $balancePartyResult = null;
        $balanceParty = is_array($canonical['balanceParty'] ?? null) ? $canonical['balanceParty'] : [];
        if ($balanceParty !== []) {
            $balancePartyResult = $this->party('balanceParty', $balanceParty, true, $issues);
        }

        $rowsResolve = [];
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        // D2 (exchange-received-reverse-charge): u přijatého dokladu se kódy
        // DPH hledají v číselníku NAŠÍ registrace; ostatní doklady jdou
        // kaskádou registrationCountry → prefix kódu → země dodavatele.
        // Pokyn: země registrace dokladu ({@see PayloadContext::vatCodeCountry()}).
        $vatCtx = $this->vat->context($canonical);
        $vatCountry = $this->payload->vatCodeCountry($vatCtx, $canonical);
        $taxPointDate = $vatCtx['taxPointDate'];

        foreach ($rows as $idx => $row) {
            // Text řádku, jak skončí na dokladu (#84 D2): náhled ho zobrazuje
            // hotový ze serveru, frontend skladbu nezrcadlí. Informativní,
            // apply ho nečte — skládá si ho znovu tímtéž helperem.
            $rowResolve = [
                'index'   => $idx,
                'rowText' => CanonicalRowText::compose(is_array($row) ? $row : []),
            ];
            if (PayloadResolve::rowHasItem($row)) {
                $itemBlock = $this->rowItem("rows.{$idx}.item", $row['item'], $supplierPersonId, $issues);
                if ($itemBlock !== null) {
                    $rowResolve['item'] = $itemBlock;
                }
            }

            // Účet kontačního řádku (acc.record) — {@see rowAccount()}.
            $accountBlock = $this->rowAccount($idx, is_array($row) ? ($row['account'] ?? null) : null, $issues);
            if ($accountBlock !== null) {
                $rowResolve['account'] = $accountBlock;
            }
            // Partner řádku (kontace, úhrada, záloha) — {@see PayloadResolve::resolvePartner()}.
            $partnerBlock = $this->resolve->resolvePartner(
                "rows.{$idx}.partner",
                is_array($row) ? ($row[CanonicalReferences::PARTNER] ?? null) : null,
                true,
                $issues,
            );
            if ($partnerBlock !== null) {
                $rowResolve['partner'] = $partnerBlock;
            }
            if (!empty($row['unit'])) {
                // Jednotka z papíru: i zkratkou a aliasem; číslo je id (#166
                // D60). Nerozpoznaná jen varuje.
                $unitR = is_int($row['unit'])
                    ? $this->unitResolver->resolveId($row['unit'])
                    : $this->unitResolver->resolve((string) $row['unit'], false);
                $rowResolve['unit'] = $unitR->toArray();
                if ($unitR->status === ResolveStatus::NotFound) {
                    $issues[] = [
                        'severity' => 'warning',
                        'path'     => "rows.{$idx}.unit",
                        'code'     => 'unit_not_found',
                        'message'  => "Jednotka „{$row['unit']}\" nebyla rozpoznána; bude doplněna default.",
                    ];
                }
            }
            $rowDimensions = $this->resolve->resolveDimensions(
                is_array($row['dimensions'] ?? null) ? $row['dimensions'] : [],
                "rows.{$idx}.dimensions",
                $issues,
            );
            if ($rowDimensions !== []) {
                $rowResolve['dimensions'] = $rowDimensions;
            }
            $vatResolve = $this->vat->resolveRowVatCode(
                (int) $idx,
                is_array($row) ? $row : [],
                $vatCtx,
                $vatCountry,
                $supplierCountry,
                $issues,
            );
            if ($vatResolve !== null) {
                $rowResolve['vatCode'] = $vatResolve;
            }
            $subjectResolve = $this->resolve->resolveRowReverseChargeCode(
                (int) $idx,
                is_array($row) ? $row : [],
                $vatResolve,
                $vatCtx,
                $vatCountry,
                $supplierCountry,
                $issues,
            );
            if ($subjectResolve !== null) {
                $rowResolve['reverseChargeCode'] = $subjectResolve;
            }
            $rowsResolve[] = $rowResolve;
        }

        $resolved = ['rows' => $rowsResolve];
        // Dimenze hlavičky (#110 D23) — výchozí hodnota pro řádky bez vlastní.
        $headDimensions = $this->resolve->resolveDimensions(
            is_array($canonical['dimensions'] ?? null) ? $canonical['dimensions'] : [],
            'dimensions',
            $issues,
        );
        if ($headDimensions !== []) {
            $resolved['dimensions'] = $headDimensions;
        }
        // For accounting documents these stay unset → reconcile skips them
        // (no supplier/customer/bank to reconcile, no unresolved_required).
        if ($supplierResult !== null) {
            $resolved['supplier'] = $supplierResult;
        }
        if ($customerResult !== null) {
            $resolved['customer'] = $customerResult;
        }
        if ($balancePartyResult !== null) {
            $resolved['balanceParty'] = $balancePartyResult;
        }
        if ($supplierBankResult !== null) {
            $resolved['supplierBank'] = $supplierBankResult;
        }
        // Partner hlavičky (#166 D30) — jen účetní doklad a typy vázané na
        // pokladnu; u faktur je partnerem strana (nález dává validátor).
        $headPartner = CanonicalReferences::carriesHeadPartner($canonical)
            ? $this->resolve->resolvePartner(CanonicalReferences::PARTNER, $canonical[CanonicalReferences::PARTNER] ?? null, true, $issues)
            : null;
        if ($headPartner !== null) {
            $resolved['partner'] = $headPartner;
            // Pokladní doklad: partnera hlavičky nese i strana podle směru —
            // dvě různé osoby pokyn neuloží (v návrhu má pole přednost).
            $headPartnerId = PayloadResolve::matchedPersonId($headPartner);
            $partyPartnerId = match ($canonical['selfParty'] ?? null) {
                'customer' => PayloadResolve::matchedPersonId($supplierResult),
                'supplier' => PayloadResolve::matchedPersonId($customerResult),
                default    => PayloadResolve::matchedPersonId($supplierResult) ?? PayloadResolve::matchedPersonId($customerResult),
            };
        }
        // Opravný doklad (#92 task B U3): výsledek dohledání původního dokladu
        // do náhledu; withResolve() ho vrátí klientovi.
        $correction = $this->resolve->correctionBlock($canonical);
        if ($correction !== null) {
            $resolved['correction'] = $correction;
        }
        return $resolved;
    }

    // ── Volby uživatele → plán (#111, #166 D32) ─────────────────────────────

    /**
     * Indexy řádků canonicalu vynechaných volbou uživatele v klientském
     * `_resolve` (#111 D9): řádková `rows[i].userAction = skip`, nebo
     * položková `rows[i].item.userAction = skip` u řádku s blokem položky
     * („Vynechat řádek“ z review modalu). Jediný zdroj pro {@see reconcile()}
     * (`rowSkips` plánu), {@see DocumentApplier::previewPlan()}, {@see RecapInterpretation::recapSource()}
     * (D12) i {@see ProposalReport::annotateRowDisplay()} — náhled a apply vynechávají
     * stejně. Pozice v `_resolve.rows` = index canonicalu ({@see resolveAll()}
     * vyrábí záznam pro každý řádek). Jede nad canonicalem, protože ho
     * {@see DocumentApplier::appendRecapSourceIssue()} potřebuje před resolve.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve Klientské `_resolve`.
     * @return list<int>
     */
    public function skippedRowIndices(array $canonical, array $clientResolve): array
    {
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $clientRows = is_array($clientResolve['rows'] ?? null) ? $clientResolve['rows'] : [];
        $out = [];
        foreach ($rows as $i => $row) {
            $clientRow = is_array($clientRows[$i] ?? null) ? $clientRows[$i] : [];
            if (($clientRow['userAction'] ?? null) === 'skip') {
                $out[] = (int) $i;
                continue;
            }
            if (PayloadResolve::rowHasItem($row)
                && is_array($clientRow['item'] ?? null)
                && ($clientRow['item']['userAction'] ?? null) === 'skip'
            ) {
                $out[] = (int) $i;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $resolved        Fresh resolve output.
     * @param array<string, mixed> $clientResolve   Client's _resolve with userAction set.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}, #111 D9).
     * @return array{
     *   errorCode: ?string, errorMessage: ?string,
     *   partyCreates: array<string, array<string, mixed>>,
     *   bankCreate: ?array<string, mixed>,
     *   rowItemCreates: array<int, array<string, mixed>>,
     *   rowSkips: list<int>,
     *   rowItemPins: list<int>,
     *   resolvedSupplier: ?int, resolvedCustomer: ?int, resolvedBalanceParty: ?int,
     *   resolvedSupplierBank: ?int,
     *   resolvedRowItems: array<int, int|null>,
     *   resolvedRowUnits: array<int, int|null>,
     *   resolvedRowVatCodes: array<int, array<string, mixed>|null>,
     *   resolvedRowReverseChargeCodes: array<int, ?string>
     * }
     */
    public function reconcile(
        array $resolved,
        array $clientResolve,
        array &$issues,
        array $rowSkips,
        mixed $autoCreateMode = 'strict',
    ): array {
        $this->autoCreateMode = $autoCreateMode;
        // Co plyne z bloků bez rozhodování, dává sdílený základ plánu.
        $plan = PayloadResolve::basePlan($resolved, $rowSkips);

        // Partner hlavičky účetního a pokladního dokladu — nepovinný. Pole
        // `partner` (#166 D30) má přednost; bez něj volba _resolve.partner
        // (useExisting:<id>).
        if (!isset($resolved['partner'])) {
            $plan['resolvedHeadPartner'] = $this->resolvePin(
                'partner',
                is_array($clientResolve['partner'] ?? null) ? ($clientResolve['partner']['userAction'] ?? null) : null,
                $issues,
            );
        }

        foreach (['supplier', 'customer', 'balanceParty'] as $partyKey) {
            $fresh = $resolved[$partyKey] ?? null;
            $client = $clientResolve[$partyKey] ?? null;
            if ($fresh === null) {
                continue;
            }
            $userAction = is_array($client) ? ($client['userAction'] ?? null) : null;
            $res = $this->resolveOne($partyKey, $fresh, $userAction, 'base_persons_persons', $plan, $issues);
            $plan["resolved" . ucfirst($partyKey)] = $res['id'];
            if ($res['autoCreate']) {
                $plan['partyCreates'][$partyKey] = $fresh['createPayload'] ?? [];
            }
        }

        if (isset($resolved['supplierBank'])) {
            $fresh = $resolved['supplierBank'];
            $client = $clientResolve['supplierBank'] ?? null;
            $userAction = is_array($client) ? ($client['userAction'] ?? null) : null;
            $res = $this->resolveOne('supplierBank', $fresh, $userAction, 'base_persons_bank_accounts', $plan, $issues);
            $plan['resolvedSupplierBank'] = $res['id'];
            if ($res['autoCreate']) {
                $plan['bankCreate'] = $fresh['createPayload'] ?? [];
            }
        }

        $clientRows = is_array($clientResolve['rows'] ?? null) ? $clientResolve['rows'] : [];
        // Enrichment řádků podle klíče `index` (RowHistoryEnricher::writeEnrichment),
        // ne podle pozice — pro D7b porovnání účtu.
        $enrichmentByIndex = [];
        foreach ($clientRows as $entry) {
            if (is_array($entry) && isset($entry['index']) && is_array($entry['enrichment'] ?? null)) {
                $enrichmentByIndex[(int) $entry['index']] = $entry['enrichment'];
            }
        }
        foreach ($resolved['rows'] ?? [] as $i => $rowResolve) {
            if (in_array($i, $rowSkips, true)) {
                // Vynechaný řádek — řádková volba i „Vynechat řádek" z review
                // modalu (rows[i].item = skip), {@see skippedRowIndices}:
                // na doklad se nezapíše, resolveOne se nevolá.
                continue;
            }
            $clientRow = $clientRows[$i] ?? null;

            $itemFresh = $rowResolve['item'] ?? null;
            if ($itemFresh !== null) {
                $itemClient = is_array($clientRow['item'] ?? null) ? $clientRow['item'] : null;
                $itemAction = $itemClient['userAction'] ?? null;
                $itemRes = $this->resolveOne("rows.{$i}.item", $itemFresh, $itemAction, 'economy_items', $plan, $issues);
                $plan['resolvedRowItems'][$i] = $itemRes['id'];
                if ($itemRes['autoCreate']) {
                    $plan['rowItemCreates'][$i] = $itemFresh['createPayload'] ?? [];
                }
                if ($itemRes['id'] !== null && is_string($itemAction) && str_starts_with($itemAction, 'useExisting:')) {
                    $plan['rowItemPins'][] = $i;
                }
            }

            $accountFresh = $rowResolve['account'] ?? null;

            // D7b (#111): u ručně zvolené položky jde účet s položkou — účet
            // řádku navržený historií nebo štítkem (shoda čísla se
            // `enrichment.suggested.account`) se nezapíše. Účet od uživatele
            // nebo z AI zůstává.
            $suggestedAccount = $enrichmentByIndex[(int) ($rowResolve['index'] ?? $i)]['suggested']['account'] ?? null;
            if ($plan['resolvedRowAccounts'][$i] !== null
                && in_array($i, $plan['rowItemPins'], true)
                && is_string($suggestedAccount)
                && trim((string) ($accountFresh['number'] ?? '')) === trim($suggestedAccount)) {
                $plan['resolvedRowAccounts'][$i] = null;
            }

            // Partner řádku — pole `rows[].partner` (#166 D30), bez něj
            // v návrhu volba _resolve.rows[i].partner.
            if (!isset($rowResolve['partner'])) {
                $plan['resolvedRowPartners'][$i] = $this->resolvePin(
                    "rows.{$i}.partner",
                    is_array($clientRow['partner'] ?? null) ? ($clientRow['partner']['userAction'] ?? null) : null,
                    $issues,
                );
            }
        }

        return $plan;
    }

    /**
     * Resolve a `useExisting:<id>` pin against linkable persons (anything
     * except Deleted 90 — archived targets are allowed, see
     * LINKABLE_STATES). Returns the id when valid + linkable, null
     * otherwise (no pin / malformed). A pin pointing at a missing or
     * deleted record emits a warning issue instead of failing silently —
     * the partner is optional, but the caller must be able to see it was
     * dropped. Used for the optional accounting-document partners (head +
     * per row) in proposal mode when the payload does not carry the
     * `partner` field (#166 D30, U3) — no fresh resolve, no side-create.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function resolvePin(string $path, ?string $userAction, array &$issues): ?int
    {
        if (!is_string($userAction) || !str_starts_with($userAction, 'useExisting:')) {
            return null;
        }
        $idStr = substr($userAction, strlen('useExisting:'));
        if (!ctype_digit($idStr) || (int) $idStr <= 0) {
            return null;
        }
        $id = (int) $idStr;
        if (!$this->entityLinkable('base_persons_persons', $id)) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => $path,
                'code'     => 'pin_target_missing',
                'message'  => "Cílový záznam base_persons_persons#{$id} pro „{$path}\" neexistuje nebo je smazaný — partner nebyl nastaven.",
            ];
            return null;
        }
        return $id;
    }

    /**
     * Generic per-reference reconcile.
     *
     * Returns {id: ?int, autoCreate: bool}:
     *   - `id`         — resolved DB id, or null when none (error or
     *                    pending side-create).
     *   - `autoCreate` — true when caller should schedule a side-create
     *                    from `$fresh['createPayload']`. Driven by
     *                    explicit `userAction = 'create'` OR by
     *                    `applyOptions.autoCreateMode` (liberal always;
     *                    safe when `safetyGuardOk()` passes).
     *
     * @param array<string, mixed> $fresh Fresh resolve output (toArray shape).
     * @param array<string, mixed> $plan  Modified in place on error.
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{id: ?int, autoCreate: bool}
     */
    private function resolveOne(string $path, array $fresh, ?string $userAction, string $existsTable, array &$plan, array &$issues): array
    {
        $status = $fresh['status'] ?? null;

        if (!empty($fresh['referenceError'])) {
            // Reference z payloadu se nenašla (#166 D31, D33) — chybu už nese
            // nález; volba uživatele ji nepřebíjí a nic se nezakládá.
            return ['id' => null, 'autoCreate' => false];
        }

        if ($userAction === null) {
            if ($status === 'matched') {
                return ['id' => $fresh['matchedId'] ?? null, 'autoCreate' => false];
            }
            if ($status === 'canCreate' && $this->autoCreateAllowed($existsTable, $fresh)) {
                return ['id' => null, 'autoCreate' => true];
            }
            if ($status === 'canCreate' || $status === 'ambiguous' || $status === 'notFound') {
                PayloadResolve::unresolvedRequired($path, $plan, $issues);
            }
            return ['id' => null, 'autoCreate' => false];
        }

        if (str_starts_with($userAction, 'useExisting:')) {
            $idStr = substr($userAction, strlen('useExisting:'));
            if (!ctype_digit($idStr) || (int) $idStr <= 0) {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "Neplatné id v userAction pro „{$path}\".";
                return ['id' => null, 'autoCreate' => false];
            }
            $id = (int) $idStr;
            if (!$this->entityLinkable($existsTable, $id)) {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "Cílový záznam {$existsTable}#{$id} pro „{$path}\" neexistuje nebo je smazaný.";
                return ['id' => null, 'autoCreate' => false];
            }
            return ['id' => $id, 'autoCreate' => false];
        }

        if ($userAction === 'create') {
            if ($status !== 'canCreate') {
                $plan['errorCode'] = 'conflict';
                $plan['errorMessage'] = "userAction=\"create\" pro „{$path}\", ale resolve status je „{$status}\".";
                return ['id' => null, 'autoCreate' => false];
            }
            return ['id' => null, 'autoCreate' => true];
        }

        if ($userAction === 'skip') {
            return ['id' => null, 'autoCreate' => false];
        }

        $plan['errorCode'] = 'conflict';
        $plan['errorMessage'] = "Neznámá userAction „{$userAction}\" pro „{$path}\".";
        return ['id' => null, 'autoCreate' => false];
    }

    /**
     * Decide whether `userAction = null` on a `canCreate` reference should
     * be promoted to autocreate based on applyOptions.autoCreateMode.
     *
     * - `strict` (default) — never.
     * - `liberal`          — always.
     * - `safe`             — only if `$fresh['createPayload']` has enough
     *                        identifiers per {@see safetyGuardOk()}.
     *
     * @param array<string, mixed> $fresh
     */
    private function autoCreateAllowed(string $existsTable, array $fresh): bool
    {
        $mode = $this->autoCreateMode ?? 'strict';
        if ($mode === 'liberal') {
            return true;
        }
        if ($mode === 'safe') {
            return $this->safetyGuardOk($existsTable, $fresh);
        }
        return false;
    }

    /**
     * Per-table minimum identifiers required for "safe" autocreate.
     * See exchange-format-phase2.md §"autoCreateMode" guard table.
     *
     * @param array<string, mixed> $fresh
     */
    private function safetyGuardOk(string $existsTable, array $fresh): bool
    {
        $payload = $fresh['createPayload'] ?? [];
        if (!is_array($payload)) {
            return false;
        }
        return match ($existsTable) {
            'base_persons_persons' => !empty($payload['company_id']),
            'economy_items' => !empty($payload['name']),
            'base_persons_bank_accounts' =>
                !empty($payload['iban']) || !empty($payload['account_number']),
            default => false,
        };
    }

    /**
     * True when the record exists in a linkable state (anything except
     * Deleted 90). Used for validating explicit `useExisting:<id>` pins —
     * archived (70) targets are allowed, see LINKABLE_STATES.
     */
    private function entityLinkable(string $table, int $id): bool
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM %n WHERE [id] = %i AND [docState] IN (%i, %i, %i, %i)',
            $table, $id,
            self::LINKABLE_STATES[0], self::LINKABLE_STATES[1], self::LINKABLE_STATES[2], self::LINKABLE_STATES[3],
        );
        return $row !== null;
    }
}
