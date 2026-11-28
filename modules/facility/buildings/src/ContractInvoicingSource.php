<?php

declare(strict_types=1);

namespace Shipard\Module\Facility\Buildings;

use Shipard\Core\I18n\CatalogText;
use Shipard\Module\Economy\PeriodicInvoicing\Source\AbstractPeriodicInvoicingSource;

/**
 * Smlouvy jako zdroj periodické fakturace (#174 D34,
 * modules/economy/periodicInvoicing/README.md): kandidáti jsou smlouvy ve
 * stavu V pořádku s periodicitou a „fakturovat od“. Smlouva V opravě mezi
 * kandidáty není — její období dožene první běh po návratu do V pořádku;
 * ukončená a zrušená smlouva se nefakturuje a období začínající po
 * `date_end` engine nevystaví.
 *
 * Normalizovaný záznam je řádek `facility_buildings_contracts` 1 : 1
 * (`customer` = nájemce, `date_end` = ukončení), řádek předpisu řádek
 * `facility_buildings_contract_rows`; druh nese tytéž sloupce `inv_*`.
 * Evidence období = `facility_buildings_contract_periods` se sloupcem
 * `contract`. Dimenze dokladu je jen středisko.
 */
class ContractInvoicingSource extends AbstractPeriodicInvoicingSource
{
    public const ID = 'buildingContracts';
    /** Stabilní tableId `facility_buildings_contracts` (hlídá test proti definici tabulky). */
    public const TABLE_ID = 474;
    public const PERIODS_TABLE = 'facility_buildings_contract_periods';
    public const PERIODS_RECORD_COLUMN = 'contract';
    public const VIEWER_ID = 'facility.buildings.contracts';
    public const NAV_SECTION = 'buildings';

    public const DIMENSION_COST_CENTER = 'costCenter';

    public function id(): string
    {
        return self::ID;
    }

    public function table(): string
    {
        return ContractDocument::TABLE;
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
        return self::VIEWER_ID;
    }

    public function navSection(): ?string
    {
        return self::NAV_SECTION;
    }

    public function candidates(?int $recordId): array
    {
        $sql = 'SELECT * FROM [' . ContractDocument::TABLE . ']'
            . ' WHERE [docState] = %i AND [inv_from] IS NOT NULL AND [inv_periodicity] IS NOT NULL';
        $args = [ContractDocument::STATE_CONFIRMED];
        if ($recordId !== null) {
            $sql .= ' AND [id] = %i';
            $args[] = $recordId;
        }
        $sql .= ' ORDER BY [id]';

        return $this->fetchAll($sql, ...$args);
    }

    public function record(int $id): ?array
    {
        return $this->fetch('SELECT * FROM [' . ContractDocument::TABLE . '] WHERE [id] = %i', $id);
    }

    public function recordIdByNumber(string $number): ?int
    {
        $row = $this->fetch('SELECT [id] FROM [' . ContractDocument::TABLE . '] WHERE [number] = %s', $number);
        return $row === null ? null : (int) $row['id'];
    }

    public function kind(array $record): ?array
    {
        $kindId = (int) ($record['kind'] ?? 0);
        if ($kindId <= 0) {
            return null;
        }
        return $this->fetch('SELECT * FROM [' . ContractKindDocument::TABLE . '] WHERE [id] = %i', $kindId);
    }

    public function rows(array $record): array
    {
        return $this->fetchAll(
            'SELECT * FROM [' . ContractDocument::ROWS_TABLE . '] WHERE [contract] = %i ORDER BY [order_pos], [id]',
            (int) ($record['id'] ?? 0),
        );
    }

    public function dimensions(array $record): array
    {
        $costCenterId = (int) ($record['cost_center'] ?? 0);
        return $costCenterId > 0 ? [self::DIMENSION_COST_CENTER => $costCenterId] : [];
    }

    public function recordLabel(array $record): string
    {
        $number = trim((string) ($record['number'] ?? ''));
        $title = trim((string) ($record['title'] ?? ''));
        return trim($number . ' ' . $title) ?: '#' . (int) ($record['id'] ?? 0);
    }

    public function recordNoun(): string
    {
        return $this->text('source.recordNoun', 'Contract');
    }

    public function openActionLabel(): string
    {
        return $this->text('source.openAction', 'Open contract');
    }

    private function text(string $key, string $englishFallback): string
    {
        return CatalogText::resolve(
            $this->config?->cfgItem(BuildingsViewerBase::LABELS_CFG_ITEM),
            $key,
            $englishFallback,
        );
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /** @return list<array<string, mixed>> */
    protected function fetchAll(string $sql, mixed ...$args): array
    {
        if ($this->db === null) {
            throw new \LogicException('ContractInvoicingSource: database connection is not set');
        }
        $rows = $this->db->fetchAll($sql, ...$args);
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return array<string, mixed>|null */
    protected function fetch(string $sql, mixed ...$args): ?array
    {
        if ($this->db === null) {
            throw new \LogicException('ContractInvoicingSource: database connection is not set');
        }
        $row = $this->db->fetch($sql, ...$args);
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }
}
