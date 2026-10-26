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
use Symfony\Component\Console\Output\OutputInterface;
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

    /** @var list<string>|null */
    public ?array $only = null;

    public ?bool $verbose = null;

    public bool $normalizerCreated = false;

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

    protected function createNormalizer(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, bool $ownAccounts, ?array $only, bool $verbose): DirectoryValuesNormalizer
    {
        $this->ownAccounts = $ownAccounts;
        $this->only = $only;
        $this->verbose = $verbose;
        $this->normalizerCreated = true;
        return $this->normalizer ?? throw new \LogicException('bez runneru');
    }
}

/**
 * `shpd-ds values-normalize` (#118 D22): textový výpis po tabulkách a sloupcích
 * s přesuny IBAN a nevalidními hodnotami bez hodnot, `--json`, předání
 * `--dry-run` runneru, vlastní účty jen s aktivním `economy.codebooks`,
 * mimo adresář zdroje dat selže; `--only` a podrobný výpis `-v` (D27).
 */
class ValuesNormalizeCommandTest extends TestCase
{
    /** Report runneru s podrobným výpisem (`verbose`) — změny a nálezy s hodnotami. */
    private const VERBOSE_REPORT = [
        'verbose' => true,
        'tables' => [
            'base_persons_persons' => ['rows' => 2, 'changed' => 1, 'columns' => ['phone' => 1]],
            'base_persons_addresses' => ['skipped' => 'not_selected'],
            'base_persons_contacts' => ['rows' => 0, 'changed' => 0, 'columns' => []],
            'base_persons_bank_accounts' => ['rows' => 2, 'changed' => 1, 'columns' => ['account_number' => 1, 'iban' => 1], 'ibanMoved' => 1],
            'economy_codebooks_bank_accounts' => ['rows' => 1, 'changed' => 1, 'columns' => ['bic' => 1], 'ibanMoved' => 0],
        ],
        'changes' => [
            [
                'table' => 'base_persons_persons', 'id' => 1, 'person' => ['id' => 1, 'code' => 'F001', 'name' => 'Acme s.r.o.'], 'account' => null, 'ibanMoved' => false,
                'columns' => ['phone' => ['from' => '577 123 456', 'to' => '+420577123456']],
            ],
            [
                'table' => 'base_persons_bank_accounts', 'id' => 30, 'person' => ['id' => 1, 'code' => 'F001', 'name' => 'Acme s.r.o.'], 'account' => null, 'ibanMoved' => true,
                'columns' => ['account_number' => ['from' => 'CZ65 0800 0000 1920 0014 5399', 'to' => '19-2000145399/0800'], 'iban' => ['from' => null, 'to' => 'CZ6508000000192000145399']],
            ],
            [
                'table' => 'economy_codebooks_bank_accounts', 'id' => 40, 'person' => null, 'account' => ['code' => 'HLAVNI', 'name' => 'Hlavní účet'], 'ibanMoved' => false,
                'columns' => ['bic' => ['from' => 'gibaczpx', 'to' => 'GIBACZPX']],
            ],
        ],
        'invalid' => [
            [
                'table' => 'base_persons_persons', 'id' => 2, 'column' => 'company_id', 'code' => 'company_id_invalid',
                'person' => ['id' => 2, 'code' => 'F002', 'name' => 'Překlep s.r.o.'], 'account' => null,
                'value' => '52654151', 'reason' => 'nemá platný kontrolní součet', 'suggestion' => '52654150',
            ],
            [
                'table' => 'economy_codebooks_bank_accounts', 'id' => 40, 'column' => 'iban', 'code' => 'iban_invalid',
                'person' => null, 'account' => ['code' => 'HLAVNI', 'name' => 'Hlavní účet'],
                'value' => 'CZ6508000000192000145398', 'reason' => 'nemá platnou kontrolní číslici', 'suggestion' => null,
            ],
        ],
        'totals' => ['rows' => 5, 'changed' => 3, 'invalid' => 2],
    ];

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

    /**
     * @param array<string, mixed>|null $report
     * @return array{0: CommandTester, 1: TestableValuesNormalizeCommand, 2: StubDirectoryValuesNormalizer}
     */
    private function tester(bool $codebooksActive = true, ?array $report = null): array
    {
        $modules = $this->createMock(DataSourceModules::class);
        $modules->method('isActive')->willReturnCallback(
            static fn (string $dsDir, string $moduleId): bool => $moduleId === 'economy.codebooks' && $codebooksActive,
        );
        $normalizer = new StubDirectoryValuesNormalizer($report ?? self::REPORT);
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

    public function testDefaultRunPassesNeitherOnlyNorVerbose(): void
    {
        [$tester, $command] = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertNull($command->only);
        $this->assertFalse($command->verbose);
        $this->assertStringNotContainsString('Jen druhy', $tester->getDisplay());
        $this->assertStringNotContainsString('Změny', $tester->getDisplay());
    }

    public function testOnlyIsParsedAndPassedToTheRunner(): void
    {
        [$tester, $command] = $this->tester(report: ['only' => ['phone', 'zip']] + self::REPORT);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--only' => ' phone, zip ']));
        $this->assertSame(['phone', 'zip'], $command->only);
        $this->assertStringContainsString("Jen druhy: phone, zip\n", $tester->getDisplay());
    }

    public function testUnknownKindIsACallError(): void
    {
        [$tester, $command, $normalizer] = $this->tester();

        $this->assertSame(Command::INVALID, $tester->execute(['--only' => 'phone,xyz']));
        $this->assertStringContainsString('Neznámý druh hodnot „xyz“ — povolené: phone, email, web, zip, ids, accounts.', $tester->getDisplay());
        $this->assertFalse($command->normalizerCreated, 'runner se nevolá');
        $this->assertNull($normalizer->dryRun);

        $this->assertSame(Command::INVALID, $tester->execute(['--only' => ' , ']), 'prázdný seznam');
    }

    public function testVerboseTextListsChangesAndFindingsWithValues(): void
    {
        [$tester, $command] = $this->tester(report: self::VERBOSE_REPORT);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]));
        $this->assertTrue($command->verbose);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("base_persons_addresses: přeskočeno — mimo --only\n", $display);
        $this->assertStringContainsString(
            "Změny (3):\n"
            . "  base_persons_persons · 1 · osoba 1 F001 „Acme s.r.o.“ · phone „577 123 456“ → „+420577123456“\n"
            . "  base_persons_bank_accounts · 30 · osoba 1 F001 „Acme s.r.o.“ · IBAN přesunut z čísla účtu: account_number „CZ65 0800 0000 1920 0014 5399“ → „19-2000145399/0800“; iban „“ → „CZ6508000000192000145399“\n"
            . "  economy_codebooks_bank_accounts · 40 · účet HLAVNI „Hlavní účet“ · bic „gibaczpx“ → „GIBACZPX“\n",
            $display,
        );
        $this->assertStringContainsString(
            "Nevalidní hodnoty (k ruční opravě): 2\n"
            . "  base_persons_persons · 2 · osoba 2 F002 „Překlep s.r.o.“ · company_id · company_id_invalid · „52654151“ — nemá platný kontrolní součet · návrh: „52654150“\n"
            . "  economy_codebooks_bank_accounts · 40 · účet HLAVNI „Hlavní účet“ · iban · iban_invalid · „CZ6508000000192000145398“ — nemá platnou kontrolní číslici\n"
            . "(dry-run, nic se nezapsalo)\n",
            $display,
        );
    }

    public function testDefaultOutputCarriesNoValues(): void
    {
        [$tester] = $this->tester(report: self::VERBOSE_REPORT);

        // Runner bez `verbose` hodnoty nevrací; příkaz je ani sám nedoplní —
        // kontrola nad výpisem pro jistotu i s reportem, který by je nesl.
        $this->assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));
        $this->assertTrue(json_decode($tester->getDisplay(), true)['verbose'], 'JSON je report runneru beze změny');

        [$tester] = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        foreach (['Acme', 'F001', '577 123 456', 'návrh', 'Změny'] as $secret) {
            $this->assertStringNotContainsString($secret, $tester->getDisplay());
        }
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
