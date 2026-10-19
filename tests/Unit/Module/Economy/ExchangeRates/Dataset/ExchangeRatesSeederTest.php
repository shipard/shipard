<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Dataset;

use Dibi\Connection;
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
use Shipard\Module\Economy\ExchangeRates\Dataset\ExchangeRatesSeeder;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/** Seeder sekce exchangeRates (#121 D10): created / skipped = ok, conflict a neplatný soubor = failed, zdroj „datová sada“. */
class ExchangeRatesSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_xrseed_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('exchangeRates/0001-cz.cnb.daily-2026-06-05.jsonc', DatasetPreflightTest::exchangeRateList());
        $w->writeJsonc('exchangeRates/0002-cz.cnb.other-2026-06-01.jsonc', DatasetPreflightTest::exchangeRateList(['listType' => 'cz.cnb.other', 'validFrom' => '2026-06-01', 'validTo' => '2026-06-30']));
        $w->writeJsonc('exchangeRates/0003-cz.cnb.daily-2026-06-06.jsonc', DatasetPreflightTest::exchangeRateList(['validFrom' => '2026-06-06']));
        $w->writeRaw('exchangeRates/0004-broken.jsonc', '{"listType": ');
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['exchangeRates' => 4]));
        return DatasetReader::open($this->tmp . '/set');
    }

    private function context(DatasetReader $reader): SeedContext
    {
        return new SeedContext(
            reader: $reader,
            db: $this->createMock(Connection::class),
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

    /** @return ExchangeRateListImporter&object{calls: list<array{0: string, 1: int, 2: bool}>} */
    private function importer(array $outcomes): ExchangeRateListImporter
    {
        return new class($outcomes) extends ExchangeRateListImporter {
            /** @var list<array{0: string, 1: int, 2: bool}> */
            public array $calls = [];

            public function __construct(private readonly array $outcomes)
            {
            }

            public function import(array $list, int $source, bool $replace = false): ExchangeRateListImportResult
            {
                $key = $list['listType'] . ' ' . $list['validFrom'];
                $this->calls[] = [$key, $source, $replace];
                $outcome = $this->outcomes[$key] ?? ExchangeRateListImportResult::CREATED;
                $errors = $outcome === ExchangeRateListImportResult::INVALID ? ['Neznámá měna XXX.'] : [];
                return new ExchangeRateListImportResult($outcome, (string) $list['listType'], (string) $list['validFrom'], 1, $errors);
            }
        };
    }

    public function testCreatedAndSkippedAreOkConflictAndBrokenFileFail(): void
    {
        $importer = $this->importer([
            'cz.cnb.other 2026-06-01' => ExchangeRateListImportResult::SKIPPED,
            'cz.cnb.daily 2026-06-06' => ExchangeRateListImportResult::CONFLICT,
        ]);
        $report = new SeedReport();
        $seeder = new ExchangeRatesSeeder($importer);
        $this->assertSame('exchangeRates', $seeder->section());

        $seeder->seed($this->context($this->writeSet()), $report);

        $this->assertSame(['ok' => 2, 'failed' => 2, 'skipped' => 0], $report->counts()['exchangeRates']);
        $this->assertSame(
            [['cz.cnb.daily 2026-06-05', ExchangeRateListImporter::SOURCE_DATASET, false], ['cz.cnb.other 2026-06-01', 2, false], ['cz.cnb.daily 2026-06-06', 2, false]],
            $importer->calls,
            'soubory podle jména, zdroj datová sada, bez replace; rozbitý soubor importeru nejde',
        );
        $errors = $report->errors();
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('0003-cz.cnb.daily-2026-06-06.jsonc: lístek cz.cnb.daily 2026-06-06 už ve zdroji dat je s jiným obsahem — seed nepřepisuje', $errors[0]);
        $this->assertStringContainsString('0004-broken.jsonc', $errors[1]);
    }

    public function testInvalidListReportsImporterErrors(): void
    {
        $report = new SeedReport();
        (new ExchangeRatesSeeder($this->importer(['cz.cnb.daily 2026-06-05' => ExchangeRateListImportResult::INVALID])))
            ->seed($this->context($this->writeSet()), $report);

        $this->assertStringContainsString('0001-cz.cnb.daily-2026-06-05.jsonc: Neznámá měna XXX.', $report->errors()[0]);
    }
}
