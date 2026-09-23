<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\NavigationController;
use Shipard\Api\Response;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Hlavní navigace (sidebar) v app módu — sekce dle cfgItem global.navSections,
 * řazení dle navSections.order / navOrder, sentinel _top, fallback do system,
 * skrytí souhrnného docs.core.heads (sdílená tabulka faktur nepoškozena) a
 * mizení položek přesunutých do Nastavení. Reálné moduly z modules/, cfgItem
 * mockovaný (jako u SettingsControllerTest::testAccountNavigation…).
 */
class NavigationControllerTest extends TestCase
{
    private string $dsDir;
    private ModulePathResolver $resolver;
    private NavigationController $ctrl;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_navctrl_' . uniqid('', true);
        mkdir($this->dsDir . '/config', 0755, true);
        $this->resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $this->ctrl     = new NavigationController();
        // Provider testy logují (missing class) — log do tempu, ne /opt.
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->dsDir . '/test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->dsDir . '/test.log');
        @unlink($this->dsDir . '/config/main.json');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    // --- helpers ---

    /** @param string[] $modules */
    private function config(array $modules): DataSourceConfig
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'                => 'test-test-test-test',
            'name'              => 'Testovací firma',
            'database_name'     => 'x',
            'database_user'     => 'x',
            'database_password' => 'x',
            'created'           => '2026-01-01T00:00:00+00:00',
            'modules'           => $modules,
        ]));
        return new DataSourceConfig($this->dsDir);
    }

    /**
     * ConfigRuntime mock vracející REÁLNý global.navSections (lokalizovaný
     * stejně jako compiled config) — věrná simulace produkce.
     */
    private function configRuntime(string $language): ConfigRuntime
    {
        $raw       = JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/install/base/config/navSections.jsonc');
        $localized = ConfigLocalizer::localize($raw, $language);

        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'global.navSections' ? $localized : null,
        );
        return $cfg;
    }

    /**
     * Výchozí runtime mapa tabulek — běžný DS s aktivním core.chat
     * (Chat leaf vyžaduje core_chat_conversations, 07b D10).
     *
     * @return array<string, TableDefinition>
     */
    private function defaultTables(): array
    {
        return ['core_chat_conversations' => $this->tableDef(adminOnly: false)];
    }

    /**
     * Plný strom admina na běžném DS (layout testy) — admin auth, protože
     * ne-adminovi se strom ořezává (adminOnly viewery, např. Upozornění);
     * ne-admin varianty testují treeWith() explicitně.
     *
     * @param string[] $modules @return array<int, array> the navigation tree
     */
    private function tree(array $modules, string $language = 'cs', ?ConfigRuntime $cfg = null): array
    {
        $resp = $this->ctrl->navigation(
            $this->config($modules),
            $this->resolver,
            $language,
            $cfg ?? $this->configRuntime($language),
            null,
            $this->admin(),
            $this->defaultTables(),
        );
        $this->assertInstanceOf(Response::class, $resp);
        return $resp->getPayload()['data'];
    }

    /** Vrátí top-level node dle id (sekce nebo root leaf), nebo null. */
    private function node(array $tree, string $id): ?array
    {
        foreach ($tree as $n) {
            if (($n['id'] ?? null) === $id) {
                return $n;
            }
        }
        return null;
    }

    /** Rekurzivně sesbírá všechny viewerId v stromu. @return string[] */
    private function allViewerIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $n) {
            if (isset($n['viewerId'])) {
                $ids[] = $n['viewerId'];
            }
            if (!empty($n['children'])) {
                $ids = array_merge($ids, $this->allViewerIds($n['children']));
            }
        }
        return $ids;
    }

    /** Rekurzivně sesbírá všechny table ids v stromu. @return string[] */
    private function allTableIds(array $tree): array
    {
        $ids = [];
        foreach ($tree as $n) {
            if (($n['type'] ?? null) === 'table' && isset($n['table'])) {
                $ids[] = $n['table'];
            }
            if (!empty($n['children'])) {
                $ids = array_merge($ids, $this->allTableIds($n['children']));
            }
        }
        return $ids;
    }

    // --- full production layout ---

    public function testFullProductionTreeMatchesTargetLayout(): void
    {
        $tree = $this->tree(['install.base']);

        // Root-level order: Dashboard, Chat, Došlá pošta, Úkoly, then sections.
        $rootLabels = array_map(fn($n) => $n['label'], $tree);
        $this->assertSame(
            ['Dashboard', 'Chat', 'Došlá pošta', 'Spisovna', 'Úkoly', 'Základní', 'Nákup', 'Prodej', 'Účtárna', 'Systém'],
            $rootLabels,
        );

        // Dashboard + Chat are root leaves (type, no children).
        $this->assertSame('dashboard', $tree[0]['type']);
        $this->assertSame('chat', $tree[1]['type']);

        // _top viewers are root leaves carrying viewerId, ordered by navOrder.
        $this->assertSame('core.mail.incoming', $tree[2]['viewerId']);
        $this->assertSame('viewer', $tree[2]['type']);
        $this->assertSame('base.registry.documents', $tree[3]['viewerId']);
        $this->assertSame('tasks.core', $tree[4]['viewerId']);

        // Sections in navSections.order with the right children/order.
        $this->assertSame(
            ['base.persons', 'economy.items'],
            array_column($this->node($tree, 'basic')['children'], 'viewerId'),
        );
        $this->assertSame(
            ['docs.invoicesIn.heads'],
            array_column($this->node($tree, 'purchase')['children'], 'viewerId'),
        );
        $this->assertSame(
            ['docs.invoicesOut.heads', 'docs.proformasOut.heads', 'docs.cashRegister.heads'],
            array_column($this->node($tree, 'sales')['children'], 'viewerId'),
        );
        $this->assertSame(
            ['docs.accountingDocs.heads', 'docs.cashDocs.heads', 'economy.accounting.journal', 'economy.accounting.accounts', 'economy.bank.transactions', 'economy.accbal.cases', 'economy.accbal.ledger', 'economy.bank.statements', 'economy.vat.reportPeriods', 'economy.vat.filings'],
            array_column($this->node($tree, 'accounting')['children'], 'viewerId'),
        );
        // System holds ONLY Alerts — users/settings moved to Settings app.
        $this->assertSame(
            ['core.alerts.alerts'],
            array_column($this->node($tree, 'system')['children'], 'viewerId'),
        );
    }

    public function testSectionLabelsLocalizedEn(): void
    {
        $tree = $this->tree(['install.base'], 'en');
        $rootLabels = array_map(fn($n) => $n['label'], $tree);
        $this->assertSame(
            ['Dashboard', 'Chat', 'Incoming messages', 'Registry', 'Tasks', 'Basic', 'Purchase', 'Sales', 'Accounting', 'System'],
            $rootLabels,
        );
    }

    // --- shared table docs_core_heads ---

    public function testDocsHeadsHiddenButInvoicesVisible(): void
    {
        $tree     = $this->tree(['install.base']);
        $viewerIds = $this->allViewerIds($tree);

        // Summary viewer hidden…
        $this->assertNotContains('docs.core.heads', $viewerIds);
        // …but both per-type invoice viewers (sharing docs_core_heads) remain.
        $this->assertContains('docs.invoicesIn.heads', $viewerIds);
        $this->assertContains('docs.invoicesOut.heads', $viewerIds);

        // The shared table never leaks back as a raw fallback table item.
        $this->assertNotContains('docs_core_heads', $this->allTableIds($tree));
    }

    // --- items moved to Settings are gone from the main nav ---

    public function testMovedItemsAbsentFromNavigation(): void
    {
        $tableIds = $this->allTableIds($this->tree(['install.base']));

        foreach ([
            'core_chat_messages',
            'core_system_users',
            'core_system_settings',
        ] as $moved) {
            $this->assertNotContains($moved, $tableIds, "Moved-to-settings table leaked into nav: {$moved}");
        }
    }

    // --- empty sections are omitted ---

    public function testEmptySectionsOmitted(): void
    {
        // base.persons pulls core.alerts (→ system/Upozornění) as a dependency,
        // but no purchase/sales/accounting viewers — those sections vanish.
        $tree = $this->tree(['base.persons']);
        $ids  = array_map(fn($n) => $n['id'], $tree);

        $this->assertContains('basic', $ids);
        $this->assertContains('system', $ids);
        $this->assertNotContains('purchase', $ids);
        $this->assertNotContains('sales', $ids);
        $this->assertNotContains('accounting', $ids);
    }

    // --- fallback: viewer without navSection → system ---

    public function testViewerWithoutNavSectionFallsBackToSystem(): void
    {
        $modRoot = $this->makeFallbackFixtureModule();
        $resolver = new ModulePathResolver([$modRoot, dirname(__DIR__, 4) . '/modules']);

        $resp = $this->ctrl->navigation(
            $this->config(['test.navfallback']),
            $resolver,
            'cs',
            $this->configRuntime('cs'),
        );
        $tree = $resp->getPayload()['data'];

        $system = $this->node($tree, 'system');
        $this->assertNotNull($system, 'system section should exist');
        $this->assertContains('test.navfallback.viewer', array_column($system['children'], 'viewerId'));

        $this->cleanupFixtureModule($modRoot);
    }

    // --- fallback sections when compiled config is missing ---

    public function testFallbackSectionsWhenConfigRuntimeNull(): void
    {
        // configRuntime === null → controller uses its built-in PHP
        // SECTIONS_FALLBACK (degraded but functional, no crash). Call the
        // controller directly so the tree() helper does not substitute a mock.
        $resp = $this->ctrl->navigation(
            $this->config(['install.base']),
            $this->resolver,
            'cs',
            null,
            null,
            $this->admin(),
            $this->defaultTables(),
        );
        $tree = $resp->getPayload()['data'];
        $ids  = array_map(fn($n) => $n['id'], $tree);

        $this->assertSame(
            ['dashboard', 'chat', 'viewer:core.mail.incoming', 'viewer:base.registry.documents', 'viewer:tasks.core', 'basic', 'purchase', 'sales', 'accounting', 'system'],
            $ids,
        );
        // Fallback labels come from the PHP const, localized by $language.
        $this->assertSame('Základní', $this->node($tree, 'basic')['label']);
        $this->assertSame('Účtárna', $this->node($tree, 'accounting')['label']);
    }

    public function testFallbackSectionsEnglishWhenConfigRuntimeNull(): void
    {
        $resp = $this->ctrl->navigation($this->config(['install.base']), $this->resolver, 'en', null);
        $tree = $resp->getPayload()['data'];
        $this->assertSame('Accounting', $this->node($tree, 'accounting')['label']);
    }

    // --- API shape unchanged ---

    public function testApiShapeUnchanged(): void
    {
        $tree = $this->tree(['install.base']);

        // Root leaf: type + icon, no children.
        $this->assertSame('dashboard', $tree[0]['type']);
        $this->assertArrayHasKey('icon', $tree[0]);
        $this->assertArrayNotHasKey('children', $tree[0]);

        // Section: id + label + children; no internal keys leak out.
        $basic = $this->node($tree, 'basic');
        $this->assertArrayHasKey('children', $basic);
        $this->assertArrayNotHasKey('_section', $basic['children'][0]);
        $this->assertArrayNotHasKey('_order', $basic['children'][0]);

        // Viewer child: id 'viewer:<id>', type, viewerId.
        $this->assertSame('viewer:base.persons', $basic['children'][0]['id']);
        $this->assertSame('viewer', $basic['children'][0]['type']);
        $this->assertSame('base.persons', $basic['children'][0]['viewerId']);
    }

    // --- navigation providers (dynamické datové položky) ---

    public function testProviderItemsMergedIntoSection(): void
    {
        // Reálný BalancesNavigationProvider (registrace v economy.accbal) nad
        // mockovaným DB spojením — dvě saldokonta se show_in_navigation,
        // agregace předpisových účtů určuje ikonu (Issue #54).
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['code' => 'receivables', 'name' => 'Pohledávky', 'short_name' => 'Pohledávky',
                'side_min' => 0, 'side_max' => 0, 'side_cnt' => 2],
            ['code' => 'payables',    'name' => 'Závazky z obchodních vztahů', 'short_name' => null,
                'side_min' => 1, 'side_max' => 1, 'side_cnt' => 2],
        ]);

        $resp = $this->ctrl->navigation(
            $this->config(['install.base']),
            $this->resolver,
            'cs',
            $this->configRuntime('cs'),
            $db,
        );
        $children = $this->node($resp->getPayload()['data'], 'accounting')['children'];
        $ids      = array_column($children, 'id');

        // Saldokonta hned za Saldokonto po případech (navOrder 31 →
        // _order 32+), v pořadí ze SELECTU (sort_order); Saldo pohyby (39)
        // až za nimi (#69 D1: případy jsou vstup, pohyby detail).
        $casesPos = array_search('viewer:economy.accbal.cases', $ids, true);
        $this->assertIsInt($casesPos);
        $this->assertSame('accbal-balance:receivables', $ids[$casesPos + 1] ?? null);
        $this->assertSame('accbal-balance:payables', $ids[$casesPos + 2] ?? null);
        $this->assertSame('viewer:economy.accbal.ledger', $ids[$casesPos + 3] ?? null);

        $receivables = $children[$casesPos + 1];
        $this->assertSame('Pohledávky', $receivables['label']);
        $this->assertSame('viewer', $receivables['type']);
        $this->assertSame('economy.accbal.cases', $receivables['viewerId']);
        $this->assertSame('receivable', $receivables['icon']);
        $this->assertSame('receivables', $receivables['fixedViewGroup']);
        // Interní klíče nesmí proleakovat do API výstupu.
        $this->assertArrayNotHasKey('_section', $receivables);
        $this->assertArrayNotHasKey('_order', $receivables);

        // Label fallback: prázdný short_name → plný name; DAL strana → payable.
        $this->assertSame('Závazky z obchodních vztahů', $children[$casesPos + 2]['label']);
        $this->assertSame('payable', $children[$casesPos + 2]['icon']);
    }

    public function testProviderSkippedWithoutDb(): void
    {
        // Bez DB spojení (settings/account mód, degradace) se providery
        // přeskočí — žádné accbal-balance položky, navigace jinak celá.
        $children = $this->node($this->tree(['install.base']), 'accounting')['children'];
        foreach (array_column($children, 'id') as $id) {
            $this->assertStringStartsNotWith('accbal-balance:', $id);
        }
    }

    public function testProviderDbFailureDoesNotBreakNavigation(): void
    {
        // DS před ds-upgrade (chybějící sloupec) — provider chytá výjimku
        // z dotazu sám a vrací prázdno; statická navigace stojí beze změny.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willThrowException(new \RuntimeException('Unknown column show_in_navigation'));

        $resp = $this->ctrl->navigation(
            $this->config(['install.base']),
            $this->resolver,
            'cs',
            $this->configRuntime('cs'),
            $db,
        );
        $tree = $resp->getPayload()['data'];

        $this->assertContains('economy.accbal.ledger', $this->allViewerIds($tree));
        $ids = array_column($this->node($tree, 'accounting')['children'], 'id');
        $this->assertNotContains('accbal-balance:receivables', $ids);
    }

    public function testMissingProviderClassDoesNotBreakNavigation(): void
    {
        // Fixture modul registruje neexistující provider třídu — controller
        // zaloguje warn a navigace se sestaví bez ní.
        $root = sys_get_temp_dir() . '/shpd_navprov_' . uniqid('', true);
        $dir  = $root . '/test/navprov';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode([
            'id'                  => 'test.navprov',
            'name'                => 'Nav provider fixture',
            'dependencies'        => [],
            'tables'              => [],
            'navigationProviders' => [['class' => 'Acme\\MissingProvider']],
        ]));
        $resolver = new ModulePathResolver([$root, dirname(__DIR__, 4) . '/modules']);

        $resp = $this->ctrl->navigation(
            $this->config(['test.navprov', 'base.persons']),
            $resolver,
            'cs',
            $this->configRuntime('cs'),
            $this->createMock(DataSourceConnection::class),
        );
        $tree = $resp->getPayload()['data'];
        $this->assertContains('base.persons', $this->allViewerIds($tree));

        @unlink($dir . '/module.jsonc');
        @rmdir($dir);
        @rmdir($root . '/test');
        @rmdir($root);
    }

    // --- adminOnly filtrace (D4) + Chat gating (D5) + panely v navigaci ---

    public function testAdminOnlyItemsHiddenFromNonAdmin(): void
    {
        $modRoot  = $this->makeAdminFixtureModule();
        $resolver = new ModulePathResolver([$modRoot, dirname(__DIR__, 4) . '/modules']);
        $tables   = ['test_navadm_secrets' => $this->tableDef(adminOnly: true)];

        // Ne-admin: viewer nad adminOnly tabulkou, viewer nad core_system_*
        // i viewer s adminOnly na deklaraci zmizí; běžný viewer zůstává.
        $tree = $this->treeWith(['test.navadm'], $resolver, $this->nonAdmin(), $tables);
        $ids  = $this->allViewerIds($tree);
        $this->assertNotContains('test.navadm.secrets', $ids);
        $this->assertNotContains('test.navadm.sysusers', $ids);
        $this->assertNotContains('test.navadm.declared', $ids);
        $this->assertContains('test.navadm.plain', $ids);

        // Admin: žádná filtrace.
        $tree = $this->treeWith(['test.navadm'], $resolver, $this->admin(), $tables);
        $ids  = $this->allViewerIds($tree);
        $this->assertContains('test.navadm.secrets', $ids);
        $this->assertContains('test.navadm.sysusers', $ids);
        $this->assertContains('test.navadm.declared', $ids);

        $this->cleanupModuleRoot($modRoot, 'test/navadm');
    }

    public function testAdminOnlyFallbackTableHiddenFromNonAdmin(): void
    {
        // Tabulka bez vieweru (generický fallback item) s adminOnly na
        // runtime TableDefinition se ne-adminovi neemituje.
        $modRoot  = $this->makeAdminFixtureModule();
        $resolver = new ModulePathResolver([$modRoot, dirname(__DIR__, 4) . '/modules']);
        $tables   = ['test_navadm_bare' => $this->tableDef(adminOnly: true)];

        $tree = $this->treeWith(['test.navadm'], $resolver, $this->nonAdmin(), $tables);
        $this->assertNotContains('test_navadm_bare', $this->allTableIds($tree));

        $tree = $this->treeWith(['test.navadm'], $resolver, $this->admin(), $tables);
        $this->assertContains('test_navadm_bare', $this->allTableIds($tree));

        $this->cleanupModuleRoot($modRoot, 'test/navadm');
    }

    public function testNullAuthFiltersAsNonAdmin(): void
    {
        // Degradovaný kontext ($auth === null) = fail-closed, filtruje
        // jako ne-admin.
        $modRoot  = $this->makeAdminFixtureModule();
        $resolver = new ModulePathResolver([$modRoot, dirname(__DIR__, 4) . '/modules']);
        $tables   = ['test_navadm_secrets' => $this->tableDef(adminOnly: true)];

        $tree = $this->treeWith(['test.navadm'], $resolver, null, $tables);
        $ids  = $this->allViewerIds($tree);
        $this->assertNotContains('test.navadm.secrets', $ids);
        $this->assertNotContains('test.navadm.declared', $ids);

        $this->cleanupModuleRoot($modRoot, 'test/navadm');
    }

    public function testPanelWithNavSectionEmittedAsOrderedRootLeaf(): void
    {
        // Panel s navSection _top a navOrder 10 = první root leaf, před
        // Dashboardem (20) a Chatem (25); panel bez navSection se v hlavní
        // navigaci neobjeví (zůstává settings/account-only).
        $modRoot  = $this->makePanelFixtureModule();
        $resolver = new ModulePathResolver([$modRoot, dirname(__DIR__, 4) . '/modules']);

        $tree = $this->treeWith(['test.navpanel'], $resolver, $this->nonAdmin(), $this->defaultTables());

        $this->assertSame('panel:testPortal', $tree[0]['id']);
        $this->assertSame('panel', $tree[0]['type']);
        $this->assertSame('testPortal', $tree[0]['panelId']);
        $this->assertSame('Můj portál', $tree[0]['label']);
        $this->assertSame('database', $tree[0]['icon']);
        $this->assertSame('dashboard', $tree[1]['type']);
        $this->assertSame('chat', $tree[2]['type']);

        $allIds = array_map(fn($n) => $n['id'], $tree);
        $this->assertNotContains('panel:testHiddenPanel', $allIds);

        $this->cleanupModuleRoot($modRoot, 'test/navpanel');
    }

    public function testChatHiddenOnReadOnlyDs(): void
    {
        // #56 D5: read-only DS má chat vypnutý (routy 403) — leaf se neemituje
        // ani adminovi. Dashboard zůstává.
        $resp = $this->ctrl->navigation(
            $this->config(['install.base']),
            $this->resolver,
            'cs',
            $this->configRuntime('cs'),
            null,
            $this->admin(),
            $this->defaultTables(),
            readOnly: true,
        );
        $ids = array_map(fn($n) => $n['id'], $resp->getPayload()['data']);
        $this->assertNotContains('chat', $ids);
        $this->assertContains('dashboard', $ids);
    }

    public function testChatGatedForNonAdminOnHostingDs(): void
    {
        // Chat leaf = core.chat aktivní && (admin || hosting neaktivní) —
        // D5 z hosting-07 + D10 z 07b, identické s capability `chat`
        // v DashboardController.
        $chatAndHosting = $this->defaultTables()
            + ['hosting_core_data_sources' => $this->tableDef(adminOnly: true)];

        // Ne-admin na DS s aktivním hosting.core → Chat chybí, Dashboard je.
        $tree = $this->treeWith(['install.base'], $this->resolver, $this->nonAdmin(), $chatAndHosting);
        $ids  = array_map(fn($n) => $n['id'], $tree);
        $this->assertNotContains('chat', $ids);
        $this->assertContains('dashboard', $ids);

        // Admin na hosting DS s aktivním chatem Chat má.
        $tree = $this->treeWith(['install.base'], $this->resolver, $this->admin(), $chatAndHosting);
        $this->assertContains('chat', array_map(fn($n) => $n['id'], $tree));

        // Ne-admin bez hostingu (chat aktivní) Chat má.
        $tree = $this->treeWith(['install.base'], $this->resolver, $this->nonAdmin(), $this->defaultTables());
        $this->assertContains('chat', array_map(fn($n) => $n['id'], $tree));

        // Bez core.chat Chat chybí i adminovi bez hostingu (07b D10).
        $tree = $this->treeWith(['install.base'], $this->resolver, $this->admin(), []);
        $this->assertNotContains('chat', array_map(fn($n) => $n['id'], $tree));
    }

    public function testAlertsViewerAdminOnlyInNavigation(): void
    {
        // D7 (07b): viewer Upozornění nese adminOnly na deklaraci — ne-admin
        // ho nedostane na žádném DS (alerty obsluhuje přes dashboard feed),
        // admin ano. Tabulka core_alerts_alerts bariéru nemá (feed akce
        // open_viewer/open_form ne-adminovi fungují dál).
        $tree = $this->treeWith(['install.base'], $this->resolver, $this->nonAdmin(), $this->defaultTables());
        $this->assertNotContains('core.alerts.alerts', $this->allViewerIds($tree));

        $tree = $this->treeWith(['install.base'], $this->resolver, $this->admin(), $this->defaultTables());
        $this->assertContains('core.alerts.alerts', $this->allViewerIds($tree));
    }

    public function testHostingPortalPanelOrderInProductionTree(): void
    {
        // Reálný hosting.core: portál(10) → Dashboard(20) → _top viewery
        // (30+). Dedikovaný hosting DS nemá core.chat → Chat leaf chybí
        // i adminovi (07b D10). Hosting viewery jsou v settingsItems →
        // v hlavní navigaci nejsou ani adminovi.
        $hosting = ['hosting_core_data_sources' => $this->tableDef(adminOnly: true)];

        $tree = $this->treeWith(['install.base', 'hosting.core'], $this->resolver, $this->admin(), $hosting);
        $ids  = array_map(fn($n) => $n['id'], $tree);
        $this->assertSame(
            ['panel:hostingPortal', 'dashboard', 'viewer:core.mail.incoming'],
            array_slice($ids, 0, 3),
        );
        $this->assertNotContains('chat', $ids);
        $this->assertSame('Moje zdroje dat', $tree[0]['label']);
        $this->assertSame('panel', $tree[0]['type']);
        $this->assertSame('hostingPortal', $tree[0]['panelId']);

        // Ne-admin: portál + Dashboard ano, Chat ne (D5), žádné hosting
        // viewery.
        $tree = $this->treeWith(['install.base', 'hosting.core'], $this->resolver, $this->nonAdmin(), $hosting);
        $ids  = array_map(fn($n) => $n['id'], $tree);
        $this->assertSame(['panel:hostingPortal', 'dashboard'], array_slice($ids, 0, 2));
        $this->assertNotContains('chat', $ids);
        foreach ($this->allViewerIds($tree) as $viewerId) {
            $this->assertStringStartsNotWith('hosting.', $viewerId);
        }
    }

    // --- fixture helpers ---

    /** Creates a temp module root with a viewer that has NO navSection. */
    private function makeFallbackFixtureModule(): string
    {
        $root = sys_get_temp_dir() . '/shpd_navfix_' . uniqid('', true);
        $dir  = $root . '/test/navfallback';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode([
            'id'           => 'test.navfallback',
            'name'         => 'Nav fallback fixture',
            'dependencies' => [],
            'tables'       => ['test_navfallback_things'],
            'viewers'      => [[
                'id'    => 'test.navfallback.viewer',
                'name'  => 'Things',
                'icon'  => 'box',
                'table' => 'test_navfallback_things',
                'class' => 'Acme\\Nope',
                // intentionally NO navSection / navOrder
            ]],
        ]));
        return $root;
    }

    private function cleanupFixtureModule(string $root): void
    {
        @unlink($root . '/test/navfallback/module.jsonc');
        @rmdir($root . '/test/navfallback');
        @rmdir($root . '/test');
        @rmdir($root);
    }

    // --- helpers pro adminOnly / panel testy ---

    /** tree() s auth + tables — plná signatura navigation(). */
    private function treeWith(array $modules, ModulePathResolver $resolver, ?AuthContext $auth, array $tables): array
    {
        $resp = $this->ctrl->navigation(
            $this->config($modules),
            $resolver,
            'cs',
            $this->configRuntime('cs'),
            null,
            $auth,
            $tables,
        );
        $this->assertInstanceOf(Response::class, $resp);
        return $resp->getPayload()['data'];
    }

    private function admin(): AuthContext
    {
        return new AuthContext(isAuthenticated: true, userId: 1, isAdmin: true);
    }

    private function nonAdmin(): AuthContext
    {
        return new AuthContext(isAuthenticated: true, userId: 2, isAdmin: false);
    }

    /** Minimální runtime TableDefinition — pro navigaci stačí adminOnly flag. */
    private function tableDef(bool $adminOnly): TableDefinition
    {
        return new TableDefinition(
            tableId: 999,
            name: 'test',
            displayPattern: null,
            columnGroups: [],
            columns: [],
            indexes: [],
            childTables: [],
            docStates: null,
            stateTransitionsRunDocumentHooks: false,
            adminOnly: $adminOnly,
        );
    }

    /**
     * Fixture modul se čtyřmi viewery (adminOnly tabulka / core_system_
     * tabulka / adminOnly na deklaraci / běžný) a jednou tabulkou bez
     * vieweru (fallback item pro adminOnly test).
     */
    private function makeAdminFixtureModule(): string
    {
        $root = sys_get_temp_dir() . '/shpd_navadm_' . uniqid('', true);
        $dir  = $root . '/test/navadm';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode([
            'id'           => 'test.navadm',
            'name'         => 'AdminOnly nav fixture',
            'dependencies' => [],
            'tables'       => ['test_navadm_bare'],
            'viewers'      => [
                [
                    'id'    => 'test.navadm.secrets',
                    'name'  => 'Secrets',
                    'table' => 'test_navadm_secrets',
                    'class' => 'Acme\\Nope',
                    'navSection' => 'basic',
                ],
                [
                    'id'    => 'test.navadm.sysusers',
                    'name'  => 'System users',
                    'table' => 'core_system_users',
                    'class' => 'Acme\\Nope',
                    'navSection' => 'basic',
                ],
                [
                    'id'        => 'test.navadm.declared',
                    'name'      => 'Declared admin-only',
                    'table'     => 'test_navadm_public',
                    'class'     => 'Acme\\Nope',
                    'navSection' => 'basic',
                    'adminOnly' => true,
                ],
                [
                    'id'    => 'test.navadm.plain',
                    'name'  => 'Plain',
                    'table' => 'test_navadm_plain',
                    'class' => 'Acme\\Nope',
                    'navSection' => 'basic',
                ],
            ],
        ]));
        return $root;
    }

    /** Fixture modul s panelem v hlavní navigaci + panelem bez navSection. */
    private function makePanelFixtureModule(): string
    {
        $root = sys_get_temp_dir() . '/shpd_navpanel_' . uniqid('', true);
        $dir  = $root . '/test/navpanel';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.jsonc', json_encode([
            'id'           => 'test.navpanel',
            'name'         => 'Panel nav fixture',
            'dependencies' => [],
            'tables'       => [],
            'panels'       => [
                [
                    'id'         => 'testPortal',
                    'name'       => 'My portal',
                    'name:cs'    => 'Můj portál',
                    'icon'       => 'database',
                    'navSection' => '_top',
                    'navOrder'   => 10,
                ],
                [
                    'id'   => 'testHiddenPanel',
                    'name' => 'Settings-only panel',
                ],
            ],
        ]));
        return $root;
    }

    private function cleanupModuleRoot(string $root, string $relDir): void
    {
        @unlink($root . '/' . $relDir . '/module.jsonc');
        @rmdir($root . '/' . $relDir);
        @rmdir(dirname($root . '/' . $relDir));
        @rmdir($root);
    }
}
