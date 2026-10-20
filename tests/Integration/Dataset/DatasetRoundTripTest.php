<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Dataset;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Registry\Dataset\RegistrySeeder;
use Shipard\Module\Base\Registry\RegistryApplier;
use Shipard\Module\Base\Registry\RegistryExporter;
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
use Shipard\Module\Core\Exchange\Export\DocumentExporter;
use Shipard\Module\Core\Exchange\Export\ItemExporter;
use Shipard\Module\Core\Exchange\Export\PersonExporter;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Mail\Dataset\MailExporter;
use Shipard\Module\Core\Mail\Dataset\MailSeeder;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Round-trip datové sady proti živému DS (`SHIPARD_INTEGRATION_DS_PATH`):
 * syntetická sada (osoba, položka, přijatá faktura ve 40, prodejka ve 40
 * na vlastní pokladně, dokument spisovny s přílohou, zpráva s přílohou +
 * snapshotem analýzy a vazbou na doklad) → seed v merge režimu (bez resetu,
 * sdílený DS) → export jen vložených záznamů → porovnání s sadou +
 * invarianty (zaúčtování, lineage, `att:` remapa, přílohy na disku, pokladna
 * a vázaná řada prodejky). Uklízí po sobě podle přirozených klíčů s prefixem
 * `IT-DS`; number counters se nehnou (sequenceNumber = null) — jen řada
 * testovací pokladny dostane čítač, a ta se maže celá.
 *
 * Pokladnu test vkládá přímo do číselníku (bez Document lifecycle, tedy bez
 * `CashDeskSeriesEventHandler`) — vázané řady `cash` / `cashreg` zakládá až
 * applier líně přes `BoundNumberSeriesProvisioner` (#59 D12).
 *
 * Mimo test: `dataset-seed` s resetem a byte-shodnost dump → seed → dump
 * se ověřovaly ručně na jednorázovém DS (tasks/dataset-phase1.md).
 */
class DatasetRoundTripTest extends IntegrationTestCase
{
    private const P = 'IT-DS';
    private const COMPANY_ID = '99990001';
    private const ITEM_CODE = 'IT-DS-001';
    private const DOC_NUMBER = 'IT-DS-0001';
    private const CASH_DESK_CODE = 'ITDS';
    /** `%D%C%y%5` řady prodejky (doc_id_code 14, kód pokladny, rok 2026, pořadí 1). */
    private const CASHREG_NUMBER = '14ITDS2600001';
    private const MESSAGE_ID = 'MSG-IT-DS-0001';
    private const BINDER = 'IT-DS Šanon';
    private const REGISTRY_TITLE = 'IT-DS Smlouva o dílo';

    private string $setDir;
    private ConfigRuntime $config;
    private SeedContext $ctx;
    private ?string $seriesCode = null;
    private int $cashDeskId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $dibi = $this->db->getDibiConnection();
        // Kód řady (%C) může být prázdný — pak exporter numberSeriesCode
        // vynechává a applier bere první aktivní řadu (stejně jako dump z DS).
        $series = $dibi->fetch(
            "SELECT doc_number_code FROM docs_core_number_series
             WHERE doc_type = 'invni' AND docState IN (10, 40, 80) ORDER BY id LIMIT 1",
        );
        if ($series === null) {
            $this->markTestSkipped('DS nemá aktivní řadu pro invni.');
        }
        $code = trim((string) $series['doc_number_code']);
        $this->seriesCode = $code !== '' ? $code : null;
        if ($dibi->fetch('SELECT id FROM base_persons_persons WHERE is_own = 1 AND docState IN (10, 40, 80) LIMIT 1') === null) {
            $this->markTestSkipped('DS nemá vlastní firmu (is_own) — selfParty resolve by selhal.');
        }
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');
        $docTypes = $this->config->cfgItem('docs.core.docTypes');
        if (!isset($this->tables['economy_codebooks_cash_desks']) || !is_array($docTypes) || !isset($docTypes['cashreg'])) {
            $this->markTestSkipped('DS nemá číselník pokladen nebo typ dokladu cashreg (docs.cashRegister).');
        }
        $cashAccount = $dibi->fetch("SELECT id FROM economy_accounting_accounts WHERE number = '211100' AND docState <> 90");
        if ($cashAccount === null) {
            $this->markTestSkipped('DS nemá účet 211100 v osnově — pokladna by se nezaúčtovala.');
        }

        $this->cleanup(); // orphan data z předchozího pádu

        // Pokladna přímo do číselníku: řady `cash` / `cashreg` si applier
        // založí sám (líně, BoundNumberSeriesProvisioner), až ji doklad použije.
        $dibi->query('INSERT INTO [economy_codebooks_cash_desks] %v', [
            'code' => self::CASH_DESK_CODE, 'name' => self::P . ' Pokladna', 'currency' => 'czk',
            'is_default' => 0, 'sort_order' => 0, 'docState' => 40, 'docStateMain' => 3,
            'accounting_account' => (int) $cashAccount['id'],
        ]);
        $this->cashDeskId = (int) $dibi->getInsertId();

        $this->setDir = $this->dsPath . '/set';
        $this->writeSet();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $registry = DocumentLoader::load($this->dsConfig, $resolver);
        $journal = JournalEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $this->config);
        $dispatcher = DocumentEventHandlerLoader::load($this->dsConfig, $resolver, $dibi, $this->config, $journal);

        $this->ctx = new SeedContext(
            reader: DatasetReader::open($this->setDir),
            db: $dibi,
            dsConnection: $this->db,
            config: $this->config,
            dsConfig: $this->dsConfig,
            registry: $registry,
            tables: $this->tables,
            dsDir: $this->dsPath,
            attachments: new AttachmentService($this->db, $this->dsPath, $this->tables),
            dispatcher: $dispatcher,
            merge: true,
        );
    }

    protected function onTearDown(): void
    {
        $this->cleanup();
    }

    public function testSeedThenExportReproducesTheSet(): void
    {
        $preflight = (new DatasetPreflight())->check($this->ctx->reader);
        $this->assertSame([], $preflight['errors'], implode("\n", $preflight['errors']));

        $dibi = $this->ctx->db;
        $party = new PartyResolver($dibi, new OwnCompanyResolver($dibi));
        $personApplier = PersonApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables);
        $seeders = [
            new PersonSeeder($personApplier),
            new ItemSeeder(ItemApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, $personApplier)),
            new DocumentSeeder(DocumentApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, $this->ctx->dispatcher)),
            new RegistrySeeder(new RegistryApplier($this->db, $this->ctx->registry, $this->ctx->attachments, $this->config, $party), $party),
            new MailSeeder($party),
        ];

        $report = (new DatasetSeeder())->seed($this->ctx, $seeders);

        $this->assertSame([], $report->errors(), implode("\n", $report->errors()));
        $counts = $report->counts();
        $this->assertSame(1, $counts['persons']['ok']);
        $this->assertSame(1, $counts['items']['ok']);
        $this->assertSame(2, $counts['docs']['ok'], 'faktura přijatá + prodejka');
        $this->assertSame(1, $counts['registry']['ok']);
        $this->assertSame(1, $counts['mail']['ok']);

        // ── ids podle přirozených klíčů ───────────────────────────────────
        $personId = (int) $dibi->fetchSingle('SELECT id FROM base_persons_persons WHERE company_id = %s AND docState <> 90', self::COMPANY_ID);
        $itemId = (int) $dibi->fetchSingle('SELECT id FROM economy_items WHERE code = %s', self::ITEM_CODE);
        $docId = (int) $dibi->fetchSingle('SELECT id FROM docs_core_heads WHERE doc_number = %s AND docState <> 90', self::DOC_NUMBER);
        $cashregId = (int) $dibi->fetchSingle('SELECT id FROM docs_core_heads WHERE doc_number = %s AND docState <> 90', self::CASHREG_NUMBER);
        $registryId = (int) $dibi->fetchSingle('SELECT id FROM base_registry_documents WHERE title = %s', self::REGISTRY_TITLE);
        $messageId = (int) $dibi->fetchSingle('SELECT id FROM core_mail_incoming_messages WHERE message_id = %s', self::MESSAGE_ID);
        $this->assertGreaterThan(0, $personId);
        $this->assertGreaterThan(0, $itemId);
        $this->assertGreaterThan(0, $docId);
        $this->assertGreaterThan(0, $cashregId);
        $this->assertGreaterThan(0, $registryId);
        $this->assertGreaterThan(0, $messageId);

        // ── DB invarianty ────────────────────────────────────────────────
        $doc = $dibi->fetch('SELECT * FROM docs_core_heads WHERE id = %i', $docId)->toArray();
        $this->assertSame(40, (int) $doc['docState'], 'doklad je ve stavu V pořádku');
        $this->assertSame(self::DOC_NUMBER, $doc['doc_number']);
        $this->assertNull($doc['sequence_number'], 'sequenceNumber null → číslo mimo vzorec, counter netknutý');
        $this->assertSame($messageId, (int) $doc['source_message'], 'lineage doklad → zpráva obnovená podle čísla dokladu');
        $hasChart = (int) $dibi->fetchSingle("SELECT COUNT(*) FROM economy_accounting_accounts WHERE number = '518100'") > 0;
        if ($hasChart) {
            $this->assertSame(1, (int) $doc['accounting_state'], 'doklad ve 40 se přes dispatcher zaúčtoval');
            $this->assertGreaterThan(0, (int) $dibi->fetchSingle('SELECT COUNT(*) FROM economy_accounting_journal WHERE doc_head = %i', $docId));
        }

        // Prodejka (#59 D12): řada vázaná na pokladnu vznikla líně, pokladna
        // hlavičky je denormalizovaná z řady, číslo i pořadí převzaté.
        $cashreg = $dibi->fetch('SELECT * FROM docs_core_heads WHERE id = %i', $cashregId)->toArray();
        $this->assertSame('cashreg', $cashreg['doc_type']);
        $this->assertSame(40, (int) $cashreg['docState']);
        $this->assertSame($this->cashDeskId, (int) $cashreg['cash_desk'], 'pokladna hlavičky podle kódu ze sady');
        $this->assertSame(0, (int) $cashreg['cash_dir'], 'prodejka má směr pevně — cash_dir zůstává 0');
        $this->assertSame(0, (int) $cashreg['payment_method'], 'hotově');
        $this->assertSame(1, (int) $cashreg['sequence_number']);
        $this->assertNull($cashreg['partner'], 'anonymní prodejka bez partnera');
        $series = $dibi->fetch('SELECT * FROM docs_core_number_series WHERE id = %i', (int) $cashreg['number_series'])->toArray();
        $this->assertSame('cashreg', $series['doc_type']);
        $this->assertSame($this->cashDeskId, (int) $series['cash_desk'], 'řada založená provisionerem pro testovací pokladnu');
        $this->assertSame(self::CASH_DESK_CODE, $series['doc_number_code'], 'kód vázané řady = kód pokladny');
        $this->assertSame(
            1,
            (int) $dibi->fetchSingle('SELECT MAX(last_assigned) FROM docs_core_number_counters WHERE number_series = %i', (int) $series['id']),
            'převzaté pořadí srovnalo čítač řady',
        );
        if ($hasChart) {
            $this->assertSame(1, (int) $cashreg['accounting_state'], 'prodejka ve 40 se zaúčtovala (211100 / 604)');
        }

        $msg = $dibi->fetch('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $messageId)->toArray();
        $this->assertSame('docs_core_heads', $msg['target_table_id']);
        $this->assertSame($docId, (int) $msg['target_row']);
        $this->assertSame(30, (int) $msg['analysis_state'], 'snapshot mode: analysis_state explicitně 30, žádná fronta AI');
        $this->assertSame(40, (int) $msg['docState']);
        $attRows = $dibi->fetchAll('SELECT * FROM core_attachments_files WHERE table_id = 303 AND record_id = %i AND is_deleted = 0', $messageId);
        $this->assertCount(1, $attRows);
        $this->assertFileExists($this->dsPath . '/att/' . $attRows[0]['file_path'] . '/' . $attRows[0]['file_name']);
        $analysis = $dibi->fetch('SELECT * FROM core_mail_message_analyses WHERE message = %i', $messageId)->toArray();
        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $this->assertSame('att:' . $attRows[0]['id'], $canonical['attachments'][0]['ref'], 'att:<pořadí> → att:<nové id>');
        $this->assertSame(40, (int) $analysis['resolution']);

        $regAtt = $dibi->fetchAll('SELECT * FROM core_attachments_files WHERE table_id = 428 AND record_id = %i AND is_deleted = 0', $registryId);
        $this->assertCount(1, $regAtt);
        $this->assertSame('smlouva.pdf', $regAtt[0]['name']);
        $this->assertSame(
            (int) $dibi->fetchSingle('SELECT id FROM base_registry_binders WHERE name = %s', self::BINDER),
            (int) $dibi->fetchSingle('SELECT binder FROM base_registry_documents WHERE id = %i', $registryId),
            'šanon podle názvu byl založen a přiřazen',
        );

        // ── export zpět a porovnání se sadou ─────────────────────────────
        $reader = $this->ctx->reader;
        $person = (new PersonExporter($dibi))->exportByIds([$personId])[0]->data;
        $item = (new ItemExporter($dibi))->exportByIds([$itemId])[0]->data;
        $docExporter = new DocumentExporter($dibi);
        $docsOut = [];
        foreach ($docExporter->exportByIds([$docId, $cashregId]) as $record) {
            $docsOut[$record->id] = $record->data;
        }
        $this->assertSame([$cashregId, $docId], array_keys($docsOut), 'dump řadí podle doc_type (cashreg < invni)');
        $docOut = $docsOut[$docId];
        $cashregOut = $docsOut[$cashregId];
        $registryOut = (new RegistryExporter($dibi, $this->dsPath))->exportByIds([$registryId])[0];
        $mailOut = (new MailExporter($dibi, $this->dsPath))->exportByIds([$messageId])[0];

        $srcPerson = $reader->readJsonc('persons/0001-it-ds-dodavatel.jsonc');
        $this->assertSame($srcPerson['companyId'], $person['companyId']);
        $this->assertSame($srcPerson['name'], $person['name']);
        $this->assertSame($srcPerson['country'], $person['country']);
        $this->assertSame($srcPerson['addresses'][0]['street'], $person['addresses'][0]['street']);
        $this->assertSame($srcPerson['addresses'][0]['zip'], $person['addresses'][0]['zip']);
        $this->assertSame($srcPerson['bankAccounts'][0]['iban'], $person['bankAccounts'][0]['iban']);
        $this->assertSame($srcPerson['contacts'][0]['email'], $person['contacts'][0]['email']);

        $srcItem = $reader->readJsonc('items/0001-it-ds-001.jsonc');
        $this->assertSame($srcItem['code'], $item['code']);
        $this->assertSame($srcItem['name'], $item['name']);
        $this->assertSame($srcItem['unit'], $item['unit']);
        $this->assertSame($srcItem['supplierCodes'][0]['supplierCode'], $item['supplierCodes'][0]['supplierCode']);
        $this->assertSame(self::COMPANY_ID, $item['supplierCodes'][0]['supplier']['companyId']);
        if ($hasChart) {
            $this->assertSame('518100', $item['accountingAccount']);
        }

        $srcDoc = $reader->readJsonc('docs/0001-it-ds-0001.jsonc');
        $this->assertSame('invoiceReceived', $docOut['docType']);
        $this->assertSame($srcDoc['docNumber'], $docOut['docNumber']);
        $this->assertSame('customer', $docOut['selfParty']);
        $this->assertSame(self::COMPANY_ID, $docOut['supplier']['companyId']);
        $this->assertSame($srcDoc['dates']['issueDate'], $docOut['dates']['issueDate']);
        $this->assertSame('CZK', $docOut['currency']);
        $this->assertSame(self::ITEM_CODE, $docOut['rows'][0]['item']['ourCode']);
        $this->assertSame(2.0, $docOut['rows'][0]['quantity']);
        $this->assertSame(500.0, $docOut['rows'][0]['unitPrice']);
        $this->assertSame('cz-110', $docOut['rows'][0]['vat']['code']);
        $this->assertSame(['docNumber' => self::DOC_NUMBER, 'sequenceNumber' => null], $docOut['applyOptions']['importNumber']);
        $this->assertSame($this->seriesCode, $docOut['applyOptions']['numberSeriesCode'] ?? null);
        $this->assertSame(40, $docOut['applyOptions']['targetDocState']);
        $this->assertArrayNotHasKey('cashDesk', $docOut, 'faktura na převod pokladnu nenese');
        $this->assertArrayNotHasKey('cashDirection', $docOut);

        $srcCashreg = $reader->readJsonc('docs/0002-14itds2600001.jsonc');
        $this->assertSame('cashRegisterDocument', $cashregOut['docType']);
        $this->assertSame(self::CASH_DESK_CODE, $cashregOut['cashDesk'], 'pokladna kódem — seed ji podle něj zase najde');
        $this->assertArrayNotHasKey('cashDirection', $cashregOut, 'prodejka směr nenese');
        $this->assertSame('supplier', $cashregOut['selfParty']);
        $this->assertArrayNotHasKey('supplier', $cashregOut);
        $this->assertArrayNotHasKey('customer', $cashregOut, 'anonymní prodejka: žádná strana');
        $keys = array_keys($cashregOut);
        $this->assertSame(
            array_search('selfParty', $keys, true) + 1,
            array_search('cashDesk', $keys, true),
            'cashDesk hned za selfParty jako ve schématu',
        );
        $this->assertSame($srcCashreg['docText'], $cashregOut['docText']);
        $this->assertSame($srcCashreg['dates']['issueDate'], $cashregOut['dates']['issueDate']);
        $this->assertSame($srcCashreg['dates']['issueDate'], $cashregOut['dates']['dueDate'], 'hotovostní doklad: splatnost = vystavení');
        $this->assertSame('CZK', $cashregOut['currency']);
        $this->assertSame('cash', $cashregOut['payment']['method']);
        $this->assertSame('sale.goods', $cashregOut['rows'][0]['operation']);
        $this->assertSame(self::ITEM_CODE, $cashregOut['rows'][0]['item']['ourCode']);
        $this->assertSame(500.0, $cashregOut['rows'][0]['unitPrice']);
        $this->assertSame(['totalBase' => 500.0, 'totalVat' => 105.0, 'totalAmount' => 605.0, 'totalRounding' => 0.0], $cashregOut['totals']);
        $this->assertSame(['docNumber' => self::CASHREG_NUMBER, 'sequenceNumber' => 1], $cashregOut['applyOptions']['importNumber']);
        // Kód vázané řady = kód pokladny; applier ho u vázaných typů ignoruje
        // (řadu určuje pokladna), v dumpu je ale přítomný.
        $this->assertSame(self::CASH_DESK_CODE, $cashregOut['applyOptions']['numberSeriesCode']);
        $this->assertSame(40, $cashregOut['applyOptions']['targetDocState']);
        $this->assertSame([], $docExporter->getWarnings());

        $srcRegistry = $reader->readJsonc('registry/0001-it-ds-smlouva.jsonc');
        $this->assertSame($srcRegistry['document']['title'], $registryOut->data['document']['title']);
        $this->assertSame('contract', $registryOut->data['document']['docType']);
        $this->assertSame($srcRegistry['document']['kindFields'], $registryOut->data['document']['kindFields']);
        $this->assertSame(self::BINDER, $registryOut->data['binder']);
        $this->assertSame($srcRegistry['refNumber'], $registryOut->data['refNumber']);
        $this->assertSame('smlouva.pdf', $registryOut->data['attachments'][0]['file']);
        $this->assertSame(hash('sha256', '%PDF-IT-DS-smlouva'), $registryOut->data['attachments'][0]['sha256']);
        $this->assertFileExists($registryOut->files[0]->sourcePath);

        $m = $mailOut->data;
        $this->assertSame(self::MESSAGE_ID, $m['messageId']);
        $this->assertSame('default', $m['mailbox']);
        $this->assertSame(['table' => 'docs_core_heads', 'docNumber' => self::DOC_NUMBER], $m['target']);
        $this->assertSame('faktura.pdf', $m['attachments'][0]['file']);
        $this->assertSame('att:1', $m['analyses'][0]['canonicalJson']->attachments[0]->ref, 'export přepíše nové id zpět na pořadí — sada je stabilní');
        $this->assertSame('claude-test', $m['analyses'][0]['modelName']);
        $this->assertSame(40, $m['analyses'][0]['resolution']);
        $this->assertSame(self::COMPANY_ID, $m['senderPerson']['companyId']);
    }

    // ── fixture ─────────────────────────────────────────────────────────────

    private function writeSet(): void
    {
        $w = DatasetWriter::create($this->setDir);
        $w->writeJsonc('persons/0001-it-ds-dodavatel.jsonc', [
            'format' => 'shpd.persons.person', 'formatVersion' => '1.0', 'personType' => 'company', 'country' => 'cz',
            'companyId' => self::COMPANY_ID, 'vatId' => 'CZ' . self::COMPANY_ID,
            'name' => ['fullName' => self::P . ' Dodavatel s.r.o.'],
            'contact' => ['email' => 'fakturace@it-ds.example'],
            'status' => ['docState' => 40],
            'addresses' => [['addressType' => 1, 'street' => 'Testovací', 'houseNumber' => '1', 'city' => 'Praha', 'zip' => '11000', 'country' => 'cz']],
            'bankAccounts' => [['accountNumber' => '9999000001/0100', 'iban' => 'CZ0901000000009999000001', 'currency' => 'CZK']],
            'contacts' => [['name' => 'Účtárna IT-DS', 'email' => 'uctarna@it-ds.example']],
            'applyOptions' => ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
        ]);
        $w->writeJsonc('items/0001-it-ds-001.jsonc', [
            'format' => 'shpd.items.item', 'formatVersion' => '1.0', 'code' => self::ITEM_CODE, 'name' => self::P . ' Konzultace',
            'kind' => ['code' => 'service', 'itemType' => 0], 'unit' => 'hr', 'accountingAccount' => '518100',
            'supplierCodes' => [['supplier' => ['name' => self::P . ' Dodavatel s.r.o.', 'companyId' => self::COMPANY_ID], 'supplierCode' => 'SUP-IT-DS']],
            'status' => ['docState' => 40],
            'applyOptions' => ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
        ]);
        $w->writeJsonc('docs/0001-it-ds-0001.jsonc', [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'source' => ['kind' => 'aiExtraction'],
            'docType' => 'invoiceReceived', 'docNumber' => '2026-IT-DS-77', 'docText' => self::P . ' faktura', 'selfParty' => 'customer',
            'supplier' => ['name' => self::P . ' Dodavatel s.r.o.', 'country' => 'cz', 'companyId' => self::COMPANY_ID, 'vatId' => 'CZ' . self::COMPANY_ID,
                           'bankAccount' => ['accountNumber' => '9999000001/0100', 'iban' => 'CZ0901000000009999000001']],
            'dates' => ['issueDate' => '2026-06-01', 'dueDate' => '2026-06-15', 'accountingDate' => '2026-06-01', 'taxPointDate' => '2026-06-01'],
            'currency' => 'CZK', 'vat' => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz'],
            'payment' => ['method' => 'bankTransfer', 'paymentReference' => '20260601'],
            'rows' => [[
                'rowKind' => 'item', 'operation' => 'purchase.services', 'orderPos' => 1,
                'item' => ['ourCode' => self::ITEM_CODE, 'name' => self::P . ' Konzultace'], 'unit' => 'hr',
                'quantity' => 2.0, 'unitPrice' => 500.0, 'totalPrice' => 1000.0, 'priceCalcMode' => 'fromUnitPrice',
                'vat' => ['code' => 'cz-110', 'pct' => 21.0], 'description' => 'Konzultace IT-DS',
            ]],
            'vatRecap' => [['vatCode' => 'cz-110', 'vatPct' => 21.0, 'base' => 1000.0, 'tax' => 210.0, 'total' => 1210.0]],
            'totals' => ['totalBase' => 1000.0, 'totalVat' => 210.0, 'totalAmount' => 1210.0, 'totalRounding' => 0.0],
            'applyOptions' => array_filter([
                'targetDocState' => 40,
                'importNumber' => ['docNumber' => self::DOC_NUMBER, 'sequenceNumber' => null],
                'numberSeriesCode' => $this->seriesCode,
            ], static fn($v) => $v !== null),
        ]);
        // Prodejka na testovací pokladně (#59 D12): anonymní, hotově, číslo ve
        // tvaru vázané řady `%D%C%y%5`. `numberSeriesCode` sada nenese — řadu
        // určuje pokladna (applier kód u vázaných typů ignoruje).
        $w->writeJsonc('docs/0002-14itds2600001.jsonc', [
            'format' => 'shpd.docs.document', 'formatVersion' => '1.0',
            'docType' => 'cashRegisterDocument', 'docText' => self::P . ' prodejka', 'selfParty' => 'supplier',
            'cashDesk' => self::CASH_DESK_CODE,
            'dates' => ['issueDate' => '2026-06-02', 'accountingDate' => '2026-06-02', 'taxPointDate' => '2026-06-02'],
            'currency' => 'CZK', 'vat' => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz'],
            'payment' => ['method' => 'cash'],
            'rows' => [[
                'rowKind' => 'item', 'operation' => 'sale.goods', 'orderPos' => 1,
                'item' => ['ourCode' => self::ITEM_CODE, 'name' => self::P . ' Konzultace'], 'unit' => 'hr',
                'quantity' => 1.0, 'unitPrice' => 500.0, 'totalPrice' => 500.0, 'priceCalcMode' => 'fromUnitPrice',
                'vat' => ['code' => 'cz-110', 'pct' => 21.0], 'description' => 'Prodej IT-DS',
            ]],
            'vatRecap' => [['vatCode' => 'cz-110', 'vatPct' => 21.0, 'base' => 500.0, 'tax' => 105.0, 'total' => 605.0]],
            'totals' => ['totalBase' => 500.0, 'totalVat' => 105.0, 'totalAmount' => 605.0, 'totalRounding' => 0.0],
            'applyOptions' => [
                'targetDocState' => 40,
                'importNumber' => ['docNumber' => self::CASHREG_NUMBER, 'sequenceNumber' => 1],
            ],
        ]);
        $w->writeJsonc('registry/0001-it-ds-smlouva.jsonc', [
            'format' => 'shpd.dataset.registryDocument.v1',
            'document' => [
                'schema' => 'shpd.registry.document.v1', 'docType' => 'contract', 'title' => self::REGISTRY_TITLE,
                'summary' => 'Testovací smlouva.', 'party' => ['name' => self::P . ' Dodavatel s.r.o.', 'companyId' => self::COMPANY_ID],
                'kindFields' => ['contractNumber' => 'SML-IT-DS-1', 'subject' => 'dílo'],
            ],
            'docState' => 40, 'sourceKind' => 'mail', 'binder' => self::BINDER, 'refNumber' => 'SML-IT-DS-1',
            'created' => '2026-06-02T09:00:00',
            'attachments' => [['file' => 'smlouva.pdf', 'name' => 'smlouva.pdf', 'mimeType' => 'application/pdf']],
        ]);
        $w->writeRaw('registry/0001-it-ds-smlouva.files/smlouva.pdf', '%PDF-IT-DS-smlouva');
        $w->writeJsonc('mail/0001-msg-it-ds-0001.jsonc', [
            'format' => 'shpd.mail.incomingMessage', 'formatVersion' => '1.0', 'messageId' => self::MESSAGE_ID,
            'mailbox' => 'default', 'primaryType' => 'invoiceReceived', 'primaryTypeSource' => 'ai',
            'subject' => self::P . ' faktura', 'senderEmail' => 'fakturace@it-ds.example', 'senderName' => self::P . ' Dodavatel',
            'senderPerson' => ['companyId' => self::COMPANY_ID],
            'receivedAt' => '2026-06-01T07:55:10', 'sourceType' => 1, 'docState' => 40, 'analysisState' => 30,
            'created' => '2026-06-01T07:55:11',
            'target' => ['table' => 'docs_core_heads', 'docNumber' => self::DOC_NUMBER],
            'attachments' => [['file' => 'faktura.pdf', 'name' => 'faktura.pdf', 'mimeType' => 'application/pdf', 'order' => 0]],
            'analyses' => [[
                'analyzedAt' => '2026-06-01T08:00:00', 'status' => 2, 'modelName' => 'claude-test', 'promptVersion' => 'v4.2.0',
                'analysisJson' => ['overall_confidence' => 0.9, 'raw' => new \stdClass()],
                'canonicalJson' => ['format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'docType' => 'invoiceReceived',
                                    'attachments' => [['filename' => 'faktura.pdf', 'kind' => 'original', 'ref' => 'att:1']]],
                'confidence' => 0.9, 'proposedType' => 'invoiceReceived', 'resolution' => 40, 'resolvedAt' => '2026-06-01T09:00:00',
            ]],
        ]);
        $w->writeRaw('mail/0001-msg-it-ds-0001.files/faktura.pdf', '%PDF-IT-DS-faktura');
        $w->writeManifest(new DatasetManifest('it-ds', 'Integration round-trip', null, 'fixed', '2026-08-26T10:00:00Z',
            ['persons' => 1, 'items' => 1, 'docs' => 2, 'registry' => 1, 'mail' => 1]));
    }

    // ── úklid ───────────────────────────────────────────────────────────────

    private function cleanup(): void
    {
        $dibi = $this->db->getDibiConnection();

        foreach ($dibi->fetchAll('SELECT id FROM core_mail_incoming_messages WHERE message_id = %s', self::MESSAGE_ID) as $r) {
            $dibi->query('DELETE FROM core_mail_message_analyses WHERE message = %i', $r['id']);
            $dibi->query('DELETE FROM core_attachments_files WHERE table_id = 303 AND record_id = %i', $r['id']);
            $dibi->query('DELETE FROM core_mail_incoming_messages WHERE id = %i', $r['id']);
        }
        foreach ($dibi->fetchAll('SELECT id FROM base_registry_documents WHERE title = %s', self::REGISTRY_TITLE) as $r) {
            $dibi->query('DELETE FROM core_attachments_files WHERE table_id = 428 AND record_id = %i', $r['id']);
            $dibi->query('DELETE FROM base_registry_documents WHERE id = %i', $r['id']);
        }
        $dibi->query('DELETE FROM base_registry_binders WHERE name = %s', self::BINDER);
        // Doklady podle čísla i podle testovací pokladny (pád s jiným číslem).
        $deskIds = array_map('intval', $dibi->fetchPairs('SELECT id, id FROM economy_codebooks_cash_desks WHERE code = %s', self::CASH_DESK_CODE));
        $docs = $dibi->fetchAll(
            'SELECT id FROM docs_core_heads WHERE doc_number IN %in' . ($deskIds !== [] ? ' OR cash_desk IN %in' : ''),
            [self::DOC_NUMBER, self::CASHREG_NUMBER],
            ...($deskIds !== [] ? [$deskIds] : []),
        );
        foreach ($docs as $r) {
            $dibi->query('DELETE FROM economy_accounting_journal WHERE doc_head = %i', $r['id']);
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $r['id']);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $r['id']);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $r['id']);
        }
        // Pokladna + vázané řady (založil je applier) + jejich čítače.
        foreach ($deskIds as $deskId) {
            foreach ($dibi->fetchAll('SELECT id FROM docs_core_number_series WHERE cash_desk = %i', $deskId) as $s) {
                $dibi->query('DELETE FROM docs_core_number_counters WHERE number_series = %i', $s['id']);
                $dibi->query('DELETE FROM docs_core_number_series WHERE id = %i', $s['id']);
            }
            $dibi->query('DELETE FROM economy_codebooks_cash_desks WHERE id = %i', $deskId);
        }
        foreach ($dibi->fetchAll('SELECT id FROM economy_items WHERE code = %s', self::ITEM_CODE) as $r) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE item = %i', $r['id']);
            $dibi->query('DELETE FROM economy_items WHERE id = %i', $r['id']);
        }
        foreach ($dibi->fetchAll('SELECT id FROM base_persons_persons WHERE company_id = %s', self::COMPANY_ID) as $r) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $r['id']);
            $dibi->query('DELETE FROM base_persons_addresses WHERE person = %i', $r['id']);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $r['id']);
            $dibi->query('DELETE FROM base_persons_contacts WHERE person = %i', $r['id']);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $r['id']);
        }
    }
}
