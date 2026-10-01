<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportColumn;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRow;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\SubtotalAggregator;

/**
 * Soupis majetku (docs/assets.md D66) — karty v evidenci ke konci
 * zvoleného období, včetně drobného a cizího majetku: druh, typ, datum
 * pořízení, vstupní cena a účetní zůstatková cena k datu.
 *
 * Dlouhodobý majetek se řídí událostmi (v evidenci od zařazení nebo
 * počátečního stavu do vyřazení; cena a zůstatková cena z plánu účetního
 * okruhu), drobný a nezařazený cizí majetek daty a cenou na kartě.
 * Dlouhodobá karta bez zařazení v soupisu není — pořízení je ještě na
 * účtu pořízení.
 */
final class RegisterBuilder implements ReportBuilder
{
    public function __construct(private readonly ?AssetReportSupport $support = null)
    {
    }

    public function build(ReportRequest $request): ReportResult
    {
        $cs = $request->language === 'cs';
        $support = $this->support ?? new AssetReportSupport();
        $period = $support->period($request);
        $date = $period['end'];
        $categories = $support->categories($request);
        $groupBy = (string) ($request->params['groupBy'] ?? 'type');
        $foreign = (string) ($request->params['foreign'] ?? 'include');

        $cards = [];
        foreach ($support->cards($request) as $card) {
            $isForeign = !empty($card['is_foreign']);
            if (($foreign === 'exclude' && $isForeign) || ($foreign === 'only' && !$isForeign)) {
                continue;
            }
            $cards[(int) $card['id']] = $card;
        }

        // Plány jen dlouhodobým kartám — drobný majetek události nemá.
        $plans = $support->planService($request)->plansOf(
            array_filter($cards, static fn(array $card): bool => $categories->isLongTerm((string) ($card['category'] ?? ''))),
            $date,
        );

        $detailRows = [];
        $groups = [];
        $groupOfKey = [];
        $sortKeys = [];
        foreach ($cards as $id => $card) {
            $category = (string) ($card['category'] ?? '');
            $line = isset($plans[$id])
                ? $this->longTerm($card, CircuitYear::of($plans[$id]['acc'], $date, $date), CircuitYear::of($plans[$id]['tax'], $date, $date), $date)
                : $this->fromCard($card, $date);
            if ($line === null) {
                continue;
            }

            [$groupKey, $groupLabel, $groupOrder] = match ($groupBy) {
                'category' => [$category, $categories->label($category), array_search($category, array_keys($categories->all()), true)],
                'accountingGroup' => [
                    (string) (int) ($card['accounting_group'] ?? 0),
                    AssetReportSupport::accountingGroupLabel($card, $cs),
                    (int) ($card['group_order'] ?? 0),
                ],
                default => [(string) (int) ($card['asset_type'] ?? 0), AssetReportSupport::typeLabel($card, $cs), 0],
            };
            $key = 'asset:' . $id;
            $groups[$groupKey] = $groupLabel;
            $groupOfKey[$key] = (string) $groupKey;
            $sortKeys[$key] = [$groupOrder, $groupLabel, AssetReportSupport::number($card), $id];

            $values = [
                'number'     => AssetReportSupport::number($card),
                'type'       => trim((string) ($card['type_name'] ?? '')),
                'category'   => $categories->label($category),
                'acquired'   => (string) ($line['acquired'] ?? ''),
                'entryPrice' => AssetReportSupport::money($line['entryPrice']),
                'owner'      => !empty($card['is_foreign'])
                    ? (trim((string) ($card['owner_name'] ?? '')) ?: ($cs ? 'cizí majetek' : 'foreign asset'))
                    : '',
            ];
            if ($line['residual'] !== null) {
                $values['residual'] = AssetReportSupport::money($line['residual']);
            }
            $detailRows[] = new ReportRow(ReportRowKind::Detail, 2, null, AssetReportSupport::name($card), $values, $key);
        }
        usort($detailRows, static fn(ReportRow $a, ReportRow $b): int => $sortKeys[$a->key] <=> $sortKeys[$b->key]);

        $rows = (new SubtotalAggregator())->groupBy(
            $detailRows,
            static fn(ReportRow $row): string => $groupOfKey[$row->key],
            static fn(string $key): string => $groups[$key] ?? $key,
            $cs ? 'Celkem' : 'Total',
        );

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: [],
            columns: [
                new ReportColumn('number', ReportColumn::TYPE_TEXT, $cs ? 'Inv. číslo' : 'Asset no.'),
                new ReportColumn('type', ReportColumn::TYPE_TEXT, $cs ? 'Typ' : 'Type'),
                new ReportColumn('category', ReportColumn::TYPE_TEXT, $cs ? 'Druh' : 'Category'),
                new ReportColumn('acquired', ReportColumn::TYPE_DATE, $cs ? 'Datum pořízení' : 'Acquired'),
                new ReportColumn('entryPrice', ReportColumn::TYPE_MONEY, $cs ? 'Vstupní cena' : 'Entry price'),
                new ReportColumn('residual', ReportColumn::TYPE_MONEY, $cs ? 'Účetní zůstatková cena' : 'Accounting residual value'),
                new ReportColumn('owner', ReportColumn::TYPE_TEXT, $cs ? 'Vlastník (cizí majetek)' : 'Owner (foreign asset)'),
            ],
            rows: $rows,
        );
    }

    /**
     * Dlouhodobá karta k datu z plánů: v evidenci od zařazení / počátečního
     * stavu, vyřazená k datu už ne. Karta bez zařazení se posoudí podle
     * dat na kartě jen jako cizí majetek (leasing bez vlastních událostí).
     *
     * @param array<string, mixed> $card
     * @return array{acquired: ?string, entryPrice: float, residual: ?float}|null
     */
    private function longTerm(array $card, CircuitYear $acc, CircuitYear $tax, string $date): ?array
    {
        $circuit = $acc->startDate !== null ? $acc : $tax;
        if ($circuit->startDate === null) {
            return !empty($card['is_foreign']) ? $this->fromCard($card, $date) : null;
        }
        $disposal = $acc->disposalDate ?? $tax->disposalDate;
        if ($circuit->startDate > $date || ($disposal !== null && $disposal <= $date)) {
            return null;
        }
        $acquired = substr((string) ($card['acquired_date'] ?? ''), 0, 10);

        return [
            'acquired'   => $acquired !== '' ? $acquired : $circuit->startDate,
            'entryPrice' => $circuit->entryPrice,
            'residual'   => $circuit->residual,
        ];
    }

    /**
     * Drobný (a nezařazený cizí) majetek k datu z karty: pořízený do data
     * (bez data pořízení se bere jako pořízený), nevyřazený k datu. Účetní
     * zůstatkovou cenu nemá — jde do nákladů při pořízení.
     *
     * @param array<string, mixed> $card
     * @return array{acquired: ?string, entryPrice: float, residual: ?float}|null
     */
    private function fromCard(array $card, string $date): ?array
    {
        $acquired = substr((string) ($card['acquired_date'] ?? ''), 0, 10);
        $disposed = substr((string) ($card['disposed_date'] ?? ''), 0, 10);
        if (($acquired !== '' && $acquired > $date) || ($disposed !== '' && $disposed <= $date)) {
            return null;
        }
        return [
            'acquired'   => $acquired !== '' ? $acquired : null,
            'entryPrice' => round((float) ($card['price'] ?? 0), 2),
            'residual'   => null,
        ];
    }
}
