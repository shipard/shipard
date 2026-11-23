<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Resolve\DimensionResolver;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;
use Shipard\Module\Docs\Core\VatRateDates;

/**
 * Dohledání hodnot, které payload **říká** — společné kroky resolve pokynu
 * i návrhu (#166 D51, etapa 5): kód DPH řádku ověřený proti číselníku,
 * předmět plnění u tuzemského přenesení, řádek převzaté rekapitulace.
 * Nic neodvozuje; odvození a volby uživatele drží `Interpret\`.
 */
final class PayloadResolve
{
    /** Blok `_resolve` reference, která se nenašla — chybu už nese nález. */
    public const REFERENCE_ERROR_BLOCK = ['status' => 'notFound', 'referenceError' => true];

    public const ACCOUNTS_TABLE = 'economy_accounting_accounts';

    public function __construct(
        private readonly PayloadContext $payload,
        private readonly VatCodeResolver $vatCodeResolver,
        private readonly VatCodeDerivation $vatCodeDerivation,
        /** Null = zdroj dat bez dimenzí s přirozeným klíčem — objekt `dimensions` se ignoruje. */
        private readonly ?DimensionResolver $dimensionResolver = null,
    ) {
    }

    // ── Reference formátu (#166 D30–D33) ────────────────────────────────────


    /**
     * Blok `_resolve` pro referenci strany nebo položky: nalezeno =
     * `matched` s `matchedBy: "ref"`; chyba = nález a blok s příznakem
     * `referenceError`, podle kterého {@see Interpret\RecordMatching::resolveOne()} nic dalšího
     * nehlásí ani nezakládá.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    public function referenceBlock(string $table, mixed $value, string $path, array &$issues): array
    {
        try {
            return ResolveResult::matched($this->payload->references()->resolve($table, $value), 'ref')->toArray();
        } catch (ReferenceException $e) {
            $issues[] = PayloadContext::referenceIssue($e, $path);
            return self::REFERENCE_ERROR_BLOCK;
        }
    }

    /**
     * Partner hlavičky účetního a pokladního dokladu a partner řádku (#166
     * D30): hledá se **jen přes `ref`**, v obou režimech — pole je nové,
     * žádný uložený návrh ho nenese, takže se podle údajů nehledá ani
     * v návrhu. Objekt s názvem, IČO nebo DIČ bez `ref` je v pokynu chyba
     * `reference_missing`; v návrhu varování a pole se nepoužije (dál platí
     * volba `_resolve…partner`, u pokladního dokladu strana).
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param bool $lenient návrh: údaje bez `ref` jen varují a pole se nepoužije;
     *        pokyn (false): chyba `reference_missing`
     * @return array<string, mixed>|null blok `_resolve`; null = pole partnera neurčuje
     */
    public function resolvePartner(string $path, mixed $partner, bool $lenient, array &$issues): ?array
    {
        if (!is_array($partner)) {
            return null;
        }
        if (CanonicalReferences::isSet($partner['ref'] ?? null)) {
            return $this->referenceBlock('base_persons_persons', $partner['ref'], $path, $issues);
        }
        if (!CanonicalReferences::partyIdentifies($partner)) {
            return null;
        }
        $issues[] = [
            'severity' => $lenient ? 'warning' : 'error',
            'path'     => $path,
            'code'     => 'reference_missing',
            'message'  => $lenient
                ? 'Partner nese údaje osoby, ale ne referenci — pole se nepoužije.'
                : 'Partner nese údaje osoby, ale ne referenci — v pokynu ho urči polem ref (id, "@kód osoby", "@company_id:IČO").',
        ];
        return $lenient ? null : self::REFERENCE_ERROR_BLOCK;
    }

    /** Id osoby z bloku `_resolve`, jen když se našla. */
    public static function matchedPersonId(mixed $block): ?int
    {
        return is_array($block) && ($block['status'] ?? null) === ResolveStatus::Matched->value && isset($block['matchedId'])
            ? (int) $block['matchedId']
            : null;
    }

    /**
     * Řádek canonicalu nese blok položky — tatáž podmínka, podle které
     * {@see DocumentApplier::resolveAll()} vyrábí `_resolve.rows[i].item`; podle ní
     * {@see Interpret\RecordMatching::skippedRowIndices()} uznává položkovou volbu `skip`.
     */
    public static function rowHasItem(mixed $row): bool
    {
        return is_array($row) && is_array($row['item'] ?? null) && $row['item'] !== [];
    }

    /**
     * Dimenze deníku z objektu `dimensions` (#110 T2): klíč = id dimenze,
     * hodnota = reference na záznam cílové tabulky (#166 D30 — id, nebo
     * `"@klíč"` podle `exchangeKey` tabulky). Neznámé id hlásí
     * {@see DocumentValidator} (`dimension_unknown`) — tady se přeskočí;
     * prázdná hodnota = bez dimenze; špatný tvar, nenalezený nebo
     * nejednoznačný záznam = chyba s kódem resolveru (blokuje apply).
     * Platí v obou režimech i v importním módu. Bez resolveru (DS bez
     * dimenzí) se nic neřeší.
     *
     * @param array<string, mixed> $dimensions
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, array{value: int|string, status: string, matchedId?: int}>
     */
    public function resolveDimensions(array $dimensions, string $path, array &$issues): array
    {
        if ($this->dimensionResolver === null) {
            return [];
        }
        $out = [];
        foreach ($dimensions as $id => $value) {
            $id = (string) $id;
            if (!CanonicalReferences::isSet($value) || !$this->dimensionResolver->knows($id)) {
                continue;
            }
            try {
                $out[$id] = [
                    'value'     => $value,
                    'status'    => 'matched',
                    'matchedId' => $this->dimensionResolver->resolve($id, $value),
                ];
            } catch (ReferenceException $e) {
                $out[$id] = ['value' => $value, 'status' => 'notFound'];
                $issues[] = PayloadContext::referenceIssue($e, "{$path}.{$id}", $this->dimensionResolver->name($id) . ': ');
            }
        }
        return $out;
    }

    /**
     * @param array<string, array{value: string, status: string, matchedId?: int}> $resolve
     * @return array<string, int> id dimenze → id záznamu (jen nalezené)
     */
    public static function matchedDimensionIds(array $resolve): array
    {
        $out = [];
        foreach ($resolve as $id => $r) {
            if (($r['status'] ?? null) === 'matched' && isset($r['matchedId'])) {
                $out[(string) $id] = (int) $r['matchedId'];
            }
        }
        return $out;
    }

    /**
     * Účet řádku zadaný **referencí** (číslo = id, `"@518100"`) — v obou
     * režimech. Nenalezený je chyba s kódem resolveru. Holé číslo je signál
     * návrhu (D39) a sem nepatří.
     *
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{number: ?string, matchedId: ?int, status: string}
     */
    public function rowAccountReference(int|string $idx, mixed $account, array &$issues): array
    {
        try {
            $accountId = $this->payload->references()->resolve(self::ACCOUNTS_TABLE, $account);
        } catch (ReferenceException $e) {
            $issues[] = PayloadContext::referenceIssue($e, "rows.{$idx}.account");
            return ['number' => is_string($account) ? substr($account, 1) : null, 'matchedId' => null, 'status' => 'notFound'];
        }
        return [
            'number'    => $this->payload->references()->keyOf(self::ACCOUNTS_TABLE, $accountId),
            'matchedId' => $accountId,
            'status'    => 'matched',
        ];
    }

    /**
     * Opravný doklad (#92 task B U3): výsledek dohledání původního dokladu
     * jako blok `_resolve.correction` — tvar jako ResolveResult::toArray();
     * souhrn ho nepočítá. Null = doklad blok nenese nebo ho typ nepodporuje.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>|null
     */
    public function correctionBlock(array $canonical): ?array
    {
        $correction = $this->payload->correctionDecision($canonical);
        if ($correction === null || !$correction['supported']) {
            return null;
        }
        return array_filter([
            'status'       => $correction['status'],
            'matchedId'    => $correction['originalId'],
            'matchedBy'    => $correction['matchedBy'],
            'docNumber'    => $correction['originalNumber'],
            'taxPointDate' => $correction['originalDate'],
        ], static fn ($v) => $v !== null);
    }

    // ── Kódy DPH, které payload říká ────────────────────────────────────────

    /**
     * `_resolve.rows[].vatCode` řádku, jehož kód **nese payload**: jen se
     * ověří proti číselníku země registrace k datu sazby řádku (#159 U3);
     * neznámý je chyba `vat_code_unknown`. Null = řádek kód nenese.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $vatCtx kontext DPH dokladu (`rateHead`)
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @param float|null $fallbackPct procento z dokladu jako záloha, když kód
     *        k datu sazbu nemá — jen návrh; pokyn `vat.pct` nepoužije (#166 D20)
     * @return array<string, mixed>|null
     */
    public function rowVatCode(
        int $idx,
        array $row,
        array $vatCtx,
        string $vatCountry,
        string $supplierCountry,
        array &$issues,
        ?float $fallbackPct = null,
    ): ?array {
        $rowVat = is_array($row['vat'] ?? null) ? $row['vat'] : [];
        $code = trim((string) ($rowVat['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $date = $this->payload->lineRateDate($vatCtx, $rowVat['rateDate'] ?? null);
        $country = $this->payload->vatCountryForCode($code, $vatCountry, $supplierCountry);
        $vatR = $this->vatCodeResolver->resolve(
            $code,
            $country !== '' ? $country : null,
            $date,
            $fallbackPct,
        );
        if ($vatR->status === ResolveStatus::NotFound) {
            $issues[] = [
                'severity' => 'error',
                'path'     => "rows.{$idx}.vat.code",
                'code'     => 'vat_code_unknown',
                'message'  => "Neznámý kód DPH „{$code}\".",
            ];
        }
        return $vatR->toArray();
    }

    /**
     * `_resolve.rows[].reverseChargeCode` jednoho řádku (#89 D10): předmět
     * plnění u řádku, na kterém skončí kód tuzemského přenesení — ať kód
     * vznikl derivací, z canonicalu, z historie řádků nebo volbou. Null =
     * řádek kód přenesení nemá (předmět se pak ignoruje) nebo doklad vznikne
     * Bez DPH.
     *
     * Předmět se bere z `rows[].vat.reverseChargeCode` a ověřuje proti
     * číselníku k datu sazby řádku; chybějící (`reverse_charge_code_missing`) ani
     * neznámý (`reverse_charge_code_unknown`) se nedoplňuje — error, který
     * blokuje apply i v importním módu.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $vatResolve {@see rowVatCode()}
     * @param array<string, mixed> $vatCtx {@see PayloadContext::vatContext()}
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * Volba uživatele (`_resolve.rows[].reverseChargeCode.userAction:
     * "useValue:<kód>"`, D12) má přednost; mimo nabídku je error
     * `reverse_charge_code_pin_invalid`. U řádku bez kódu přenesení se
     * ignoruje i volba.
     *
     * @return array{value: ?string, source: ?string, status: string}|null
     */
    public function resolveRowReverseChargeCode(
        int $idx,
        array $row,
        ?array $vatResolve,
        array $vatCtx,
        string $vatCountry,
        string $supplierCountry,
        array &$issues,
    ): ?array {
        if ($vatResolve === null || ($vatResolve['status'] ?? null) !== 'matched' || $vatCtx['withoutVat']) {
            return null;
        }
        $code = trim((string) ($vatResolve['createPayload']['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $country = $this->payload->vatCountryForCode($code, $vatCountry, $supplierCountry);
        $country = $country !== '' ? $country : $vatCountry;
        // Platnost předmětu k datu sazby řádku (#159 U3).
        $date = $this->payload->lineRateDate($vatCtx, $row['vat']['rateDate'] ?? null);

        // D12: volba uživatele má přednost před dokladem. Platí jen předmět
        // z číselníku platný k datu sazby řádku (klient dostává nabídku
        // `reverseChargeOptions` k datu sazby dokladu); volba mimo je error
        // a předmět z dokladu ji nenahradí.
        $pinned = $vatCtx['pins']['subjects'][$idx] ?? null;
        if ($pinned !== null) {
            $decision = $this->vatCodeDerivation->reverseChargeSubject($country, $code, $date, $pinned);
            if (!$decision['applies']) {
                return null; // řádek kód přenesení nemá — volba se ignoruje
            }
            if ($decision['status'] !== 'matched') {
                $issues[] = [
                    'severity' => 'error',
                    'path'     => "rows.{$idx}.vat.reverseChargeCode",
                    'code'     => 'reverse_charge_code_pin_invalid',
                    'message'  => "Zvolený předmět plnění „{$pinned}“ není v nabídce"
                        . ($date !== null ? " platné k datu {$date}" : '') . ' — vyber jiný.',
                ];
            }
            return [
                'value'  => $decision['value'],
                'source' => 'user',
                'status' => $decision['status'] === 'matched' ? 'matched' : 'notFound',
            ];
        }

        $input = trim((string) ($row['vat']['reverseChargeCode'] ?? ''));
        $decision = $this->vatCodeDerivation->reverseChargeSubject($country, $code, $date, $input);
        if (!$decision['applies']) {
            return null;
        }
        if ($decision['status'] !== 'matched') {
            $issues[] = self::reverseChargeCodeIssue("rows.{$idx}.vat.reverseChargeCode", $decision, $date);
        }
        return [
            'value'  => $decision['value'],
            'source' => $decision['value'] !== null ? 'document' : null,
            'status' => $decision['status'] === 'matched' ? 'matched' : 'notFound',
        ];
    }

    /**
     * Issue chybějícího / neznámého předmětu plnění — jeden text pro řádek
     * i řádek převzaté rekapitulace.
     *
     * @param array{status: ?string, value: ?string} $decision {@see VatCodeDerivation::reverseChargeSubject()}
     * @return array{severity: string, path: string, code: string, message: string}
     */
    public static function reverseChargeCodeIssue(string $path, array $decision, ?string $date, string $prefix = ''): array
    {
        if ($decision['status'] === 'missing') {
            return [
                'severity' => 'error',
                'path'     => $path,
                'code'     => 'reverse_charge_code_missing',
                'message'  => $prefix . 'U přenesení daňové povinnosti chybí předmět plnění'
                    . ' (kód pro kontrolní hlášení, např. 4 stavební práce, 5 zboží z přílohy č. 5).',
            ];
        }
        return [
            'severity' => 'error',
            'path'     => $path,
            'code'     => 'reverse_charge_code_unknown',
            'message'  => $prefix . "Předmět plnění „{$decision['value']}“ není v číselníku"
                . ($date !== null ? " nebo k datu {$date} neplatí." : '.'),
        ];
    }

    /**
     * Předmět plnění řádku pro doklad: jen ověřený (`matched`) — neznámý se
     * na doklad nepropíše, apply stejně zablokuje error.
     *
     * @param array<string, mixed> $rowResolve
     */
    public static function matchedReverseChargeCode(array $rowResolve): ?string
    {
        $block = $rowResolve['reverseChargeCode'] ?? null;
        return is_array($block) && ($block['status'] ?? null) === 'matched' && ($block['value'] ?? null) !== null
            ? (string) $block['value']
            : null;
    }

    /**
     * Jeden řádek převzaté rekapitulace do tvaru dokladu — společné pro
     * návrh i pokyn. Kód už je ověřený proti číselníku země `$country`.
     *
     * @param array<int, array<string, mixed>> $recap
     * @param list<array{severity: string, path: string, code: string, message: string}> $subjectIssues
     * @param array<string, mixed> $entry řádek `vatRecap`
     */
    public function appendRecapEntry(
        array &$recap,
        array &$subjectIssues,
        array $entry,
        int|string $position,
        string $code,
        float $pct,
        ?string $rateDate,
        string $country,
    ): void {
        $recap[] = [
            'vat_code'        => $code,
            'vat_pct'         => $pct,
            'base'            => round((float) ($entry['base'] ?? 0), 2),
            'tax'             => round((float) ($entry['tax'] ?? 0), 2),
            'total'           => round((float) ($entry['total'] ?? 0), 2),
            'is_reverse_pair' => ($entry['isReversePair'] ?? null) === true ? 1 : 0,
        ];
        $last = array_key_last($recap);
        // Vlastní datum sazby řádku (#159 U4) se jen předá — mimo režim
        // „na řádcích“ ho dokument při uložení smaže.
        $ownRateDate = PayloadContext::dateText($entry['rateDate'] ?? null);
        if ($ownRateDate !== null) {
            $recap[$last][VatRateDates::COLUMN] = $ownRateDate;
        }
        // Předmět plnění tuzemského přenesení (#89 D10): přebírá se ze
        // vstupu, nic se nedoplňuje — chybějící nebo neznámý je chyba
        // i v importním módu. Oddaňovací pár nese předmět skupiny, sám
        // se neověřuje.
        $subject = trim((string) ($entry['reverseChargeCode'] ?? ''));
        if (($entry['isReversePair'] ?? null) === true) {
            $recap[$last]['reverse_charge_code'] = $subject !== '' ? $subject : null;
            return;
        }
        $decision = $this->vatCodeDerivation->reverseChargeSubject($country, $code, $rateDate, $subject);
        if (!$decision['applies']) {
            return;
        }
        $recap[$last]['reverse_charge_code'] = $decision['value'];
        if ($decision['status'] !== 'matched') {
            $subjectIssues[] = self::reverseChargeCodeIssue(
                'vatRecap.' . $position . '.reverseChargeCode',
                $decision,
                $rateDate,
                "Rekapitulace DPH, řádek {$code}: ",
            );
        }
    }
}
