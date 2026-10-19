<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Dataset;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetPreflight;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetSeeder;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\Seed\DocumentSeeder;
use Shipard\Module\Core\Exchange\Dataset\Seed\ItemSeeder;
use Shipard\Module\Core\Exchange\Dataset\Seed\PersonSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Economy\ExchangeRates\Dataset\ExchangeRatesExporter;
use Shipard\Module\Economy\ExchangeRates\Dataset\ExchangeRatesSeeder;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Kurzovní lístky v datové sadě proti živému DS (#121 D10, U7): sada
 * s měsíčním lístkem (THB, červen 2026) a konceptem přijaté faktury v THB
 * bez kurzu → seed (lístek přes importer se zdrojem datová sada, doklad
 * přes applier, který kurz doplní z lístku) → export sekce exchangeRates
 * vrátí lístek shodný se sadou → druhý seed je idempotentní (skipped = ok).
 * Uklízí podle přirozených klíčů s prefixem `IT-XR`.
 */
class ExchangeRatesRoundTripTest extends IntegrationTestCase
{
    private const P = 'IT-XR';
    private const COMPANY_ID = '99990002';
    private const ITEM_CODE = 'IT-XR-001';
    private const DOC_TEXT = 'IT-XR faktura v THB';
    private const LIST_TYPE = 'cz.cnb.other';
    private const LIST_FROM = '2026-06-01';

    private string $setDir;
    private ConfigRuntime $config;
    private SeedContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->tables[ExchangeRateResolver::TABLE_LISTS])) {
            $this->markTestSkipped('DS nemá tabulku kurzovních lístků (ds-upgrade).');
        }
        $dibi = $this->db->getDibiConnection();
        if ($dibi->fetch("SELECT id FROM docs_core_number_series WHERE doc_type = 'invni' AND docState IN (10, 40, 80) LIMIT 1") === null) {
            $this->markTestSkipped('DS nemá aktivní řadu pro invni.');
        }
        if ($dibi->fetch('SELECT id FROM base_persons_persons WHERE is_own = 1 AND docState IN (10, 40, 80) LIMIT 1') === null) {
            $this->markTestSkipped('DS nemá vlastní firmu (is_own).');
        }
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        if ($this->config->cfgItem('world.cz.exchangeRateLists') === null) {
            $this->markTestSkipped('DS nemá zkompilované typy lístků (ds-upgrade).');
        }
        if ($dibi->fetch('SELECT id FROM economy_exchange_rates_lists WHERE list_type = %s AND valid_from = %d', self::LIST_TYPE, new \DateTimeImmutable(self::LIST_FROM)) !== null) {
            $this->markTestSkipped('DS už má měsíční lístek ' . self::LIST_FROM . ' — test by se s ním srazil.');
        }

        $this->cleanup();
        $this->setDir = $this->dsPath . '/set';
        $this->writeSet();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->ctx = new SeedContext(
            reader: DatasetReader::open($this->setDir),
            db: $dibi,
            dsConnection: $this->db,
            config: $this->config,
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

    public function testSeedFillsRateFromListAndExportReturnsTheList(): void
    {
        $preflight = (new DatasetPreflight())->check($this->ctx->reader);
        $this->assertSame([], $preflight['errors'], implode("\n", $preflight['errors']));

        $report = (new DatasetSeeder())->seed($this->ctx, $this->seeders());
        $this->assertSame([], $report->errors(), implode("\n", $report->errors()));
        $this->assertSame(1, $report->counts()['exchangeRates']['ok'] ?? 0);
        $this->assertSame(1, $report->counts()['docs']['ok'] ?? 0);

        $dibi = $this->db->getDibiConnection();
        $list = $dibi->fetch('SELECT * FROM economy_exchange_rates_lists WHERE list_type = %s AND valid_from = %d', self::LIST_TYPE, new \DateTimeImmutable(self::LIST_FROM));
        $this->assertNotNull($list);
        $this->assertSame(ExchangeRateListImporter::SOURCE_DATASET, (int) $list['source']);

        $doc = $dibi->fetch('SELECT doc_currency, exchange_rate, accounting_date FROM docs_core_heads WHERE doc_text = %s', self::DOC_TEXT);
        $this->assertNotNull($doc);
        $this->assertSame('thb', $doc['doc_currency']);
        $this->assertEqualsWithDelta(0.695, (float) $doc['exchange_rate'], 0.0000001, 'kurz za jednotku z amount 100 (69,5 / 100)');

        $exporter = new ExchangeRatesExporter($dibi, ExchangeRateResolver::create($dibi, $this->config, $this->dsConfig));
        $exported = null;
        foreach ($exporter->exportAll() as $record) {
            if ($record->slug === self::LIST_TYPE . '-' . self::LIST_FROM) {
                $exported = $record;
            }
        }
        $this->assertNotNull($exported, 'lístek použitý dokladem v THB je v exportu');
        $this->assertSame($this->ctx->reader->readJsonc('exchangeRates/0001-cz.cnb.other-2026-06-01.jsonc'), $exported->data, 'dump = sada');

        $second = (new DatasetSeeder())->seed($this->ctx, [new ExchangeRatesSeeder($this->importer())]);
        $this->assertSame([], $second->errors());
        $this->assertSame(1, $second->counts()['exchangeRates']['ok'] ?? 0, 'shodný lístek podruhé = skipped = ok');
    }

    /** @return list<\Shipard\Module\Core\Exchange\Dataset\SectionSeeder> */
    private function seeders(): array
    {
        $dibi = $this->ctx->db;
        $personApplier = PersonApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables);
        return [
            new ExchangeRatesSeeder($this->importer()),
            new PersonSeeder($personApplier),
            new ItemSeeder(ItemApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, $personApplier)),
            new DocumentSeeder(DocumentApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, null)),
        ];
    }

    private function importer(): ExchangeRateListImporter
    {
        return ExchangeRateListImporter::create($this->ctx->db, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables);
    }

    private function writeSet(): void
    {
        $w = DatasetWriter::create($this->setDir);
        $w->writeJsonc('exchangeRates/0001-cz.cnb.other-2026-06-01.jsonc', [
            'format' => ExchangeRateListImporter::FORMAT, 'listType' => self::LIST_TYPE, 'homeCurrency' => 'CZK',
            'validFrom' => self::LIST_FROM, 'validTo' => '2026-06-30', 'listNumber' => '6',
            'rates' => [['currency' => 'THB', 'amount' => 100, 'rate' => 69.5]],
        ]);
        $w->writeJsonc('persons/0001-it-xr-dodavatel.jsonc', [
            'format' => 'shpd.persons.person', 'formatVersion' => '1.0', 'personType' => 'company', 'country' => 'th',
            'companyId' => self::COMPANY_ID, 'name' => ['fullName' => self::P . ' Supplier Ltd.'],
            'status' => ['docState' => 40],
            'applyOptions' => ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
        ]);
        $w->writeJsonc('items/0001-it-xr-001.jsonc', [
            'format' => 'shpd.items.item', 'formatVersion' => '1.0', 'code' => self::ITEM_CODE, 'name' => self::P . ' Služba',
            'kind' => ['code' => 'service', 'itemType' => 0], 'unit' => 'hr', 'status' => ['docState' => 40],
            'applyOptions' => ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
        ]);
        $w->writeJsonc('docs/0001-it-xr-thb.jsonc', [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'source' => ['kind' => 'aiExtraction'],
            'docType' => 'invoiceReceived', 'docNumber' => 'TH-2026-77', 'docText' => self::DOC_TEXT, 'selfParty' => 'customer',
            'supplier' => ['name' => self::P . ' Supplier Ltd.', 'country' => 'th', 'companyId' => self::COMPANY_ID],
            'dates' => ['issueDate' => '2026-06-08', 'dueDate' => '2026-06-22', 'accountingDate' => '2026-06-10'],
            'currency' => 'THB', 'exchangeRate' => null,
            'vat' => ['mode' => 'none', 'place' => 'domestic', 'registrationCountry' => 'cz'],
            'payment' => ['method' => 'bankTransfer'],
            'rows' => [[
                'rowKind' => 'item', 'operation' => 'purchase.services', 'orderPos' => 1,
                'item' => ['ourCode' => self::ITEM_CODE, 'name' => self::P . ' Služba'], 'unit' => 'hr',
                'quantity' => 1.0, 'unitPrice' => 1000.0, 'totalPrice' => 1000.0, 'priceCalcMode' => 'fromUnitPrice',
                'vat' => ['code' => null, 'pct' => 0.0], 'description' => 'Služba IT-XR',
            ]],
            'totals' => ['totalBase' => 1000.0, 'totalVat' => 0.0, 'totalAmount' => 1000.0, 'totalRounding' => 0.0],
            'applyOptions' => ['targetDocState' => 10],
        ]);
        $w->writeManifest(new DatasetManifest('it-xr', 'Integration exchange rates', null, 'fixed', '2026-10-19T10:00:00Z',
            ['exchangeRates' => 1, 'persons' => 1, 'items' => 1, 'docs' => 1]));
    }

    private function cleanup(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($dibi->fetchAll('SELECT id FROM docs_core_heads WHERE doc_text = %s', self::DOC_TEXT) as $row) {
            $id = (int) $row['id'];
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_vat_recap')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        $dibi->delete('economy_items')->where('code = %s', self::ITEM_CODE)->execute();
        $dibi->delete('base_persons_persons')->where('company_id = %s', self::COMPANY_ID)->execute();
        foreach ($dibi->fetchAll('SELECT id FROM economy_exchange_rates_lists WHERE list_type = %s AND valid_from = %d', self::LIST_TYPE, new \DateTimeImmutable(self::LIST_FROM)) as $row) {
            $dibi->delete('economy_exchange_rates_values')->where('list = %i', (int) $row['id'])->execute();
            $dibi->delete('economy_exchange_rates_lists')->where('id = %i', (int) $row['id'])->execute();
        }
    }
}
