<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportMessage;
use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\SubtotalAggregator;

/**
 * Sestava odpisů (docs/assets.md D66) — dlouhodobé karty v evidenci
 * v účetním roce: vstupní cena, oprávky na začátku, odpis roku, oprávky
 * a zůstatková cena na konci, daňově i účetně, a rozdíl účetní − daňový
 * odpis.
 *
 * Hodnoty jdou z plánů (potvrzené události + plán do konce roku); karta
 * s dosud nepotvrzeným odpisem roku má ve sloupci Stav „plán“. Daňový
 * odpis je uplatněný odpis (D11). Seskupení a výběr druhu parametry
 * `groupBy` a `category`.
 */
final class DepreciationScheduleBuilder implements ReportBuilder
{
    public function __construct(private readonly ?AssetReportSupport $support = null)
    {
    }

    public function build(ReportRequest $request): ReportResult
    {
        $cs = $request->language === 'cs';
        $support = $this->support ?? new AssetReportSupport();
        $period = $support->period($request);
        $categories = $support->categories($request);
        $groupBy = (string) ($request->params['groupBy'] ?? 'accountingGroup');
        $category = (string) ($request->params['category'] ?? 'all');

        $items = [];
        foreach ($support->longTermInPeriod($request, $period['yearBegin'], $period['yearEnd']) as $item) {
            $depreciable = $categories->isDepreciable((string) ($item->card['category'] ?? ''));
            if (($category === 'longTerm' && !$depreciable) || ($category === 'nondepreciable' && $depreciable)) {
                continue;
            }
            $items[] = $item;
        }

        // Skupina řádku: klíč, název a pořadí.
        $groups = [];
        $groupOfKey = [];
        $detailRows = [];
        $sortKeys = [];
        foreach ($items as $item) {
            [$groupKey, $groupLabel, $groupOrder] = $this->group($support, $request, $item, $groupBy, $cs);
            $groups[$groupKey] = $groupLabel;
            $key = 'asset:' . $item->id();
            $groupOfKey[$key] = $groupKey;
            $sortKeys[$key] = [$groupOrder, $groupLabel, AssetReportSupport::number($item->card), $item->id()];

            $state = [];
            if ($item->planned()) {
                $state[] = $cs ? 'plán' : 'planned';
            }
            $disposal = $item->disposalDate();
            if ($disposal !== null && $disposal <= $period['yearEnd']) {
                $state[] = ($cs ? 'vyřazeno ' : 'disposed ') . AssetReportSupport::czDate($disposal);
            }

            $detailRows[] = new ReportRow(
                ReportRowKind::Detail,
                $groupBy === 'none' ? 1 : 2,
                null,
                AssetReportSupport::name($item->card),
                [
                    'number'          => AssetReportSupport::number($item->card),
                    'taxRule'         => $support->taxRuleLabel($request, $item->card),
                    'state'           => implode(', ', $state),
                    'taxEntry'        => AssetReportSupport::money($item->tax->entryPrice),
                    'taxOpening'      => AssetReportSupport::money($item->tax->opening),
                    'taxDepreciation' => AssetReportSupport::money($item->tax->depreciation),
                    'taxClosing'      => AssetReportSupport::money($item->tax->closing),
                    'taxResidual'     => AssetReportSupport::money($item->tax->residual),
                    'accEntry'        => AssetReportSupport::money($item->acc->entryPrice),
                    'accOpening'      => AssetReportSupport::money($item->acc->opening),
                    'accDepreciation' => AssetReportSupport::money($item->acc->depreciation),
                    'accClosing'      => AssetReportSupport::money($item->acc->closing),
                    'accResidual'     => AssetReportSupport::money($item->acc->residual),
                    'difference'      => AssetReportSupport::money($item->acc->depreciation - $item->tax->depreciation),
                ],
                $key,
                AssetReportSupport::cardLink($item->id()),
            );
        }
        usort($detailRows, static fn(ReportRow $a, ReportRow $b): int => $sortKeys[$a->key] <=> $sortKeys[$b->key]);

        $rows = (new SubtotalAggregator())->groupBy(
            $detailRows,
            $groupBy === 'none' ? null : static fn(ReportRow $row): string => $groupOfKey[$row->key],
            static fn(string $key): string => $groups[$key] ?? $key,
            $cs ? 'Celkem' : 'Total',
        );

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $this->messages($support, $request, $items, $rows, $period['yearName'], $cs),
            columns: $this->columns($cs),
            rows: $rows,
        );
    }

    /** @return array{string, string, int} klíč skupiny, název, pořadí */
    private function group(AssetReportSupport $support, ReportRequest $request, AssetYear $item, string $groupBy, bool $cs): array
    {
        $card = $item->card;

        return match ($groupBy) {
            'taxRule' => [
                (string) ($card['tax_method'] ?? '') . '|' . (string) ($card['tax_rule'] ?? ''),
                $support->taxRuleLabel($request, $card) ?: ($cs ? 'Bez daňových odpisů' : 'No tax depreciation'),
                0,
            ],
            'type' => [(string) (int) ($card['asset_type'] ?? 0), AssetReportSupport::typeLabel($card, $cs), 0],
            'none' => ['', '', 0],
            default => [
                (string) (int) ($card['accounting_group'] ?? 0),
                AssetReportSupport::accountingGroupLabel($card, $cs),
                (int) ($card['group_order'] ?? 0),
            ],
        };
    }

    /**
     * @param list<AssetYear> $items
     * @param list<ReportRow> $rows
     * @return list<ReportMessage>
     */
    private function messages(AssetReportSupport $support, ReportRequest $request, array $items, array $rows, string $yearName, bool $cs): array
    {
        $index = AssetReportSupport::rowIndex($rows);
        $messages = [];
        $planned = 0;
        $unrecorded = 0;
        foreach ($items as $item) {
            array_push($messages, ...$support->planErrors($request, $item, $index));
            $planned += $item->planned() ? 1 : 0;
            $unrecorded += $item->tax->unrecorded > 0 ? 1 : 0;
        }
        if ($planned > 0) {
            $count = AssetReportSupport::cardCount($planned, $cs);
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Info,
                'assets.depreciationPlanned',
                $cs
                    ? "Odpis roku {$yearName} je u některých karet zatím jen plán ({$count}, sloupec Stav) — čísla nejsou konečná."
                    : "The {$yearName} depreciation is only planned for some cards ({$count}, column State) — figures are not final.",
            );
        }
        if ($unrecorded > 0) {
            $count = AssetReportSupport::cardCount($unrecorded, $cs);
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Info,
                'assets.claimUnrecorded',
                $cs
                    ? "Daňový odpis roku neobsahuje odpisy s neevidovanou uplatněnou částkou ({$count}); oprávky a zůstatkovou cenu snižují."
                    : "The tax depreciation excludes depreciation with an unrecorded claimed amount ({$count}); it still reduces the residual value.",
            );
        }
        return $messages;
    }

    /** @return list<ReportColumn> */
    private function columns(bool $cs): array
    {
        $money = static fn(string $id, string $csLabel, string $enLabel): ReportColumn
            => new ReportColumn($id, ReportColumn::TYPE_MONEY, $cs ? $csLabel : $enLabel);
        $text = static fn(string $id, string $csLabel, string $enLabel): ReportColumn
            => new ReportColumn($id, ReportColumn::TYPE_TEXT, $cs ? $csLabel : $enLabel);

        return [
            $text('number', 'Inv. číslo', 'Asset no.'),
            $text('taxRule', 'Daňová skupina / metoda', 'Tax group / method'),
            $text('state', 'Stav', 'State'),
            $money('taxEntry', 'Daňová vstupní cena', 'Tax entry price'),
            $money('taxOpening', 'Daňové oprávky na začátku', 'Tax accumulated — opening'),
            $money('taxDepreciation', 'Daňový odpis', 'Tax depreciation'),
            $money('taxClosing', 'Daňové oprávky na konci', 'Tax accumulated — closing'),
            $money('taxResidual', 'Daňová zůstatková cena', 'Tax residual value'),
            $money('accEntry', 'Účetní vstupní cena', 'Accounting entry price'),
            $money('accOpening', 'Účetní oprávky na začátku', 'Accounting accumulated — opening'),
            $money('accDepreciation', 'Účetní odpis', 'Accounting depreciation'),
            $money('accClosing', 'Účetní oprávky na konci', 'Accounting accumulated — closing'),
            $money('accResidual', 'Účetní zůstatková cena', 'Accounting residual value'),
            $money('difference', 'Rozdíl účetní − daňový odpis', 'Accounting − tax depreciation'),
        ];
    }
}
