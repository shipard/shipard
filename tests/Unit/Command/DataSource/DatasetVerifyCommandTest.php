<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DatasetVerifyCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\Verify\ApplyReport;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyWiring;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** Kontrola s pevným výsledkem (nebo výjimkou). */
class StubVerifyCheck implements VerifyCheck
{
    public function __construct(private readonly string $id, private readonly ?CheckResult $result, private readonly ?\Throwable $throw = null) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return "Kontrola {$this->id}";
    }

    public function run(VerifyContext $ctx): CheckResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->result ?? CheckResult::ok($this->id, $this->label());
    }
}

class TestableDatasetVerifyCommand extends DatasetVerifyCommand
{
    public string $mode = 'development';
    /** @var list<string> */
    public array $membership = [];
    /** @var list<SetDocument> */
    public array $documents = [];
    public ?ApplyReport $applyResult = null;
    public int $applyCalls = 0;
    /** @var array<string, string> */
    public array $skippedAtApply = [];
    /** @var list<VerifyCheck> */
    public array $checks = [];
    public ?VerifyContext $ctx = null;
    /** @var list<int|null> */
    public array $itemCounts = [3, 3];

    public function __construct(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, public readonly VerifyWiring $wiring)
    {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return '/nonexistent';
    }

    protected function getServerMode(): string
    {
        return $this->mode;
    }

    protected function createWiring(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, string $dsDir): VerifyWiring
    {
        return $this->wiring;
    }

    protected function membershipProblems(VerifyWiring $w, array $setMessageIds): array
    {
        return $this->membership;
    }

    protected function loadDocuments(VerifyWiring $w, array $setMessageIds): array
    {
        return $this->documents;
    }

    protected function homeCurrency(VerifyWiring $w): string
    {
        return 'CZK';
    }

    protected function countItems(VerifyWiring $w): ?int
    {
        return array_shift($this->itemCounts);
    }

    protected function applyProposals(VerifyWiring $w, array $documents, array $skipped): ApplyReport
    {
        $this->applyCalls++;
        $this->skippedAtApply = $skipped;
        return $this->applyResult ?? new ApplyReport(true, ['MSG-1']);
    }

    protected function createReportRunner(VerifyWiring $w): ?ReportRunner
    {
        return null;
    }

    protected function createChecks(VerifyContext $ctx): array
    {
        $this->ctx = $ctx;
        return $this->checks;
    }
}

/**
 * `dataset-verify` (tasks/testing-phase3.md D26): preflight (produkce,
 * země, očekávání sady, data mimo sadu → exit 2), apply + kontroly
 * s exit 0 / 1, `--no-apply`, `--json`, výjimka kontroly jako rozdíl.
 */
class DatasetVerifyCommandTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_verify_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    private function writeSet(bool $withExpected = true, string $country = 'cz'): string
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('mail/0001-msg-20260601-0001.jsonc', DatasetPreflightTest::mail());
        $w->writeRaw('mail/0001-msg-20260601-0001.files/faktura.pdf', '%PDF');
        if ($withExpected) {
            $w->copyFile(dirname(__DIR__, 4) . '/tests/corpus/projection.jsonc', 'expected/projection.jsonc');
            $w->writeRaw('expected/vat.json', "[]\n");
            $w->writeRaw('expected/costs.json', "[\n  {\n    \"messageId\": \"MSG-20260601-0001\",\n    \"currency\": \"CZK\",\n    \"cost\": 100.00\n  }\n]\n");
        }
        $w->writeManifest(new DatasetManifest('cz-test', 'Test', null, 'fixed', '2026-10-14T00:00:00Z', ['mail' => 1], country: $country, profile: 'vat-payer'));
        return $this->tmp . '/set';
    }

    private function command(): TestableDatasetVerifyCommand
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getCountry')->willReturn('cz');
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('getId')->willReturn('test-ds-id');
        $dsConnection = $this->createMock(DataSourceConnection::class);
        $wiring = new VerifyWiring($this->createMock(\Dibi\Connection::class), $dsConnection, $dsConfig, '/nonexistent');
        $command = new TestableDatasetVerifyCommand($dsConfig, $dsConnection, $wiring);
        $command->documents = [
            self::doc('MSG-20260601-0001', 1, 'CZK', docId: null),
            self::doc('MSG-EUR', 2, 'EUR', docId: null),
            self::doc('MSG-DONE', 3, 'CZK', docId: 77, resolution: 40),
        ];
        return $command;
    }

    private static function doc(string $messageId, int $ndx, string $currency, ?int $docId, ?int $resolution = null): SetDocument
    {
        return new SetDocument($messageId, $ndx, '2026-06-01 08:00:00', 10 + $ndx, $resolution,
            ['format' => 'shpd.docs.document', 'currency' => $currency], $docId, $docId !== null ? 40 : null, $docId !== null ? 'FP-1' : null);
    }

    private function runVerify(TestableDatasetVerifyCommand $command, array $args = []): CommandTester
    {
        $app = new Application();
        $app->add($command);
        $tester = new CommandTester($app->find('dataset-verify'));
        $tester->execute(['path' => $this->tmp . '/set'] + $args);
        return $tester;
    }

    public function testProductionIsRefused(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->mode = 'production';
        $tester = $this->runVerify($command);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('production', $tester->getDisplay());
        $this->assertSame(0, $command->applyCalls);
    }

    public function testCountryMismatchIsRefused(): void
    {
        $this->writeSet(country: 'sk');
        $tester = $this->runVerify($this->command());
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('jiné zemi', $tester->getDisplay());
    }

    public function testMissingExpectationsAreRefused(): void
    {
        $this->writeSet(withExpected: false);
        $tester = $this->runVerify($this->command());
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('expected/projection.jsonc', $tester->getDisplay());
    }

    public function testForeignDataIsRefusedWithoutForce(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->membership = ['doklad #5 nemá zdrojovou zprávu'];
        $tester = $this->runVerify($command);
        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('mimo sadu', $tester->getDisplay());
        $this->assertStringContainsString('doklad #5', $tester->getDisplay());
        $this->assertSame(0, $command->applyCalls);
        $this->assertFalse($command->getDefinition()->hasOption('force'));
    }

    public function testAppliesAndRunsChecksWithJsonReport(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->checks = [
            new StubVerifyCheck('a', null),
            new StubVerifyCheck('b', CheckResult::skipped('b', 'Kontrola b', 'nic k ověření')),
        ];
        $tester = $this->runVerify($command, ['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(1, $command->applyCalls);
        $this->assertSame(['MSG-EUR' => 'kurz — #121'], $command->skippedAtApply, 'cizí měna se přeskočí, hotový doklad ne');
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['name' => 'cz-test', 'profile' => 'vat-payer', 'country' => 'cz'], $report['dataset']);
        $this->assertSame('test-ds-id', $report['dataSource']);
        $this->assertTrue($report['apply']['ran']);
        $this->assertSame(['MSG-1'], $report['apply']['applied']);
        $this->assertSame(['MSG-EUR' => 'kurz — #121'], $report['apply']['skipped']);
        $this->assertSame(3, $report['apply']['itemsBefore']);
        $this->assertSame(['ok', 'skipped'], array_column($report['checks'], 'status'));
        $this->assertSame('nic k ověření', $report['checks'][1]['reason']);
        $this->assertSame(['ok' => 1, 'diff' => 0, 'skipped' => 1], $report['summary']);
        $this->assertSame(0, $report['exitCode']);
        $this->assertNotNull($command->ctx);
        $this->assertSame('CZK', $command->ctx->homeCurrency);
        $this->assertSame([77], $command->ctx->appliedDocIds());
    }

    public function testNoApplyOnlyChecks(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->checks = [new StubVerifyCheck('a', null)];
        $tester = $this->runVerify($command, ['--no-apply' => true, '--write-ledger-snapshot' => '/tmp/x.json']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(0, $command->applyCalls);
        $this->assertFalse($command->ctx?->apply->ran);
        $this->assertSame('/tmp/x.json', $command->ctx?->ledgerSnapshotOut);
        $this->assertStringContainsString('vynecháno (--no-apply)', $tester->getDisplay());
        $this->assertStringContainsString('V pořádku.', $tester->getDisplay());
    }

    public function testDifferenceGivesExitOne(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->checks = [new StubVerifyCheck('a', CheckResult::fromDifferences('a', 'Kontrola a', ['MSG-1: rows[0].unit: očekáváno "ks", skutečnost "pcs"']))];
        $tester = $this->runVerify($command);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('rozdíl (1)', $tester->getDisplay());
        $this->assertStringContainsString('- MSG-1: rows[0].unit', $tester->getDisplay());
        $this->assertStringContainsString('Rozdíly.', $tester->getDisplay());
    }

    public function testApplyFailureGivesExitOneEvenWhenChecksPass(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->applyResult = new ApplyReport(true, [], [['messageId' => 'MSG-1', 'code' => 'VALIDATION', 'message' => 'exchange_rate required']]);
        $command->checks = [new StubVerifyCheck('a', null)];
        $tester = $this->runVerify($command);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('selhalo MSG-1: VALIDATION exchange_rate required', $tester->getDisplay());
    }

    public function testCheckExceptionIsReportedAsDifference(): void
    {
        $this->writeSet();
        $command = $this->command();
        $command->checks = [new StubVerifyCheck('boom', null, new \RuntimeException('report nejde spustit')), new StubVerifyCheck('b', null)];
        $tester = $this->runVerify($command, ['--json' => true]);
        $this->assertSame(1, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['diff', 'ok'], array_column($report['checks'], 'status'));
        $this->assertSame(['kontrola selhala: report nejde spustit'], $report['checks'][0]['differences']);
    }
}
