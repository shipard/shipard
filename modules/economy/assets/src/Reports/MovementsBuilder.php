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
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\Plan;

/**
 * Přírůstky a úbytky (docs/assets.md D66) — potvrzené události v období:
 * zařazení a technická zhodnocení (přírůstky), snížení hodnoty a vyřazení
 * (úbytky), a drobný majetek podle data pořízení / vyřazení na kartě.
 * Mezisoučty po druhu pohybu, na konci přírůstky a úbytky celkem.
 *
 * U vyřazení je částka vstupní cena a vedle ní oprávky a zůstatková cena
 * k vyřazení z plánu účetního okruhu. Počáteční stav přírůstek není —
 * je to převzatá historie, ne pohyb období.
 */
final class MovementsBuilder implements ReportBuilder
{
    private const SMALL_ACQUIRED = 'smallAcquired';
    private const SMALL_DISPOSED = 'smallDisposed';

    /** Druh pohybu → je to přírůstek? Pořadí = pořadí skupin ve výsledku. */
    private const GROUPS = [
        AssetEvent::KIND_ACTIVATION  => true,
        AssetEvent::KIND_IMPROVEMENT => true,
        self::SMALL_ACQUIRED         => true,
        AssetEvent::KIND_REDUCTION   => false,
        AssetEvent::KIND_DISPOSAL    => false,
        self::SMALL_DISPOSED         => false,
    ];

    public function __construct(private readonly ?AssetReportSupport $support = null)
    {
    }

    public function build(ReportRequest $request): ReportResult
    {
        $cs = $request->language === 'cs';
        $support = $this->support ?? new AssetReportSupport();
        $period = $support->period($request);
        $kind = (string) ($request->params['kind'] ?? 'all');
        $wanted = array_keys(array_filter(
            self::GROUPS,
            static fn(bool $addition): bool => $kind === 'all' || ($kind === 'additions') === $addition,
        ));

        $cards = [];
        foreach ($support->cards($request) as $card) {
            $cards[(int) $card['id']] = $card;
        }
        $categories = $support->categories($request);

        $events = [];
        foreach ($support->eventsInPeriod(
            $request,
            $period['begin'],
            $period['end'],
            array_values(array_filter($wanted, AssetEvent::isKind(...))),
        ) as $event) {
            if (isset($cards[(int) $event['asset']])) {
                $events[] = $event;
            }
        }

        // Oprávky a zůstatková cena k vyřazení jsou v plánu, ne v události.
        $disposed = [];
        foreach ($events as $event) {
            if ($event['event_kind'] === AssetEvent::KIND_DISPOSAL) {
                $disposed[(int) $event['asset']] = $cards[(int) $event['asset']];
            }
        }
        $plans = $disposed === [] ? [] : $support->planService($request)->plansOf($disposed, $period['end']);

        $detailRows = [];
        $groupOf = [];
        $messages = [];
        $missingPlan = [];
        foreach ($events as $event) {
            $card = $cards[(int) $event['asset']];
            $eventKind = (string) $event['event_kind'];
            $postingDoc = (int) ($event['posting_doc'] ?? 0);
            $values = [
                'date'      => substr((string) $event['event_date'], 0, 10),
                'number'    => AssetReportSupport::number($card),
                'eventKind' => AssetReportSupport::eventKindLabel($request, $eventKind),
                'document'  => $postingDoc > 0 ? (string) ($event['posting_doc_number'] ?? '') : '',
                'amount'    => AssetReportSupport::money((float) $event['amount']),
            ];
            if ($eventKind === AssetEvent::KIND_DISPOSAL) {
                $disposal = $this->disposal($plans[(int) $card['id']] ?? null, (int) $event['id']);
                if ($disposal === null) {
                    $missingPlan[] = 'event:' . (int) $event['id'];
                } else {
                    $values['amount'] = AssetReportSupport::money($disposal['entry']);
                    $values['accumulated'] = AssetReportSupport::money($disposal['accumulated']);
                    $values['residual'] = AssetReportSupport::money($disposal['residual']);
                }
            }
            $detailRows[] = new ReportRow(
                ReportRowKind::Detail,
                2,
                null,
                AssetReportSupport::name($card),
                $values,
                'event:' . (int) $event['id'],
                AssetReportSupport::cardLink((int) $card['id']),
                // Zaúčtovaná událost: číslo dokladu vede na doklad zaúčtování.
                $postingDoc > 0 ? ['document' => AssetReportSupport::documentLink($postingDoc)] : [],
            );
            $groupOf['event:' . (int) $event['id']] = $eventKind;
        }

        // Drobný majetek: pohyb je datum pořízení / vyřazení na kartě.
        foreach ($cards as $card) {
            if ($categories->isLongTerm((string) ($card['category'] ?? ''))) {
                continue;
            }
            foreach ([[self::SMALL_ACQUIRED, 'acquired_date', 'acquired'], [self::SMALL_DISPOSED, 'disposed_date', 'disposed']] as [$group, $column, $suffix]) {
                $date = substr((string) ($card[$column] ?? ''), 0, 10);
                if (!in_array($group, $wanted, true) || $date === '' || $date < $period['begin'] || $date > $period['end']) {
                    continue;
                }
                $key = 'asset:' . (int) $card['id'] . ':' . $suffix;
                $detailRows[] = new ReportRow(
                    ReportRowKind::Detail,
                    2,
                    null,
                    AssetReportSupport::name($card),
                    [
                        'date'      => $date,
                        'number'    => AssetReportSupport::number($card),
                        'eventKind' => $this->groupLabel($request, $group, $cs),
                        'document'  => '',
                        'amount'    => AssetReportSupport::money((float) ($card['price'] ?? 0)),
                    ],
                    $key,
                    AssetReportSupport::cardLink((int) $card['id']),
                );
                $groupOf[$key] = $group;
            }
        }
        $order = array_flip(array_keys(self::GROUPS));
        usort($detailRows, static fn(ReportRow $a, ReportRow $b): int
            => [$order[$groupOf[$a->key]], $a->values['date'], $a->values['number'], $a->key]
            <=> [$order[$groupOf[$b->key]], $b->values['date'], $b->values['number'], $b->key]);

        $rows = (new SubtotalAggregator())->groupBy(
            $detailRows,
            static fn(ReportRow $row): string => $groupOf[$row->key],
            fn(string $group): string => $this->groupLabel($request, $group, $cs),
            null,
        );

        // Přírůstky a úbytky celkem — jen částka pohybu, ne oprávky.
        if ($rows !== []) {
            foreach ([[true, $cs ? 'Přírůstky celkem' : 'Additions total'], [false, $cs ? 'Úbytky celkem' : 'Disposals total']] as [$addition, $label]) {
                if ($kind !== 'all' && ($kind === 'additions') !== $addition) {
                    continue;
                }
                $sum = 0.0;
                foreach ($detailRows as $row) {
                    if (self::GROUPS[$groupOf[$row->key]] === $addition) {
                        $sum += $row->values['amount']['balance'];
                    }
                }
                $rows[] = new ReportRow(ReportRowKind::Computed, 0, null, $label, ['amount' => AssetReportSupport::money($sum)]);
            }
        }

        if ($missingPlan !== []) {
            $index = AssetReportSupport::rowIndex($rows);
            foreach ($missingPlan as $key) {
                $messages[] = new ReportMessage(
                    ReportMessageSeverity::Warning,
                    'assets.disposalValuesMissing',
                    $cs
                        ? 'U vyřazení se nepodařilo určit vstupní cenu, oprávky a zůstatkovou cenu — plán účetních odpisů karty vyřazení nezná.'
                        : 'Entry price, accumulated depreciation and residual value of the disposal are unknown — the accounting plan has no disposal row.',
                    isset($index[$key]) ? "rows.{$index[$key]}" : null,
                );
            }
        }

        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: $messages,
            columns: [
                new ReportColumn('date', ReportColumn::TYPE_DATE, $cs ? 'Datum' : 'Date'),
                new ReportColumn('number', ReportColumn::TYPE_TEXT, $cs ? 'Inv. číslo' : 'Asset no.'),
                new ReportColumn('eventKind', ReportColumn::TYPE_TEXT, $cs ? 'Pohyb' : 'Movement'),
                new ReportColumn('document', ReportColumn::TYPE_TEXT, $cs ? 'Doklad zaúčtování' : 'Posting document'),
                new ReportColumn('amount', ReportColumn::TYPE_MONEY, $cs ? 'Částka' : 'Amount'),
                new ReportColumn('accumulated', ReportColumn::TYPE_MONEY, $cs ? 'Oprávky při vyřazení' : 'Accumulated at disposal'),
                new ReportColumn('residual', ReportColumn::TYPE_MONEY, $cs ? 'Zůstatková cena při vyřazení' : 'Residual value at disposal'),
            ],
            rows: $rows,
        );
    }

    /**
     * Vstupní cena, oprávky a zůstatková cena k vyřazení: řádek vyřazení
     * v plánu účetního okruhu, u karty bez účetního okruhu daňového.
     *
     * @param array{tax: Plan, acc: Plan}|null $plans
     * @return array{entry: float, accumulated: float, residual: float}|null
     */
    private function disposal(?array $plans, int $eventId): ?array
    {
        foreach ($plans === null ? [] : [$plans['acc'], $plans['tax']] as $plan) {
            foreach ($plan->rows as $row) {
                if ($row->kind === AssetEvent::KIND_DISPOSAL && $row->eventId === $eventId) {
                    return [
                        'entry'       => round($row->entryPrice, 2),
                        'accumulated' => round($row->entryPrice - $row->amount, 2),
                        'residual'    => round($row->amount, 2),
                    ];
                }
            }
        }
        return null;
    }

    private function groupLabel(ReportRequest $request, string $group, bool $cs): string
    {
        return match ($group) {
            self::SMALL_ACQUIRED => $cs ? 'Drobný majetek — pořízení' : 'Small assets — acquisition',
            self::SMALL_DISPOSED => $cs ? 'Drobný majetek — vyřazení' : 'Small assets — disposal',
            default => AssetReportSupport::eventKindLabel($request, $group),
        };
    }
}
