<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AccbalRegenerateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * accbal-regenerate bez DB: validace rozsahu (bez --all/--doc/--fiscal-year
 * nic nedělá) a výběr zdrojů (sjednocení deník ∪ ledger, filtr období,
 * deterministické pořadí). Běh nad reálným DS kryje integrační
 * AccbalRegenerateCommandTest.
 */
class AccbalRegenerateCommandTest extends TestCase
{
    private function tester(): CommandTester
    {
        // Mocky se při chybě rozsahu nesmí dotknout — createMock bez očekávání
        // by na volání nespadl, proto se ověřuje výstup + exit kód.
        $config = $this->createMock(DataSourceConfig::class);
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('getDibiConnection');
        return new CommandTester(new AccbalRegenerateCommand($config, $db));
    }

    public function testRequiresScope(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Vyžaduje --all, --doc <id> nebo --fiscal-year <id>', $tester->getDisplay());
    }

    public function testDryRunAloneIsNotAScope(): void
    {
        $tester = $this->tester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--dry-run' => true]));
    }

    public function testRejectsNonNumericIds(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute(['--doc' => 'abc']));
        $this->assertStringContainsString('--doc musí být kladné celé číslo', $tester->getDisplay());

        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute(['--fiscal-year' => '0']));
        $this->assertStringContainsString('--fiscal-year musí být kladné celé číslo', $tester->getDisplay());
    }

    // ── listSources ─────────────────────────────────────────────────────────

    /** @return array{0: \Dibi\Connection, 1: array<int, list<mixed>>} */
    private function dbReturning(array $journalRows, array $ledgerRows): array
    {
        $calls = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use (&$calls, $journalRows, $ledgerRows): array {
            $calls[] = $args;
            return count($calls) === 1 ? $journalRows : $ledgerRows;
        });
        return [$db, &$calls];
    }

    public function testListSourcesUnionsJournalAndLedgerSorted(): void
    {
        [$db] = $this->dbReturning(
            [
                ['kind' => 'doc', 'id' => 20],
                ['kind' => 'bankTransaction', 'id' => 5],
                ['kind' => 'doc', 'id' => 7],
                ['kind' => 'doc', 'id' => null],   // řádek deníku bez zdroje — ignorovat
            ],
            [
                ['kind' => 'doc', 'id' => 7],      // duplicitní s deníkem
                ['kind' => 'doc', 'id' => 99],     // osiřelý pohyb bez deníku → musí být ve výběru
            ],
        );

        $sources = AccbalRegenerateCommand::listSources($db, null);

        $this->assertSame([
            ['kind' => 'bankTransaction', 'id' => 5],
            ['kind' => 'doc', 'id' => 7],
            ['kind' => 'doc', 'id' => 20],
            ['kind' => 'doc', 'id' => 99],
        ], $sources);
    }

    public function testListSourcesPassesFiscalYearToBothQueries(): void
    {
        $calls = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use (&$calls): array {
            $calls[] = $args;
            return [];
        });

        AccbalRegenerateCommand::listSources($db, 7);

        $this->assertCount(2, $calls, 'deník + ledger');
        foreach ($calls as $args) {
            $this->assertStringContainsString('WHERE [fiscal_year] = %i', (string) $args[0]);
            $this->assertSame(7, end($args), 'id období jako poslední parametr');
        }
        $this->assertStringContainsString('economy_accounting_journal', (string) $calls[0][0]);
        $this->assertStringContainsString('economy_accbal_ledger', (string) $calls[1][0]);
    }
}
