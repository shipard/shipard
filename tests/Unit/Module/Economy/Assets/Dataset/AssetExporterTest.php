<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\Assets\Dataset\AssetExporter;

/**
 * Sekce assets při dumpu (#131 fáze 2c, D38, D40): přirozené klíče místo
 * id, potvrzené události s původem, systémové odpisy a nepotvrzené
 * události vynechané s varováním, karta bez čísla bez identity.
 */
class AssetExporterTest extends TestCase
{
    private const CARD_DEFAULTS = [
        'asset_number' => null, 'name' => '', 'short_name' => null, 'note' => null, 'asset_type' => null,
        'category' => 'tangible', 'tracking' => 'single', 'accounting_group' => null, 'is_foreign' => 0, 'owner' => null,
        'acquired_date' => null, 'disposed_date' => null, 'price' => null,
        'tax_method' => null, 'tax_rule' => null, 'acc_method' => null, 'acc_months' => null,
        'docState' => 40, 'docStateMain' => 3,
    ];

    private const EVENT_DEFAULTS = [
        'scope' => 'both', 'period_begin' => null, 'period_end' => null, 'amount' => '0.00', 'accumulated' => null,
        'units_done' => null, 'price_increased' => 0, 'original_date' => null, 'half_year' => 0, 'claim_unrecorded' => 0,
        'origin' => 'manual', 'doc_head' => null, 'note' => null, 'docState' => 40, 'docStateMain' => 3,
    ];

    /** @return list<array<string, mixed>> */
    private function cards(): array
    {
        return [
            ['id' => 3, 'asset_number' => 'MA0001', 'name' => 'Server 1U, 2× CPU, 64 GB RAM', 'asset_type' => 5,
             'accounting_group' => 2, 'acquired_date' => new \DateTimeImmutable('2026-02-14'), 'price' => null,
             'tax_method' => 'straight', 'tax_rule' => 'cz-1', 'acc_method' => 'as_tax'] + self::CARD_DEFAULTS,
            ['id' => 1, 'asset_number' => 'MA0002', 'name' => 'Vrtačka', 'category' => 'small', 'is_foreign' => 1, 'owner' => 5,
             'acquired_date' => '2025-03-01', 'disposed_date' => '2026-01-31', 'price' => '4990.00', 'docState' => 70] + self::CARD_DEFAULTS,
            ['id' => 8, 'asset_number' => 'MA0003', 'name' => 'V opravě', 'accounting_group' => 2, 'asset_type' => 99,
             'tax_method' => 'straight', 'tax_rule' => 'cz-2', 'acc_method' => 'time', 'acc_months' => 36, 'docState' => 80] + self::CARD_DEFAULTS,
            ['id' => 7, 'name' => 'Koncept bez čísla', 'docState' => 10] + self::CARD_DEFAULTS,
        ];
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function events(): array
    {
        return [
            3 => [
                ['id' => 13, 'asset' => 3, 'event_kind' => 'depreciation', 'scope' => 'tax', 'event_date' => '2026-12-31',
                 'period_begin' => '2026-01-01', 'period_end' => '2026-12-31', 'amount' => '30000.00', 'origin' => 'import',
                 'claim_unrecorded' => 1, 'note' => 'ze starého systému'] + self::EVENT_DEFAULTS,
                ['id' => 11, 'asset' => 3, 'event_kind' => 'depreciation', 'scope' => 'acc', 'event_date' => '2026-03-31',
                 'period_begin' => '2026-03-01', 'period_end' => '2026-03-31', 'amount' => '3000.00', 'origin' => 'system', 'doc_head' => 500] + self::EVENT_DEFAULTS,
                ['id' => 12, 'asset' => 3, 'event_kind' => 'improvement', 'event_date' => '2026-05-05', 'amount' => '1000.00', 'docState' => 10] + self::EVENT_DEFAULTS,
                ['id' => 10, 'asset' => 3, 'event_kind' => 'activation', 'event_date' => new \DateTimeImmutable('2026-02-14'),
                 'amount' => '150000.00', 'doc_head' => 600] + self::EVENT_DEFAULTS,
            ],
        ];
    }

    /** @param list<string> $sql */
    private function db(array &$sql): Connection
    {
        $cards = $this->cards();
        $events = $this->events();
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use (&$sql, $cards, $events): array {
            $query = (string) $args[0];
            $sql[] = $query;
            if (str_contains($query, 'FROM [economy_assets_assets]')) {
                $rows = $cards;
                if (str_contains($query, '[id] IN %in')) {
                    $ids = (array) $args[2];
                    $rows = array_values(array_filter($rows, static fn(array $r): bool => in_array($r['id'], $ids, true)));
                }
                return array_map(static fn(array $r): Row => new Row($r), $rows);
            }
            if (str_contains($query, 'FROM [economy_assets_events]')) {
                $this->assertStringContainsString('[docState] <> %i ORDER BY [event_date], [id]', $query);
                return array_map(static fn(array $r): Row => new Row($r), $events[(int) $args[1]] ?? []);
            }
            $this->fail("unexpected fetchAll: {$query}");
        });
        $db->method('fetch')->willReturnCallback(function (string $query, mixed ...$args): ?Row {
            $id = (int) ($args[0] ?? 0);
            if (str_contains($query, 'FROM [economy_assets_types]')) {
                return $id === 5 ? new Row(['name' => 'Výpočetní technika']) : null;
            }
            if (str_contains($query, 'FROM [economy_assets_accounting_groups]')) {
                return $id === 2 ? new Row(['code' => '022']) : null;
            }
            if (str_contains($query, 'FROM [base_persons_persons]')) {
                return $id === 5 ? new Row([
                    'id' => 5, 'full_name' => 'Půjčovna nářadí s.r.o.', 'company_id' => '12345678', 'tax_id' => 'CZ12345678',
                    'vat_id' => 'CZ12345678', 'court_registration' => null, 'email' => null, 'phone' => null, 'web' => null,
                ]) : null;
            }
            if (str_contains($query, 'FROM [base_persons_addresses]')) {
                return new Row(['street' => 'Dlouhá', 'house_number' => '1', 'city' => 'Brno', 'city_part' => null, 'zip' => '60200', 'country' => 'CZ', 'registry_code' => null]);
            }
            $this->fail("unexpected fetch: {$query}");
        });
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $categories = JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/economy/assets/config/categories.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'economy.assets.categories' ? $categories : null,
        );
        return $config;
    }

    public function testExportsNaturalKeysAndConfirmedEventsWithOrigin(): void
    {
        $sql = [];
        $exporter = new AssetExporter($this->db($sql), $this->config());
        $this->assertSame('assets', $exporter->section());

        $records = $exporter->exportAll();

        $this->assertStringContainsString('FROM [economy_assets_assets] WHERE [docState] <> %i ORDER BY [asset_number], [id]', $sql[0]);
        $this->assertSame(['MA0001', 'MA0002', 'MA0003'], array_map(static fn($r) => $r->slug, $records), 'koncept bez čísla vynechán');
        $this->assertSame([3, 1, 8], array_map(static fn($r) => $r->id, $records));

        $server = $records[0]->data;
        $this->assertSame(['format', 'asset', 'events'], array_keys($server));
        $this->assertSame(AssetExporter::FORMAT, $server['format']);
        $this->assertSame([
            'assetNumber' => 'MA0001', 'name' => 'Server 1U, 2× CPU, 64 GB RAM', 'type' => 'Výpočetní technika',
            'category' => 'tangible', 'tracking' => 'single', 'accountingGroup' => '022',
            'taxMethod' => 'straight', 'taxRule' => 'cz-1', 'accMethod' => 'as_tax', 'state' => 'confirmed',
        ], $server['asset'], 'typ názvem, skupina kódem, dlouhodobá karta bez data pořízení a ceny (D13), bez null hodnot');
        $this->assertSame([
            ['kind' => 'activation', 'scope' => 'both', 'date' => '2026-02-14', 'amount' => 150000.0, 'origin' => 'manual'],
            ['kind' => 'depreciation', 'scope' => 'tax', 'date' => '2026-12-31', 'periodBegin' => '2026-01-01', 'periodEnd' => '2026-12-31',
             'amount' => 30000.0, 'claimUnrecorded' => true, 'note' => 'ze starého systému', 'origin' => 'import'],
        ], $server['events'], 'chronologicky, jen potvrzené, bez systémových; zaúčtovaná událost bez doc_head; původ import zachován');

        $drill = $records[1]->data;
        $this->assertSame(['format', 'asset'], array_keys($drill), 'drobný majetek bez událostí');
        $this->assertSame([
            'assetNumber' => 'MA0002', 'name' => 'Vrtačka', 'category' => 'small', 'tracking' => 'single', 'foreign' => true,
            'owner' => ['name' => 'Půjčovna nářadí s.r.o.', 'country' => 'cz', 'companyId' => '12345678', 'taxId' => 'CZ12345678', 'vatId' => 'CZ12345678'],
            'acquiredDate' => '2025-03-01', 'disposedDate' => '2026-01-31', 'price' => 4990.0, 'state' => 'archived',
        ], $drill['asset'], 'drobný majetek nese data a cenu, cizí majetek vlastníka identifikátory');

        $edit = $records[2]->data['asset'];
        $this->assertSame('confirmed', $edit['state'], 'V opravě se přenáší jako V pořádku');
        $this->assertSame(36, $edit['accMonths']);
        $this->assertArrayNotHasKey('type', $edit, 'neexistující typ se nepřenáší');

        $warnings = $exporter->getWarnings();
        $this->assertCount(4, $warnings, implode("\n", $warnings));
        $this->assertStringContainsString('karta MA0001: 1 systémových odpisů se nepřenáší', $warnings[0]);
        $this->assertStringContainsString('karta MA0001: 1 nepotvrzených událostí se nepřenáší', $warnings[1]);
        $this->assertStringContainsString('karta MA0003: typ majetku #99 neexistuje', $warnings[2]);
        $this->assertStringContainsString('karta #7 bez inventárního čísla', $warnings[3]);

        $validator = new SchemaValidator(new SchemaLoader(dirname(__DIR__, 6) . '/modules/core/exchange/schemas'));
        foreach ($records as $record) {
            $decoded = json_decode(DatasetWriter::encode($record->data), true);
            $this->assertSame([], $validator->validate($decoded, 'shpd.dataset.asset', '1'), "{$record->slug} musí projít schématem obálky");
        }
    }

    public function testExportByIdsFiltersAndEmptyListQueriesNothing(): void
    {
        $sql = [];
        $exporter = new AssetExporter($this->db($sql), $this->config());

        $this->assertSame([], $exporter->exportByIds([]));
        $this->assertSame([], $sql);

        $records = $exporter->exportByIds([1, 1]);
        $this->assertStringContainsString('[id] IN %in', $sql[0]);
        $this->assertSame(['MA0002'], array_map(static fn($r) => $r->slug, $records));
    }

    public function testWithoutCategoriesConfigCardIsNotLongTerm(): void
    {
        // Bez kompilované konfigurace (degradace) se druh nebere jako dlouhodobý —
        // data a cena z karty se vypíší; validace dlouhodobého majetku hlídá seed.
        $sql = [];
        $records = (new AssetExporter($this->db($sql)))->exportByIds([3]);

        $this->assertSame('2026-02-14', $records[0]->data['asset']['acquiredDate']);
    }
}
