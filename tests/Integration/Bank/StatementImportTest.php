<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Bank;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Bank\Checks\StatementReconciliationCheck;
use Shipard\Module\Economy\Bank\Import\StatementImportService;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Fáze 2: import výpisu ze souboru — parsing, dedup idempotence (external_id /
 * fingerprint), zůstatkový můstek, dohledání partnera, match účtu, charset.
 */
class StatementImportTest extends IntegrationTestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/Bank';

    private ?ConfigRuntime $configRuntime = null;
    private ?\Dibi\Connection $dibi = null;
    private int $bankAccountId = 0;
    /** @var int[] */
    private array $personBankAccountIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->dibi = $this->db->getDibiConnection();

        // Vlastní bankovní spojení, které matchne všechny tři formáty:
        // CAMT i FIO (bez bankId) přes IBAN, GPC přes předčíslí a číslo
        // tuzemského čísla účtu — hlavička GPC kód banky nenese (#118 D25),
        // proto bez `ebanking_id`.
        $this->dibi->insert('economy_codebooks_bank_accounts', [
            'code'           => 'ITF2-' . substr((string) abs(crc32($this->name())), 0, 5),
            'name'           => 'IT F2 účet',
            'currency'       => 'czk',
            'account_number' => '19-2000145399/0800',
            'iban'           => 'CZ6508000000192000145399',
            'is_default'     => 0,
            'sort_order'     => 0,
            'docState'       => 40,
            'docStateMain'   => 3,
        ])->execute();
        $this->bankAccountId = (int) $this->dibi->getInsertId();
    }

    protected function onTearDown(): void
    {
        if ($this->bankAccountId > 0) {
            $stmtIds = $this->dibi->query(
                'SELECT [id] FROM [economy_bank_statements] WHERE [bank_account] = %i',
                $this->bankAccountId,
            )->fetchPairs(null, 'id');
            foreach ($stmtIds as $sid) {
                $this->dibi->delete('core_attachments_files')
                    ->where('[table_id] = %i AND [record_id] = %i', 415, (int) $sid)->execute();
            }
            $this->dibi->delete('economy_bank_transactions')->where('[bank_account] = %i', $this->bankAccountId)->execute();
            $this->dibi->delete('economy_bank_statements')->where('[bank_account] = %i', $this->bankAccountId)->execute();
            $this->dibi->delete('economy_codebooks_bank_accounts')->where('[id] = %i', $this->bankAccountId)->execute();
        }
        foreach ($this->personBankAccountIds as $id) {
            $this->dibi->delete('base_persons_bank_accounts')->where('[id] = %i', $id)->execute();
        }
    }

    private function service(): StatementImportService
    {
        // Refaktorované apply jádro vzniká transakce přes dokumentovou vrstvu;
        // service se staví factory create() (registry + gateway).
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $this->dibi, $this->configRuntime);
        return StatementImportService::create(
            $this->dibi,
            $this->configRuntime,
            $this->dsConfig,
            $registry,
            $this->tables,
            $dispatcher,
            null,
        );
    }

    private function fixture(string $name): string
    {
        return file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function txCount(): int
    {
        return (int) $this->dibi->query(
            'SELECT COUNT(*) FROM [economy_bank_transactions] WHERE [bank_account] = %i',
            $this->bankAccountId,
        )->fetchSingle();
    }

    // ── CAMT ────────────────────────────────────────────────────────────────

    public function testCamtImportCreatesStatementAndTransactions(): void
    {
        $summary = $this->service()->import($this->fixture('camt053.xml'));

        $this->assertSame('cz.cba-xml', $summary['format']);
        $this->assertSame(2, $summary['created']);
        $this->assertSame(0, $summary['skipped']);
        $this->assertCount(1, $summary['statements']);
        $this->assertSame(1, $summary['statements'][0]['reconciliation']); // 1000+1210-50=2160
        $this->assertSame(2, $this->txCount());

        $rows = $this->dibi->query(
            'SELECT * FROM [economy_bank_transactions] WHERE [bank_account] = %i ORDER BY [date_transaction]',
            $this->bankAccountId,
        )->fetchAll();
        $this->assertSame(1, (int) $rows[0]['direction']);
        $this->assertEqualsWithDelta(1210.0, (float) $rows[0]['amount'], 0.001);
        $this->assertSame('payment.in', $rows[0]['operation']);
        $this->assertSame(10, (int) $rows[0]['docState']);
        $this->assertSame(2, (int) $rows[1]['direction']);
        $this->assertSame('payment.out', $rows[1]['operation']);
    }

    public function testImportIsIdempotent(): void
    {
        $this->service()->import($this->fixture('camt053.xml'));
        $second = $this->service()->import($this->fixture('camt053.xml'));

        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame(2, $this->txCount(), 'Re-import nesmí zdvojit transakce');
    }

    public function testReconciliationMismatchOnBrokenClosing(): void
    {
        $broken = str_replace('2160.00', '9999.00', $this->fixture('camt053.xml'));
        $summary = $this->service()->import($broken);

        $this->assertSame(2, $summary['statements'][0]['reconciliation']);
    }

    public function testUnknownAccountReportedAsError(): void
    {
        $foreign = str_replace('CZ6508000000192000145399', 'CZ9900000000000000000000', $this->fixture('camt053.xml'));
        $summary = $this->service()->import($foreign);

        $this->assertSame(0, $summary['created']);
        $this->assertArrayHasKey('error', $summary['statements'][0]);
        $this->assertStringContainsString('není v žádném', $summary['statements'][0]['error']);
        $this->assertSame(0, $this->txCount());
    }

    public function testArchivedAccountMatchedOnImport(): void
    {
        // Archivní účet (70) je odkazovatelný — výpis k později archivovanému
        // účtu je historické datum, ref-matching ho nesmí vynechat.
        $this->dibi->update('economy_codebooks_bank_accounts', [
            'docState'     => 70,
            'docStateMain' => 4,
        ])->where('[id] = %i', $this->bankAccountId)->execute();

        $summary = $this->service()->import($this->fixture('camt053.xml'));

        $this->assertSame(2, $summary['created'], 'import na archivní účet projde');
        $this->assertArrayNotHasKey('error', $summary['statements'][0]);
        $this->assertSame(2, $this->txCount());
    }

    public function testPartnerMatchedByCounterpartyAccount(): void
    {
        $person = $this->dibi->query('SELECT [id] FROM [base_persons_persons] ORDER BY [id] LIMIT 1')->fetch();
        if ($person === null || $person === false) {
            $this->markTestSkipped('Dev DS nemá žádnou osobu');
        }
        $personId = (int) $person['id'];

        $this->dibi->insert('base_persons_bank_accounts', [
            'person'         => $personId,
            'name'           => 'IT F2 protistrana',
            'account_number' => '123456789/0800',
            'currency'       => 'czk',
            'source'         => 1,
            'order_pos'      => 0,
            'docState'       => 40,
            'docStateMain'   => 3,
        ])->execute();
        $this->personBankAccountIds[] = (int) $this->dibi->getInsertId();

        $this->service()->import($this->fixture('camt053.xml'));

        $partner = $this->dibi->query(
            'SELECT [partner] FROM [economy_bank_transactions]'
            . ' WHERE [bank_account] = %i AND [counterparty_account] = %s',
            $this->bankAccountId,
            '123456789/0800',
        )->fetchSingle();
        $this->assertSame($personId, (int) $partner);
    }

    // ── FIO (dedup přes external_id) ──────────────────────────────────────────

    public function testFioDedupByExternalId(): void
    {
        $this->service()->import($this->fixture('fio.json'));
        $second = $this->service()->import($this->fixture('fio.json'));

        $this->assertSame(2, $this->txCount());
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);

        $ext = $this->dibi->query(
            'SELECT [external_id] FROM [economy_bank_transactions] WHERE [bank_account] = %i ORDER BY [date_transaction]',
            $this->bankAccountId,
        )->fetchPairs(null, 'external_id');
        $this->assertContains('EXT-FIO-001', $ext);
    }

    // ── GPC (dedup přes fingerprint) ──────────────────────────────────────────

    public function testGpcDedupByFingerprint(): void
    {
        $this->service()->import($this->fixture('statement.gpc'));
        $second = $this->service()->import($this->fixture('statement.gpc'));

        $this->assertSame(2, $this->txCount());
        $this->assertSame(0, $second['created']);
        $this->assertSame(2, $second['skipped']);

        // GPC nemá external_id → dedup drží fingerprint
        $ext = $this->dibi->query(
            'SELECT [external_id], [fingerprint] FROM [economy_bank_transactions] WHERE [bank_account] = %i LIMIT 1',
            $this->bankAccountId,
        )->fetch();
        $this->assertNull($ext['external_id']);
        $this->assertNotEmpty($ext['fingerprint']);
    }

    public function testGpcCharsetCp1250(): void
    {
        // Vloží diakritiku do memo (20 znaků) na fixní pozici a zakóduje do CP1250.
        $utf8 = $this->fixture('statement.gpc');
        $lines = explode("\n", $utf8);
        $memo = 'Příliš žluťoučký kůň'; // přesně 20 znaků
        $lines[1] = mb_substr($lines[1], 0, 97, 'UTF-8') . $memo . mb_substr($lines[1], 117, null, 'UTF-8');
        $cp1250 = iconv('UTF-8', 'CP1250', implode("\n", $lines));
        $this->assertNotFalse($cp1250);

        $summary = $this->service()->import($cp1250);
        $this->assertSame('cz.gpc', $summary['format']);

        $message = $this->dibi->query(
            'SELECT [message] FROM [economy_bank_transactions]'
            . ' WHERE [bank_account] = %i ORDER BY [date_transaction] LIMIT 1',
            $this->bankAccountId,
        )->fetchSingle();
        $this->assertStringContainsString('Příliš žluťoučký kůň', (string) $message);
    }

    public function testReconciliationMismatchProducesAlertFinding(): void
    {
        $broken = str_replace('2160.00', '9999.00', $this->fixture('camt053.xml'));
        $this->service()->import($broken);

        $stmtId = (int) $this->dibi->query(
            'SELECT [id] FROM [economy_bank_statements] WHERE [bank_account] = %i LIMIT 1',
            $this->bankAccountId,
        )->fetchSingle();

        $check = new StatementReconciliationCheck($this->db, $this->configRuntime, 'cs');
        $mine = array_values(array_filter(
            $check->run(),
            static fn($f) => $f->subjectRowId === $stmtId && $f->subjectTableId === 415,
        ));

        $this->assertCount(1, $mine);
        $this->assertSame('warning', $mine[0]->severity);
        $this->assertSame((string) $stmtId, $mine[0]->findingKey);
    }
}
