<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Dibi\Connection;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Document\ApplyMode;
use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\PayloadContext;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;
use Shipard\Module\Core\Exchange\Document\RoundingModeNames;
use Shipard\Module\Core\Exchange\Document\VatCodeDerivation;
use Shipard\Module\Core\Exchange\Document\VatPlaceDerivation;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Core\Exchange\Values\CanonicalValueNormalizer;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Module\World\Trade\TradeUnionResolver;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Interpretace **papírové podoby** dokladu (#166 D51, D52): z toho, co je
 * na předloze (pošta, ISDOC), a z voleb uživatele v `_resolve` udělá
 * **hotový doklad** — payload platný jako pokyn — a zprávu o tom, co našla
 * a odvodila. Čte databázi, **nic nezapisuje** a doklady neukládá.
 *
 * Kroky jsou tytéž jako v cestě návrhu `DocumentApplier` a jdou přes tytéž
 * třídy — pravidla odvozování existují jednou:
 *
 *  1. normalizace hodnot včetně opravy IBAN ↔ číslo účtu;
 *  2. kontroly předlohy ({@see PaperChecks}) a nálezy k DPH, rekapitulaci,
 *     kurzu, opravě a registraci;
 *  3. hledání záznamů ({@see RecordMatching::resolveAll()}) a kontext DPH
 *     ({@see VatInterpretation::context()});
 *  4. rozhodnutí nad volbami uživatele ({@see RecordMatching::reconcile()});
 *  5. skladba hotového dokladu ({@see FinishedDocument::build()}), seznam
 *     záznamů k založení, mapa řádků a dodavatelské kódy k naučení.
 *
 * Schéma payloadu ověřuje volající. Zápisy (založení záznamů z `pending`,
 * uložení pokynu, učení kódů) patří vstupu návrhu, ne interpretaci.
 */
final class DocumentInterpreter
{
    private const NO_SIDE_IDS = ['supplier' => null, 'customer' => null, 'supplierBank' => null, 'rowItems' => []];

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly DocumentValidator $validator,
        private readonly PayloadContext $payload,
        private readonly PayloadResolve $resolve,
        private readonly VatInterpretation $vat,
        private readonly RecapInterpretation $recap,
        private readonly RecordMatching $matching,
        private readonly ProposalDefaults $defaults,
        private readonly ProposalReport $report,
        private readonly UnitResolver $unitResolver,
    ) {
    }

    /**
     * Interpretace nad zdrojem dat — stejné resolvery jako
     * `DocumentApplier::create()`.
     *
     * @param array<string, TableDefinition> $tables aktivní tabulky zdroje dat
     */
    public static function create(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        array $tables,
    ): self {
        $vatRateResolver = new VatRateResolver($config);
        $vatCodeResolver = new VatCodeResolver($vatRateResolver);
        $vatCodeDerivation = new VatCodeDerivation($vatRateResolver);
        $contentTags = $config->cfgItem('core.exchange.contentTags');
        $dimensions = JournalDimensionSet::fromConfig($config);
        $unitResolver = new UnitResolver($db);

        $payload = new PayloadContext(
            $db,
            $config,
            new ReferenceResolver($db, $tables),
            isset($tables[ExchangeRateResolver::TABLE_LISTS]) ? ExchangeRateResolver::create($db, $config, $dsConfig) : null,
        );
        $resolve = new PayloadResolve($payload, $vatCodeResolver, $vatCodeDerivation, new DimensionResolver($db, $dimensions, $tables));
        $vat = new VatInterpretation(
            $db,
            $config,
            $payload,
            $resolve,
            $vatCodeResolver,
            $vatCodeDerivation,
            new VatPlaceDerivation(new TradeUnionResolver($config)),
            is_array($contentTags) ? $contentTags : [],
        );
        $itemColumns = array_map(static fn ($column) => $column->id, ($tables['economy_items'] ?? null)?->columns ?? []);

        return new self(
            $db,
            $config,
            new DocumentValidator($dimensions, $tables),
            $payload,
            $resolve,
            $vat,
            new RecapInterpretation($payload, $resolve, $vat, $vatCodeResolver),
            new RecordMatching(
                $db,
                $payload,
                $resolve,
                $vat,
                new PartyResolver($db, new OwnCompanyResolver($db)),
                new ItemResolver($db),
                $unitResolver,
                new BankAccountResolver($db),
                new AccountResolver($db),
            ),
            new ProposalDefaults($db, $config, $payload),
            new ProposalReport($db, in_array('accounting_account', $itemColumns, true)),
            $unitResolver,
        );
    }

    /**
     * @param array<string, mixed> $canonical papírová podoba dokladu
     *        (`shpd.docs.document.v1`, schéma už ověřené) s volbami uživatele
     *        v `_resolve`
     */
    public function interpret(array $canonical): InterpretedDocument
    {
        // 1. Tvary hodnot a oprava bankovního spojení z redundance (#118 D2).
        $canonical = CanonicalValueNormalizer::normalize(CanonicalReferences::expand($canonical));
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $applyOptions = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];
        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        $rowSkips = $this->matching->skippedRowIndices($canonical, $clientResolve);

        // 2. Nálezy — ve stejném pořadí jako náhled návrhu.
        $issues = $this->validator->validate($canonical, ApplyMode::Proposal);
        $this->payload->appendAuthorIssue($canonical, $issues);
        $this->vat->appendVatModeIssue($canonical, $issues);
        $this->vat->appendVatHeaderIssues($canonical, $issues);
        $this->payload->appendVatRateDateIssue($canonical, $issues);
        $recap = $this->recap->recapSource($canonical, $rowSkips);
        foreach ($recap['issues'] ?? [] as $issue) {
            $issues[] = $issue;
        }
        $fallback = RecapInterpretation::fallbackIssue($recap);
        if ($fallback !== null) {
            $issues[] = $fallback;
        }
        $this->payload->appendExchangeRateIssue($canonical, $issues);
        $this->payload->appendCorrectionIssues($canonical, $issues);
        $this->payload->appendVatRegistrationIssue($canonical, $issues);

        // 3. Hledání záznamů a kontext DPH.
        $vatCtx = $this->vat->context($canonical);
        $vatMode = $this->vat->effectiveVatMode($canonical, $vatCtx);
        $resolved = $this->resolve->vatBlocks(
            $canonical,
            $this->matching->resolveAll($canonical, $issues),
            $vatCtx,
            $vatMode,
        );

        // 4. Rozhodnutí nad volbami uživatele — nálezy z něj patří až uložení.
        $decisionIssues = [];
        $plan = $this->matching->reconcile(
            $resolved,
            $clientResolve,
            $decisionIssues,
            $rowSkips,
            $applyOptions['autoCreateMode'] ?? 'strict',
        );
        $pendingItemTypes = [];
        foreach ($plan['rowItemCreates'] as $i => $payload) {
            $pendingItemTypes[$i] = PendingRecords::plannedItemType($this->db, is_array($payload) ? $payload : []);
        }
        $plan['rowOperationDefaults'] = $this->defaults->rowOperations(
            $canonical,
            $plan,
            self::NO_SIDE_IDS,
            $decisionIssues,
            $pendingItemTypes,
        );

        // 5. Hotový doklad.
        $docType = $this->payload->mapDocType($canonical);
        $cashDir = isset($canonical['cashDirection']) ? (int) $canonical['cashDirection'] : 0;
        $rowIndices = PayloadResolve::keptRowIndices($rows, $rowSkips);
        $limitations = [];
        $grossTotals = $this->vat->supplierVatGrossTotals($canonical, $vatCtx);
        $roundingAuto = RoundingInterpretation::resolveAuto($this->config, $canonical, $docType, $cashDir);
        $roundingMode = RoundingInterpretation::resolveMode($canonical, $roundingAuto, $grossTotals);
        $correction = $this->payload->correctionDecision($canonical);
        $place = $vatCtx['place'];

        $document = FinishedDocument::build($canonical, [
            'rowIndices'           => $rowIndices,
            'plan'                 => $plan,
            'resolved'             => $resolved,
            'skippedParties'       => self::skippedParties($clientResolve),
            'headPartnerAllowed'   => in_array($canonical['docType'] ?? null, CanonicalReferences::HEAD_PARTNER_DOC_TYPES, true),
            'vatMode'              => $vatMode['value'],
            'vatPlace'             => is_string($place) && isset(DocumentApplier::VAT_PLACE_MAP[$place]) ? $place : 'domestic',
            'vatRegistration'      => $this->vat->registrationId($canonical),
            'withoutVat'           => (bool) $vatCtx['withoutVat'],
            'recap'                => $recap,
            'grossTotals'          => $grossTotals,
            'totalRoundingAuto'    => $roundingAuto,
            // Bez automatiky a bez odhadu platí výchozí mód — výslovně.
            'totalRoundingMode'    => $roundingAuto ? null : (RoundingModeNames::totalName($roundingMode ?? 0) ?? 'cent'),
            'paymentMethod'        => DocumentApplier::canonicalPaymentMethod($this->defaults->paymentMethodFor($canonical, $docType)),
            'unitCodes'            => $this->unitCodes($rows, $rowIndices, $resolved, $limitations),
            'correctionOriginalId' => $correction !== null && $correction['supported'] ? $correction['originalId'] : null,
            'references'           => [
                'numberSeries'   => $this->referenceId('docs_core_number_series', $canonical['numberSeries'] ?? null),
                'cashDesk'       => $this->referenceId('economy_codebooks_cash_desks', $canonical['cashDesk'] ?? null),
                'ownBankAccount' => $this->referenceId(
                    'economy_codebooks_bank_accounts',
                    is_array($canonical['payment'] ?? null) ? ($canonical['payment']['ownBankAccount'] ?? null) : null,
                ),
            ],
        ]);

        $this->appendBankLimitation($resolved, $clientResolve, $plan, $limitations);

        return new InterpretedDocument(
            document: $document,
            report: $this->buildReport($canonical, $resolved, $issues, $rowSkips),
            pending: self::pending($plan, $rowIndices),
            rowMap: $rowIndices,
            learn: self::learn($rows, $plan),
            decisionError: $plan['errorCode'] !== null
                ? ['code' => (string) $plan['errorCode'], 'message' => (string) ($plan['errorMessage'] ?? 'Reconcile selhal.')]
                : null,
            decisionIssues: $decisionIssues,
            limitations: $limitations,
            paper: $canonical,
            recapFallback: $recap['fallback'] ?? null,
        );
    }

    /**
     * Zpráva: bloky `_resolve` tak, jak je dnes nese náhled návrhu — bez
     * bloku `computed`, který počítá pokyn nad hotovým dokladem.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $resolved
     * @param array<int, array<string, mixed>> $issues
     * @param list<int> $rowSkips
     * @return array<string, mixed>
     */
    private function buildReport(array $canonical, array $resolved, array $issues, array $rowSkips): array
    {
        $resolved = $this->report->annotateRowDisplay($canonical, $resolved, $rowSkips);
        $report = $resolved + ['issues' => $issues];
        $report['summary'] = PayloadResolve::summary($resolved, $issues);
        if (is_array($report['rows'] ?? null)) {
            $report['rows'] = ProposalReport::carryOverEnrichment($canonical['_resolve']['rows'] ?? null, $report['rows']);
        }
        $repairs = $canonical['_resolve'][CanonicalValueNormalizer::REPAIRS_KEY] ?? null;
        if (is_array($repairs) && $repairs !== []) {
            $report[CanonicalValueNormalizer::REPAIRS_KEY] = $repairs;
        }
        return $report;
    }

    /**
     * Strany, u kterých uživatel zvolil „bez osoby“ (`userAction: skip`).
     *
     * @param array<string, mixed> $clientResolve
     * @return list<string>
     */
    private static function skippedParties(array $clientResolve): array
    {
        $out = [];
        foreach (['supplier', 'customer', 'balanceParty'] as $key) {
            if (is_array($clientResolve[$key] ?? null) && ($clientResolve[$key]['userAction'] ?? null) === 'skip') {
                $out[] = $key;
            }
        }
        return $out;
    }

    /**
     * Jednotky řádků systémovým kódem (#166 D37) — papír je nese zkratkou
     * nebo názvem. Jednotka, která systémový kód nemá, se do formátu
     * zapsat nedá: řádek zůstane bez jednotky a je to mez (`limitations`).
     *
     * @param array<int, mixed> $rows
     * @param list<int> $rowIndices
     * @param array<string, mixed> $resolved
     * @param list<array{path: string, code: string, message: string}> $limitations
     * @return array<int, ?string>
     */
    private function unitCodes(array $rows, array $rowIndices, array $resolved, array &$limitations): array
    {
        $out = [];
        foreach ($rowIndices as $i) {
            $block = $resolved['rows'][$i]['unit'] ?? null;
            if (!is_array($block) || ($block['status'] ?? null) !== ResolveStatus::Matched->value || !isset($block['matchedId'])) {
                continue;
            }
            $unit = (string) ($rows[$i]['unit'] ?? '');
            $code = UnitResolver::systemCodeFromText($unit, $block['matchedBy'] ?? null)
                ?? $this->unitResolver->systemCodeById((int) $block['matchedId']);
            if ($code === null) {
                $limitations[] = [
                    'path'    => "rows.{$i}.unit",
                    'code'    => 'unit_without_system_code',
                    'message' => "Jednotka „{$unit}“ nemá systémový kód — hotový doklad ji nevyjádří.",
                ];
            }
            $out[$i] = $code;
        }
        return $out;
    }

    /**
     * Reference z payloadu → id záznamu; null = pole není vyplněné nebo se
     * reference nenašla (pak zůstává, jak je, a chybu ohlásí pokyn).
     */
    private function referenceId(string $table, mixed $value): ?int
    {
        if (!CanonicalReferences::isSet($value)) {
            return null;
        }
        try {
            return $this->payload->references()->resolve($table, $value);
        } catch (ReferenceException) {
            return null;
        }
    }

    /**
     * Zvolený bankovní účet partnera (`useExisting:<id>`) formát vyjádřit
     * neumí — pokyn váže účet jen přesnou shodou s účtem osoby. Liší-li se
     * volba od shody, je to mez.
     *
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $clientResolve
     * @param array<string, mixed> $plan
     * @param list<array{path: string, code: string, message: string}> $limitations
     */
    private function appendBankLimitation(array $resolved, array $clientResolve, array $plan, array &$limitations): void
    {
        $action = is_array($clientResolve['supplierBank'] ?? null) ? ($clientResolve['supplierBank']['userAction'] ?? null) : null;
        if (!is_string($action) || !str_starts_with($action, 'useExisting:') || ($plan['resolvedSupplierBank'] ?? null) === null) {
            return;
        }
        $block = $resolved['supplierBank'] ?? null;
        $matched = is_array($block) && ($block['status'] ?? null) === ResolveStatus::Matched->value ? ($block['matchedId'] ?? null) : null;
        if ($matched === null || (int) $matched !== (int) $plan['resolvedSupplierBank']) {
            $limitations[] = [
                'path'    => 'supplier.bankAccount',
                'code'    => 'partner_bank_pin',
                'message' => 'Zvolený bankovní účet partnera hotový doklad nevyjádří — formát pro něj referenci nemá.',
            ];
        }
    }

    /**
     * Záznamy k založení před uložením ({@see InterpretedDocument::$pending}).
     *
     * @param array<string, mixed> $plan
     * @param list<int> $rowIndices
     * @return list<array<string, mixed>>
     */
    private static function pending(array $plan, array $rowIndices): array
    {
        $out = [];
        foreach ($plan['partyCreates'] as $key => $payload) {
            $out[] = [
                'key'     => (string) $key,
                'kind'    => InterpretedDocument::PENDING_PERSON,
                'table'   => 'base_persons_persons',
                'payload' => $payload,
                'ref'     => "{$key}.ref",
            ];
        }
        if (is_array($plan['bankCreate'] ?? null)) {
            $out[] = [
                'key'     => 'supplierBank',
                'kind'    => InterpretedDocument::PENDING_BANK_ACCOUNT,
                'table'   => 'base_persons_bank_accounts',
                'payload' => $plan['bankCreate'],
                'person'  => $plan['resolvedSupplier'] ?? null,
                'ref'     => null,
            ];
        }
        foreach ($plan['rowItemCreates'] as $i => $payload) {
            $position = array_search($i, $rowIndices, true);
            $out[] = [
                'key'     => "rows.{$i}.item",
                'kind'    => InterpretedDocument::PENDING_ITEM,
                'table'   => 'economy_items',
                'payload' => $payload,
                'row'     => (int) $i,
                'unit'    => $plan['resolvedRowUnits'][$i] ?? null,
                'ref'     => $position !== false ? "rows.{$position}.item.ref" : null,
            ];
        }
        return $out;
    }

    /**
     * Dodavatelské kódy položek k naučení po uložení
     * ({@see SupplierCodeLearning}) — řádky s kódem dodavatele, jejichž
     * položka je známá nebo se založí; bez dodavatele nic.
     *
     * @param array<int, mixed> $rows
     * @param array<string, mixed> $plan
     * @return array{supplier: ?int, codes: list<array{row: int, supplierCode: string, supplierName: ?string, item: ?int, overwrite: bool}>}
     */
    private static function learn(array $rows, array $plan): array
    {
        $supplierId = $plan['resolvedSupplier'] ?? null;
        $out = ['supplier' => $supplierId !== null ? (int) $supplierId : null, 'codes' => []];
        if ($supplierId === null && !isset($plan['partyCreates']['supplier'])) {
            return $out;
        }
        foreach ($rows as $i => $row) {
            if (!is_array($row) || in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            $supplierCode = $row['item']['supplierCode'] ?? null;
            if (!is_string($supplierCode) || trim($supplierCode) === '') {
                continue;
            }
            $itemId = $plan['resolvedRowItems'][$i] ?? null;
            if ($itemId === null && !isset($plan['rowItemCreates'][$i])) {
                continue;
            }
            $out['codes'][] = [
                'row'          => (int) $i,
                'supplierCode' => $supplierCode,
                'supplierName' => isset($row['item']['name']) ? (string) $row['item']['name'] : null,
                'item'         => $itemId !== null ? (int) $itemId : null,
                'overwrite'    => in_array($i, $plan['rowItemPins'] ?? [], true),
            ];
        }
        return $out;
    }
}
