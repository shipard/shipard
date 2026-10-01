<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Assets\AssetPlanService;

/**
 * AssetPlanService nad pamětí místo DB: karta, potvrzené události
 * a kalendářní účetní roky 2021–2026 (id 1–6, měsíce 101…). Konfigurace
 * je skutečná (`assets-cz.jsonc`, `categories.jsonc`, `planMessages.jsonc`).
 */
class TestAssetPlanService extends AssetPlanService
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    /** @var array<int, array<string, mixed>> id → karta */
    public array $cards = [];
    /** @var list<array<string, mixed>> potvrzené události všech karet */
    public array $events = [];
    /** @var list<int> zamčené měsíce (yyyymm) */
    public array $lockedMonths = [];
    /** @var array<int, array{docId: int, docNumber: string}> zaúčtované události: id → doklad */
    public array $posting = [];
    public string $periodicity = self::PERIODICITY_YEAR;
    public string $asOf = '2024-06-30';

    public static function config(): ConfigRuntime
    {
        static $items = null;
        $items ??= [
            'world.assets.cz'           => JsoncParser::parseFile(self::MODULES . '/world/assets/config/assets-cz.jsonc'),
            'economy.assets.categories' => JsoncParser::parseFile(self::MODULES . '/economy/assets/config/categories.jsonc'),
            'economy.assets.planMessages' => JsoncParser::parseFile(self::MODULES . '/economy/assets/config/planMessages.jsonc'),
        ];
        $config = new class($items) extends ConfigRuntime {
            /** @param array<string, mixed> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function cfgItem(string $id): mixed
            {
                return $this->items[$id] ?? null;
            }
        };
        return $config;
    }

    public function __construct()
    {
        parent::__construct(null, self::config(), 'cz', null);
    }

    public function accPeriodicity(): string
    {
        return $this->periodicity;
    }

    public function today(): string
    {
        return $this->asOf;
    }

    protected function loadCard(int $assetId): ?array
    {
        return $this->cards[$assetId] ?? null;
    }

    protected function loadConfirmedEvents(array $assetIds): array
    {
        $out = [];
        foreach ($this->events as $event) {
            if (in_array((int) $event['asset'], $assetIds, true) && (int) ($event['docState'] ?? 40) === 40) {
                $out[(int) $event['asset']][] = $event;
            }
        }
        foreach ($out as &$events) {
            usort($events, static fn(array $a, array $b): int
                => [$a['event_date'], $a['id'] ?? 0] <=> [$b['event_date'], $b['id'] ?? 0]);
        }
        return $out;
    }

    protected function loadPosting(int $assetId): array
    {
        return $this->posting;
    }

    protected function loadFiscalYears(): array
    {
        $years = [];
        foreach (range(2021, 2026) as $i => $year) {
            $years[] = ['id' => $i + 1, 'name' => (string) $year, 'begin' => "{$year}-01-01", 'end' => "{$year}-12-31"];
        }
        return $years;
    }

    protected function loadFiscalMonths(): array
    {
        $months = [];
        foreach (range(2021, 2026) as $i => $year) {
            for ($m = 1; $m <= 12; $m++) {
                $begin = sprintf('%04d-%02d-01', $year, $m);
                $months[] = [
                    'id'          => 100 + ($i * 12) + $m,
                    'fiscal_year' => $i + 1,
                    'begin'       => $begin,
                    'end'         => (new \DateTimeImmutable($begin))->format('Y-m-t'),
                    'locked'      => in_array($year * 100 + $m, $this->lockedMonths, true),
                ];
            }
        }
        return $months;
    }
}
