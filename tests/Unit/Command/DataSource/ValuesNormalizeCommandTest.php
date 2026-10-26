<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ValuesNormalizeCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Server\DataSourceModules;
use Shipard\Module\Base\Persons\Values\DirectoryValuesNormalizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Runner s pevným reportem — zapamatuje si, zda běžel jako dry-run. */
final class StubDirectoryValuesNormalizer extends DirectoryValuesNormalizer
{
    public ?bool $dryRun = null;

    /** @param array<string, mixed> $report */
    public function __construct(private readonly array $report)
    {
    }

    public function run(bool $dryRun): array
    {
        $this->dryRun = $dryRun;
        return ['dryRun' => $dryRun] + $this->report;
    }
}

final class TestableValuesNormalizeCommand extends ValuesNormalizeCommand
{
    public ?bool $ownAccounts = null;

    public function __construct(
        ?DataSourceConfig $dsConfig,
        ?DataSourceConnection $dsConnection,
        ?DataSourceModules $modules,
        private readonly string $dsDir,
        private readonly ?StubDirectoryValuesNormalizer $normalizer = null,
    ) {
        parent::__construct($dsConfig, $dsConnection, $modules);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function createNormalizer(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, bool $ownAccounts): DirectoryValuesNormalizer
    {
        $this->ownAccounts = $ownAccounts;
        return $this->normalizer ?? throw new \LogicException('bez runneru');
    }
}

/**
 * `shpd-ds values-normalize` (#118 D22): textový výpis po tabulkách a sloupcích
 * s přesuny IBAN a nevalidními hodnotami bez hodnot, `--json`, předání
 * `--dry-run` runneru, vlastní účty jen s aktivním `economy.codebooks`,
 * mimo adresář zdroje dat selže.
 */
class ValuesNormalizeCommandTest extends TestCase
{
    private const REPORT = [
        'tables' => [
            'base_persons_persons' => ['rows' => 120, 'changed' => 7, 'columns' => ['company_id' => 2, 'phone' => 7]],
            'base_persons_addresses' => ['rows' => 80, 'changed' => 0, 'columns' => []],
            'base_persons_contacts' => ['rows' => 40, 'changed' => 1, 'columns' => ['email' => 1]],
            'base_persons_bank_accounts' => ['rows' => 30, 'changed' => 9, 'columns' => ['account_number' => 9, 'iban' => 9], 'ibanMoved' => 9],
            'economy_codebooks_bank_accounts' => ['skipped' => 'module_inactive'],
        ],
        'invalid' => [
            ['table' => 'base_persons_persons', 'id' => 12, 'column' => 'company_id', 'code' => 'company_id_invalid'],
            ['table' => 'base_persons_bank_accounts', 'id' => 7, 'column' => 'iban', 'code' => 'iban_invalid'],
        ],
        'totals' => ['rows' => 270, 'changed' => 17, 'invalid' => 2],
    ];

    /** @return array{0: CommandTester, 1: TestableValuesNormalizeCommand, 2: StubDirectoryValuesNormalizer} */
    private function tester(bool $codebooksActive = true): array
    {
        $modules = $this->createMock(DataSourceModules::class);
        $modules->method('isActive')->willReturnCallback(
            static fn (string $dsDir, string $moduleId): bool => $moduleId === 'economy.codebooks' && $codebooksActive,
        );
        $normalizer = new StubDirectoryValuesNormalizer(self::REPORT);
        $command = new TestableValuesNormalizeCommand(
            $this->createMock(DataSourceConfig::class),
            $this->createMock(DataSourceConnection::class),
            $modules,
            '/nonexistent/ds',
            $normalizer,
        );
        return [new CommandTester($command), $command, $normalizer];
    }

    public function testTextOutputListsTablesMovesAndInvalidValues(): void
    {
        [$tester, $command, $normalizer] = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        $this->assertFalse($normalizer->dryRun);
        $this->assertTrue($command->ownAccounts);
        $this->assertStringContainsString('base_persons_persons: 120 záznamů, 7 změněno (company_id 2, phone 7)', $display);
        $this->assertStringContainsString("base_persons_addresses: 80 záznamů, 0 změněno\n", $display);
        $this->assertStringContainsString('base_persons_bank_accounts: 30 záznamů, 9 změněno (account_number 9, iban 9); IBAN přesunut z čísla účtu: 9', $display);
        $this->assertStringContainsString('economy_codebooks_bank_accounts: přeskočeno — modul economy.codebooks není aktivní', $display);
        $this->assertStringContainsString('Celkem: 270 záznamů, 17 změněno', $display);
        $this->assertStringContainsString("Nevalidní hodnoty (k ruční opravě): 2\n  base_persons_persons · 12 · company_id · company_id_invalid\n  base_persons_bank_accounts · 7 · iban · iban_invalid", $display);
        $this->assertStringNotContainsString('dry-run', $display);
    }

    public function testDryRunIsPassedToTheRunnerAndAnnounced(): void
    {
        [$tester, , $normalizer] = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        $this->assertTrue($normalizer->dryRun);
        $this->assertStringContainsString('(dry-run, nic se nezapsalo)', $tester->getDisplay());
    }

    public function testJsonOutputIsTheReport(): void
    {
        [$tester] = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true, '--dry-run' => true]));
        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertSame(['dryRun' => true] + self::REPORT, $decoded);
    }

    public function testInactiveCodebooksModuleSkipsOwnAccounts(): void
    {
        [$tester, $command] = $this->tester(codebooksActive: false);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertFalse($command->ownAccounts);
    }

    public function testFailsOutsideDataSourceDirectory(): void
    {
        $dir = sys_get_temp_dir() . '/shpd-values-normalize-' . uniqid();
        mkdir($dir);
        try {
            $tester = new CommandTester(new TestableValuesNormalizeCommand(null, null, null, $dir));
            $this->assertSame(Command::FAILURE, $tester->execute([]));
            $this->assertStringContainsString('chybí config/main.json', $tester->getDisplay());
        } finally {
            rmdir($dir);
        }
    }
}
