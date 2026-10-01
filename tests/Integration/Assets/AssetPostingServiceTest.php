<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Assets;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\DepreciationRunService;
use Shipard\Module\Economy\Assets\Posting\AssetPostingDocuments;
use Shipard\Module\Economy\Assets\Posting\AssetPostingException;
use Shipard\Module\Economy\Assets\Posting\AssetPostingService;
use Shipard\Module\Economy\Assets\SystemDepreciationWriter;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * „Odpisy a zaúčtování za období“ nad reálným dev DS (docs/assets.md
 * D50–D55): jeden doklad `cmnbkp` za období rovnou V pořádku, deník
 * s dimenzí `asset`, vazba `doc_head`, idempotence, zámky, zrušení jen
 * posledního období, rollback celé transakce a čisté vyřazení.
 *
 * Služba i běh odpisů jsou zúžené na karty testu (ostatní karty DS se
 * neúčtují); doklady jdou přes produkční zapojení handlerů — účtovací
 * engine a saldo ledger běží uvnitř transakce služby.
 */
class AssetPostingServiceTest extends IntegrationTestCase
{
    private ?ConfigRuntime $config = null;
    private ?DocumentRegistry $registry = null;
    private ?DocumentEventDispatcher $dispatcher = null;

    /** @var array<string, int> účty účetní skupiny (sloupec → id) */
    private array $group = [];
    private int $groupId = 0;
    /** @var array<int, array{id: int, end: string}> rok → účetní rok */
    private array $years = [];

    /** @var list<int> */
    private array $cards = [];
    /** @var list<int> */
    private array $heads = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = ConfigRuntime::load($this->realDsPath, 'cs');

        foreach (['economy_assets_events' => 'doc_head', 'economy_accounting_journal' => 'asset', 'docs_core_rows' => 'asset'] as $table => $column) {
            if (!isset($this->tables[$table]) || $this->db->fetchAll("SHOW COLUMNS FROM `{$table}` LIKE %s", $column) === []) {
                $this->markTestSkipped("DS nemá {$table}.{$column} — spusťte ds-upgrade");
            }
        }
        if (!is_array($this->config->cfgItem('docs.core.rowOperations')['asset.depreciation'] ?? null)) {
            $this->markTestSkipped('DS nemá compiled operace asset.* — spusťte ds-upgrade');
        }

        $group = $this->db->fetchRow(
            'SELECT g.* FROM economy_assets_accounting_groups g'
            . ' WHERE g.docState <> 90 AND g.account_asset IS NOT NULL AND g.account_acquisition IS NOT NULL'
            . ' AND g.account_accumulated IS NOT NULL AND g.account_depreciation IS NOT NULL'
            . ' AND g.account_disposal IS NOT NULL ORDER BY g.id LIMIT 1',
        );
        if ($group === null) {
            $this->markTestSkipped('DS nemá účetní skupinu majetku se všemi účty');
        }
        $this->groupId = (int) $group['id'];
        foreach (['account_asset', 'account_acquisition', 'account_accumulated', 'account_depreciation', 'account_disposal'] as $column) {
            $this->group[$column] = (int) $group[$column];
        }

        foreach ([2026, 2027] as $year) {
            $row = $this->db->fetchRow(
                'SELECT id, date_end FROM economy_codebooks_fiscal_years'
                . ' WHERE date_begin = %s AND date_end = %s AND docState <> 90',
                "{$year}-01-01",
                "{$year}-12-31",
            );
            if ($row === null) {
                $this->markTestSkipped("DS nemá kalendářní účetní rok {$year}");
            }
            $this->years[$year] = ['id' => (int) $row['id'], 'end' => "{$year}-12-31"];
        }
        $locked = (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_codebooks_fiscal_months WHERE locked = 1 AND date_begin >= %s AND date_end <= %s',
            '2026-01-01',
            '2027-12-31',
        );
        if ($locked > 0) {
            $this->markTestSkipped('DS má v letech 2026–2027 zamčený účetní měsíc');
        }
        $settings = new SettingsStore($this->db);
        if ($settings->get(AssetPlanService::SETTING_PERIODICITY) === AssetPlanService::PERIODICITY_MONTH) {
            $this->markTestSkipped('DS má měsíční četnost účetních odpisů — test počítá s roční');
        }
        if ($this->service()->preview($this->years[2026]['id'])['series'] === null) {
            $this->markTestSkipped('DS nemá nastavenou řadu účetních dokladů majetku');
        }
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        if ($this->cards !== []) {
            // Doklady dohledat i přes vazbu událostí — test mohl spadnout
            // dřív, než si id dokladu zapamatoval.
            foreach ($this->db->fetchAll(
                'SELECT DISTINCT doc_head FROM economy_assets_events WHERE asset IN %in AND doc_head IS NOT NULL',
                $this->cards,
            ) as $row) {
                $this->heads[] = (int) $row['doc_head'];
            }
            foreach ($this->db->fetchAll(
                'SELECT DISTINCT doc_head FROM docs_core_rows WHERE asset IN %in',
                $this->cards,
            ) as $row) {
                $this->heads[] = (int) $row['doc_head'];
            }
        }
        foreach (array_unique($this->heads) as $id) {
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_rows')->where('doc_head = %i', $id)->execute();
            $dibi->delete('docs_core_heads')->where('id = %i', $id)->execute();
        }
        if ($this->cards !== []) {
            $dibi->delete('economy_assets_events')->where('asset IN %in', $this->cards)->execute();
            $dibi->delete('economy_assets_assets')->where('id IN %in', $this->cards)->execute();
        }
    }

    // ── scénáře ─────────────────────────────────────────────────────────────

    public function testPreviewShowsDepreciationEventsAndAccountTotals(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);

        $preview = $this->service()->preview($this->years[2026]['id']);

        $this->assertTrue($preview['canPost'], json_encode($preview['blockers'] + $preview['excluded']));
        $this->assertSame([$card], array_column($preview['depreciations'], 'id'));
        $this->assertSame(11000.0, $preview['depreciationTotal'], 'sk. 2 rovnoměrně, 1. rok 11 %');
        $this->assertSame([['activation', '2026-03-15', 100000.0]], array_map(
            static fn(array $e): array => [$e['kind'], $e['date'], $e['amount']],
            $preview['events'],
        ));
        $this->assertSame(4, $preview['rowCount']);
        $this->assertSame(111000.0, $preview['totalDebit']);
        $this->assertSame(111000.0, $preview['totalCredit']);
        $this->assertSame(
            [$this->group['account_asset'] => 100000.0, $this->group['account_depreciation'] => 11000.0],
            array_filter(array_column($preview['accounts'], 'debit', 'account')),
        );
        // Náhled nic nezapisuje.
        $this->assertSame(1, $this->eventCount($card));
    }

    public function testPostCreatesOneConfirmedDocumentWithAssetDimension(): void
    {
        $card = $this->card();
        $activation = $this->event($card, 'activation', '2026-03-15', 100000.0);

        $result = $this->service()->post($this->years[2026]['id']);

        $this->assertTrue($result['posted']);
        $this->assertSame(1, $result['depreciationCount']);
        $this->assertSame(11000.0, $result['depreciationTotal']);
        $this->assertSame(2, $result['eventCount']);
        $this->assertSame(4, $result['rowCount']);
        $docId = $this->rememberHead($result['docId']);

        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        $this->assertSame('cmnbkp', $head['doc_type']);
        $this->assertSame(40, (int) $head['docState'], 'doklad vzniká rovnou V pořádku (D51)');
        $this->assertSame(1, (int) $head['accounting_state']);
        $this->assertSame('2026-12-31', $this->date($head['accounting_date']));
        $this->assertSame($result['docNumber'], $head['doc_number']);
        $this->assertStringNotContainsString('!', (string) $head['doc_number'], 'doklad má číslo z řady');

        $rows = $this->db->fetchAll('SELECT * FROM docs_core_rows WHERE doc_head = %i ORDER BY order_pos', $docId);
        $this->assertSame(
            ['asset.activation', 'asset.activation', 'asset.depreciation', 'asset.depreciation'],
            array_column($rows, 'operation'),
        );
        $this->assertSame([$card], array_values(array_unique(array_map('intval', array_column($rows, 'asset')))));

        // Deník nese dimenzi a sedí na evidenci: MD majetek = vstupní cena,
        // MD odpisy = potvrzené účetní odpisy karty.
        $journal = $this->db->fetchAll('SELECT * FROM economy_accounting_journal WHERE doc_head = %i', $docId);
        $this->assertCount(4, $journal);
        $this->assertSame([$card], array_values(array_unique(array_map('intval', array_column($journal, 'asset')))));
        $this->assertSame(100000.0, $this->journalTurnover($card, 'account_asset', 'money_dr'));
        $this->assertSame(100000.0, $this->journalTurnover($card, 'account_acquisition', 'money_cr'));
        $this->assertSame(
            $this->confirmedAccDepreciations($card),
            $this->journalTurnover($card, 'account_depreciation', 'money_dr'),
        );
        $this->assertSame(11000.0, $this->journalTurnover($card, 'account_accumulated', 'money_cr'));

        // Obě události (zařazení i nový systémový odpis) jsou navázané.
        $events = $this->db->fetchAll('SELECT * FROM economy_assets_events WHERE asset = %i ORDER BY id', $card);
        $this->assertCount(2, $events);
        $this->assertSame([$docId, $docId], array_map('intval', array_column($events, 'doc_head')));
        $this->assertSame('system', $events[1]['origin']);
        $this->assertSame($activation, (int) $events[0]['id']);
    }

    public function testSecondPostOfSamePeriodCreatesNothing(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        $first = $this->service()->post($this->years[2026]['id']);
        $this->rememberHead($first['docId']);

        $second = $this->service()->post($this->years[2026]['id']);

        $this->assertFalse($second['posted']);
        $this->assertNull($second['docId']);
        $this->assertSame(2, $this->eventCount($card));
        $this->assertSame(1, (int) $this->db->fetchSingle(
            'SELECT COUNT(DISTINCT doc_head) FROM docs_core_rows WHERE asset = %i',
            $card,
        ));
    }

    public function testPostedDocumentAndEventsAreLocked(): void
    {
        $card = $this->card();
        $activation = $this->event($card, 'activation', '2026-03-15', 100000.0);
        $docId = $this->rememberHead($this->service()->post($this->years[2026]['id'])['docId']);

        // Ruční úprava ani storno dokladu neprojde — doklad spravuje Majetek.
        foreach ([['doc_text' => 'ruční oprava'], ['docState' => 30], ['docState' => 80]] as $change) {
            $result = $this->gateway('docs_core_heads')->saveDocument(['id' => $docId] + $change);
            $this->assertFalse($result->isSuccess(), json_encode($change));
            $this->assertContains('locked', array_map(
                static fn($e): string => (string) $e->code,
                $result->getValidation()?->getErrors() ?? [],
            ));
        }
        $this->assertFalse($this->gateway('docs_core_heads')->deleteDocument($docId)->isSuccess());
        $this->assertSame(40, (int) $this->db->fetchSingle('SELECT docState FROM docs_core_heads WHERE id = %i', $docId));

        // Zaúčtovaná událost je zamčená (D52).
        $result = $this->gateway('economy_assets_events')->saveDocument(['id' => $activation, 'docState' => 80]);
        $this->assertFalse($result->isSuccess());
        $titles = array_map(static fn($e): string => $e->message, $result->getValidation()?->getErrors() ?? []);
        $this->assertNotSame([], preg_grep('/^Zaúčtováno dokladem /', $titles), implode(' | ', $titles));
    }

    public function testUnpostCancelsDocumentAndUnlinksEvents(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        $service = $this->service();
        $docId = $this->rememberHead($service->post($this->years[2026]['id'])['docId']);
        $this->assertSame($this->years[2026]['id'], $service->lastPosting()['periodId']);

        $result = $service->unpost($this->years[2026]['id']);

        $this->assertSame([$docId], $result['docIds']);
        $this->assertSame(2, $result['eventCount']);
        $this->assertSame(30, (int) $this->db->fetchSingle('SELECT docState FROM docs_core_heads WHERE id = %i', $docId));
        $this->assertSame(0, (int) $this->db->fetchSingle('SELECT COUNT(*) FROM economy_accounting_journal WHERE doc_head = %i', $docId));
        $this->assertSame(0, (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM economy_assets_events WHERE asset = %i AND doc_head IS NOT NULL',
            $card,
        ));
        // Systémový odpis běhu zůstává potvrzený — jde smazat od konce.
        $this->assertSame(11000.0, $this->confirmedAccDepreciations($card));
        $this->assertNull($service->lastPosting());

        // Další běh období zaúčtuje znovu, novým dokladem; odpis už existuje.
        $again = $service->post($this->years[2026]['id']);
        $this->assertTrue($again['posted']);
        $this->assertSame(0, $again['depreciationCount']);
        $this->assertNotSame($docId, $this->rememberHead($again['docId']));
        $this->assertSame(4, $again['rowCount']);
    }

    public function testOnlyLastPostedPeriodCanBeCancelled(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        $service = $this->service();
        $this->rememberHead($service->post($this->years[2026]['id'])['docId']);
        $second = $service->post($this->years[2027]['id']);
        $this->assertTrue($second['posted']);
        $this->rememberHead($second['docId']);
        $this->assertSame(22250.0, $second['depreciationTotal']);

        try {
            $service->unpost($this->years[2026]['id']);
            $this->fail('Zrušení staršího období mělo být odmítnuto');
        } catch (AssetPostingException $e) {
            $this->assertSame(AssetPostingService::ERROR_NOT_LAST_PERIOD, $e->errorCode);
        }

        $this->assertSame([(int) $second['docId']], $service->unpost($this->years[2027]['id'])['docIds']);
        $this->assertSame($this->years[2026]['id'], $service->lastPosting()['periodId']);
    }

    public function testPeriodsArePostedWithoutGaps(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        $service = $this->service();

        $preview = $service->preview($this->years[2027]['id']);

        $this->assertSame([], $preview['depreciations']);
        $excluded = array_column($preview['excluded'], 'reason', 'id');
        // Neodepsaný rok 2026 hlásí už běh odpisů; nezaúčtované zařazení
        // z roku 2026 má přednost — je to důvod, který uživatel řeší první.
        $this->assertSame(AssetPostingService::REASON_EARLIER_UNPOSTED, $excluded[$card]);

        $result = $service->post($this->years[2027]['id']);
        $this->assertFalse($result['posted']);
        $this->assertSame(1, $this->eventCount($card), 'vyloučená karta nedostane ani odpis');
    }

    public function testMissingSeriesRejectsTheRunBeforeAnyWrite(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        $service = $this->service(seriesMissing: true);

        $preview = $service->preview($this->years[2026]['id']);
        $this->assertFalse($preview['canPost']);
        $this->assertSame([AssetPostingService::ERROR_SERIES_MISSING], array_column($preview['blockers'], 'code'));

        try {
            $service->post($this->years[2026]['id']);
            $this->fail('Běh bez řady měl být odmítnut');
        } catch (AssetPostingException $e) {
            $this->assertSame(AssetPostingService::ERROR_SERIES_MISSING, $e->errorCode);
        }
        $this->assertSame(1, $this->eventCount($card));
    }

    public function testFailureAfterDocumentRollsBackEverything(): void
    {
        // Doklad vznikl a zaúčtoval se (engine i ledger běžely uvnitř
        // transakce služby) a až pak přišla chyba — nesmí zůstat nic:
        // doklad, deník, odpis ani vazba.
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        $before = (int) $this->db->fetchSingle('SELECT COALESCE(MAX(id), 0) FROM docs_core_heads');

        try {
            $this->service(failAfterCreate: true)->post($this->years[2026]['id']);
            $this->fail('Zaúčtování mělo selhat');
        } catch (AssetPostingException $e) {
            $this->assertSame('accounting_failed', $e->errorCode);
        }

        $this->assertSame(0, (int) $this->db->fetchSingle('SELECT COUNT(*) FROM docs_core_heads WHERE id > %i AND doc_type = %s', $before, 'cmnbkp'));
        $this->assertSame(0, (int) $this->db->fetchSingle('SELECT COUNT(*) FROM docs_core_rows WHERE asset = %i', $card));
        $this->assertSame(0, (int) $this->db->fetchSingle('SELECT COUNT(*) FROM economy_accounting_journal WHERE asset = %i', $card));
        $this->assertSame(1, $this->eventCount($card), 'odpis běhu se vrátil s transakcí');
        $this->assertSame(0, (int) $this->db->fetchSingle('SELECT COUNT(*) FROM economy_assets_events WHERE asset = %i AND doc_head IS NOT NULL', $card));
        $this->assertSame(0, (int) $this->db->getDibiConnection()->fetchSingle('SELECT @@in_transaction'));
    }

    public function testDisposalIsPostedAsCleanWriteOff(): void
    {
        $card = $this->card();
        $this->event($card, 'activation', '2026-03-15', 100000.0);
        // Vyřazení přes dokument: založí poslední odpisy obou okruhů
        // a kartu archivuje (D35, D38).
        $disposal = $this->gateway('economy_assets_events')->saveDocument([
            'asset' => $card, 'event_kind' => 'disposal', 'event_date' => '2026-09-10', 'half_year' => 0, 'docState' => 40,
        ]);
        $this->assertTrue($disposal->isSuccess(), (string) json_encode($disposal->getValidation()?->toArray()));
        $accumulated = $this->confirmedAccDepreciations($card);
        $this->assertGreaterThan(0.0, $accumulated);

        $result = $this->service()->post($this->years[2026]['id']);

        $this->assertTrue($result['posted'], json_encode($result['excluded']));
        $this->assertSame(0, $result['depreciationCount'], 'odpisy založilo vyřazení');
        $this->rememberHead($result['docId']);

        // Čistý zápis: účet majetku i oprávek karty je po vyřazení nulový,
        // zůstatková cena jde na účet vyřazení.
        $this->assertSame(
            $this->journalTurnover($card, 'account_asset', 'money_dr'),
            $this->journalTurnover($card, 'account_asset', 'money_cr'),
        );
        $this->assertSame(
            $this->journalTurnover($card, 'account_accumulated', 'money_dr'),
            $this->journalTurnover($card, 'account_accumulated', 'money_cr'),
        );
        $this->assertSame($accumulated, $this->journalTurnover($card, 'account_accumulated', 'money_dr'));
        $this->assertSame(100000.0 - $accumulated, $this->journalTurnover($card, 'account_disposal', 'money_dr'));
        $this->assertSame($accumulated, $this->journalTurnover($card, 'account_depreciation', 'money_dr'));

        // Zaúčtované vyřazení nejde zrušit — je zamčené a jeho odpisy taky (D56).
        $cancel = $this->gateway('economy_assets_events')->saveDocument([
            'id' => (int) $disposal->getData()['id'], 'docState' => 80,
        ]);
        $this->assertFalse($cancel->isSuccess());
        $this->assertContains('disposalPosted', array_map(
            static fn($e): string => (string) $e->code,
            $cancel->getValidation()?->getErrors() ?? [],
        ));
    }

    // ── pomůcky ─────────────────────────────────────────────────────────────

    private function card(): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_assets_assets', [
            'asset_number'     => substr('IT' . strtoupper(bin2hex(random_bytes(6))), 0, 20),
            'name'             => 'Integrační test zaúčtování',
            'category'         => 'tangible',
            'accounting_group' => $this->groupId,
            'tax_method'       => 'straight',
            'tax_rule'         => 'cz-2',
            'acc_method'       => 'as_tax',
            'docState'         => 40,
            'docStateMain'     => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->cards[] = $id;
        return $id;
    }

    private function event(int $card, string $kind, string $date, float $amount): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_assets_events', [
            'asset' => $card, 'event_kind' => $kind, 'scope' => 'both', 'event_date' => $date,
            'amount' => $amount, 'origin' => 'manual', 'docState' => 40, 'docStateMain' => 3,
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    private function rememberHead(mixed $docId): int
    {
        $this->assertNotNull($docId);
        $this->heads[] = (int) $docId;
        return (int) $docId;
    }

    private function eventCount(int $card): int
    {
        return (int) $this->db->fetchSingle('SELECT COUNT(*) FROM economy_assets_events WHERE asset = %i AND docState = 40', $card);
    }

    private function confirmedAccDepreciations(int $card): float
    {
        return (float) $this->db->fetchSingle(
            'SELECT COALESCE(SUM(amount), 0) FROM economy_assets_events'
            . ' WHERE asset = %i AND event_kind = %s AND scope = %s AND docState = 40',
            $card,
            'depreciation',
            'acc',
        );
    }

    private function journalTurnover(int $card, string $groupAccount, string $column): float
    {
        return (float) $this->db->fetchSingle(
            "SELECT COALESCE(SUM(`{$column}`), 0) FROM economy_accounting_journal WHERE asset = %i AND account = %i",
            $card,
            $this->group[$groupAccount],
        );
    }

    private function date(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    private function resolver(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
    }

    private function registry(): DocumentRegistry
    {
        return $this->registry ??= DocumentLoader::load($this->dsConfig, $this->resolver());
    }

    /** Produkční zapojení: document handlery + journal handlery (saldo ledger). */
    private function dispatcher(): DocumentEventDispatcher
    {
        $dibi = $this->db->getDibiConnection();

        return $this->dispatcher ??= DocumentEventHandlerLoader::load(
            $this->dsConfig,
            $this->resolver(),
            $dibi,
            $this->config,
            JournalEventHandlerLoader::load($this->dsConfig, $this->resolver(), $dibi, $this->config),
        );
    }

    private function gateway(string $table): TableGateway
    {
        $def = $this->tables[$table];

        return new TableGateway(
            $table,
            $this->db->getDibiConnection(),
            $this->registry(),
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->dispatcher(),
            $def->docStates,
            $def,
        );
    }

    private function service(bool $seriesMissing = false, bool $failAfterCreate = false): AssetPostingService
    {
        $dibi = $this->db->getDibiConnection();
        $settings = new SettingsStore($this->db);
        $planService = new AssetPlanService($dibi, $this->config, $this->dsConfig->getCountry(), $settings);
        $writer = new SystemDepreciationWriter($dibi);
        $cards = fn(): array => $this->cards;

        $run = new class($planService, $writer, $dibi, null, $cards) extends DepreciationRunService {
            public function __construct(
                AssetPlanService $service,
                SystemDepreciationWriter $writer,
                \Dibi\Connection $db,
                mixed $texts,
                private readonly \Closure $only,
            ) {
                parent::__construct($service, $writer, $db, $texts);
            }

            protected function loadCandidateCards(?int $assetId): array
            {
                return array_values(array_filter(
                    parent::loadCandidateCards($assetId),
                    fn(array $card): bool => in_array((int) $card['id'], ($this->only)(), true),
                ));
            }
        };

        $documents = new class($dibi, $this->config, $this->dsConfig, $this->registry(), $this->tables, $this->dispatcher(), $failAfterCreate) extends AssetPostingDocuments {
            public function __construct(
                \Dibi\Connection $db,
                ?ConfigRuntime $config,
                mixed $dsConfig,
                DocumentRegistry $documents,
                array $tables,
                DocumentEventDispatcher $dispatcher,
                private readonly bool $fail,
            ) {
                parent::__construct($db, $config, $dsConfig, $documents, $tables, $dispatcher);
            }

            public function create(array $head): array
            {
                $document = parent::create($head);
                if ($this->fail) {
                    throw new AssetPostingException('accounting_failed', 'Simulovaná chyba po zaúčtování dokladu.');
                }
                return $document;
            }
        };

        return new class($dibi, $planService, $run, $writer, $documents, $settings, $cards, $seriesMissing) extends AssetPostingService {
            public function __construct(
                \Dibi\Connection $db,
                AssetPlanService $planService,
                DepreciationRunService $runService,
                SystemDepreciationWriter $writer,
                AssetPostingDocuments $documents,
                SettingsStore $settings,
                private readonly \Closure $only,
                private readonly bool $seriesMissing,
            ) {
                parent::__construct($db, $planService, $runService, $writer, $documents, $settings);
            }

            protected function resolveSeries(): ?array
            {
                return $this->seriesMissing ? null : parent::resolveSeries();
            }

            protected function loadUnpostedEvents(string $until): array
            {
                return array_values(array_filter(
                    parent::loadUnpostedEvents($until),
                    fn(array $event): bool => in_array((int) $event['asset'], ($this->only)(), true),
                ));
            }

            protected function loadPostedDocuments(): array
            {
                $ids = ($this->only)();
                if ($ids === []) {
                    return [];
                }
                $own = [];
                foreach ($this->db->fetchAll(
                    'SELECT DISTINCT [doc_head] FROM [economy_assets_events] WHERE [asset] IN %in AND [doc_head] IS NOT NULL',
                    $ids,
                ) as $row) {
                    $own[] = (int) $row['doc_head'];
                }

                return array_values(array_filter(
                    parent::loadPostedDocuments(),
                    static fn(array $doc): bool => in_array($doc['id'], $own, true),
                ));
            }
        };
    }
}
