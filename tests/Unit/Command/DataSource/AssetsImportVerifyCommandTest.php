<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AssetsImportVerifyCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\Import\AssetImportVerifier;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetPlanService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Ověřovač s pevným výsledkem. */
class StubAssetImportVerifier extends AssetImportVerifier
{
    /** @param array<string, mixed> $result */
    public function __construct(public readonly array $result, public ?string $asked = null)
    {
        parent::__construct(new TestAssetPlanService(), null);
    }

    public function run(?string $assetNumber = null): array
    {
        $this->asked = $assetNumber;
        return $this->result;
    }
}

class TestableAssetsImportVerifyCommand extends AssetsImportVerifyCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        public readonly StubAssetImportVerifier $verifier,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return '/nonexistent';
    }

    protected function createVerifier(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, string $dsDir): AssetImportVerifier
    {
        return $this->verifier;
    }
}

/**
 * `assets-import-verify` (docs/assets.md D82): textový výpis, `--json`,
 * filtr `--asset`, exit 0 bez rozdílů a 1 s rozdíly.
 */
class AssetsImportVerifyCommandTest extends TestCase
{
    /** @param array<string, mixed> $result */
    private function tester(array $result, ?StubAssetImportVerifier &$verifier = null): CommandTester
    {
        $verifier = new StubAssetImportVerifier($result);
        $command = new TestableAssetsImportVerifyCommand(
            $this->createMock(DataSourceConfig::class),
            $this->createMock(DataSourceConnection::class),
            $verifier,
        );
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('assets-import-verify'));
    }

    /** @return array<string, mixed> */
    private function outcome(bool $ok): array
    {
        return [
            'tax' => $ok ? [] : [[
                'assetId' => 1, 'number' => 'MA0001', 'name' => 'Stroj', 'year' => '2023-01-01', 'periodEnd' => '2023-12-31',
                'imported' => 22000.0, 'computed' => 22250.0, 'difference' => -250.0, 'halfYear' => false, 'claimUnrecorded' => true,
            ]],
            'planErrors' => [],
            'accounting' => $ok ? [] : [['assetId' => 1, 'number' => 'MA0001', 'name' => 'Stroj', 'year' => '2024', 'evidence' => 0.0, 'journal' => 22250.0, 'difference' => -22250.0]],
            'journalCheck' => [['year' => '2024', 'status' => $ok ? 'ok' : 'errors', 'codes' => $ok ? [] : ['assets.journalCheck.accountMismatch' => 2]]],
            'accPeriodElapsed' => $ok ? [] : [[
                'assetId' => 2, 'number' => 'MA0002', 'name' => 'Budova', 'end' => '2022-12-31', 'periodEnd' => '2024-12-31', 'amount' => 10500000.0,
            ]],
            'summary' => ['cards' => 1, 'taxChecked' => 2, 'taxDifferences' => $ok ? 0 : 1, 'planErrors' => 0, 'accChecked' => 2,
                'accDifferences' => $ok ? 0 : 1, 'checkYears' => 1, 'checkErrors' => $ok ? 0 : 1, 'accPeriodElapsed' => $ok ? 0 : 1],
            'ok' => $ok,
        ];
    }

    public function testCleanRunPrintsSectionsAndSucceeds(): void
    {
        $tester = $this->tester($this->outcome(true), $verifier);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1. Zlatý test daňového okruhu', $display);
        $this->assertStringContainsString('2024  ok', $display);
        $this->assertStringContainsString('4. Uplynulá doba účetního odpisování', $display);
        $this->assertStringContainsString('karet s varováním: 0', $display);
        $this->assertStringContainsString('V pořádku.', $display);
        $this->assertNull($verifier->asked);
    }

    public function testDifferencesFailWithDetails(): void
    {
        $tester = $this->tester($this->outcome(false), $verifier);

        $this->assertSame(Command::FAILURE, $tester->execute(['--asset' => 'MA0001']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('MA0001', $display);
        $this->assertStringContainsString('(neuplatněno)', $display);
        $this->assertStringContainsString('assets.journalCheck.accountMismatch: 2', $display);
        $this->assertStringContainsString('MA0002       Budova                         doba do 2022-12-31  plán 2024  částka  10 500 000,00', $display);
        $this->assertStringContainsString('Rozdíly.', $display);
        $this->assertStringContainsString('uplynulá doba 1', $display);
        $this->assertSame('MA0001', $verifier->asked);
    }

    public function testJsonOutput(): void
    {
        $tester = $this->tester($this->outcome(false));

        $this->assertSame(Command::FAILURE, $tester->execute(['--json' => true]));
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertFalse($decoded['ok']);
        $this->assertSame(-250.0, $decoded['tax'][0]['difference']);
        $this->assertSame(['assets.journalCheck.accountMismatch' => 2], $decoded['journalCheck'][0]['codes']);
    }
}
