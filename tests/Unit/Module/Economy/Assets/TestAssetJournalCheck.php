<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use Shipard\Module\Economy\Assets\AssetJournalCheck;

/**
 * AssetJournalCheck nad pamětí: karty s účty účetní skupiny, události
 * s příznakem `posted` a řádky deníku, ze kterých dvojník skládá stejné
 * agregace jako SQL.
 */
class TestAssetJournalCheck extends AssetJournalCheck
{
    public const ACCOUNTS = [
        'asset' => '022100', 'acquisition' => '042100', 'accumulated' => '082100',
        'depreciation' => '551100', 'disposal' => '541100',
    ];

    /** @var array<int, array<string, mixed>> */
    public array $cards = [];
    /** @var list<array<string, mixed>> */
    public array $events = [];
    /** @var list<array{asset: ?int, account: string, operation: ?string, dr: float, cr: float, date: string, year: int, month: int}> */
    public array $journal = [];
    /** @var list<array<string, string>> role → číslo účtu per účetní skupina */
    public array $groups = [self::ACCOUNTS];
    /** @var array<string, string> */
    public array $names = ['022100' => 'Stroje', '042100' => 'Pořízení', '082100' => 'Oprávky', '551100' => 'Odpisy', '541100' => 'Zůstatková cena'];

    /**
     * Datum → [id účetního roku, id účetního měsíce]; výchozí = kalendářní
     * rok a číslo měsíce. Testy reportu ji přepínají na id z `TestAssetPlanService`.
     *
     * @var \Closure(string): array{int, int}|null
     */
    public ?\Closure $periodOf = null;

    private int $eventId = 1;

    public function __construct()
    {
        parent::__construct(null);
    }

    /** @param array<string, string>|null $accounts null = výchozí účty */
    public function card(int $id, ?array $accounts = null, string $name = ''): void
    {
        $this->cards[$id] = [
            'id' => $id, 'asset_number' => sprintf('MA%04d', $id), 'name' => $name !== '' ? $name : "Stroj {$id}",
            'category' => 'tangible', 'accounts' => $accounts ?? self::ACCOUNTS,
        ];
    }

    /**
     * Událost; `origin: import` v `$o` = zaúčtovaná ve starém systému
     * (D76: `posted` i `imported`, bez ohledu na `$posted`).
     *
     * @param array<string, mixed> $o
     */
    public function event(int $asset, string $kind, string $date, float $amount = 0.0, bool $posted = true, array $o = []): int
    {
        $id = $this->eventId++;
        $imported = ($o['origin'] ?? 'manual') === 'import';
        $this->events[] = $o + [
            'id' => $id, 'asset' => $asset, 'event_kind' => $kind, 'scope' => $kind === 'depreciation' ? 'acc' : 'both',
            'event_date' => $date, 'amount' => $amount, 'accumulated' => null,
            'posted' => $posted || $imported ? 1 : 0, 'imported' => $imported ? 1 : 0,
        ];
        return $id;
    }

    /** Řádek deníku; `$month` = id účetního měsíce (0 = otevírací období). */
    public function journal(?int $asset, string $account, float $dr, float $cr, string $date, ?string $operation = 'acc.record', ?int $month = null): void
    {
        [$yearId, $monthId] = $this->periodOf !== null
            ? ($this->periodOf)($date)
            : [(int) substr($date, 0, 4), (int) substr($date, 5, 2)];
        $this->journal[] = [
            'asset' => $asset, 'account' => $account, 'operation' => $operation, 'dr' => $dr, 'cr' => $cr, 'date' => $date,
            'year' => $yearId, 'month' => $month ?? $monthId,
        ];
    }

    /** Zápis MD / DAL operace majetku s kartou — to, co zaúčtuje Majetek. */
    public function posting(int $asset, string $operation, string $debit, string $credit, float $amount, string $date): void
    {
        $this->journal($asset, $debit, $amount, 0.0, $date, $operation);
        $this->journal($asset, $credit, 0.0, $amount, $date, $operation);
    }

    protected function loadCards(?int $assetId): array
    {
        return $assetId === null ? $this->cards : array_intersect_key($this->cards, [$assetId => true]);
    }

    protected function loadEvents(?int $assetId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(array $event): bool => $assetId === null || (int) $event['asset'] === $assetId,
        ));
    }

    protected function loadPostingJournal(?string $asOf, ?int $assetId): array
    {
        $out = [];
        foreach ($this->journal as $row) {
            if ($row['asset'] === null || !str_starts_with((string) $row['operation'], 'asset.')
                || ($asOf !== null && $row['date'] > $asOf) || ($assetId !== null && $row['asset'] !== $assetId)
            ) {
                continue;
            }
            $out[$row['asset']][$row['account']] ??= ['dr' => 0.0, 'cr' => 0.0];
            $out[$row['asset']][$row['account']]['dr'] += $row['dr'];
            $out[$row['asset']][$row['account']]['cr'] += $row['cr'];
        }
        return $out;
    }

    protected function loadAcquisitionJournal(?string $asOf, ?int $assetId): array
    {
        $out = [];
        foreach ($this->journal as $row) {
            if ($row['asset'] === null || !str_starts_with($row['account'], '04') || str_starts_with((string) $row['operation'], 'asset.')
                || ($asOf !== null && $row['date'] > $asOf) || ($assetId !== null && $row['asset'] !== $assetId)
            ) {
                continue;
            }
            $out[$row['asset']] ??= ['amount' => 0.0, 'lastDate' => null];
            $out[$row['asset']]['amount'] += $row['dr'] - $row['cr'];
            $out[$row['asset']]['lastDate'] = max((string) $out[$row['asset']]['lastDate'], $row['date']);
        }
        return $out;
    }

    protected function loadGroupAccounts(): array
    {
        return $this->groups;
    }

    protected function loadAccountJournal(int $fiscalYearId, array $accountNumbers): array
    {
        $out = [];
        foreach ($this->journal as $row) {
            if ($row['year'] !== $fiscalYearId || !in_array($row['account'], $accountNumbers, true)) {
                continue;
            }
            $withAsset = $row['asset'] !== null ? 1 : 0;
            $key = "{$row['account']}|{$row['month']}|{$withAsset}";
            $out[$key] ??= ['account_number' => $row['account'], 'fiscal_month' => $row['month'], 'with_asset' => $withAsset,
                'dr' => 0.0, 'cr' => 0.0, 'row_count' => 0];
            $out[$key]['dr'] += $row['dr'];
            $out[$key]['cr'] += $row['cr'];
            $out[$key]['row_count']++;
        }
        return array_values($out);
    }

    protected function loadAccountNames(array $accountNumbers): array
    {
        return array_intersect_key($this->names, array_flip($accountNumbers));
    }
}
