<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Dataset;

use Dibi\Connection;
use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetSeeder;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Economy\Codebooks\Dataset\FiscalYearsSeeder;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\Dataset\AccountingItemsSeeder;
use Shipard\Tests\Integration\IntegrationTestCase;
use Shipard\Tests\Unit\Module\Core\Exchange\Dataset\DatasetPreflightTest;

/**
 * Provisioning seedu proti živému DS (`SHIPARD_INTEGRATION_DS_PATH`,
 * #103 D22/D23): sada s daty v roce 2001 (doklad, zpráva, canonical návrhu)
 * a jedním kódem nabídky osnovy → `FiscalYearsSeeder` založí rok 2001 se
 * 14 měsíci, `AccountingItemsSeeder` položku přes ItemDocument; druhý běh
 * je idempotentní (existující rok i kód = ok, žádné nové řádky). Uklízí
 * podle `date_begin` a id položky.
 */
class DatasetProvisionSeedTest extends IntegrationTestCase
{
    private const YEAR_BEGIN = '2001-01-01';

    private Connection $dibi;
    private SeedContext $ctx;
    private ?string $code = null;
    private ?string $account = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dibi = $this->db->getDibiConnection();

        if (!isset($this->tables['economy_codebooks_fiscal_years'], $this->tables['economy_items'])) {
            $this->markTestSkipped('DS nemá fiskální roky nebo položky.');
        }
        $settings = new SettingsStore($this->db);
        $startMonth = $settings->get('economy.fiscalYearStartMonth');
        $variant = $settings->get('economy.accountChart');
        if ((int) $startMonth !== 1 || !is_string($settings->get('economy.homeCurrency'))
            || !in_array($variant, ['default', 'npo'], true)) {
            $this->markTestSkipped('Test předpokládá první měsíc 1, domácí měnu a osnovu default / npo.');
        }
        if ($this->dibi->fetch('SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin = %d', self::YEAR_BEGIN) !== null) {
            $this->markTestSkipped('Rok 2001 na DS už existuje — nejde ověřit založení.');
        }

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $offer = new AccountingItemsOffer($this->db, $resolver);
        $seed = $offer->loadSeed((string) $variant) ?? ['items' => []];
        foreach ($seed['items'] as $code => $entry) {
            $code = (string) $code;
            $account = (string) ($entry['account'] ?? '');
            $exists = $this->dibi->fetch('SELECT id FROM economy_items WHERE code = %s', $code) !== null;
            $hasAccount = $this->dibi->fetch(
                'SELECT id FROM economy_accounting_accounts WHERE number = %s AND account_level = 4 AND docState IN (10, 40, 80)',
                $account,
            ) !== null;
            if (!$exists && $hasAccount) {
                $this->code = $code;
                $this->account = $account;
                break;
            }
        }
        if ($this->code === null) {
            $this->markTestSkipped('Všechny kódy nabídky už na DS existují — není co založit.');
        }

        $this->cleanup();
        $setDir = $this->dsPath . '/set';
        $w = DatasetWriter::create($setDir);
        $doc = DatasetPreflightTest::doc();
        $doc['dates'] = ['issueDate' => '2001-05-15', 'accountingDate' => '2001-05-15', 'dueDate' => '2002-01-10'];
        $w->writeJsonc('docs/0001-doc.jsonc', $doc);
        $mail = DatasetPreflightTest::mail();
        $mail['receivedAt'] = '2001-05-16T08:00:00';
        $mail['analyses'][0]['canonicalJson']['dates'] = ['issueDate' => '2001-05-15', 'taxPointDate' => '2001-05-15'];
        $w->writeJsonc('mail/0001-msg.jsonc', $mail);
        $w->writeManifest(new DatasetManifest(
            'it-provision', 'Integration provision', null, 'fixed', '2026-10-14T00:00:00Z',
            ['docs' => 1, 'mail' => 1],
            country: $this->dsConfig->getCountry(),
            provision: ['accountingItems' => [$this->code]],
        ));

        $config = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->ctx = new SeedContext(
            reader: DatasetReader::open($setDir),
            db: $this->dibi,
            dsConnection: $this->db,
            config: $config,
            dsConfig: $this->dsConfig,
            registry: DocumentLoader::load($this->dsConfig, $resolver),
            tables: $this->tables,
            dsDir: $this->dsPath,
            attachments: new AttachmentService($this->db, $this->dsPath, $this->tables),
            dispatcher: null,
            merge: true,
        );
    }

    protected function onTearDown(): void
    {
        $this->cleanup();
    }

    public function testFiscalYearAndAccountingItemAreProvisionedIdempotently(): void
    {
        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $seeders = [new FiscalYearsSeeder(), new AccountingItemsSeeder(new AccountingItemsOffer($this->db, $resolver))];

        $report = (new DatasetSeeder())->seed($this->ctx, $seeders);
        $this->assertSame([], $report->errors(), implode("\n", $report->errors()));
        $this->assertSame(1, $report->counts()['fiscalYears']['ok'], 'všechna data sady leží v roce 2001');
        $this->assertSame(1, $report->counts()['accountingItems']['ok']);

        $year = $this->dibi->fetch('SELECT * FROM economy_codebooks_fiscal_years WHERE date_begin = %d', self::YEAR_BEGIN);
        $this->assertNotNull($year);
        $this->assertSame('2001', (string) $year['name']);
        $this->assertSame('2001-12-31', $year['date_end'] instanceof \DateTimeInterface ? $year['date_end']->format('Y-m-d') : (string) $year['date_end']);
        $this->assertSame(40, (int) $year['docState']);
        $this->assertSame(14, (int) $this->dibi->fetchSingle('SELECT COUNT(*) FROM economy_codebooks_fiscal_months WHERE fiscal_year = %i', $year['id']));

        $item = $this->dibi->fetch('SELECT * FROM economy_items WHERE code = %s', $this->code);
        $this->assertNotNull($item);
        $this->assertSame('setup.accountingItems', (string) $item['source_kind']);
        $this->assertSame(40, (int) $item['docState']);
        $accountId = (int) $this->dibi->fetchSingle(
            'SELECT id FROM economy_accounting_accounts WHERE number = %s AND account_level = 4 AND docState IN (10, 40, 80)',
            $this->account,
        );
        $this->assertSame($accountId, (int) $item['accounting_account']);
        $kindId = (int) $this->dibi->fetchSingle("SELECT id FROM economy_items_kinds WHERE system_code = 'accounting'");
        $this->assertSame($kindId, (int) $item['item_kind']);

        // Druhý běh: nic nového, žádné chyby.
        $again = (new DatasetSeeder())->seed($this->ctx, $seeders);
        $this->assertSame([], $again->errors(), implode("\n", $again->errors()));
        $this->assertSame(1, $again->counts()['fiscalYears']['ok']);
        $this->assertSame(1, $again->counts()['accountingItems']['ok'], 'already_exists → ok');
        $this->assertSame(1, (int) $this->dibi->fetchSingle('SELECT COUNT(*) FROM economy_codebooks_fiscal_years WHERE date_begin = %d', self::YEAR_BEGIN));
        $this->assertSame(1, (int) $this->dibi->fetchSingle('SELECT COUNT(*) FROM economy_items WHERE code = %s', $this->code));
    }

    private function cleanup(): void
    {
        foreach ($this->dibi->fetchAll('SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin = %d', self::YEAR_BEGIN) as $r) {
            $this->dibi->query('DELETE FROM economy_codebooks_fiscal_months WHERE fiscal_year = %i', $r['id']);
            $this->dibi->query('DELETE FROM economy_codebooks_fiscal_years WHERE id = %i', $r['id']);
        }
        if ($this->code !== null) {
            $this->dibi->query(
                'DELETE FROM economy_items WHERE code = %s AND source_kind = %s',
                $this->code,
                'setup.accountingItems',
            );
        }
    }
}
