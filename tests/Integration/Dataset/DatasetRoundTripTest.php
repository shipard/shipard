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
use Shipard\Module\Core\Exchange\Dataset\Seed\SetupSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Bank\BankStatementApplier;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Export\DocumentExporter;
use Shipard\Module\Core\Exchange\Export\ItemExporter;
use Shipard\Module\Core\Exchange\Export\PersonExporter;
use Shipard\Module\Core\Exchange\Export\SetupExporter;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Mail\Dataset\MailExporter;
use Shipard\Module\Core\Mail\Dataset\MailSeeder;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Economy\Assets\AssetAcquisitionService;
use Shipard\Module\Economy\Assets\Dataset\AssetEventsSeeder;
use Shipard\Module\Economy\Assets\Dataset\AssetExporter;
use Shipard\Module\Economy\Assets\Dataset\AssetSeeder;
use Shipard\Module\Economy\Bank\Dataset\BankStatementExporter;
use Shipard\Module\Economy\Bank\Dataset\BankStatementSeeder;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderExporter;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderPeriodsSeeder;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderSeeder;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;
use Shipard\Module\Economy\WorkOrders\Invoicing\ContentHash;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Round-trip datové sady proti živému DS (`SHIPARD_INTEGRATION_DS_PATH`):
 * syntetická sada (osoba, položka, přijatá faktura ve 40, prodejka ve 40
 * na vlastní pokladně, bankovní výpis s úhradou té faktury, nespárovaným
 * příjmem a poplatkem, dokument spisovny s přílohou, zpráva s přílohou +
 * snapshotem analýzy a vazbou na doklad) → seed v merge režimu (bez resetu,
 * sdílený DS) → export jen vložených záznamů → porovnání s sadou +
 * invarianty (zaúčtování, lineage, `att:` remapa, přílohy na disku, pokladna
 * a vázaná řada prodejky, partner a předpis dohledané z výpisu). Uklízí po
 * sobě podle přirozených klíčů s prefixem `IT-DS`; number counters se nehnou
 * (sequenceNumber = null) — jen řada testovací pokladny dostane čítač, a ta
 * se maže celá.
 *
 * Pokladnu i bankovní účet test vkládá přímo do číselníku (bez Document
 * lifecycle, tedy bez `CashDeskSeriesEventHandler`) — vázané řady `cash` /
 * `cashreg` zakládá až applier líně přes `BoundNumberSeriesProvisioner`
 * (#59 D12); účet nese analytiku 221100, jinak by bankovní engine hlásil
 * `bank_account_not_found` (#131 fáze 2a).
 *
 * Majetek (#131 fáze 2c, D38, D40): skupina typů a typ v setup/, karta
 * serveru se zařazením v assets/ (seed bez importního módu → původ
 * `manual`), přijatá faktura s řádkem pořízení `purchase.asset` s kartou
 * v `dimensions`, a účetní doklad, kterému test po seedu přepíše řádek na
 * `asset.activation` — exportér dokladů ho má vynechat s varováním.
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
    private const BANK_ACCOUNT_CODE = 'ITDSBU';
    private const STATEMENT_EXTERNAL_ID = 'IT-DS-BU-2026-06';
    private const BANK_FILE = 'bank/0001-itdsbu-2026-06.jsonc';
    /** Zakázky (#131 fáze 2b): druh a řada v setup/, periodická zakázka s obdobím na vydanou fakturu. */
    private const WO_KIND = 'IT-DS Paušál';
    private const WO_SERIES_CODE = 'ITDSS';
    private const WO_NUMBER = 'ITDSS0001';
    private const WO_FILE = 'workOrders/0001-itdss0001.jsonc';
    private const INVNO_NUMBER = 'IT-DS-1001';
    /** Majetek (#131 fáze 2c): typ v setup/, karta v assets/, faktura s pořízením, doklad zaúčtování. */
    private const ASSET_TYPE_GROUP = 'IT-DS Hmotný majetek';
    private const ASSET_TYPE = 'IT-DS Výpočetní technika';
    private const ASSET_NUMBER = 'ITDSMA0001';
    private const ASSET_FILE = 'assets/0001-itdsma0001.jsonc';
    private const ASSET_DOC_NUMBER = 'IT-DS-0002';
    private const POSTING_DOC_NUMBER = 'IT-DS-6001';

    private string $setDir;
    private ConfigRuntime $config;
    private SeedContext $ctx;
    private ?string $seriesCode = null;
    private int $cashDeskId = 0;
    /** Sekce bank jen s modulem banky a analytikou 221100 na DS. */
    private bool $hasBank = false;
    private int $bankAccountId = 0;
    /** Sekce workOrders jen s modulem zakázek, vlastním účtem (faktura ve 40) a jedinou aktivní řadou invno. */
    private bool $hasWorkOrders = false;
    /** @var array{docType: string, code?: string} reference řady invno pro setup (D37) */
    private array $invnoSeries = ['docType' => 'invno'];
    private ?string $invnoCode = null;
    /** Sekce assets jen s modulem majetku, úplnou účetní skupinou 022, účtem 042100 a účetním rokem data zařazení. */
    private bool $hasAssets = false;

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

        // Bankovní účet přímo do číselníku (#131 fáze 2a) — s analytikou 221100.
        $bankChart = $dibi->fetch("SELECT id FROM economy_accounting_accounts WHERE number = '221100' AND docState <> 90");
        $this->hasBank = isset($this->tables['economy_bank_statements'], $this->tables['economy_bank_transactions']) && $bankChart !== null;
        if ($this->hasBank) {
            $dibi->query('INSERT INTO [economy_codebooks_bank_accounts] %v', [
                'code' => self::BANK_ACCOUNT_CODE, 'name' => self::P . ' Běžný účet', 'account_number' => '9999000002/0100',
                'iban' => 'CZ0901000000009999000002', 'bic' => 'KOMBCZPP', 'currency' => 'czk', 'is_default' => 0, 'sort_order' => 0,
                'docState' => 40, 'docStateMain' => 3, 'accounting_account' => (int) $bankChart['id'],
            ]);
            $this->bankAccountId = (int) $dibi->getInsertId();
        }

        // Zakázky (#131 fáze 2b): vydaná faktura ve 40 potřebuje vlastní účet,
        // druh zakázky odkazuje řadu invno složeným klíčem {docType, code} (D37).
        $invnoSeries = $dibi->fetchAll("SELECT id, doc_number_code FROM docs_core_number_series WHERE doc_type = 'invno' AND docState IN (10, 40, 80)");
        $this->hasWorkOrders = $this->hasBank
            && isset($this->tables['economy_work_orders_heads'], $this->tables['economy_work_orders_rows'],
                $this->tables['economy_work_orders_periods'], $this->tables['economy_work_orders_kinds'],
                $this->tables['economy_work_orders_number_series'])
            && count($invnoSeries) === 1;
        if ($this->hasWorkOrders) {
            $code = trim((string) $invnoSeries[0]['doc_number_code']);
            $this->invnoCode = $code !== '' ? $code : null;
            $this->invnoSeries = ['docType' => 'invno'] + ($this->invnoCode !== null ? ['code' => $this->invnoCode] : []);
        }

        // Majetek (#131 fáze 2c): dlouhodobá karta jde potvrdit jen s úplnou
        // účetní skupinou (D57), řádek pořízení nese účet 042100 přímo, ruční
        // zařazení musí ležet v založeném účetním roce (D58).
        $this->hasAssets = isset($this->tables['economy_assets_assets'], $this->tables['economy_assets_events'],
                $this->tables['economy_assets_types'], $this->tables['economy_assets_type_groups'],
                $this->tables['economy_assets_accounting_groups'])
            && $dibi->fetch("SELECT id FROM economy_assets_accounting_groups WHERE code = '022' AND docState <> 90
                 AND account_depreciation IS NOT NULL AND account_accumulated IS NOT NULL") !== null
            && $dibi->fetch("SELECT id FROM economy_accounting_accounts WHERE number = '042100' AND docState <> 90") !== null
            && $dibi->fetch("SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= '2026-02-14' AND date_end >= '2026-02-14' AND docState <> 90") !== null;

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
            new SetupSeeder(),
            new PersonSeeder($personApplier),
            new ItemSeeder(ItemApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, $personApplier)),
        ];
        if ($this->hasWorkOrders) {
            // Zakázky před doklady: faktura je odkazuje číslem (dimenze workOrder).
            $seeders[] = new WorkOrderSeeder(
                WorkOrderImportApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables),
                $party,
                new ItemResolver($dibi),
            );
        }
        if ($this->hasAssets) {
            // Karty před doklady: řádek pořízení odkazuje kartu inventárním číslem.
            $seeders[] = new AssetSeeder($party);
        }
        $seeders[] = new DocumentSeeder(DocumentApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, $this->ctx->dispatcher));
        if ($this->hasWorkOrders) {
            // Evidence období až za doklady: odkazuje číslo vydané faktury.
            $seeders[] = new WorkOrderPeriodsSeeder();
        }
        if ($this->hasAssets) {
            // Události až za doklady (D40), bez importního módu.
            $seeders[] = new AssetEventsSeeder();
        }
        if ($this->hasBank) {
            // Až za doklady: mikroengine dohledává předpis právě naseedované faktury.
            $seeders[] = new BankStatementSeeder(
                BankStatementApplier::create($dibi, $this->config, $this->dsConfig, $this->ctx->registry, $this->tables, $this->ctx->dispatcher),
            );
        }
        $seeders[] = new RegistrySeeder(new RegistryApplier($this->db, $this->ctx->registry, $this->ctx->attachments, $this->config, $party), $party);
        $seeders[] = new MailSeeder($party);

        $report = (new DatasetSeeder())->seed($this->ctx, $seeders);

        $this->assertSame([], $report->errors(), implode("\n", $report->errors()));
        $counts = $report->counts();
        $this->assertSame(1, $counts['persons']['ok']);
        $this->assertSame(1, $counts['items']['ok']);
        $this->assertSame(
            2 + ($this->hasWorkOrders ? 1 : 0) + ($this->hasAssets ? 2 : 0),
            $counts['docs']['ok'],
            'faktura přijatá + prodejka (+ vydaná ze zakázky) (+ pořízení majetku + účetní doklad)',
        );
        $this->assertSame(1, $counts['registry']['ok']);
        $this->assertSame(1, $counts['mail']['ok']);
        if ($this->hasBank) {
            $this->assertSame(1, $counts['bank']['ok'], 'výpis');
        }
        $setupFiles = ($this->hasWorkOrders ? 2 : 0) + ($this->hasAssets ? 2 : 0);
        if ($setupFiles > 0) {
            $this->assertSame($setupFiles, $counts['setup']['ok'], 'druhy a řady zakázek, skupina typů a typ majetku');
        }
        if ($this->hasWorkOrders) {
            $this->assertSame(1, $counts['workOrders']['ok']);
            $this->assertSame(1, $counts['workOrderPeriods']['ok']);
        }
        if ($this->hasAssets) {
            $this->assertSame(1, $counts['assets']['ok']);
            $this->assertSame(1, $counts['assetEvents']['ok']);
        }

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

        // Bankovní výpis (#131 fáze 2a): partner z protiúčtu, výchozí pohyb,
        // stav 40 zaúčtovaný; úhrada faktury našla předpis (321), ne clearing.
        $statementId = 0;
        if ($this->hasBank) {
            $statementId = (int) $dibi->fetchSingle(
                'SELECT id FROM economy_bank_statements WHERE bank_account = %i AND external_id = %s',
                $this->bankAccountId, self::STATEMENT_EXTERNAL_ID,
            );
            $this->assertGreaterThan(0, $statementId);
            $stmt = $dibi->fetch('SELECT * FROM economy_bank_statements WHERE id = %i', $statementId)->toArray();
            $this->assertSame(40, (int) $stmt['docState']);
            $this->assertSame('czk', $stmt['currency'], 'měna výpisu lowercase na hranici DB');
            $this->assertSame('6/2026', $stmt['statement_number']);

            $txs = [];
            foreach ($dibi->fetchAll('SELECT * FROM economy_bank_transactions WHERE statement = %i ORDER BY date_transaction, id', $statementId) as $t) {
                $txs[$t['external_id']] = $t->toArray();
            }
            $this->assertSame(['IT-DS-BU-202606-001', 'IT-DS-BU-202606-002', 'IT-DS-BU-202606-003'], array_keys($txs), 'pořadí vložení = pořadí v souboru');
            $payment = $txs['IT-DS-BU-202606-001'];
            $this->assertSame(40, (int) $payment['docState']);
            $this->assertSame(2, (int) $payment['direction'], 'záporná částka = výdaj');
            $this->assertSame(1210.0, (float) $payment['amount']);
            $this->assertSame('payment.out', $payment['operation'], 'výchozí pohyb doplnil applier');
            $this->assertSame($personId, (int) $payment['partner'], 'partner dohledaný z protiúčtu v bankovních spojeních osoby');
            $this->assertNull($txs['IT-DS-BU-202606-002']['partner'], 'neznámý protiúčet → bez partnera');
            $this->assertSame('payment.in', $txs['IT-DS-BU-202606-002']['operation']);
            $this->assertSame('fee.out', $txs['IT-DS-BU-202606-003']['operation'], 'výslovný pohyb zůstal');
            if ($hasChart) {
                foreach ($txs as $ext => $t) {
                    $this->assertSame(1, (int) $t['accounting_state'], "{$ext}: zaúčtována bez chyby");
                }
                $accounts = array_map('strval', $dibi->fetchPairs(
                    'SELECT id, account_number FROM economy_accounting_journal WHERE bank_transaction = %i',
                    (int) $payment['id'],
                ));
                $this->assertNotEmpty(array_filter($accounts, static fn(string $a): bool => str_starts_with($a, '321')), 'úhrada přijaté faktury našla předpis 321: ' . implode(', ', $accounts));
                $this->assertSame([], array_filter($accounts, static fn(string $a): bool => str_starts_with($a, '2613')), 'úhrada nešla na clearing');
            }
        }

        // Zakázky (#131 fáze 2b): setup → druh a řada, applier → zakázka V pořádku
        // s předpisem, evidence období na vydanou fakturu s otiskem uloženého
        // dokladu, faktura nese zdroj i dimenzi zakázky (deník ji dědí z hlavičky).
        $workOrderId = 0;
        $invnoId = 0;
        if ($this->hasWorkOrders) {
            $kind = $dibi->fetch('SELECT * FROM economy_work_orders_kinds WHERE name = %s AND docState <> 90', self::WO_KIND);
            $this->assertNotNull($kind, 'druh ze setup/work_order_kinds.jsonc');
            $invnoSeriesId = (int) $dibi->fetchSingle("SELECT id FROM docs_core_number_series WHERE doc_type = 'invno' AND docState IN (10, 40, 80)");
            $this->assertSame($invnoSeriesId, (int) $kind['inv_number_series'], 'řada dokladů přes {docType, code} (D37)');
            $this->assertSame($this->bankAccountId, (int) $kind['inv_bank_account'], 'účet kódem');
            $series = $dibi->fetch('SELECT * FROM economy_work_orders_number_series WHERE number_code = %s AND docState <> 90', self::WO_SERIES_CODE);
            $this->assertNotNull($series);
            $this->assertSame((int) $kind['id'], (int) $series['kind'], 'druh názvem');

            $workOrder = $dibi->fetch('SELECT * FROM economy_work_orders_heads WHERE number = %s AND docState <> 90', self::WO_NUMBER);
            $this->assertNotNull($workOrder, 'zakázka přes WorkOrderImportApplier');
            $workOrder = $workOrder->toArray();
            $workOrderId = (int) $workOrder['id'];
            $this->assertSame(40, (int) $workOrder['docState'], 'cílový stav V pořádku');
            $this->assertSame((int) $series['id'], (int) $workOrder['number_series']);
            $this->assertSame('periodic', $workOrder['type'], 'typ denormalizovaný z řady');
            $this->assertSame($personId, (int) $workOrder['customer'], 'zákazník podle identifikátorů');
            $this->assertSame(1, (int) $workOrder['sequence_number'], 'převzaté pořadí');
            $this->assertSame(
                1,
                (int) $dibi->fetchSingle('SELECT MAX(last_assigned) FROM economy_work_orders_number_counters WHERE number_series = %i', (int) $series['id']),
                'čítač řady zakázek srovnaný',
            );
            $woRows = $dibi->fetchAll('SELECT * FROM economy_work_orders_rows WHERE work_order = %i ORDER BY order_pos', $workOrderId);
            $this->assertCount(2, $woRows, 'řádky předpisu s platností');
            $this->assertSame($itemId, (int) $woRows[0]['item']);

            $invnoId = (int) $dibi->fetchSingle('SELECT id FROM docs_core_heads WHERE doc_number = %s AND docState <> 90', self::INVNO_NUMBER);
            $this->assertGreaterThan(0, $invnoId);
            $invno = $dibi->fetch('SELECT * FROM docs_core_heads WHERE id = %i', $invnoId)->toArray();
            $this->assertSame('workOrder', $invno['source_kind'], 'zdroj dokladu = periodická fakturace');
            $this->assertSame($workOrderId, (int) $invno['work_order'], 'dimenze zakázky na hlavičce podle čísla');
            if ($hasChart) {
                $journalWo = array_map('intval', $dibi->fetchPairs('SELECT id, work_order FROM economy_accounting_journal WHERE doc_head = %i', $invnoId));
                $this->assertNotEmpty($journalWo);
                $this->assertSame([$workOrderId], array_values(array_unique($journalWo)), 'deník dědí zakázku z hlavičky dokladu');
            }

            $period = $dibi->fetch('SELECT * FROM economy_work_orders_periods WHERE work_order = %i', $workOrderId);
            $this->assertNotNull($period, 'evidence období z periods[]');
            $period = $period->toArray();
            $this->assertSame('issued', $period['state']);
            $this->assertSame($invnoId, (int) $period['doc'], 'doklad číslem mezi vydanými');
            $this->assertSame(ContentHash::ofStoredDocument($dibi, $invnoId), $period['content_hash'], 'otisk jako po běhu');
        }

        // Majetek (#131 fáze 2c): typ ze setup/ s FK přirozeným klíčem, karta
        // V pořádku s převzatým číslem, zařazení potvrzené s původem manual
        // (žádný importní mód) a bez dokladu, datum pořízení karty ze zařazení,
        // řádek pořízení s kartou a účtem 042100 → sekce Pořízení = zařazení.
        $assetId = 0;
        $assetDocId = 0;
        $postingId = 0;
        if ($this->hasAssets) {
            $typeGroup = $dibi->fetch('SELECT * FROM economy_assets_type_groups WHERE name = %s AND docState <> 90', self::ASSET_TYPE_GROUP);
            $this->assertNotNull($typeGroup, 'skupina typů ze setup/asset_type_groups.jsonc');
            $type = $dibi->fetch('SELECT * FROM economy_assets_types WHERE name = %s AND docState <> 90', self::ASSET_TYPE);
            $this->assertNotNull($type, 'typ ze setup/asset_types.jsonc');
            $groupId = (int) $dibi->fetchSingle("SELECT id FROM economy_assets_accounting_groups WHERE code = '022' AND docState <> 90");
            $this->assertSame((int) $typeGroup['id'], (int) $type['type_group'], 'skupina typů názvem');
            $this->assertSame($groupId, (int) $type['default_accounting_group'], 'výchozí účetní skupina kódem');

            $card = $dibi->fetch('SELECT * FROM economy_assets_assets WHERE asset_number = %s', self::ASSET_NUMBER);
            $this->assertNotNull($card, 'karta přes AssetDocument');
            $card = $card->toArray();
            $assetId = (int) $card['id'];
            $this->assertSame(40, (int) $card['docState'], 'karta V pořádku');
            $this->assertSame((int) $type['id'], (int) $card['asset_type'], 'typ názvem');
            $this->assertSame($groupId, (int) $card['accounting_group'], 'účetní skupina kódem');
            $this->assertSame('2026-02-14', substr((string) $card['acquired_date'], 0, 10), 'datum pořízení dosadilo potvrzené zařazení (D38)');
            $this->assertNull($card['price'], 'dlouhodobá karta cenu nenese (D13)');

            $events = $dibi->fetchAll('SELECT * FROM economy_assets_events WHERE asset = %i AND docState <> 90', $assetId);
            $this->assertCount(1, $events, 'jediná událost: zařazení');
            $event = $events[0]->toArray();
            $this->assertSame('activation', $event['event_kind']);
            $this->assertSame(40, (int) $event['docState'], 'potvrzená');
            $this->assertSame('manual', $event['origin'], 'původ manual — seed nejede importním módem (D38)');
            $this->assertNull($event['doc_head'], 'nezaúčtovaná');
            $this->assertSame(150000.0, (float) $event['amount']);

            $assetDocId = (int) $dibi->fetchSingle('SELECT id FROM docs_core_heads WHERE doc_number = %s AND docState <> 90', self::ASSET_DOC_NUMBER);
            $this->assertGreaterThan(0, $assetDocId);
            $assetRow = $dibi->fetch('SELECT * FROM docs_core_rows WHERE doc_head = %i ORDER BY order_pos LIMIT 1', $assetDocId)->toArray();
            $this->assertSame('purchase.asset', $assetRow['operation']);
            $this->assertSame($assetId, (int) $assetRow['asset'], 'karta na řádku podle inventárního čísla (dimenze asset)');
            $this->assertSame(
                (int) $dibi->fetchSingle("SELECT id FROM economy_accounting_accounts WHERE number = '042100' AND docState <> 90"),
                (int) $assetRow['account'],
                'účet pořízení přímo na řádku (rowAccount direct)',
            );
            if ($hasChart) {
                $journal = $dibi->fetchAll('SELECT account_number, asset FROM economy_accounting_journal WHERE doc_head = %i', $assetDocId);
                $withCard = array_values(array_filter($journal, static fn($j): bool => (int) $j['asset'] === $assetId));
                $this->assertNotEmpty($withCard, 'deník nese kartu z řádku pořízení');
                $this->assertSame(['042100'], array_values(array_unique(array_map(static fn($j): string => (string) $j['account_number'], $withCard))));
            }
            $acquisition = (new AssetAcquisitionService($dibi))->acquisition($assetId);
            $this->assertSame(150000.0, $acquisition['activation']['amount'], 'sekce Pořízení = částka zařazení');
            $this->assertSame('2026-02-10', $acquisition['activation']['date']);

            // Účetní doklad zaúčtování majetku: řádek `asset.*` (po seedu přepsaný
            // přímo v DB — zaúčtování majetku přes applier nejde) exportér vynechá.
            $postingId = (int) $dibi->fetchSingle('SELECT id FROM docs_core_heads WHERE doc_number = %s AND docState <> 90', self::POSTING_DOC_NUMBER);
            $this->assertGreaterThan(0, $postingId);
            $dibi->query('UPDATE docs_core_rows SET operation = %s, asset = %i WHERE doc_head = %i', 'asset.activation', $assetId, $postingId);
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

        if ($this->hasBank) {
            $bankExporter = new BankStatementExporter($dibi, 'czk');
            $bankOut = $bankExporter->exportByIds([$statementId])[0];
            $this->assertSame(self::BANK_ACCOUNT_CODE . '-2026-06', $bankOut->slug);
            $this->assertSame(
                $reader->readJsonc(self::BANK_FILE),
                $bankOut->data,
                'výpis se vrátí beze změny — dopočtený partner, pohyb a účtování se nepřenáší',
            );
            $this->assertSame([], $bankExporter->getWarnings());
        }

        if ($this->hasWorkOrders) {
            // Setup: druh a řada zakázek se vrátí beze změny (FK přirozeným klíčem).
            $setupOut = [];
            foreach ((new SetupExporter($dibi, $this->tables))->exportAll() as $record) {
                $setupOut[$record->slug] = $record->data;
            }
            $kindRows = array_values(array_filter($setupOut['work_order_kinds']['rows'], static fn(array $r): bool => $r['name'] === self::WO_KIND));
            $this->assertSame($reader->readJsonc('setup/work_order_kinds.jsonc')['rows'], $kindRows, 'druh: řada dokladů {docType, code}, účet kódem');
            $seriesRows = array_values(array_filter($setupOut['work_order_series']['rows'], static fn(array $r): bool => $r['number_code'] === self::WO_SERIES_CODE));
            $this->assertSame($reader->readJsonc('setup/work_order_series.jsonc')['rows'], $seriesRows, 'řada: druh názvem');

            $woExporter = new WorkOrderExporter($dibi, $this->config, $this->tables);
            $woOut = $woExporter->exportByIds([$workOrderId])[0];
            $this->assertSame(self::WO_NUMBER, $woOut->slug);
            $srcWo = $reader->readJsonc(self::WO_FILE);
            $this->assertSame(self::COMPANY_ID, $woOut->data['workOrder']['customer']['companyId']);
            $this->assertSame($srcWo['workOrder']['customer']['name'], $woOut->data['workOrder']['customer']['name']);
            $srcWoLoose = $srcWo;
            $outWoLoose = $woOut->data;
            unset($srcWoLoose['workOrder']['customer'], $outWoLoose['workOrder']['customer']);
            $this->assertSame($srcWoLoose, $outWoLoose, 'zakázka se vrátí beze změny — předpis s platností, období s číslem dokladu');
            $this->assertSame([], $woExporter->getWarnings());

            $invnoOut = (new DocumentExporter($dibi, $this->config, ['economy_work_orders_heads']))->exportByIds([$invnoId])[0]->data;
            $this->assertSame(['kind' => 'workOrder'], $invnoOut['source']);
            $this->assertSame(['workOrder' => self::WO_NUMBER], $invnoOut['dimensions'], 'dimenze zakázky přežije round-trip');
            $this->assertArrayNotHasKey('dimensions', $invnoOut['rows'][0], 'řádek dimenzi nenese (deník ji dědí z hlavičky)');
        }

        if ($this->hasAssets) {
            // Setup: skupina typů a typ se vrátí beze změny (FK přirozeným klíčem).
            $setupOut = [];
            foreach ((new SetupExporter($dibi, $this->tables))->exportAll() as $record) {
                $setupOut[$record->slug] = $record->data;
            }
            $groupRows = array_values(array_filter($setupOut['asset_type_groups']['rows'], static fn(array $r): bool => $r['name'] === self::ASSET_TYPE_GROUP));
            $this->assertSame($reader->readJsonc('setup/asset_type_groups.jsonc')['rows'], $groupRows);
            $typeRows = array_values(array_filter($setupOut['asset_types']['rows'], static fn(array $r): bool => $r['name'] === self::ASSET_TYPE));
            $this->assertSame($reader->readJsonc('setup/asset_types.jsonc')['rows'], $typeRows, 'typ: skupina typů názvem, výchozí účetní skupina kódem');

            $assetExporter = new AssetExporter($dibi, $this->config, $this->tables);
            $assetOut = $assetExporter->exportByIds([$assetId])[0];
            $this->assertSame(self::ASSET_NUMBER, $assetOut->slug);
            $this->assertSame($reader->readJsonc(self::ASSET_FILE), $assetOut->data, 'karta se vrátí beze změny — typ názvem, skupina kódem, zařazení s původem manual');
            $this->assertSame([], $assetExporter->getWarnings());

            $withAssets = new DocumentExporter($dibi, $this->config, ['economy_assets_assets']);
            $assetDocs = $withAssets->exportByIds([$assetDocId, $postingId]);
            $this->assertSame([$assetDocId], array_map(static fn($r) => $r->id, $assetDocs), 'doklad zaúčtování majetku (řádky asset.*) se nepřenáší');
            $row = $assetDocs[0]->data['rows'][0];
            $this->assertSame('purchase.asset', $row['operation']);
            $this->assertSame('042100', $row['account']);
            $this->assertSame(['asset' => self::ASSET_NUMBER], $row['dimensions'], 'dimenze majetku přežije round-trip');
            $this->assertArrayNotHasKey('dimensions', $assetDocs[0]->data, 'hlavička kartu nenese');
            $warnings = $withAssets->getWarnings();
            $this->assertCount(1, $warnings, implode("\n", $warnings));
            $this->assertStringContainsString('docs ' . self::POSTING_DOC_NUMBER . ': doklad zaúčtování majetku', $warnings[0]);
        }

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
        // Výpis (#131 fáze 2a): úhrada přijaté faktury (protiúčet osoby + VS
        // faktury → partner i předpis dohledá aplikace), nespárovaný příjem
        // z neznámého účtu, poplatek s výslovným pohybem. Klíče v pořadí
        // schématu, aby export byl shodný se zdrojem.
        if ($this->hasBank) {
            $w->writeJsonc(self::BANK_FILE, [
                'format'      => 'shpd.dataset.bankStatement.v1',
                'bankAccount' => self::BANK_ACCOUNT_CODE,
                'statement'   => [
                    'statementNumber' => '6/2026', 'externalId' => self::STATEMENT_EXTERNAL_ID,
                    'periodStart' => '2026-06-01', 'periodEnd' => '2026-06-30',
                    'openingBalance' => 10000.0, 'closingBalance' => 9265.0, 'currency' => 'CZK',
                ],
                'transactions' => [
                    ['externalId' => 'IT-DS-BU-202606-001', 'amount' => -1210.0, 'dateTransaction' => '2026-06-10',
                     'counterpartyAccount' => '9999000001/0100', 'counterpartyName' => self::P . ' Dodavatel s.r.o.', 'paymentReference' => '20260601'],
                    ['externalId' => 'IT-DS-BU-202606-002', 'amount' => 500.0, 'dateTransaction' => '2026-06-15',
                     'counterpartyAccount' => '9999000003/0100', 'counterpartyName' => self::P . ' Neznámý plátce', 'paymentReference' => '777'],
                    ['externalId' => 'IT-DS-BU-202606-003', 'amount' => -25.0, 'dateTransaction' => '2026-06-30',
                     'message' => 'Poplatek za vedení účtu', 'operation' => 'fee.out'],
                ],
                'docState' => 40,
            ]);
        }
        // Zakázky (#131 fáze 2b): druh a řada v setup/ (FK přirozeným klíčem,
        // řada dokladů složeným klíčem D37), periodická zakázka s předpisem
        // s platností a vystaveným obdobím na vydanou fakturu, která nese
        // zdroj i dimenzi zakázky. Klíče v pořadí exportérů (round-trip).
        if ($this->hasWorkOrders) {
            $w->writeJsonc('setup/work_order_kinds.jsonc', [
                'format' => 'shpd.dataset.setup.v1', 'table' => 'work_order_kinds',
                'rows' => [[
                    'name' => self::WO_KIND, 'type' => 'periodic', 'notice' => null,
                    'inv_doc_type' => 'invno', 'inv_number_series' => $this->invnoSeries, 'inv_due_days' => 14, 'inv_timing' => 'start',
                    'inv_vat_mode' => 1, 'inv_payment_method' => 1, 'inv_bank_account' => self::BANK_ACCOUNT_CODE, 'docState' => 40,
                ]],
            ]);
            $w->writeJsonc('setup/work_order_series.jsonc', [
                'format' => 'shpd.dataset.setup.v1', 'table' => 'work_order_series',
                'rows' => [[
                    'kind' => self::WO_KIND, 'name' => self::P . ' Paušály', 'notice' => null, 'number_code' => self::WO_SERIES_CODE,
                    'number_pattern' => '%C%4', 'reset_scope' => 'none', 'valid_from' => null, 'valid_to' => null, 'docState' => 40,
                ]],
            ]);
            $w->writeJsonc(self::WO_FILE, [
                'format'    => 'shpd.dataset.workOrder.v1',
                'workOrder' => [
                    'number' => self::WO_NUMBER, 'sequenceNumber' => 1, 'numberSeries' => self::WO_SERIES_CODE,
                    'title' => self::P . ' Správa IT', 'state' => 'confirmed', 'dateStart' => '2025-01-01',
                    'customer' => ['name' => self::P . ' Dodavatel s.r.o.', 'country' => 'cz', 'companyId' => self::COMPANY_ID, 'vatId' => 'CZ' . self::COMPANY_ID],
                    'currency' => 'czk',
                    'invoicing' => [
                        'periodicity' => 'month', 'invoiceFrom' => '2026-06-01', 'docText' => self::P . ' paušál {období}',
                        'numberSeries' => $this->invnoSeries, 'bankAccount' => self::BANK_ACCOUNT_CODE,
                    ],
                ],
                'rows' => [
                    ['item' => self::ITEM_CODE, 'description' => self::P . ' paušál', 'quantity' => 1.0, 'unit' => 'hr', 'unitPrice' => 500.0, 'vatCode' => 'cz-120', 'validTo' => '2026-05-31'],
                    ['item' => self::ITEM_CODE, 'description' => self::P . ' paušál', 'quantity' => 1.0, 'unit' => 'hr', 'unitPrice' => 600.0, 'vatCode' => 'cz-120', 'validFrom' => '2026-06-01'],
                ],
                'periods' => [
                    ['from' => '2026-06-01', 'to' => '2026-06-30', 'state' => 'issued', 'docNumber' => self::INVNO_NUMBER],
                ],
            ]);
            $w->writeJsonc('docs/0003-it-ds-1001.jsonc', [
                'format' => 'shpd.docs.document', 'formatVersion' => '1.0', 'source' => ['kind' => 'workOrder'],
                'docType' => 'invoiceIssued', 'docText' => self::P . ' paušál červen 2026', 'selfParty' => 'supplier',
                'customer' => ['name' => self::P . ' Dodavatel s.r.o.', 'country' => 'cz', 'companyId' => self::COMPANY_ID, 'vatId' => 'CZ' . self::COMPANY_ID],
                'dates' => ['issueDate' => '2026-06-01', 'dueDate' => '2026-06-15', 'accountingDate' => '2026-06-01', 'taxPointDate' => '2026-06-01',
                            'periodFrom' => '2026-06-01', 'periodTo' => '2026-06-30'],
                'currency' => 'CZK', 'vat' => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz'],
                'payment' => ['method' => 'bankTransfer', 'paymentReference' => '1001'],
                'dimensions' => ['workOrder' => self::WO_NUMBER],
                'rows' => [[
                    'rowKind' => 'item', 'operation' => 'sale.services', 'orderPos' => 1,
                    'item' => ['ourCode' => self::ITEM_CODE, 'name' => self::P . ' Konzultace'], 'unit' => 'hr',
                    'quantity' => 1.0, 'unitPrice' => 600.0, 'totalPrice' => 600.0, 'priceCalcMode' => 'fromUnitPrice',
                    'vat' => ['code' => 'cz-120', 'pct' => 21.0], 'description' => self::P . ' paušál',
                ]],
                'vatRecap' => [['vatCode' => 'cz-120', 'vatPct' => 21.0, 'base' => 600.0, 'tax' => 126.0, 'total' => 726.0]],
                'totals' => ['totalBase' => 600.0, 'totalVat' => 126.0, 'totalAmount' => 726.0, 'totalRounding' => 0.0],
                'applyOptions' => array_filter([
                    'targetDocState' => 40,
                    'importNumber' => ['docNumber' => self::INVNO_NUMBER, 'sequenceNumber' => null],
                    'numberSeriesCode' => $this->invnoCode,
                    'importOwnBankAccount' => self::BANK_ACCOUNT_CODE,
                ], static fn($v) => $v !== null),
            ]);
        }
        // Majetek (#131 fáze 2c): skupina typů a typ v setup/ (klíče v pořadí
        // sloupců tabulky), karta serveru se zařazením (klíče v pořadí
        // exportéru), faktura s řádkem pořízení `purchase.asset` (účet přímo,
        // karta v dimensions) a účetní doklad, ze kterého test udělá
        // zaúčtování majetku přepsáním řádku na asset.activation.
        if ($this->hasAssets) {
            $w->writeJsonc('setup/asset_type_groups.jsonc', [
                'format' => 'shpd.dataset.setup.v1', 'table' => 'asset_type_groups',
                'rows' => [['name' => self::ASSET_TYPE_GROUP, 'note' => null, 'sort_order' => 0, 'docState' => 40]],
            ]);
            $w->writeJsonc('setup/asset_types.jsonc', [
                'format' => 'shpd.dataset.setup.v1', 'table' => 'asset_types',
                'rows' => [[
                    'name' => self::ASSET_TYPE, 'short_name' => null, 'type_group' => self::ASSET_TYPE_GROUP, 'note' => null, 'sort_order' => 0,
                    'default_category' => 'tangible', 'default_accounting_group' => '022', 'docState' => 40,
                ]],
            ]);
            $w->writeJsonc(self::ASSET_FILE, [
                'format' => 'shpd.dataset.asset.v1',
                'asset'  => [
                    'assetNumber' => self::ASSET_NUMBER, 'name' => self::P . ' Server', 'type' => self::ASSET_TYPE,
                    'category' => 'tangible', 'tracking' => 'single', 'accountingGroup' => '022',
                    'taxMethod' => 'straight', 'taxRule' => 'cz-1', 'accMethod' => 'as_tax', 'state' => 'confirmed',
                ],
                'events' => [
                    ['kind' => 'activation', 'scope' => 'both', 'date' => '2026-02-14', 'amount' => 150000.0, 'origin' => 'manual'],
                ],
            ]);
            $w->writeJsonc('docs/0004-it-ds-0002.jsonc', [
                'format' => 'shpd.docs.document', 'formatVersion' => '1.0',
                'docType' => 'invoiceReceived', 'docNumber' => '2026-IT-DS-78', 'docText' => self::P . ' server', 'selfParty' => 'customer',
                'supplier' => ['name' => self::P . ' Dodavatel s.r.o.', 'country' => 'cz', 'companyId' => self::COMPANY_ID, 'vatId' => 'CZ' . self::COMPANY_ID,
                               'bankAccount' => ['accountNumber' => '9999000001/0100', 'iban' => 'CZ0901000000009999000001']],
                'dates' => ['issueDate' => '2026-02-10', 'dueDate' => '2026-02-20', 'accountingDate' => '2026-02-10', 'taxPointDate' => '2026-02-10'],
                'currency' => 'CZK', 'vat' => ['mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz'],
                'payment' => ['method' => 'bankTransfer', 'paymentReference' => '20260210'],
                'rows' => [[
                    'rowKind' => 'item', 'operation' => 'purchase.asset', 'orderPos' => 1, 'unit' => 'pcs',
                    'quantity' => 1.0, 'unitPrice' => 150000.0, 'totalPrice' => 150000.0, 'priceCalcMode' => 'fromUnitPrice',
                    'vat' => ['code' => 'cz-110', 'pct' => 21.0], 'description' => self::P . ' Server 1U', 'account' => '042100',
                    'dimensions' => ['asset' => self::ASSET_NUMBER],
                ]],
                'vatRecap' => [['vatCode' => 'cz-110', 'vatPct' => 21.0, 'base' => 150000.0, 'tax' => 31500.0, 'total' => 181500.0]],
                'totals' => ['totalBase' => 150000.0, 'totalVat' => 31500.0, 'totalAmount' => 181500.0, 'totalRounding' => 0.0],
                'applyOptions' => array_filter([
                    'targetDocState' => 40,
                    'importNumber' => ['docNumber' => self::ASSET_DOC_NUMBER, 'sequenceNumber' => null],
                    'numberSeriesCode' => $this->seriesCode,
                ], static fn($v) => $v !== null),
            ]);
            $w->writeJsonc('docs/0005-it-ds-6001.jsonc', [
                'format' => 'shpd.docs.document', 'formatVersion' => '1.0',
                'docType' => 'accountingDocument', 'docText' => self::P . ' zaúčtování majetku',
                'dates' => ['issueDate' => '2026-02-28', 'accountingDate' => '2026-02-28'],
                'currency' => 'CZK', 'vat' => ['mode' => 'none', 'place' => 'domestic'],
                'payment' => ['method' => 'bankTransfer'],
                'rows' => [
                    ['rowKind' => 'item', 'operation' => 'acc.record', 'orderPos' => 1, 'unitPrice' => 0.0, 'totalPrice' => 150000.0,
                     'priceCalcMode' => 'fromTotal', 'description' => self::P . ' zařazení serveru', 'account' => '022100', 'accSide' => 'debit'],
                    ['rowKind' => 'item', 'operation' => 'acc.record', 'orderPos' => 2, 'unitPrice' => 0.0, 'totalPrice' => 150000.0,
                     'priceCalcMode' => 'fromTotal', 'description' => self::P . ' pořízení serveru', 'account' => '042100', 'accSide' => 'credit'],
                ],
                'totals' => ['totalBase' => 150000.0, 'totalVat' => 0.0, 'totalAmount' => 150000.0, 'totalRounding' => 0.0],
                'applyOptions' => [
                    'targetDocState' => 40,
                    'importNumber' => ['docNumber' => self::POSTING_DOC_NUMBER, 'sequenceNumber' => null],
                ],
            ]);
        }
        $w->writeManifest(new DatasetManifest('it-ds', 'Integration round-trip', null, 'fixed', '2026-08-26T10:00:00Z',
            ['setup' => ($this->hasWorkOrders ? 2 : 0) + ($this->hasAssets ? 2 : 0), 'persons' => 1, 'items' => 1]
            + ($this->hasWorkOrders ? ['workOrders' => 1] : []) + ($this->hasAssets ? ['assets' => 1] : [])
            + ['docs' => 2 + ($this->hasWorkOrders ? 1 : 0) + ($this->hasAssets ? 2 : 0)]
            + ($this->hasBank ? ['bank' => 1] : []) + ['registry' => 1, 'mail' => 1]));
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
        // Výpisy a transakce testovacího účtu vč. deníku a salda (#131 fáze 2a).
        if (isset($this->tables['economy_bank_statements'], $this->tables['economy_bank_transactions'])) {
            $accountIds = array_map('intval', $dibi->fetchPairs('SELECT id, id FROM economy_codebooks_bank_accounts WHERE code = %s', self::BANK_ACCOUNT_CODE));
            foreach ($accountIds as $accountId) {
                foreach ($dibi->fetchAll('SELECT id FROM economy_bank_transactions WHERE bank_account = %i', $accountId) as $t) {
                    if (isset($this->tables['economy_accbal_ledger'])) {
                        $dibi->query('DELETE FROM economy_accbal_ledger WHERE bank_transaction = %i', $t['id']);
                    }
                    $dibi->query('DELETE FROM economy_accounting_journal WHERE bank_transaction = %i', $t['id']);
                    $dibi->query('DELETE FROM economy_bank_transactions WHERE id = %i', $t['id']);
                }
                $dibi->query('DELETE FROM economy_bank_statements WHERE bank_account = %i', $accountId);
                $dibi->query('DELETE FROM economy_codebooks_bank_accounts WHERE id = %i', $accountId);
            }
        }
        // Zakázky (#131 fáze 2b): období → řádky → hlavičky → čítače → řady → druhy.
        if (isset($this->tables['economy_work_orders_heads'], $this->tables['economy_work_orders_number_series'], $this->tables['economy_work_orders_kinds'])) {
            foreach ($dibi->fetchAll('SELECT id FROM economy_work_orders_heads WHERE number = %s', self::WO_NUMBER) as $r) {
                if (isset($this->tables['economy_work_orders_periods'])) {
                    $dibi->query('DELETE FROM economy_work_orders_periods WHERE work_order = %i', $r['id']);
                }
                $dibi->query('DELETE FROM economy_work_orders_rows WHERE work_order = %i', $r['id']);
                $dibi->query('DELETE FROM economy_work_orders_heads WHERE id = %i', $r['id']);
            }
            foreach ($dibi->fetchAll('SELECT id FROM economy_work_orders_number_series WHERE number_code = %s', self::WO_SERIES_CODE) as $s) {
                $dibi->query('DELETE FROM economy_work_orders_number_counters WHERE number_series = %i', $s['id']);
                $dibi->query('DELETE FROM economy_work_orders_number_series WHERE id = %i', $s['id']);
            }
            $dibi->query('DELETE FROM economy_work_orders_kinds WHERE name = %s', self::WO_KIND);
        }
        // Majetek (#131 fáze 2c): události → karta → typ → skupina typů (účetní skupina 022 je DS).
        if (isset($this->tables['economy_assets_assets'], $this->tables['economy_assets_events'])) {
            foreach ($dibi->fetchAll('SELECT id FROM economy_assets_assets WHERE asset_number = %s', self::ASSET_NUMBER) as $r) {
                $dibi->query('DELETE FROM economy_assets_events WHERE asset = %i', $r['id']);
                $dibi->query('DELETE FROM economy_assets_assets WHERE id = %i', $r['id']);
            }
            $dibi->query('DELETE FROM economy_assets_types WHERE name = %s', self::ASSET_TYPE);
            $dibi->query('DELETE FROM economy_assets_type_groups WHERE name = %s', self::ASSET_TYPE_GROUP);
        }
        // Doklady podle čísla i podle testovací pokladny (pád s jiným číslem).
        $deskIds = array_map('intval', $dibi->fetchPairs('SELECT id, id FROM economy_codebooks_cash_desks WHERE code = %s', self::CASH_DESK_CODE));
        $docs = $dibi->fetchAll(
            'SELECT id FROM docs_core_heads WHERE doc_number IN %in' . ($deskIds !== [] ? ' OR cash_desk IN %in' : ''),
            [self::DOC_NUMBER, self::CASHREG_NUMBER, self::INVNO_NUMBER, self::ASSET_DOC_NUMBER, self::POSTING_DOC_NUMBER],
            ...($deskIds !== [] ? [$deskIds] : []),
        );
        foreach ($docs as $r) {
            if (isset($this->tables['economy_accbal_ledger'])) {
                $dibi->query('DELETE FROM economy_accbal_ledger WHERE doc_head = %i', $r['id']);
            }
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
