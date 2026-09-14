<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Command\DataSource\AccbalRegenerateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAccbalRegenerateCommand extends AccbalRegenerateCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

/**
 * `shpd-ds accbal-regenerate` nad reálným DS (#69 D13, docs/accbal.md §4.6):
 * slitý pohyb z doby před D13 (movement_key NULL, dvě identity v jednom
 * řádku) se rozdělí, dry-run nic nezapíše, výběr zdrojů podle období bere
 * i osiřelý pohyb bez deníku a smaže ho.
 */
class AccbalRegenerateCommandTest extends IntegrationTestCase
{
    /** Neexistující fiskální rok — deník ani ledger na něj FK nemá, izoluje fixturu od zbytku DS. */
    private const FISCAL_YEAR = 987_654_321;

    /** @var list<int> */
    private array $docIds = [];

    private int $seq = 0;

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->docIds as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
        }
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new TestableAccbalRegenerateCommand($this->dsConfig, $this->db, $this->realDsPath));
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    private function newDocId(): int
    {
        $id = 920_000_000 + (++$this->seq);
        $this->docIds[] = $id;
        return $id;
    }

    private function insertReceivable(int $docId, int $partner, string $vs, float $amount): void
    {
        $this->db->getDibiConnection()->insert('economy_accounting_journal', [
            'source_kind'       => 'doc',
            'doc_head'          => $docId,
            'accounting_date'   => '2026-06-10',
            'fiscal_year'       => self::FISCAL_YEAR,
            'account_number'    => '311100',
            'money_dr'          => $amount,
            'money_cr'          => 0,
            'money_dr_cur'      => $amount,
            'money_cr_cur'      => 0,
            'currency'          => 'czk',
            'partner'           => $partner,
            'payment_reference' => $vs,
            'is_error'          => 0,
        ])->execute();
    }

    /** Pohyb ve tvaru z doby před D13 (bez klíče). */
    private function insertLegacyMovement(int $docId, float $amount): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_ledger', [
            'balance'           => $this->balanceId('receivables'),
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $docId,
            'doc_head'          => $docId,
            'movement_key'      => null,
            'account_number'    => '311100',
            'fiscal_year'       => self::FISCAL_YEAR,
            'partner'           => 1,
            'payment_reference' => 'VS1',
            'currency'          => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    /** @return list<array<string, mixed>> */
    private function ledgerOf(int $docId): array
    {
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accbal_ledger WHERE source_kind = %s AND doc_head = %i ORDER BY id',
            'doc',
            $docId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    /** Doklad se dvěma identitami slitý do jednoho pohybu. */
    private function mergedFixture(): array
    {
        $docId = $this->newDocId();
        $this->insertReceivable($docId, 1, 'VS1', 100.00);
        $this->insertReceivable($docId, 2, 'VS2', 200.00);
        $legacyId = $this->insertLegacyMovement($docId, 300.00);
        return [$docId, $legacyId];
    }

    public function testDryRunCountsButWritesNothing(): void
    {
        [$docId, $legacyId] = $this->mergedFixture();

        $tester = $this->tester();
        $exit = $tester->execute(['--doc' => (string) $docId, '--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Zdrojů: 1 (doc: 1)', $display);
        $this->assertStringContainsString('plán — vloženo: 2, aktualizováno: 0, smazáno: 1', $display);
        $this->assertStringContainsString('dry-run', $display);
        $ledger = $this->ledgerOf($docId);
        $this->assertCount(1, $ledger, 'dry-run nic nezapsal');
        $this->assertSame($legacyId, (int) $ledger[0]['id']);
        $this->assertNull($ledger[0]['movement_key']);
    }

    public function testDocSplitsMergedMovement(): void
    {
        [$docId, $legacyId] = $this->mergedFixture();

        $tester = $this->tester();
        $exit = $tester->execute(['--doc' => (string) $docId], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString("doc #{$docId}: +2 ~0 -1", $display, '-v vypíše zdroj se změnou');
        $this->assertStringContainsString('pohyby — vloženo: 2, aktualizováno: 0, smazáno: 1', $display);
        $ledger = $this->ledgerOf($docId);
        $this->assertCount(2, $ledger, 'slitý pohyb rozdělen per identita');
        $this->assertNotContains($legacyId, array_map(fn($m) => (int) $m['id'], $ledger));
        $this->assertSame(['VS1', 'VS2'], array_column($ledger, 'payment_reference'));
        $this->assertEqualsWithDelta(300.00, array_sum(array_map(fn($m) => (float) $m['amount'], $ledger)), 0.001);
        foreach ($ledger as $m) {
            $this->assertNotNull($m['movement_key']);
        }

        // Druhý běh je idempotentní: nic vloženo, nic smazáno, id drží.
        $ids = array_map(fn($m) => (int) $m['id'], $ledger);
        $tester = $this->tester();
        $tester->execute(['--doc' => (string) $docId]);
        $this->assertStringContainsString('vloženo: 0, aktualizováno: 2, smazáno: 0', $tester->getDisplay());
        $this->assertSame($ids, array_map(fn($m) => (int) $m['id'], $this->ledgerOf($docId)));
    }

    public function testFiscalYearScopeIncludesOrphanLedgerSource(): void
    {
        [$docId] = $this->mergedFixture();
        // Osiřelý pohyb: zdroj v ledgeru, ale bez deníku (doklad opustil stav 40
        // a událost se ztratila) — výběr podle období ho musí najít a smazat.
        $orphanDocId = $this->newDocId();
        $this->insertLegacyMovement($orphanDocId, 50.00);

        $sources = AccbalRegenerateCommand::listSources($this->db->getDibiConnection(), self::FISCAL_YEAR);
        $this->assertSame([
            ['kind' => 'doc', 'id' => $docId],
            ['kind' => 'doc', 'id' => $orphanDocId],
        ], $sources);
        $this->assertSame([], AccbalRegenerateCommand::listSources($this->db->getDibiConnection(), self::FISCAL_YEAR + 1), 'cizí období = nic');

        $tester = $this->tester();
        $exit = $tester->execute(['--fiscal-year' => (string) self::FISCAL_YEAR]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('Zdrojů: 2 (doc: 2)', $tester->getDisplay());
        $this->assertStringContainsString('vloženo: 2, aktualizováno: 0, smazáno: 2', $tester->getDisplay());
        $this->assertSame([], $this->ledgerOf($orphanDocId), 'osiřelý pohyb smazán');
        $this->assertCount(2, $this->ledgerOf($docId));
    }

    public function testAllCoversFixtureWithoutTouchingIds(): void
    {
        // --all nad celým DS: fixtura se dostane do výběru; přes ostatní zdroje
        // je běh idempotentní (druhý průchod nic nevkládá ani nemaže).
        [$docId] = $this->mergedFixture();

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--all' => true]), $tester->getDisplay());
        $this->assertCount(2, $this->ledgerOf($docId));

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['--all' => true, '--dry-run' => true]));
        $this->assertMatchesRegularExpression('/plán — vloženo: 0, aktualizováno: \d+, smazáno: 0/', $tester->getDisplay());
    }
}
