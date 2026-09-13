<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Vat;

use Shipard\Command\DataSource\VatFilingImportCommand;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Tests\Integration\IntegrationTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CLI `vat-filing-import` nad dev DS: import + přílohy + finish v jednom
 * běhu, `--dry-run` bez zápisu. Přílohy jdou do izolovaného úložiště testu
 * (`dsPath`), ne do `att/` dev DS.
 */
class VatFilingImportCommandTest extends IntegrationTestCase
{
    private const DATE_BEGIN = '2029-01-01';
    private const DATE_END   = '2029-01-31';
    private const FIXTURE    = __DIR__ . '/../../Fixtures/vat-xml/synthetic/dp3-regular.xml';

    private int $registrationId = 0;
    /** @var list<int> */
    private array $createdPeriods = [];
    /** @var list<int> */
    private array $createdFilings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        if (!isset($this->tables[FilingDocument::TABLE])
            || $this->db->fetchRow('SHOW COLUMNS FROM economy_vat_filings LIKE %s', 'origin') === null
            || !is_array($config->cfgItem('economy.vat.xml.cz'))
        ) {
            $this->markTestSkipped('DS nemá podání s origin nebo compiled mapování — spusťte ds-upgrade');
        }
        $reg = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_vat_registrations WHERE country = %s AND docState IN (10,40,80) ORDER BY id LIMIT 1',
            'cz',
        );
        if ($reg === null) {
            $this->markTestSkipped('DS nemá registraci k DPH (cz)');
        }
        $this->registrationId = (int) $reg['id'];
        $collision = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_vat_report_periods'
            . ' WHERE vat_registration = %i AND date_begin <= %s AND date_end >= %s AND docState != 90',
            $this->registrationId, self::DATE_END, self::DATE_BEGIN,
        );
        if ($collision > 0) {
            $this->markTestSkipped('DS už má instanci tvrzení v testovacím rozsahu 01/2029');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->createdPeriods as $periodId) {
            $filings = $this->db->fetchAll('SELECT id FROM economy_vat_filings WHERE report_period = %i', $periodId);
            foreach ($filings as $filing) {
                $id = (int) $filing['id'];
                $dibi->delete('core_attachments_files')
                    ->where('table_id = %i AND record_id = %i', FilingFilesService::TABLE_ID, $id)->execute();
                foreach (FilingDocument::SNAPSHOT_TABLES as $table) {
                    $dibi->delete($table)->where('filing = %i', $id)->execute();
                }
                $dibi->delete(FilingDocument::TABLE)->where('id = %i', $id)->execute();
            }
            $dibi->delete('economy_vat_report_periods')->where('id = %i', $periodId)->execute();
        }
    }

    public function testDryRunWritesNothing(): void
    {
        $periodId = $this->insertPeriod();
        $tester   = $this->runCommand(['--period' => (string) $periodId, '--type' => 'return', '--xml' => self::FIXTURE, '--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString('Dry-run — nic se nezapsalo.', $tester->getDisplay());
        $this->assertStringContainsString('Podání (dry-run)', $tester->getDisplay());
        $this->assertSame(0, (int) $this->db->fetchSingle('SELECT COUNT(*) FROM economy_vat_filings WHERE report_period = %i', $periodId));
    }

    public function testImportAttachesFilesAndFiles(): void
    {
        $periodId = $this->insertPeriod();
        $pdf      = $this->dsPath . '/opis.pdf';
        file_put_contents($pdf, '%PDF-1.4 opis');

        $tester = $this->runCommand([
            '--period' => (string) $periodId, '--type' => 'return', '--xml' => self::FIXTURE,
            '--name' => 'Přiznání DPH 2029/1', '--date-filed' => '2029-02-25', '--attach' => [$pdf],
        ]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('podáno', $display);
        $this->assertStringContainsString('původních souborů: 2', $display);
        $this->assertFileExists(self::FIXTURE, 'zdrojový soubor se při uploadu nepřesouvá');

        $filing = $this->db->fetchRow('SELECT * FROM economy_vat_filings WHERE report_period = %i', $periodId);
        $this->assertNotNull($filing);
        $this->assertSame(FilingDocument::DOC_STATE_FILED, (int) $filing['docState']);
        $this->assertSame(FilingDocument::ORIGIN_IMPORTED, $filing['origin']);
        $this->assertSame('Přiznání DPH 2029/1', $filing['name']);

        $attachments = $this->db->fetchAll(
            'SELECT name, metadata FROM core_attachments_files WHERE table_id = %i AND record_id = %i AND is_deleted = 0 ORDER BY id',
            FilingFilesService::TABLE_ID, (int) $filing['id'],
        );
        $this->assertCount(2, $attachments);
        $this->assertSame('dp3-regular.xml', $attachments[0]['name']);
        $this->assertSame(FilingFilesService::KIND_XML, json_decode((string) $attachments[0]['metadata'], true)['kind']);
        $this->assertSame('opis.pdf', $attachments[1]['name']);
        $this->assertSame(FilingFilesService::KIND_IMPORTED, json_decode((string) $attachments[1]['metadata'], true)['kind']);
    }

    public function testMissingPeriodFails(): void
    {
        $tester = $this->runCommand(['--period' => '999999999', '--type' => 'return']);
        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('PERIOD_NOT_FOUND', $tester->getDisplay());
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $options */
    private function runCommand(array $options): CommandTester
    {
        $command = new class ($this->realDsPath, $this->dsPath, $this->dsConfig, $this->db) extends VatFilingImportCommand {
            public function __construct(
                private readonly string $dsDir,
                private readonly string $storagePath,
                DataSourceConfig $dsConfig,
                DataSourceConnection $dsConnection,
            ) {
                parent::__construct($dsConfig, $dsConnection);
            }

            protected function getDataSourceDir(): string
            {
                return $this->dsDir;
            }

            protected function attachmentService(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, array $tables): AttachmentService
            {
                return new AttachmentService($dsConnection, $this->storagePath, $tables);
            }
        };
        $tester = new CommandTester($command);
        $tester->execute($options);
        return $tester;
    }

    private function insertPeriod(): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_vat_report_periods', [
            'vat_registration' => $this->registrationId,
            'report_type'      => 'return',
            'name'             => '01/2029 IT CLI',
            'date_begin'       => self::DATE_BEGIN,
            'date_end'         => self::DATE_END,
            'locked'           => 0,
            'docState'         => 40,
            'docStateMain'     => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdPeriods[] = $id;
        return $id;
    }
}
