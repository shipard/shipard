<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;

/**
 * Zdroj periodické fakturace nad poli (bez tabulky) — testy enginu, akcí
 * záznamu a upozornění. Kandidáti = záznamy s `docState` 40. Výchozí
 * hodnoty odpovídají zakázkám, druhý zdroj si je přepíše v konstruktoru.
 */
class FakeInvoicingSource implements PeriodicInvoicingSource
{
    /** @var list<array<string, mixed>> */
    public array $records = [];
    /** @var array<int, array<string, mixed>> id druhu → řádek druhu */
    public array $kinds = [];
    /** @var list<array<string, mixed>> řádky předpisu společné všem záznamům */
    public array $rows = [];

    /**
     * @param ?\Closure(array<string, mixed>): array<string, int> $dimensions
     */
    public function __construct(
        private readonly string $id = 'workOrders',
        private readonly string $table = 'economy_work_orders_heads',
        private readonly string $periodsTable = 'economy_work_orders_periods',
        private readonly string $recordColumn = 'work_order',
        private readonly string $viewerId = 'economy.workOrders.heads',
        private readonly ?string $navSection = 'workOrders',
        private readonly string $noun = 'Zakázka',
        private readonly string $openLabel = 'Otevřít zakázku',
        private readonly ?\Closure $dimensions = null,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function periodsTable(): string
    {
        return $this->periodsTable;
    }

    public function periodsRecordColumn(): string
    {
        return $this->recordColumn;
    }

    public function viewerId(): string
    {
        return $this->viewerId;
    }

    public function navSection(): ?string
    {
        return $this->navSection;
    }

    public function candidates(?int $recordId): array
    {
        return array_values(array_filter(
            $this->records,
            static fn(array $r): bool => (int) ($r['docState'] ?? 40) === 40 && ($recordId === null || (int) $r['id'] === $recordId),
        ));
    }

    public function record(int $id): ?array
    {
        foreach ($this->records as $record) {
            if ((int) $record['id'] === $id) {
                return $record;
            }
        }
        return null;
    }

    public function recordIdByNumber(string $number): ?int
    {
        foreach ($this->records as $record) {
            if ((string) ($record['number'] ?? '') === $number) {
                return (int) $record['id'];
            }
        }
        return null;
    }

    public function kind(array $record): ?array
    {
        return $this->kinds[(int) ($record['kind'] ?? 0)] ?? null;
    }

    public function rows(array $record): array
    {
        return $this->rows;
    }

    public function dimensions(array $record): array
    {
        if ($this->dimensions !== null) {
            return ($this->dimensions)($record);
        }
        $out = ['workOrder' => (int) ($record['id'] ?? 0)];
        if ((int) ($record['cost_center'] ?? 0) > 0) {
            $out['costCenter'] = (int) $record['cost_center'];
        }
        return $out;
    }

    public function recordLabel(array $record): string
    {
        $label = trim(trim((string) ($record['number'] ?? '')) . ' ' . trim((string) ($record['title'] ?? '')));
        return $label !== '' ? $label : '#' . (int) ($record['id'] ?? 0);
    }

    public function recordNoun(): string
    {
        return $this->noun;
    }

    public function openActionLabel(): string
    {
        return $this->openLabel;
    }
}
