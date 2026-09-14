<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Reports\FiscalPeriodProvider;

/**
 * Definice filtru „fiskální rok" pro viewery (deník, saldokonto) — jediné
 * místo, které ví, co je **aktuální fiskální rok**: rok obsahující dnešek
 * ({@see FiscalPeriodProvider::yearForDate}), jinak nejnovější existující
 * ({@see FiscalPeriodProvider::years} první). Výchozí hodnotu nese klíč
 * `default` definice filtru; frontend ji při otevření vieweru předvyplní,
 * `open_viewer` s `filters` ji přebíjí (docs/frontend.md § Filtry vieweru).
 *
 * Options jdou nejnovější první (stejně jako dosavadní select deníku), bez
 * roků je definice bez `default` (select jen s „— vše —").
 */
final class FiscalYearFilter
{
    public const ID = 'fiscal_year';

    /**
     * @param string $today datum `Y-m-d`, pro které se hledá aktuální rok
     * @return array{id: string, label: string, type: string, options: list<array{value: int, label: string}>, default?: string}
     */
    public static function build(FiscalPeriodProvider $periods, string $label, string $today): array
    {
        $years = $periods->years();

        $options = [];
        foreach ($years as $y) {
            $options[] = ['value' => $y['id'], 'label' => $y['name']];
        }

        $filter = ['id' => self::ID, 'label' => $label, 'type' => 'select', 'options' => $options];

        $current = $periods->yearForDate($today) ?? ($years[0] ?? null);
        if ($current !== null) {
            $filter['default'] = (string) $current['id'];
        }

        return $filter;
    }
}
