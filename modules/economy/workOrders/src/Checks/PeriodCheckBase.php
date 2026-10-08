<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrdersViewer;

/**
 * Společný základ per-zakázkových upozornění evidence období (tasks §6):
 * výběr období podle stavu / výsledku běhu, seskupení per zakázka,
 * nález s klíčem = id zakázky a akcí Otevřít zakázku (záložka Fakturace).
 */
abstract class PeriodCheckBase extends AlertCheck
{
    /** Stable tableId of economy_work_orders_heads. */
    protected const SUBJECT_TABLE_ID = 460;

    /**
     * Období (s číslem a názvem zakázky) seřazená per zakázka a od
     * nejnovějšího období. Seam pro testy.
     *
     * @param list<string> $states
     * @param list<string> $results
     * @return list<array<string, mixed>>
     */
    protected function periods(array $states, array $results, ?string $waitingBefore = null): array
    {
        $sql = 'SELECT [p].[id], [p].[work_order], [p].[period_from], [p].[period_to], [p].[state], [p].[result],'
            . ' [p].[message], [p].[waiting_since], [w].[number], [w].[title]'
            . ' FROM [' . PeriodRepository::TABLE . '] [p]'
            . ' JOIN [' . WorkOrderDocument::TABLE . '] [w] ON [w].[id] = [p].[work_order]'
            . ' WHERE [p].[state] IN %in';
        $args = [$states];
        if ($results !== [] && $waitingBefore !== null) {
            $sql .= ' AND ([p].[result] IN %in OR [p].[waiting_since] < %s)';
            $args[] = $results;
            $args[] = $waitingBefore;
        } elseif ($results !== []) {
            $sql .= ' AND [p].[result] IN %in';
            $args[] = $results;
        }
        $sql .= ' ORDER BY [p].[work_order], [p].[period_from] DESC';
        $rows = $this->db->fetchAll($sql, ...$args);
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /**
     * @param list<array<string, mixed>> $periods
     * @return array<int, list<array<string, mixed>>> id zakázky → období
     */
    protected function byWorkOrder(array $periods): array
    {
        $out = [];
        foreach ($periods as $period) {
            $out[(int) $period['work_order']][] = $period;
        }
        return $out;
    }

    /** @param list<array<string, mixed>> $periods */
    protected function finding(int $workOrderId, array $periods, string $title, string $message, string $severity, bool $isCs): AlertFinding
    {
        return new AlertFinding(
            findingKey: (string) $workOrderId,
            title: $title,
            message: $message,
            severity: $severity,
            subjectTableId: self::SUBJECT_TABLE_ID,
            subjectRowId: $workOrderId,
            actions: [[
                'id'      => 'open_work_order',
                'label'   => $isCs ? 'Otevřít zakázku' : 'Open work order',
                'kind'    => 'open_viewer',
                'primary' => true,
                'target'  => ['viewerId' => WorkOrdersViewer::VIEWER_ID, 'recordId' => $workOrderId],
            ]],
            context: [
                'periods' => array_map(static fn(array $p): string => (string) $p['period_from'], $periods),
            ],
        );
    }

    /** @param array<string, mixed> $period */
    protected static function workOrderLabel(array $period): string
    {
        $number = trim((string) ($period['number'] ?? ''));
        $title = trim((string) ($period['title'] ?? ''));
        return trim($number . ' ' . $title) ?: '#' . (int) $period['work_order'];
    }

    protected static function periodLabel(array $period): string
    {
        return self::czDate($period['period_from']) . ' – ' . self::czDate($period['period_to']);
    }

    protected static function czDate(mixed $value): string
    {
        $string = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $string);
        return $dt instanceof \DateTimeImmutable ? $dt->format('d.m.Y') : $string;
    }

    protected function today(): string
    {
        return date('Y-m-d');
    }
}
