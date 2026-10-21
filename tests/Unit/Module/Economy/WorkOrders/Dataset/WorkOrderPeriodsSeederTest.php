<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderPeriodsSeeder;
use Shipard\Module\Economy\WorkOrders\Invoicing\ContentHash;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodRepository;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/**
 * Evidence období ze sady (#131 fáze 2b, D30, D31): za doklady, doklad
 * číslem mezi vydanými, zápis přes PeriodRepository s otiskem uloženého
 * dokladu; chybějící doklad nebo zakázka = failed.
 */
class WorkOrderPeriodsSeederTest extends TestCase
{
    public const NOW = '2026-10-20 12:00:00';
    private const HEAD = ['id' => 500, 'doc_text' => 'Správa IT — paušál leden 2026', 'partner' => 5, 'issue_date' => '2026-01-01',
        'due_date' => '2026-01-15', 'accounting_date' => '2026-01-01', 'vat_duzp' => '2026-01-01', 'period_from' => '2026-01-01',
        'period_to' => '2026-01-31', 'doc_currency' => 'czk', 'vat_mode' => 1, 'payment_method' => 1, 'payment_reference' => '1260001',
        'bank_account' => 4, 'cost_center' => null, 'work_order' => 1, 'number_series' => 7];
    private const ROWS = [['order_pos' => 1, 'row_kind' => 1, 'operation' => 'sale.services', 'item' => 11, 'description' => 'Správa IT — měsíční paušál',
        'quantity' => '1.0000', 'unit' => 3, 'unit_price' => '16000.0000', 'vat_code' => 'cz-120', 'cost_center' => null, 'work_order' => null]];

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_woperiods_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('workOrders/0001-s0001.jsonc', DatasetPreflightTest::workOrder());
        $w->writeJsonc('workOrders/0002-s0002.jsonc', DatasetPreflightTest::workOrder([
            'workOrder' => ['number' => 'S0002'] + DatasetPreflightTest::workOrder()['workOrder'],
            'periods'   => [['from' => '2026-01-01', 'to' => '2026-01-31', 'state' => 'issued', 'docNumber' => 'CHYBI']],
        ]));
        $w->writeJsonc('workOrders/0003-s0003.jsonc', DatasetPreflightTest::workOrder([
            'workOrder' => ['number' => 'S0003'] + DatasetPreflightTest::workOrder()['workOrder'],
        ]));
        $w->writeJsonc('workOrders/0004-p0001.jsonc', [
            'format'    => 'shpd.dataset.workOrder.v1',
            'workOrder' => ['number' => 'P0001', 'numberSeries' => 'P', 'title' => 'Projekt', 'state' => 'finished', 'dateStart' => '2026-03-17'],
        ]);
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['workOrders' => 4]));
        return DatasetReader::open($this->tmp . '/set');
    }

    private function context(DatasetReader $reader): SeedContext
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql, mixed ...$params): ?Row {
            if (str_contains($sql, 'FROM [economy_work_orders_heads] WHERE [number] = %s')) {
                return match ($params[0]) { 'S0001' => new Row(['id' => 1]), 'S0002' => new Row(['id' => 2]), default => null };
            }
            if (str_contains($sql, 'FROM [docs_core_heads] WHERE [doc_number] = %s')) {
                $this->assertStringContainsString('[doc_type] IN %in AND [docState] <> %i', $sql);
                $this->assertSame(['invno', 'invpo'], $params[1]);
                return match ($params[0]) { '1260001' => new Row(['id' => 500]), '!0000000155' => new Row(['id' => 655]), default => null };
            }
            if (str_contains($sql, 'FROM [economy_work_orders_periods] WHERE [work_order] = %i AND [period_from] = %d')) {
                return new Row(['id' => 100 + (int) substr((string) $params[1], 5, 2)]);
            }
            if (str_contains($sql, 'SELECT * FROM [docs_core_heads] WHERE [id] = %i')) {
                return new Row(['id' => $params[0]] + self::HEAD);
            }
            $this->fail("unexpected fetch: {$sql}");
        });
        $db->method('fetchAll')->willReturnCallback(function (string $sql): array {
            $this->assertStringContainsString('FROM [docs_core_rows] WHERE [doc_head] = %i', $sql);
            return array_map(static fn(array $r): Row => new Row($r), self::ROWS);
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

    public function testWritesIssuedPeriodsWithStoredDocumentHash(): void
    {
        $reader = $this->writeSet();
        $ctx = $this->context($reader);
        $repository = new class($ctx->db) extends PeriodRepository {
            /** @var list<array> */
            public array $calls = [];

            protected function execute(string $sql, mixed ...$args): void
            {
                $this->calls[] = [$sql, ...$args];
            }
        };
        $seeder = new class($repository) extends WorkOrderPeriodsSeeder {
            protected function now(): string
            {
                return WorkOrderPeriodsSeederTest::NOW;
            }
        };
        $this->assertSame('workOrderPeriods', $seeder->section());
        $report = new SeedReport();

        $seeder->seed($ctx, $report);

        $counts = $report->counts()['workOrderPeriods'];
        $this->assertSame(1, $counts['ok'], 'S0001 (dvě období)');
        $this->assertSame(2, $counts['failed'], 'S0002 chybějící doklad, S0003 zakázka na DS není');
        $this->assertSame(0, $counts['skipped']);
        $this->assertArrayNotHasKey('P0001', array_flip(array_map('strval', $report->errors())), 'zakázka bez období se nepočítá');

        $hash = ContentHash::ofDocument(['id' => 500] + self::HEAD, self::ROWS);
        $this->assertCount(4, $repository->calls, 'per období INSERT IGNORE + UPDATE; S0002 se před INSERT zastaví na chybějícím dokladu');
        $this->assertStringContainsString('INSERT IGNORE INTO [economy_work_orders_periods]', $repository->calls[0][0]);
        $this->assertSame(
            ['work_order' => 1, 'period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'state' => 'planned', 'created_at' => self::NOW, 'updated_at' => self::NOW],
            $repository->calls[0][1],
        );
        $this->assertStringContainsString('UPDATE [economy_work_orders_periods] SET %a WHERE [id] = %i', $repository->calls[1][0]);
        $this->assertSame(
            ['state' => 'issued', 'doc' => 500, 'content_hash' => $hash, 'result' => null, 'message' => null, 'waiting_since' => null, 'updated_at' => self::NOW],
            $repository->calls[1][1],
        );
        $this->assertSame(101, $repository->calls[1][2]);
        $this->assertSame('2026-10-01', $repository->calls[2][1]['period_from'], 'koncept měsíce kotvy');
        $this->assertSame(655, $repository->calls[3][1]['doc'], 'zástupné číslo konceptu dohledá doklad');

        $errors = $report->errors();
        $this->assertCount(2, $errors, implode("\n", $errors));
        $this->assertStringContainsString('0002-s0002.jsonc: 1 období se nezapsalo', $errors[0]);
        $this->assertStringContainsString("0003-s0003.jsonc: zakázka 'S0003' na DS není", $errors[1]);
        $this->assertCount(1, $report->warnings());
        $this->assertStringContainsString('periods[0]: období 2026-01-01: doklad CHYBI mezi vydanými doklady není', $report->warnings()[0]);
    }
}
