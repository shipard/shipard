<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\Assets\Dataset\AssetEventsSeeder;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/**
 * Události karet ze sady (#131 fáze 2c, D38, D40): za doklady, chronologicky,
 * potvrzené, bez importního módu u původu manual (marker jen u import),
 * jedna transakce per karta; chybějící karta = failed, karta s událostmi =
 * skipped, odmítnutá událost = failed s rollbackem.
 */
class AssetEventsSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_assetevents_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('assets/0001-ma0001.jsonc', DatasetPreflightTest::asset([
            'events' => [
                ['kind' => 'depreciation', 'scope' => 'tax', 'date' => '2026-12-31', 'periodBegin' => '2026-01-01', 'periodEnd' => '2026-12-31',
                 'amount' => 30000.0, 'claimUnrecorded' => true, 'origin' => 'import'],
                ['kind' => 'improvement', 'scope' => 'both', 'date' => '2026-02-14', 'amount' => 1000.0, 'origin' => 'manual'],
                ['kind' => 'activation', 'scope' => 'both', 'date' => '2026-02-14', 'amount' => 150000.0, 'origin' => 'manual'],
            ],
        ]));
        $w->writeJsonc('assets/0002-ma0002.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0002'] + DatasetPreflightTest::asset()['asset'], 'events' => [],
        ]));
        $w->writeJsonc('assets/0003-ma0003.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0003'] + DatasetPreflightTest::asset()['asset'],
        ]));
        $w->writeJsonc('assets/0004-ma0004.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0004'] + DatasetPreflightTest::asset()['asset'],
        ]));
        $w->writeJsonc('assets/0005-ma0005.jsonc', DatasetPreflightTest::asset([
            'asset' => ['assetNumber' => 'MA0005'] + DatasetPreflightTest::asset()['asset'],
            'events' => [
                ['kind' => 'activation', 'scope' => 'both', 'date' => '2026-02-14', 'amount' => 150000.0, 'origin' => 'manual'],
                ['kind' => 'depreciation', 'scope' => 'acc', 'date' => '2026-03-31', 'periodBegin' => '2026-03-01', 'periodEnd' => '2026-03-31',
                 'amount' => 3000.5, 'origin' => 'manual'],
            ],
        ]));
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['assets' => 5]));
        return DatasetReader::open($this->tmp . '/set');
    }

    private function context(DatasetReader $reader): SeedContext
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql, mixed ...$params): ?Row {
            if (str_contains($sql, 'FROM [economy_assets_assets] WHERE [asset_number] = %s AND [docState] <> %i')) {
                return match ($params[0]) { 'MA0001' => new Row(['id' => 1]), 'MA0004' => new Row(['id' => 4]), 'MA0005' => new Row(['id' => 5]), default => null };
            }
            if (str_contains($sql, 'FROM [economy_assets_events] WHERE [asset] = %i AND [docState] <> %i LIMIT 1')) {
                return (int) $params[0] === 4 ? new Row(['id' => 40]) : null;
            }
            $this->fail("unexpected fetch: {$sql}");
        });
        return new SeedContext(
            reader: $reader,
            db: $db,
            dsConnection: $this->createMock(DataSourceConnection::class),
            config: $this->createMock(ConfigRuntime::class),
            dsConfig: $this->createMock(DataSourceConfig::class),
            registry: new DocumentRegistry(),
            tables: [],
            dsDir: $this->tmp,
            attachments: $this->createMock(AttachmentService::class),
            dispatcher: null,
            merge: false,
        );
    }

    /** @return AssetEventsSeeder&object{rows: list<array<string, mixed>>, transactions: list<string>} */
    private function seeder(): AssetEventsSeeder
    {
        return new class extends AssetEventsSeeder {
            /** @var list<array<string, mixed>> */
            public array $rows = [];
            /** @var list<string> */
            public array $transactions = [];

            protected function saveEvent(SeedContext $ctx, array $row): DocumentResult
            {
                $this->rows[] = $row;
                if ((int) $row['asset'] === 5 && $row['event_kind'] === 'depreciation') {
                    $validation = new ValidationResult();
                    $validation->addError('amount', 'Odpis musí být zaokrouhlený na celé koruny.', 'notWholeUnits');
                    return DocumentResult::validationFailed($validation);
                }
                return DocumentResult::ok(['id' => 100 + count($this->rows)] + $row);
            }

            protected function begin(Connection $db): void
            {
                $this->transactions[] = 'begin';
            }

            protected function commit(Connection $db): void
            {
                $this->transactions[] = 'commit';
            }

            protected function rollback(Connection $db): void
            {
                $this->transactions[] = 'rollback';
            }
        };
    }

    public function testWritesConfirmedEventsChronologicallyWithoutImportModeForManualOrigin(): void
    {
        $reader = $this->writeSet();
        $seeder = $this->seeder();
        $this->assertSame('assetEvents', $seeder->section());
        $report = new SeedReport();

        $seeder->seed($this->context($reader), $report);

        $counts = $report->counts()['assetEvents'];
        $this->assertSame(1, $counts['ok'], 'MA0001 (tři události)');
        $this->assertSame(2, $counts['failed'], 'MA0003 karta na DS není, MA0005 odmítnutý odpis');
        $this->assertSame(1, $counts['skipped'], 'MA0004 už má události');
        $this->assertSame(['begin', 'commit', 'begin', 'rollback'], $seeder->transactions, 'jedna transakce per karta; MA0002 bez událostí se nepočítá');

        $this->assertSame(
            ['activation', 'improvement', 'depreciation', 'activation', 'depreciation'],
            array_column($seeder->rows, 'event_kind'),
            'chronologicky: datum, pořadí druhu v rámci dne, pořadí v souboru',
        );
        $activation = $seeder->rows[0];
        $this->assertSame([
            'asset' => 1, 'event_kind' => 'activation', 'scope' => 'both', 'event_date' => '2026-02-14', 'amount' => 150000.0,
            'claim_unrecorded' => 0, 'half_year' => 0, 'price_increased' => 0, 'docState' => 40,
        ], $activation, 'ruční událost: potvrzená, bez markeru _import — původ manual dá dokument');
        $this->assertArrayNotHasKey('_import', $activation);
        $this->assertArrayNotHasKey('origin', $activation, 'původ payload nepodvrhne');

        $imported = $seeder->rows[2];
        $this->assertSame('depreciation', $imported['event_kind']);
        $this->assertTrue($imported['_import'], 'původ import = importní mód dokumentu');
        $this->assertSame(1, $imported['claim_unrecorded']);
        $this->assertSame('2026-12-31', $imported['period_end']);

        $errors = $report->errors();
        $this->assertCount(2, $errors, implode("\n", $errors));
        $this->assertStringContainsString("0003-ma0003.jsonc: karta 'MA0003' na DS není", $errors[0]);
        $this->assertStringContainsString('0005-ma0005.jsonc: events[1].amount: Odpis musí být zaokrouhlený na celé koruny.', $errors[1]);
        $this->assertCount(1, $report->warnings());
        $this->assertStringContainsString('0004-ma0004.jsonc: karta MA0004 už má události', $report->warnings()[0]);
    }

    public function testChronologicalOrderUsesKindOrderWithinDay(): void
    {
        $events = [
            ['kind' => 'disposal', 'date' => '2026-05-01'],
            ['kind' => 'depreciation', 'date' => '2026-05-01'],
            ['kind' => 'activation', 'date' => '2026-05-01'],
            ['kind' => 'improvement', 'date' => '2026-01-01'],
            'nesmysl',
        ];

        $this->assertSame([4, 3, 2, 1, 0], AssetEventsSeeder::chronological($events), 'neobjekt (bez data) první, pak datum a pořadí druhu');
    }
}
