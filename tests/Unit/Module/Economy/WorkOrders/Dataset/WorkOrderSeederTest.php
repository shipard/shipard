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
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderSeeder;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportResult;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/**
 * Seeder sekce workOrders (#131 fáze 2b, D30): přirozené klíče → id
 * v payloadu importu, zápis jen přes WorkOrderImportApplier; neznámý
 * zákazník, položka nebo řada = failed; existující číslo = skipped.
 */
class WorkOrderSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_woseed_' . bin2hex(random_bytes(4));
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
            'workOrder' => ['number' => 'S0002', 'sequenceNumber' => 2, 'customer' => ['name' => 'Neznámý s.r.o.', 'companyId' => '00000000']]
                + DatasetPreflightTest::workOrder()['workOrder'],
        ]));
        $w->writeJsonc('workOrders/0003-s0003.jsonc', DatasetPreflightTest::workOrder([
            'workOrder' => ['number' => 'S0003', 'sequenceNumber' => 3] + DatasetPreflightTest::workOrder()['workOrder'],
        ]));
        $w->writeJsonc('workOrders/0004-p0001.jsonc', [
            'format'    => 'shpd.dataset.workOrder.v1',
            'workOrder' => [
                'number' => 'P0001', 'sequenceNumber' => 1, 'numberSeries' => 'P', 'title' => 'Výměna sítě', 'state' => 'finished',
                'dateStart' => '2026-03-17', 'dateEnd' => '2026-04-22', 'costCenter' => 'S01',
                'customer' => ['name' => 'Autodoprava Kopretina s.r.o.', 'country' => 'cz', 'companyId' => '12345678'],
                'currency' => 'czk',
            ],
        ]);
        $w->writeJsonc('workOrders/0005-s0005.jsonc', DatasetPreflightTest::workOrder([
            'workOrder' => ['number' => 'S0005', 'sequenceNumber' => 5] + DatasetPreflightTest::workOrder()['workOrder'],
            'rows'      => [['item' => 'NEZNAMA', 'description' => 'x', 'quantity' => 1.0, 'unit' => 'pcs', 'unitPrice' => 1.0]],
        ]));
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['workOrders' => 5]));
        return DatasetReader::open($this->tmp . '/set');
    }

    private function context(DatasetReader $reader): SeedContext
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$params): array {
            if (str_contains($sql, 'FROM [economy_work_orders_number_series] WHERE [number_code] = %s')) {
                return match ($params[0]) { 'S' => [new Row(['id' => 1])], 'P' => [new Row(['id' => 2])], default => [] };
            }
            if (str_contains($sql, 'FROM [economy_codebooks_cost_centers] WHERE [code] = %s')) {
                return $params[0] === 'S01' ? [new Row(['id' => 3])] : [];
            }
            if (str_contains($sql, 'FROM [economy_codebooks_bank_accounts] WHERE [code] = %s')) {
                return $params[0] === 'BU2' ? [new Row(['id' => 4])] : [];
            }
            if (str_contains($sql, 'FROM [docs_core_number_series]')) {
                return $params[0] === 'invno' && !str_contains($sql, '[doc_number_code] = %s') ? [new Row(['id' => 7])] : [];
            }
            $this->fail("unexpected query: {$sql}");
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

    private function party(): PartyResolver
    {
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturnCallback(function (array $party, mixed $type, bool $identifiersOnly): ResolveResult {
            $this->assertTrue($identifiersOnly, 'strana jen podle identifikátorů');
            return ($party['companyId'] ?? null) === '12345678' ? ResolveResult::matched(5, 'companyId') : ResolveResult::notFound();
        });
        return $party;
    }

    private function items(): ItemResolver
    {
        $items = $this->createMock(ItemResolver::class);
        $items->method('resolve')->willReturnCallback(
            static fn(array $item): ResolveResult => ($item['ourCode'] ?? null) === 'SPRAVA-IT' ? ResolveResult::matched(11, 'ourCode') : ResolveResult::notFound(),
        );
        return $items;
    }

    /**
     * @param array<string, string> $outcomes číslo zakázky → created | skipped | error
     * @return WorkOrderImportApplier&object{calls: list<array<string, mixed>>}
     */
    private function applier(array $outcomes): WorkOrderImportApplier
    {
        return new class($outcomes) extends WorkOrderImportApplier {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function __construct(private readonly array $outcomes)
            {
            }

            public function apply(array $payload): WorkOrderImportResult
            {
                $this->calls[] = $payload;
                $number = (string) ($payload['workOrder']['number'] ?? '');
                return match ($this->outcomes[$number] ?? 'created') {
                    'skipped' => WorkOrderImportResult::ok(self::STATUS_SKIPPED, 9, $number, [
                        ['code' => self::WARNING_EXISTS, 'message' => "Zakázka {$number} už v tomto zdroji dat je — import ji nemění.", 'path' => 'workOrder.number'],
                    ]),
                    'error' => WorkOrderImportResult::error('validation_failed', 'Validace zakázky selhala.', [
                        ['severity' => 'error', 'path' => 'rows', 'code' => 'required', 'message' => 'Periodická zakázka mimo Koncept potřebuje aspoň jeden řádek předpisu.'],
                    ], 422),
                    default => WorkOrderImportResult::ok(self::STATUS_CREATED, 31, $number, [
                        ['code' => 'counter_not_synced', 'message' => 'Čítač řady se nesrovnal.', 'path' => 'workOrder.sequenceNumber'],
                    ], 201),
                };
            }
        };
    }

    public function testTranslatesNaturalKeysAndReportsPerFile(): void
    {
        $reader = $this->writeSet();
        $applier = $this->applier(['S0003' => 'error', 'P0001' => 'skipped']);
        $seeder = new WorkOrderSeeder($applier, $this->party(), $this->items());
        $this->assertSame('workOrders', $seeder->section());
        $report = new SeedReport();

        $seeder->seed($this->context($reader), $report);

        $counts = $report->counts()['workOrders'];
        $this->assertSame(1, $counts['ok'], 'S0001');
        $this->assertSame(1, $counts['skipped'], 'P0001 už existuje');
        $this->assertSame(3, $counts['failed'], 'S0002 neznámý zákazník, S0003 odmítl applier, S0005 neznámá položka');

        $this->assertCount(3, $applier->calls, 'applier se volá jen s přeloženým payloadem (S0001, S0003, P0001)');
        $payload = $applier->calls[0];
        $this->assertSame('shpd.workOrders.workOrder.v1', $payload['format']);
        $this->assertSame([
            'number' => 'S0001', 'sequenceNumber' => 1, 'title' => 'Správa IT — Kopretina', 'state' => 'confirmed',
            'dateStart' => '2023-04-01', 'currency' => 'czk',
            'numberSeries' => 1, 'customer' => 5,
            'invoicing' => [
                'periodicity' => 'month', 'invoiceFrom' => '2026-01-01', 'docText' => 'Správa IT — paušál {období}',
                'numberSeries' => 7, 'bankAccount' => 4,
            ],
        ], $payload['workOrder'], 'řada a účet kódem → id, řada dokladů {docType} → id (D37), zákazník identifikátory → id');
        $this->assertSame([
            ['description' => 'Správa IT — měsíční paušál', 'quantity' => 1.0, 'unit' => 'mnth', 'unitPrice' => 16000.0, 'vatCode' => 'cz-120', 'item' => 11],
        ], $payload['rows']);
        $this->assertArrayNotHasKey('periods', $payload, 'evidenci období zapisuje až WorkOrderPeriodsSeeder');

        $project = $applier->calls[2]['workOrder'];
        $this->assertSame(['P0001', 2, 3], [$project['number'], $project['numberSeries'], $project['costCenter']]);
        $this->assertArrayNotHasKey('invoicing', $project);

        $errors = $report->errors();
        $this->assertCount(3, $errors, implode("\n", $errors));
        $this->assertStringContainsString("0002-s0002.jsonc: zákazník 'Neznámý s.r.o.' (IČO 00000000) v adresáři není", $errors[0]);
        $this->assertStringContainsString('0003-s0003.jsonc: Validace zakázky selhala. (rows Periodická zakázka', $errors[1]);
        $this->assertStringContainsString("0005-s0005.jsonc: rows[0]: položka 'NEZNAMA' v číselníku není", $errors[2]);

        $warnings = $report->warnings();
        $this->assertCount(2, $warnings, implode("\n", $warnings));
        $this->assertStringContainsString('0001-s0001.jsonc: workOrder.sequenceNumber Čítač řady se nesrovnal.', $warnings[0], 'varování applieru se propouští');
        $this->assertStringContainsString('0004-p0001.jsonc: zakázka P0001 už na DS je', $warnings[1], 'skipped = varování bez duplicitního work_order_exists');
    }
}
