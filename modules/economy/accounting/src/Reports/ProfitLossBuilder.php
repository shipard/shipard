<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\SubtotalAggregator;

/**
 * Výsledovka (interní podoba) — obraty tříd 5 a 6 (filtr čistě prefixový;
 * kdyby se v datech objevily otevírací zápisy na 5/6, vstoupí — správně):
 *
 * - `period`: obraty za zvolený interval,
 * - `ytd`: obraty od začátku fiskálního roku do konce intervalu
 *   (odpovídá sloupcům „Měsíc / Rok" starého Shipardu).
 *
 * Místo generického total řádku končí `computed` řádkem „Výsledek
 * hospodaření za období": balance = výnosy − náklady (kladné = zisk),
 * md/d = 0 — výsledek není obrat stran. Řazení dle čísla účtu dává
 * Náklady (5) před Výnosy (6). Chybové masky a nulové řádky jako v knize.
 */
final class ProfitLossBuilder implements ReportBuilder
{
    private const CLASSES = ['5', '6'];

    /**
     * Stabilní klíče řádků (docs/reports.md §16.4), podle kterých Přehled
     * firmy (#120 D8) čte výsledek, náklady a výnosy — nikdy podle popisku.
     */
    public const KEY_RESULT   = 'result';
    public const KEY_COSTS    = 'costs';
    public const KEY_REVENUES = 'revenues';

    public function build(ReportRequest $request): ReportResult
    {
        $cs        = $request->language === 'cs';
        $synthetic = ($request->params['detail'] ?? 'analytic') === 'synthetic';
        $support   = new JournalReportSupport();

        $before  = $support->aggregate($request, $request->range->monthIdsBefore, self::CLASSES);
        $inRange = $support->aggregate($request, $request->range->monthIdsInRange, self::CLASSES);

        // Sloučení na klíč řádku jako v knize; ytd = before + period.
        $accounts = [];
        $slices   = ['ytd' => $before, 'period' => $inRange];
        foreach ($slices as $columnId => $sums) {
            foreach ($sums as $sum) {
                $key = (!$sum['isError'] && $synthetic)
                    ? substr($sum['number'], 0, 3)
                    : $sum['number'];
                if (!isset($accounts[$key])) {
                    $accounts[$key] = [
                        'isError' => $sum['isError'],
                        'period'  => ['md' => 0.0, 'd' => 0.0],
                        'ytd'     => ['md' => 0.0, 'd' => 0.0],
                    ];
                }
                $accounts[$key][$columnId]['md'] += $sum['md'];
                $accounts[$key][$columnId]['d']  += $sum['d'];
                if ($columnId === 'period') {
                    $accounts[$key]['ytd']['md'] += $sum['md'];
                    $accounts[$key]['ytd']['d']  += $sum['d'];
                }
            }
        }

        $names       = $support->loadAccountNames($request);
        $detailLevel = $synthetic ? 3 : 4;

        $detailRows = [];
        $errorKeys  = [];
        $result     = ['period' => 0.0, 'ytd' => 0.0];
        foreach ($accounts as $key => $acc) {
            $key = (string) $key;
            $p   = ['md' => round($acc['period']['md'], 2), 'd' => round($acc['period']['d'], 2)];
            $y   = ['md' => round($acc['ytd']['md'], 2), 'd' => round($acc['ytd']['d'], 2)];
            // Účet bez pohybu v roce do výsledovky nepatří.
            if ($p['md'] === 0.0 && $p['d'] === 0.0 && $y['md'] === 0.0 && $y['d'] === 0.0) {
                continue;
            }
            if ($acc['isError']) {
                $errorKeys[] = $key;
            }
            // Výsledek = Σ(d − md) přes třídy 5 i 6: (d−md) tříd 6 − (md−d)
            // tříd 5 = výnosy − náklady. Chybové masky vstupují — konzistentně
            // se subtotaly.
            $result['period'] += $p['d'] - $p['md'];
            $result['ytd']    += $y['d'] - $y['md'];

            $detailRows[] = new ReportRow(
                ReportRowKind::Detail,
                $detailLevel,
                $key,
                $acc['isError'] ? $key : ($names[$key] ?? $key),
                [
                    'period' => $p + ['balance' => round($p['md'] - $p['d'], 2)],
                    'ytd'    => $y + ['balance' => round($y['md'] - $y['d'], 2)],
                ],
            );
        }

        $aggregator = new SubtotalAggregator();
        $rows       = $aggregator->rollup(
            $detailRows,
            $synthetic ? [2, 1] : [3, 2, 1],
            static fn (string $prefix, int $length): string => $names[$prefix] ?? $prefix,
            $cs ? 'Celkem' : 'Total',
        );

        // Aggregator vrací total vždy (jádro se nemění) — výsledovka místo
        // něj končí dopočteným výsledkem hospodaření.
        if ($rows !== []) {
            array_pop($rows);
        }
        // Součty tříd 5 a 6 dostanou stabilní klíč (ReportRow je readonly →
        // nová instance); ostatní řádky beze změny.
        foreach ($rows as $i => $row) {
            if ($row->kind !== ReportRowKind::Subtotal || $row->level !== 1) {
                continue;
            }
            $key = match ($row->account) {
                '5'     => self::KEY_COSTS,
                '6'     => self::KEY_REVENUES,
                default => null,
            };
            if ($key !== null) {
                $rows[$i] = new ReportRow($row->kind, $row->level, $row->account, $row->label, $row->values, $key);
            }
        }
        $rows[] = new ReportRow(
            ReportRowKind::Computed,
            0,
            null,
            $cs ? 'Výsledek hospodaření za období' : 'Profit or loss for the period',
            [
                'period' => ['md' => 0.0, 'd' => 0.0, 'balance' => round($result['period'], 2)],
                'ytd'    => ['md' => 0.0, 'd' => 0.0, 'balance' => round($result['ytd'], 2)],
            ],
            self::KEY_RESULT,
        );

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $support->errorMessages($errorKeys, $rows, $cs),
            columns: [
                new ReportColumn('period', ReportColumn::TYPE_MONEY, $cs ? 'Za období' : 'Period'),
                new ReportColumn('ytd', ReportColumn::TYPE_MONEY, $cs ? 'Od počátku roku' : 'Year to date'),
            ],
            rows: $rows,
        );
    }
}
