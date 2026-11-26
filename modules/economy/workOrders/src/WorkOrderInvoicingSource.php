<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders;

use Shipard\Core\I18n\CatalogText;
use Shipard\Module\Economy\PeriodicInvoicing\Source\AbstractPeriodicInvoicingSource;

/**
 * Zakázky jako zdroj periodické fakturace (#175 D3, docs/work-orders.md
 * §5.5): kandidáti jsou zakázky periodického typu (WorkOrderTypes) ve stavu
 * V pořádku s vyplněným „fakturovat od“ a periodicitou. Zakázka V opravě
 * mezi kandidáty není — její období dožene první běh po návratu do
 * V pořádku.
 *
 * Normalizovaný záznam je řádek `economy_work_orders_heads` 1 : 1, řádek
 * předpisu řádek `economy_work_orders_rows`; druh nese tytéž sloupce
 * `inv_*`. Evidence období = `economy_work_orders_periods` se sloupcem
 * `work_order` (jediné místo, kde se jméno tabulky a sloupce drží).
 */
class WorkOrderInvoicingSource extends AbstractPeriodicInvoicingSource
{
    public const ID = 'workOrders';
    /** Stabilní tableId `economy_work_orders_heads` (hlídá test proti definici tabulky). */
    public const TABLE_ID = 460;
    public const PERIODS_TABLE = 'economy_work_orders_periods';
    public const PERIODS_RECORD_COLUMN = 'work_order';
    public const NAV_SECTION = 'workOrders';

    public const DIMENSION_WORK_ORDER = 'workOrder';
    public const DIMENSION_COST_CENTER = 'costCenter';

    public function id(): string
    {
        return self::ID;
    }

    public function table(): string
    {
        return WorkOrderDocument::TABLE;
    }

    public function tableId(): int
    {
        return self::TABLE_ID;
    }

    public function periodsTable(): string
    {
        return self::PERIODS_TABLE;
    }

    public function periodsRecordColumn(): string
    {
        return self::PERIODS_RECORD_COLUMN;
    }

    public function viewerId(): string
    {
        return WorkOrdersViewer::VIEWER_ID;
    }

    public function navSection(): ?string
    {
        return self::NAV_SECTION;
    }

    public function candidates(?int $recordId): array
    {
        $types = new WorkOrderTypes($this->config);
        $periodicTypes = array_values(array_filter(
            array_map('strval', array_keys($types->all())),
            static fn(string $type): bool => $types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC,
        ));
        if ($periodicTypes === []) {
            return [];
        }
        $sql = 'SELECT * FROM [' . WorkOrderDocument::TABLE . ']'
            . ' WHERE [docState] = %i AND [type] IN %in AND [inv_from] IS NOT NULL AND [inv_periodicity] IS NOT NULL';
        $args = [WorkOrderDocument::STATE_CONFIRMED, $periodicTypes];
        if ($recordId !== null) {
            $sql .= ' AND [id] = %i';
            $args[] = $recordId;
        }
        $sql .= ' ORDER BY [id]';

        return $this->fetchAll($sql, ...$args);
    }

    public function record(int $id): ?array
    {
        return $this->fetch('SELECT * FROM [' . WorkOrderDocument::TABLE . '] WHERE [id] = %i', $id);
    }

    public function recordIdByNumber(string $number): ?int
    {
        $row = $this->fetch('SELECT [id] FROM [' . WorkOrderDocument::TABLE . '] WHERE [number] = %s', $number);
        return $row === null ? null : (int) $row['id'];
    }

    public function kind(array $record): ?array
    {
        $kindId = (int) ($record['kind'] ?? 0);
        if ($kindId <= 0) {
            return null;
        }
        return $this->fetch('SELECT * FROM [' . KindDocument::TABLE . '] WHERE [id] = %i', $kindId);
    }

    public function rows(array $record): array
    {
        return $this->fetchAll(
            'SELECT * FROM [' . WorkOrderDocument::ROWS_TABLE . '] WHERE [work_order] = %i ORDER BY [order_pos], [id]',
            (int) ($record['id'] ?? 0),
        );
    }

    public function dimensions(array $record): array
    {
        $dimensions = [];
        $workOrderId = (int) ($record['id'] ?? 0);
        if ($workOrderId > 0) {
            $dimensions[self::DIMENSION_WORK_ORDER] = $workOrderId;
        }
        $costCenterId = (int) ($record['cost_center'] ?? 0);
        if ($costCenterId > 0) {
            $dimensions[self::DIMENSION_COST_CENTER] = $costCenterId;
        }
        return $dimensions;
    }

    public function recordLabel(array $record): string
    {
        $number = trim((string) ($record['number'] ?? ''));
        $title = trim((string) ($record['title'] ?? ''));
        return trim($number . ' ' . $title) ?: '#' . (int) ($record['id'] ?? 0);
    }

    public function recordNoun(): string
    {
        return $this->text('source.recordNoun', 'Work order');
    }

    public function openActionLabel(): string
    {
        return $this->text('source.openAction', 'Open work order');
    }

    private function text(string $key, string $englishFallback): string
    {
        return CatalogText::resolve(
            $this->config?->cfgItem(WorkOrdersViewerBase::LABELS_CFG_ITEM),
            $key,
            $englishFallback,
        );
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** @return list<array<string, mixed>> */
    protected function fetchAll(string $sql, mixed ...$args): array
    {
        if ($this->db === null) {
            throw new \LogicException('WorkOrderInvoicingSource: database connection is not set');
        }
        $rows = $this->db->fetchAll($sql, ...$args);
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return array<string, mixed>|null */
    protected function fetch(string $sql, mixed ...$args): ?array
    {
        if ($this->db === null) {
            throw new \LogicException('WorkOrderInvoicingSource: database connection is not set');
        }
        $row = $this->db->fetch($sql, ...$args);
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }
}
