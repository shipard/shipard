<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * DPH kontext hlavičky dokladu pro formuláře dětských tabulek (řádek
 * dokladu, řádek rekapitulace). Sub-formulář zná jen `doc_head`, ale
 * potřebuje z hlavičky režim DPH, měnu a hlavně **zemi, směr a místo
 * plnění**, podle kterých se nabízejí DPH kódy.
 *
 * Sdílejí ho `DocRowsForm` a `VatRecapForm` — nabídka kódů musí být
 * v řádku i v rekapitulaci stejná.
 */
final class DocHeadVatContext
{
    /**
     * Kontext nese i sloupce hlavičky, ze kterých {@see VatRateDates} určuje
     * datum sazby (#159) — jde ho proto předat rovnou jako `$head` do
     * `VatRateDates::lineDate()`:
     *  - `vat_rate_source` — platný zdroj data sazby (0 / 1 / 2),
     *  - `vat_rate_date` — vlastní datum dokladu (sloupec hlavičky),
     *  - `effective_rate_date` — datum sazby dokladu
     *    (`VatRateDates::headDate()`: vlastní datum při zdroji 1, jinak
     *    automatika),
     *  - `automatic_rate_date` — automatické datum
     *    (`VatRateDates::automaticDate()`), platí pro řádek bez vlastního
     *    data při zdroji 2.
     *
     * @return array{doc_type: string, cash_dir: int, vat_place: int,
     *     vat_duzp: mixed, vat_dppd: mixed, issue_date: mixed, corrective: mixed,
     *     corrected_doc_date: mixed, vat_rate_source: int, vat_rate_date: ?string,
     *     effective_rate_date: ?string, automatic_rate_date: ?string, vat_mode: int,
     *     doc_currency: string, home_currency: string, exchange_rate: float,
     *     country: ?string, direction: ?string, place: string}|null
     */
    public static function load(?DataSourceConnection $db, ?ConfigRuntime $config, mixed $docHeadId): ?array
    {
        if ($docHeadId === null || $docHeadId === '' || $db === null) {
            return null;
        }
        $head = $db->fetchRow(
            'SELECT `vat_registration`, `doc_type`, `cash_dir`, `vat_place`, `vat_duzp`, `vat_dppd`, `issue_date`,'
            . ' `corrective`, `corrected_doc_date`, `vat_rate_source`, `vat_rate_date`,'
            . ' `vat_mode`, `doc_currency`, `home_currency`, `exchange_rate`'
            . ' FROM `docs_core_heads` WHERE `id` = %i',
            (int) $docHeadId,
        );
        if ($head === null) {
            return null;
        }

        $context = [
            'doc_type'      => (string) ($head['doc_type'] ?? ''),
            'cash_dir'      => (int) ($head['cash_dir'] ?? 0),
            'vat_place'     => (int) ($head['vat_place'] ?? 0),
            'vat_duzp'      => $head['vat_duzp'] ?? null,
            'vat_dppd'      => $head['vat_dppd'] ?? null,
            'issue_date'    => $head['issue_date'] ?? null,
            'corrective'    => $head['corrective'] ?? null,
            'corrected_doc_date' => $head['corrected_doc_date'] ?? null,
            'vat_rate_source'     => VatRateDates::source($head, $config),
            'vat_rate_date'       => VatRateDates::dateString($head['vat_rate_date'] ?? null),
            'effective_rate_date' => VatRateDates::headDate($head, $config),
            'automatic_rate_date' => VatRateDates::automaticDate($head),
            'vat_mode'      => (int) ($head['vat_mode'] ?? 1),
            'doc_currency'  => (string) ($head['doc_currency'] ?? ''),
            'home_currency' => (string) ($head['home_currency'] ?? ''),
            'exchange_rate' => (float) ($head['exchange_rate'] ?? 1.0),
            'country'       => null,
            'direction'     => null,
            'place'         => 'domestic',
        ];

        if (!empty($head['vat_registration'])) {
            $reg = $db->fetchRow(
                'SELECT `country` FROM `economy_codebooks_vat_registrations` WHERE `id` = %i',
                (int) $head['vat_registration'],
            );
            if ($reg !== null && !empty($reg['country'])) {
                $context['country'] = (string) $reg['country'];
            }
        }

        // Směr DPH kódů = směr obchodu dokladu (per typ, u pokladního
        // dokladu per doklad z cash_dir) — jediná autorita DocDocument.
        $context['direction'] = match (DocDocument::resolveTradeDir($head, $config)) {
            1 => 'output',
            2 => 'input',
            default => null,
        };

        $context['place'] = match ($context['vat_place']) {
            0 => 'domestic',
            1 => 'intracom',
            2 => 'foreign',
            default => 'domestic',
        };

        return $context;
    }

    /**
     * Nabídka DPH kódů pro sub-formulář: kódy země, směru a místa plnění
     * hlavičky, **které mají k datu sazby sazbu** (#159 D7, U9) — popisek
     * nese procento („Tuzemsko/Vstup/Základní — 21 %“). Bez země nebo směru
     * (neplátce, nedohledaná registrace) prázdná — pole pak nemá co nabídnout.
     *
     * Datum sazby je datum řádku (`$rateDate` — `VatRateDates::lineDate()`),
     * bez něj datum sazby dokladu z kontextu. Uložený kód řádku
     * (`$currentCode`), který k datu sazbu nemá — rozpracovaný doklad po
     * změně data — v nabídce zůstává s označením podle `$noRatePattern`
     * (vzor s `{label}` z katalogu textů formuláře), aby ho šlo vědomě
     * vyměnit.
     *
     * @param array<string, mixed>|null $context
     * @return list<array{value: string, label: string}>
     */
    public static function vatCodeOptions(
        ?array $context,
        ?ConfigRuntime $config,
        ?string $rateDate = null,
        mixed $currentCode = null,
        string $noRatePattern = '{label} — no rate at this date',
    ): array {
        if ($context === null
            || empty($context['country'])
            || empty($context['direction'])
            || $config === null
        ) {
            return [];
        }
        $country = (string) $context['country'];
        $resolver = new VatRateResolver($config);
        try {
            $codes = $resolver->getVatCodes(
                $country,
                (string) $context['direction'],
                (string) $context['place'],
                includeHidden: false,
            );
        } catch (\LogicException) {
            return [];
        }
        $date = $rateDate ?? VatRateDates::dateString($context['effective_rate_date'] ?? null);
        $current = trim((string) ($currentCode ?? ''));

        $options = [];
        foreach ($codes as $key => $code) {
            $label = (string) ($code['fullName'] ?? $code['name'] ?? $key);
            if ($date !== null) {
                $pct = VatRateDates::pct($resolver, $country, (string) $key, $date);
                if ($pct !== null) {
                    $label .= ' — ' . self::formatPct($pct) . ' %';
                } elseif ((string) $key === $current) {
                    $label = strtr($noRatePattern, ['{label}' => $label]);
                } else {
                    continue;
                }
            }
            $options[] = ['value' => (string) $key, 'label' => $label];
        }
        return $options;
    }

    /** Procento bez zbytečných nul: 21, 12, 12,5. */
    public static function formatPct(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',');
    }
}
