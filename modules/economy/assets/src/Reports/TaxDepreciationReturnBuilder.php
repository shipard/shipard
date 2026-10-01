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

/**
 * Daňové odpisy pro přiznání k dani z příjmů (docs/assets.md D66) —
 * uplatněné daňové odpisy účetního roku po skupinách přiznání z pravidel
 * země (`TaxDepreciationRules::taxReturnGroups()`), pod nimi účetní odpisy
 * celkem a rozdíl účetní − daňový jako podklad pro úpravu základu daně.
 *
 * Stejná čísla jako Sestava odpisů (sdílený `AssetReportSupport`). Dokud
 * odpisy roku nejsou potvrzené, jsou čísla plán — report to hlásí
 * varováním. Mapování na řádky tiskopisu přijde s podáním DPPO.
 */
final class TaxDepreciationReturnBuilder implements ReportBuilder
{
    /** Klíč řádku pro karty, jejichž metoda a pravidlo do žádné skupiny nepatří. */
    private const UNGROUPED = '_';

    public function __construct(private readonly ?AssetReportSupport $support = null)
    {
    }

    public function build(ReportRequest $request): ReportResult
    {
        $cs = $request->language === 'cs';
        $support = $this->support ?? new AssetReportSupport();
        $period = $support->period($request);
        $rules = $support->planService($request)->rules();
        $items = $support->longTermInPeriod($request, $period['yearBegin'], $period['yearEnd']);

        /** @var array<string, array{count: int, amount: float}> $groups */
        $groups = [];
        $accTotal = 0.0;
        $taxPlanned = 0;
        $accPlanned = 0;
        $messages = [];
        foreach ($items as $item) {
            array_push($messages, ...$support->planErrors($request, $item, []));
            $accTotal += $item->acc->depreciation;
            $taxPlanned += $item->tax->planned ? 1 : 0;
            $accPlanned += $item->acc->planned ? 1 : 0;

            if (abs($item->tax->depreciation) < 0.005) {
                continue;
            }
            $method = (string) ($item->card['tax_method'] ?? '');
            $rule = (string) ($item->card['tax_rule'] ?? '');
            $group = $rules->taxReturnGroup($method, $rule !== '' ? $rule : null) ?? self::UNGROUPED;
            $groups[$group] ??= ['count' => 0, 'amount' => 0.0];
            $groups[$group]['count']++;
            $groups[$group]['amount'] += $item->tax->depreciation;
        }

        $labels = [];
        foreach ($rules->taxReturnGroups() as $key => $label) {
            $labels[(string) $key] = $label;
        }
        $labels[self::UNGROUPED] = $cs ? 'Nezařazeno do skupiny přiznání' : 'Not assigned to a tax return group';

        $rows = [];
        $taxTotal = 0.0;
        $count = 0;
        foreach ($labels as $key => $label) {
            if (!isset($groups[$key])) {
                continue;
            }
            if ($key === self::UNGROUPED) {
                $messages[] = new ReportMessage(
                    ReportMessageSeverity::Warning,
                    'assets.taxReturnGroupMissing',
                    $cs
                        ? 'Některé karty mají daňový odpis, ale jejich metoda a skupina nepatří do žádné skupiny přiznání.'
                        : 'Some cards have tax depreciation but their method and group belong to no tax return group.',
                    'rows.' . count($rows),
                );
            }
            $rows[] = new ReportRow(
                ReportRowKind::Detail,
                1,
                null,
                $label,
                ['count' => (string) $groups[$key]['count'], 'amount' => AssetReportSupport::money($groups[$key]['amount'])],
                'taxReturnGroup:' . $key,
            );
            $taxTotal += $groups[$key]['amount'];
            $count += $groups[$key]['count'];
        }

        $rows[] = new ReportRow(
            ReportRowKind::Total,
            0,
            null,
            $cs ? 'Daňové odpisy celkem' : 'Tax depreciation total',
            ['count' => (string) $count, 'amount' => AssetReportSupport::money($taxTotal)],
        );
        $rows[] = new ReportRow(
            ReportRowKind::Computed,
            0,
            null,
            $cs ? 'Účetní odpisy celkem' : 'Accounting depreciation total',
            ['count' => '', 'amount' => AssetReportSupport::money($accTotal)],
        );
        $rows[] = new ReportRow(
            ReportRowKind::Computed,
            0,
            null,
            $cs ? 'Rozdíl účetní − daňový odpis' : 'Accounting − tax depreciation',
            ['count' => '', 'amount' => AssetReportSupport::money($accTotal - $taxTotal)],
        );

        if ($taxPlanned > 0 || $accPlanned > 0) {
            $parts = [];
            if ($taxPlanned > 0) {
                $parts[] = ($cs ? 'daňový odpis ' : 'tax depreciation ') . AssetReportSupport::cardCount($taxPlanned, $cs);
            }
            if ($accPlanned > 0) {
                $parts[] = ($cs ? 'účetní odpis ' : 'accounting depreciation ') . AssetReportSupport::cardCount($accPlanned, $cs);
            }
            $messages[] = new ReportMessage(
                ReportMessageSeverity::Warning,
                'assets.depreciationPlanned',
                $cs
                    ? "Odpisy roku {$period['yearName']} nejsou všechny potvrzené (" . implode(', ', $parts) . ') — čísla nejsou konečná.'
                    : "Not all {$period['yearName']} depreciation is confirmed (" . implode(', ', $parts) . ') — figures are not final.',
            );
        }

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $messages,
            columns: [
                new ReportColumn('count', ReportColumn::TYPE_TEXT, $cs ? 'Počet karet' : 'Cards'),
                new ReportColumn('amount', ReportColumn::TYPE_MONEY, $cs ? 'Odpisy' : 'Depreciation'),
            ],
            rows: $rows,
        );
    }
}
