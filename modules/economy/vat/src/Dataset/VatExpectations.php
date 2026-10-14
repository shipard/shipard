<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Dataset;

/**
 * Agregace očekávání DPH (`expected/vat.json`, tasks/testing-phase3.md D28)
 * přes použité doklady po obdobích (`YYYY-MM` podle DUZP): řádky přiznání
 * se sčítají, kontrolní hlášení drží B.1 / B.2 / A.2 po dokladech (klíč DIČ
 * + evidenční číslo) a B.3 / A.5 jen jako součty po sazbách. Přeskočené
 * doklady (cizí měna) do agregace nejdou. Čistá logika bez DB.
 */
final class VatExpectations
{
    /** Oddíly, které hlášení nese po dokladech; ostatní jen součtem. */
    public const ITEM_SECTIONS = ['A1', 'A2', 'A4', 'B1', 'B2'];

    /**
     * Pásmo KH podle sazby (CZ): 1 = základní, 2 = první snížená,
     * 3 = druhá snížená (do 2023 10 %).
     */
    public const BAND_BY_RATE = [21 => 1, 12 => 2, 15 => 2, 10 => 3];

    /**
     * @param list<array<string, mixed>> $records   záznamy vat.json
     * @param list<string>               $messageIds použité zprávy
     * @return array<string, array{return: array<string, array{base: float, tax: float}>, items: array<string, array<string, array<string, mixed>>>, sums: array<string, array<int, array{base: float, tax: float}>>}>
     *         období → agregace
     */
    public static function aggregate(array $records, array $messageIds): array
    {
        $use = array_fill_keys($messageIds, true);
        $out = [];
        foreach ($records as $record) {
            $messageId = (string) ($record['messageId'] ?? '');
            if (!isset($use[$messageId])) {
                continue;
            }
            $period = $record['period'] ?? null;
            if (!is_string($period) || $period === '') {
                continue;
            }
            $agg = $out[$period] ?? ['return' => [], 'items' => [], 'sums' => []];
            foreach ((array) ($record['return'] ?? []) as $row => $cell) {
                $key = (string) $row;
                $agg['return'][$key] = [
                    'base' => ($agg['return'][$key]['base'] ?? 0.0) + (float) ($cell['base'] ?? 0),
                    'tax'  => ($agg['return'][$key]['tax'] ?? 0.0) + (float) ($cell['tax'] ?? 0),
                ];
            }
            $cs = $record['controlStatement'] ?? null;
            if (is_array($cs) && is_string($cs['section'] ?? null)) {
                $section = self::sectionCode($cs['section']);
                $rates = [];
                foreach ((array) ($cs['rates'] ?? []) as $rate => $cell) {
                    $rates[(int) $rate] = ['base' => (float) ($cell['base'] ?? 0), 'tax' => (float) ($cell['tax'] ?? 0)];
                }
                if (in_array($section, self::ITEM_SECTIONS, true)) {
                    $agg['items'][$section][self::itemKey($cs['supplierVatId'] ?? null, $cs['documentNumber'] ?? null)] = [
                        'messageId'         => $messageId,
                        'vatId'             => self::normalizeVatId($cs['supplierVatId'] ?? null),
                        'documentNumber'    => trim((string) ($cs['documentNumber'] ?? '')),
                        'taxPointDate'      => $cs['taxPointDate'] ?? null,
                        'reverseChargeCode' => $cs['reverseChargeCode'] ?? null,
                        'rates'             => $rates,
                    ];
                } else {
                    foreach ($rates as $rate => $cell) {
                        $agg['sums'][$section][$rate] = [
                            'base' => ($agg['sums'][$section][$rate]['base'] ?? 0.0) + $cell['base'],
                            'tax'  => ($agg['sums'][$section][$rate]['tax'] ?? 0.0) + $cell['tax'],
                        ];
                    }
                }
            }
            $out[$period] = $agg;
        }
        ksort($out);
        return $out;
    }

    /** `B.2` → `B2` (kód oddílu v reportu). */
    public static function sectionCode(string $section): string
    {
        return str_replace('.', '', strtoupper(trim($section)));
    }

    public static function normalizeVatId(?string $vatId): string
    {
        return strtoupper((string) preg_replace('/\s+/u', '', (string) $vatId));
    }

    public static function itemKey(?string $vatId, ?string $documentNumber): string
    {
        return self::normalizeVatId($vatId) . '|' . trim((string) $documentNumber);
    }

    /** Pásmo KH pro sazbu; null = sazba mimo známá pásma. */
    public static function bandForRate(int $rate): ?int
    {
        return self::BAND_BY_RATE[$rate] ?? null;
    }
}
