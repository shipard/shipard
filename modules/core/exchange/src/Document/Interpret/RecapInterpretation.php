<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Document\PayloadContext;
use Shipard\Module\Core\Exchange\Document\PayloadResolve;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\VatCodeResolver;

/**
 * Rekapitulace DPH dokladu z **papírové podoby** (#166 D51, etapa 5):
 * rozhoduje, zda se rekapitulace z předlohy převezme, nebo spočítá z řádků
 * — odvození `recapSource`, dohledání kódu rekapitulace z řádků, kontrola
 * aritmetiky, přepočet při samovyměření a při vynechaném řádku. Pokyn
 * `vat.recapSource` bere jako záměr a nic z toho nedělá (D19, D20).
 */
final class RecapInterpretation
{
    /** Důvod přepočtu rekapitulace u samovyměření (`recapFallback`, D3). */
    public const FALLBACK_REVERSE_CHARGE = 'přenesení daňové povinnosti — rekapitulace dodavatele se nepřebírá';

    public function __construct(
        private readonly PayloadContext $payload,
        private readonly PayloadResolve $resolve,
        private readonly VatInterpretation $vat,
        private readonly VatCodeResolver $vatCodeResolver,
    ) {
    }

    /**
     * Zdroj rekapitulace pro `docs_core_heads.vat_recap_source` a řádky
     * rekapitulace k převzetí (`docs/vat-calculation.md` § 5, R3/I4/I7).
     *
     * - `vat.recapSource: "declared"` — rekapitulace ze zdroje je fakt
     *   (import ze starého Shipardu, doklad dodavatele). Bere se, jak
     *   přišla; jediná podmínka je dohledatelný DPH kód u každého řádku,
     *   bez něj by nešlo určit ani flagy sčítání (`vat_code` je NOT NULL).
     *   Vnitřní nesrovnalost **není** důvod k přepočtu — u přenesení daňové
     *   povinnosti `base + tax ≠ total` platí a je správně; nesrovnalost
     *   hlásí warning `vat_recap_inconsistent`.
     * - `"computed"` — spočítat z řádků.
     * - `null` — odvodí se: u dokladu, který **přijímáme** (`selfParty`
     *   customer, typicky AI extrakce faktury dodavatele), je převzatá
     *   tehdy, když rekapitulace je neprázdná, každý řádek projde
     *   aritmetickou kontrolou a kódy jsou dohledatelné. Jinak přepočítaná.
     *   U vystavených dokladů vždy přepočítaná — rekapitulaci děláme my.
     *
     * Přijatý doklad neplátce DPH (#97 D8) rekapitulaci nemá nikdy — ani
     * při explicitním `declared`. Daň dodavatele je součástí ceny řádků,
     * rekapitulace zůstává jen v canonicalu analýzy; bez důvodu fallbacku,
     * info o tom nese `vat_non_payer`. Totéž platí pro doklad mimo české
     * DPH (#89, `vat_foreign_tax` / `vat_special_place`).
     *
     * „Dohledatelný kód" (I7) = existuje v číselníku země registrace, ověřuje
     * se stejným `VatCodeResolver` a stejnou kaskádou země jako kódy řádků.
     * Kód, který resolver nezná, by `DocDocument::takeOverVatRecapitulation`
     * odmítl `DomainException` a apply by skončil 500 — místo toho se
     * rekapitulace přepočítá z řádků a uživatel dostane info issue s důvodem.
     *
     * Vynechaný řádek (#111 D12): rekapitulace dodavatele je za všechny
     * řádky, doklad jich má míň — přepočet i při explicitním `declared`,
     * důvod „vynechaný řádek“. Jinak by součty dokladu zůstaly za celou
     * fakturu a náhled by vynechání neukázal.
     *
     * @param array<string, mixed> $canonical
     * @param list<int> $rowSkips Vynechané řádky ({@see skippedRowIndices}).
     * @return array{source: int, recap: array<int, array<string, mixed>>, fallback: ?string,
     *     issues?: list<array{severity: string, path: string, code: string, message: string}>}
     *     `issues` = chyby předmětu plnění převzaté rekapitulace (#89 D10).
     */
    public function recapSource(array $canonical, array $rowSkips): array
    {
        $computed = ['source' => 0, 'recap' => [], 'fallback' => null];
        $vatCtx = $this->vat->context($canonical);
        if ($vatCtx['withoutVat']) {
            return $computed;
        }
        $flag = $canonical['vat']['recapSource'] ?? null;
        if ($flag !== null && (DocumentApplier::VAT_RECAP_SOURCE_MAP[(string) $flag] ?? null) === 0) {
            return $computed;
        }
        $explicit = (string) ($flag ?? '') === 'declared';

        $entries = $canonical['vatRecap'] ?? null;
        if (!is_array($entries) || $entries === []) {
            return $explicit
                ? ['source' => 0, 'recap' => [], 'fallback' => 'rekapitulace je prázdná']
                : $computed;
        }

        // Bez explicitní deklarace přebírá rekapitulaci jen doklad, který
        // přijímáme — u vystaveného ji počítáme sami.
        if (!$explicit && (string) ($canonical['selfParty'] ?? '') !== 'customer') {
            return $computed;
        }
        // D12 (#111): vynechaný řádek — až tady, kde by se jinak převzala,
        // aby důvod odpovídal skutečnosti (prázdná rekapitulace a vystavený
        // doklad mají svoje).
        if ($rowSkips !== []) {
            return ['source' => 0, 'recap' => [], 'fallback' => 'vynechaný řádek'];
        }

        // D3 (exchange-received-reverse-charge): samovyměření — rekapitulace
        // dodavatele je z jeho pohledu (0 %, daň 0), naše nese nárok na
        // odpočet + oddaňovací pár. Bez explicitního declared vždy přepočet.
        if (!$explicit && $this->vat->rowsCarryReverseCharge($vatCtx)) {
            return [
                'source'   => 0,
                'recap'    => [],
                'fallback' => self::FALLBACK_REVERSE_CHARGE,
            ];
        }
        $codesByPct = $this->recapCodesFromRows($canonical, $vatCtx);
        $vatCountry = $this->payload->vatCodeCountry($vatCtx, $canonical);
        $supplierCountry = strtolower((string) ($canonical['supplier']['country'] ?? ''));
        $recap = [];
        $subjectIssues = [];
        foreach ($entries as $position => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $pct = (float) ($entry['vatPct'] ?? 0);
            // Kód i předmět k datu sazby řádku rekapitulace (#159 U3; opravný
            // doklad = původní plnění, #92 D4).
            $rateDate = $this->payload->lineRateDate($vatCtx, $entry['rateDate'] ?? null);
            $code = trim((string) ($entry['vatCode'] ?? ''));
            if ($code === '') {
                // I7: ISDOC rekapitulaci kódy nenese — dohledáme je z řádků,
                // ale jen když je pro sazbu jednoznačný.
                $code = $codesByPct[(string) $pct] ?? '';
            }
            if ($code === '') {
                return [
                    'source'   => 0,
                    'recap'    => [],
                    'fallback' => sprintf('řádek se sazbou %s %% nemá DPH kód', (string) $pct),
                ];
            }
            // I7: kód musí existovat v číselníku země registrace. Neznámý kód
            // (AI si ho vymyslela, import s jiným mapováním) → přepočítaná.
            $country = $this->payload->vatCountryForCode($code, $vatCountry, $supplierCountry);
            $codeR = $this->vatCodeResolver->resolve(
                $code,
                $country !== '' ? $country : null,
                $rateDate,
                $pct,
            );
            if ($codeR->status === ResolveStatus::NotFound) {
                return [
                    'source'   => 0,
                    'recap'    => [],
                    'fallback' => sprintf(
                        'DPH kód %s není v číselníku%s',
                        $code,
                        $country !== '' ? ' země ' . strtoupper($country) : '',
                    ),
                ];
            }
            if (!$explicit && !$this->recapEntryIsConsistent($entry, $pct)) {
                return [
                    'source'   => 0,
                    'recap'    => [],
                    'fallback' => sprintf('řádek %s %s %% je vnitřně nekonzistentní', $code, (string) $pct),
                ];
            }
            $this->resolve->appendRecapEntry(
                $recap,
                $subjectIssues,
                $entry,
                $position,
                $code,
                $pct,
                $rateDate,
                $country !== '' ? $country : $vatCountry,
            );
        }

        if ($recap === []) {
            return $explicit
                ? ['source' => 0, 'recap' => [], 'fallback' => 'rekapitulace je prázdná']
                : $computed;
        }
        return ['source' => 1, 'recap' => $recap, 'fallback' => null, 'issues' => $subjectIssues];
    }

    /**
     * Mapa sazba → DPH kód z položkových řádků, jen pro sazby s jediným
     * kódem (I7). Slouží rekapitulaci bez kódů (ISDOC, AI od promptu
     * v4.6.0). Bere kód, který na řádku skončí — odvozený má přednost před
     * canonicalem, jinak by domácí faktury bez kódů přestaly rekapitulaci
     * přebírat (#75).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $vatCtx {@see VatInterpretation::context()}
     * @return array<string, string>
     */
    public function recapCodesFromRows(array $canonical, array $vatCtx): array
    {
        $byPct = [];
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!is_array($row) || (string) ($row['rowKind'] ?? 'item') !== 'item') {
                continue;
            }
            $code = $this->vat->effectiveRowVatCode((int) $idx, $row, $vatCtx);
            if ($code === '') {
                continue;
            }
            $key = (string) (float) ($row['vat']['pct'] ?? 0);
            $byPct[$key][$code] = true;
        }
        $out = [];
        foreach ($byPct as $key => $codes) {
            if (count($codes) === 1) {
                $out[$key] = (string) array_key_first($codes);
            }
        }
        return $out;
    }

    /**
     * Aritmetika řádku rekapitulace — stejné tolerance jako
     * {@see DocumentValidator::checkVatRecapArithmetic}. Oddaňovací páry
     * a nulové sazby projdou vždy (daň je u nich informativní).
     *
     * @param array<string, mixed> $entry
     */
    public function recapEntryIsConsistent(array $entry, float $pct): bool
    {
        if (($entry['isReversePair'] ?? null) === true || $pct === 0.0) {
            return true;
        }
        foreach (['base', 'tax', 'total'] as $key) {
            if (!isset($entry[$key]) || !is_numeric($entry[$key])) {
                return false;
            }
        }
        $base  = (float) $entry['base'];
        $tax   = (float) $entry['tax'];
        $total = (float) $entry['total'];
        if (abs($base + $tax - $total) > 0.02) {
            return false;
        }
        return abs($tax - round($base * $pct / 100.0, 2)) <= max(0.05, abs($base) * 0.001);
    }

    /**
     * Info nález, když rekapitulace nešla převzít a spočítá se z řádků —
     * bez něj by uživatel nepoznal, proč je na dokladu jiná rekapitulace
     * než na předloze. Přenesení daňové povinnosti není chyba předlohy
     * („nešlo převzít“ by mátlo): dodavatel správně uvádí 0 %, daň
     * přiznáváme my, proto vlastní text; ostatní důvody šablonou.
     *
     * @param array{fallback: ?string} $resolved výsledek {@see recapSource()}
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    public static function fallbackIssue(array $resolved): ?array
    {
        if ($resolved['fallback'] === null) {
            return null;
        }
        $message = $resolved['fallback'] === self::FALLBACK_REVERSE_CHARGE
            ? 'Faktura s přenesením daňové povinnosti: dodavatel uvádí 0 % DPH, daň přiznáváte vy'
                . ' — rekapitulace se proto sestaví z řádků (daň na výstupu a nárok na odpočet).'
            : 'Rekapitulaci DPH nešlo převzít z dokladu (' . $resolved['fallback'] . ') — spočítá se z řádků.';
        return [
            'severity' => 'info',
            'path'     => 'vat.recapSource',
            'code'     => 'recap_source_computed_fallback',
            'message'  => $message,
        ];
    }
}
