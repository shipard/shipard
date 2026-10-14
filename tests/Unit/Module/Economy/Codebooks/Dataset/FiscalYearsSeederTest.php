<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks\Dataset;

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
use Shipard\Module\Economy\Codebooks\Dataset\FiscalYearsSeeder;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

class FiscalYearsSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_fyseed_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /**
     * Sada: doklad 2024-03 (issue) s dueDate 2025-01 (nesmí založit 2025),
     * zpráva přijatá 2026-08-26 s canonicalem DUZP 2026-08-25 a druhým
     * canonicalem z roku 2023.
     */
    private function writeSet(bool $withRecords = true): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        if ($withRecords) {
            $doc = DatasetPreflightTest::doc();
            $doc['dates'] = ['issueDate' => '2024-03-10', 'accountingDate' => '2024-03-10', 'dueDate' => '2025-01-05'];
            $w->writeJsonc('docs/0001-doc.jsonc', $doc);
            $mail = DatasetPreflightTest::mail();
            $mail['receivedAt'] = '2026-08-26T08:00:00';
            $mail['analyses'][0]['canonicalJson']['dates'] = ['issueDate' => '2026-08-25', 'taxPointDate' => '2026-08-25', 'periodFrom' => '2020-01-01'];
            $mail['analyses'][] = $mail['analyses'][0];
            $mail['analyses'][1]['canonicalJson']['dates'] = ['issueDate' => '2023-12-31'];
            $w->writeJsonc('mail/0001-msg.jsonc', $mail);
        }
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z'));
        return DatasetReader::open($this->tmp . '/set');
    }

    /**
     * @param array<string, mixed> $settings hodnoty settings klíčů (null = nerozhodnuto)
     */
    private function context(DatasetReader $reader, array $settings): SeedContext
    {
        $dsConnection = $this->createMock(DataSourceConnection::class);
        $dsConnection->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($settings): mixed {
                $key = (string) ($args[1] ?? '');
                return array_key_exists($key, $settings) && $settings[$key] !== null ? json_encode($settings[$key]) : null;
            },
        );
        return new SeedContext(
            reader: $reader,
            db: $this->createMock(Connection::class),
            dsConnection: $dsConnection,
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

    /**
     * @param list<string> $existingBegins roky, které už existují (date_begin)
     */
    private function seeder(array $existingBegins = [], ?\Throwable $throwOn = null): FiscalYearsSeeder
    {
        $provisioner = $this->createMock(FiscalYearsProvisioner::class);
        $provisioner->method('ensureYearForDate')->willReturnCallback(
            static function (\DateTimeImmutable $date) use ($existingBegins, $throwOn): array {
                if ($throwOn !== null && $date->format('Y') === '2023') {
                    throw $throwOn;
                }
                $begin = $date->format('Y') . '-01-01';
                return ['created' => !in_array($begin, $existingBegins, true), 'name' => $date->format('Y'), 'dateBegin' => $begin];
            },
        );
        return new class($provisioner) extends FiscalYearsSeeder {
            /** @var list<array{int, string}> */
            public array $provisionerArgs = [];
            /** @var list<string> */
            public array $dates = [];

            public function __construct(private readonly FiscalYearsProvisioner $stub) {}

            protected function provisioner(SeedContext $ctx, int $startMonth, string $currency): FiscalYearsProvisioner
            {
                $this->provisionerArgs[] = [$startMonth, $currency];
                return $this->stub;
            }
        };
    }

    public function testCollectDatesReadsDocsMailAndCanonicalButNotDueDateOrPeriods(): void
    {
        $dates = (new FiscalYearsSeeder())->collectDates($this->writeSet());

        $this->assertSame(['2023-12-31', '2024-03-10', '2026-08-25', '2026-08-26'], $dates);
    }

    public function testEnsuresOneYearPerFiscalYearAndCountsExistingAsOk(): void
    {
        $seeder = $this->seeder(existingBegins: ['2026-01-01']);
        $report = new SeedReport();
        $seeder->seed($this->context($this->writeSet(), ['economy.fiscalYearStartMonth' => 1, 'economy.homeCurrency' => 'czk']), $report);

        $this->assertSame(3, $report->counts()['fiscalYears']['ok'], '2023, 2024, 2026 — 2026 dvakrát jen jednou');
        $this->assertSame([], $report->errors());
        $this->assertSame([[1, 'czk']], $seeder->provisionerArgs);
        $this->assertCount(2, $report->warnings(), 'založené roky 2023 a 2024 se hlásí');
    }

    public function testStartMonthStoredAsStringIsCast(): void
    {
        $seeder = $this->seeder();
        $seeder->seed($this->context($this->writeSet(), ['economy.fiscalYearStartMonth' => '7', 'economy.homeCurrency' => 'eur']), new SeedReport());

        $this->assertSame([[7, 'eur']], $seeder->provisionerArgs);
    }

    public function testUndecidedSettingsWithDatesIsAFailure(): void
    {
        $report = new SeedReport();
        $this->seeder()->seed($this->context($this->writeSet(), ['economy.fiscalYearStartMonth' => 1]), $report);

        $this->assertSame(1, $report->counts()['fiscalYears']['failed']);
        $this->assertStringContainsString('economy.homeCurrency', $report->errors()[0]);
        $this->assertStringContainsString('2023, 2024, 2026', $report->errors()[0]);
    }

    public function testNoDatesMeansNoSettingsLookupAndNoErrors(): void
    {
        $seeder = $this->seeder();
        $report = new SeedReport();
        $seeder->seed($this->context($this->writeSet(withRecords: false), []), $report);

        $this->assertSame([], $report->errors());
        $this->assertSame([], $seeder->provisionerArgs);
    }

    public function testProvisionerFailureIsReportedPerDateAndContinues(): void
    {
        $report = new SeedReport();
        $this->seeder(throwOn: new \RuntimeException('db down'))
            ->seed($this->context($this->writeSet(), ['economy.fiscalYearStartMonth' => 1, 'economy.homeCurrency' => 'czk']), $report);

        $this->assertSame(1, $report->counts()['fiscalYears']['failed']);
        $this->assertSame(2, $report->counts()['fiscalYears']['ok']);
        $this->assertStringContainsString('rok pro datum 2023-12-31: db down', $report->errors()[0]);
    }
}
