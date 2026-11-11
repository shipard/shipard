<?php
declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Navigation\NavigationItemsProvider;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Hlavní navigace (sidebar) v app módu.
 *
 * Sekce sidebaru jsou sémantické — definuje je cfgItem `global.navSections`
 * (modules/install/base/config/navSections.jsonc), NE prefix module ID.
 * Každý viewer / tabulka nese `navSection` (id sekce) a volitelně `navOrder`
 * (int pořadí v sekci). `navigation()` seskupuje podle `navSection`, řadí
 * sekce dle navSections.order a položky dle navOrder.
 *
 * Sentinel `navSection: "_top"` = root-level leaf nad sekcemi (Došlá pošta,
 * Úkoly). Dashboard a Chat jsou syntetické root leaves (nejsou viewery) —
 * řadí se společně s `_top` položkami přes `_order` 20/25. Panel modulu
 * (`panels[]` v module.jsonc), který deklaruje `navSection`, vstupuje do
 * hlavní navigace jako item `{type: 'panel'}` — portál hostingu.
 * Co nemá `navSection` (nebo má neznámou sekci) → fallback do `system`.
 *
 * Ne-adminovi se navigace ořezává podle týchž bariér, které na datech
 * vynucuje TableAccessGuard (prefix core_system_, adminOnly na runtime
 * TableDefinition) + explicitní `adminOnly: true` na deklaraci vieweru/
 * panelu. `$auth === null` (degradovaný kontext) filtruje jako ne-admin
 * (fail-closed). Chat leaf se emituje jen při aktivním core.chat (07b D10)
 * a ne-adminovi na DS s aktivním hosting.core se nevrací (D5).
 *
 * API tvar odpovědi (id/label/children/type/icon/viewerId/table) je shodný
 * s dřívějším prefix-groupingem — Sidebar.svelte se nemění.
 */
class NavigationController
{
    /** Sentinel navSection — root-level leaf nad sekcemi (Došlá pošta, Úkoly). */
    private const string TOP_SECTION = '_top';

    /** Fallback sekce pro itemy bez navSection / s neznámou navSection. */
    private const string FALLBACK_SECTION = 'system';

    /** navOrder pro položky bez explicitního pořadí — padají na konec sekce. */
    private const int NAV_ORDER_DEFAULT = 1000;

    /**
     * Vestavěný fallback sekcí pro případ, že compiled config (cfgItem
     * `global.navSections`) chybí — navigace funguje degradovaně, ne crash.
     * Drží stejná id/pořadí jako navSections.jsonc; labely cs/en přímo zde.
     */
    private const array SECTIONS_FALLBACK = [
        ['id' => 'basic',      'order' => 10, 'cs' => 'Základní', 'en' => 'Basic'],
        ['id' => 'purchase',   'order' => 20, 'cs' => 'Nákup',    'en' => 'Purchase'],
        ['id' => 'sales',      'order' => 30, 'cs' => 'Prodej',   'en' => 'Sales'],
        ['id' => 'accounting', 'order' => 40, 'cs' => 'Účtárna',  'en' => 'Accounting'],
        ['id' => 'system',     'order' => 50, 'cs' => 'Systém',   'en' => 'System'],
    ];

    private const array SKIP_PATTERNS = [
        'sessions',
        'rate_limits',
        'api_keys',
    ];

    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     *        Runtime definice tabulek — zdroj pravdy pro `adminOnly`
     *        (stejný jako TableAccessGuard::guardTable()).
     */
    public function navigation(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language,
        ?ConfigRuntime $configRuntime = null,
        ?DataSourceConnection $db = null,
        ?AuthContext $auth = null,
        array $tables = [],
        bool $readOnly = false,
    ): Response {
        $isAdmin = $auth?->isAdmin ?? false;
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        $hiddenViewers = [];
        $hiddenTables  = [];

        // (1) Viewers/tables listed in any module's settingsItems are managed
        // via the Settings UI — hide them from the main navigation.
        foreach ($resolvedModules as $module) {
            foreach ($module->settingsItems as $item) {
                if ($item['viewer'] !== null) {
                    $hiddenViewers[$item['viewer']] = true;
                }
                if ($item['table'] !== null) {
                    $hiddenTables[$item['table']] = true;
                }
            }
        }

        // (2) Viewer-level `hideFromNavigation` (declared on the viewer in
        // module.jsonc) — hides that specific viewer. Used for the summary
        // docs.core.heads viewer over the shared docs_core_heads table.
        foreach ($resolvedModules as $module) {
            foreach ($module->viewers as $viewer) {
                if (!empty($viewer['hideFromNavigation']) && isset($viewer['id'])) {
                    $hiddenViewers[$viewer['id']] = true;
                }
            }
        }

        // (3) Table-level `hideFromNavigation` (declared in the table's .jsonc,
        // typically sub-tables of a parent record — fiscal_months, doc rows…).
        foreach ($resolvedModules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) {
                continue;
            }
            foreach ($module->tables as $tableName) {
                if ($this->isTableHiddenFromNavigation($modulePath, $tableName)) {
                    $hiddenTables[$tableName] = true;
                }
            }
        }

        // (4) Every table that ANY viewer targets — across ALL modules. A table
        // represented by a viewer is never rendered as a raw fallback table
        // item, even when all its viewers are hidden. Computed globally so a
        // per-type viewer (docs.invoicesIn.heads) covers a shared table
        // (docs_core_heads) owned by another module.
        $tablesWithViewer = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->viewers as $viewer) {
                if (isset($viewer['table'])) {
                    $tablesWithViewer[$viewer['table']] = true;
                }
            }
        }

        // (5) table → viewer propagation: a hidden table (hideFromNavigation or
        // settingsItems) hides any viewer targeting it. We deliberately do NOT
        // propagate viewer → table: hiding the summary docs.core.heads viewer
        // must not hide docs_core_heads and break the invoice viewers that
        // share it. Fallback-item suppression is handled by $tablesWithViewer.
        foreach ($resolvedModules as $module) {
            foreach ($module->viewers as $viewer) {
                $viewerId  = $viewer['id'] ?? null;
                $tableName = $viewer['table'] ?? null;
                if ($viewerId !== null && $tableName !== null && isset($hiddenTables[$tableName])) {
                    $hiddenViewers[$viewerId] = true;
                }
            }
        }

        $sections     = $this->loadSections($configRuntime, $language);
        $sectionIndex = [];
        foreach ($sections as $section) {
            $sectionIndex[$section['id']] = true;
        }

        $items = $this->collectItems(
            $resolvedModules,
            $resolver,
            $language,
            $hiddenViewers,
            $hiddenTables,
            $tablesWithViewer,
            $isAdmin,
            $tables,
        );

        // Dynamické položky z navigation providerů (data-driven, např.
        // saldokonta) — stejné bucketování _section/_order jako statické.
        $items = array_merge($items, $this->collectProviderItems($resolvedModules, $db, $language, $isAdmin, $tables));

        // Skupina Reporty — z report deklarací (docs/reports.md D7/D10):
        // jedna deklarace řídí navigaci i UI.
        $items = array_merge($items, $this->collectReportItems($resolvedModules, $config, $resolver, $language));

        // Bucket by navSection. `_top` → root-level leaves; unknown → fallback.
        $topLeaves = [];
        $buckets   = [];
        foreach ($items as $item) {
            $sec = $item['_section'];
            if ($sec === self::TOP_SECTION) {
                $topLeaves[] = $item;
                continue;
            }
            if (!isset($sectionIndex[$sec])) {
                $sec = self::FALLBACK_SECTION;
            }
            $buckets[$sec][] = $item;
        }

        // Dashboard a Chat — syntetické root leaves (nejsou viewery), řadí se
        // společně s `_top` položkami: portál hostingu 10, Dashboard 20,
        // Chat 25, stávající _top viewery 30+. Na běžném DS tak pořadí
        // zůstává Dashboard → Chat → pošta → … (D3/D6).
        $topLeaves[] = [
            'id'     => 'dashboard',
            'label'  => 'Dashboard',
            'type'   => 'dashboard',
            'icon'   => 'dashboard',
            '_order' => 20,
        ];
        // Chat leaf jen při aktivním core.chat (07b D10), ne pro ne-admina
        // na DS s aktivním hosting.core (D5) a ne na read-only DS (#56 D5 —
        // chat je vypnutý, routy vrací 403). Výraz musí zůstat identický
        // s capability `chat` v DashboardController::dashboard().
        if (isset($tables['core_chat_conversations'])
            && ($isAdmin || !isset($tables['hosting_core_data_sources']))
            && !$readOnly
        ) {
            $topLeaves[] = [
                'id'     => 'chat',
                'label'  => 'Chat',
                'type'   => 'chat',
                'icon'   => 'chat',
                '_order' => 25,
            ];
        }

        $this->sortItems($topLeaves);
        foreach ($buckets as &$bucket) {
            $this->sortItems($bucket);
        }
        unset($bucket);

        // Build the section tree in navSections.order; skip empty sections.
        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];
            if (empty($buckets[$sectionId])) {
                continue;
            }
            $tree[] = [
                'id'       => $sectionId,
                'label'    => $section['label'],
                'icon'     => $section['icon'] ?? null,
                'children' => array_map([$this, 'cleanItem'], $buckets[$sectionId]),
            ];
        }

        // Root-level leaves (portál, Dashboard, Chat, _top viewery — už
        // seřazené dle _order), pak sekce.
        $groups = array_merge(array_map([$this, 'cleanItem'], $topLeaves), $tree);

        return Response::success($groups);
    }

    /**
     * Sebere všechny navigační itemy napříč moduly do plochého seznamu:
     * viditelné viewery (primární) + tabulky bez vieweru (generický fallback).
     * Ke každému připojí interní `_section` (navSection / fallback) a `_order`
     * (navOrder / velké číslo). Tyto klíče se z výstupu odstraní v cleanItem().
     *
     * @param  ModuleDefinition[]  $resolvedModules
     * @return array<int, array<string, mixed>>
     */
    private function collectItems(
        array $resolvedModules,
        ModulePathResolver $resolver,
        string $language,
        array $hiddenViewers,
        array $hiddenTables,
        array $tablesWithViewer,
        bool $isAdmin,
        array $tables,
    ): array {
        $items       = [];
        $seenViewers = [];
        $seenTables  = [];
        $seenPanels  = [];

        foreach ($resolvedModules as $module) {
            $modulePath = $resolver->getPath($module->id);
            if ($modulePath === null) {
                continue;
            }

            // Viewers — primary navigation entries.
            foreach ($module->viewers as $viewer) {
                $viewerId = $viewer['id'] ?? null;
                if ($viewerId === null || isset($hiddenViewers[$viewerId]) || isset($seenViewers[$viewerId])) {
                    continue;
                }
                $seenViewers[$viewerId] = true;

                if ($this->isItemForbidden($viewer['table'] ?? null, $viewer, $isAdmin, $tables)) {
                    continue;
                }

                $label = $viewer['name:' . $language]
                    ?? $viewer['name:en']
                    ?? $viewer['name']
                    ?? $viewerId;

                $item = [
                    'id'       => 'viewer:' . $viewerId,
                    'label'    => $label,
                    'type'     => 'viewer',
                    'viewerId' => $viewerId,
                    '_section' => isset($viewer['navSection']) ? (string) $viewer['navSection'] : self::FALLBACK_SECTION,
                    '_order'   => isset($viewer['navOrder']) ? (int) $viewer['navOrder'] : self::NAV_ORDER_DEFAULT,
                ];
                if (isset($viewer['icon'])) {
                    $item['icon'] = $viewer['icon'];
                }
                $items[] = $item;
            }

            // Tables not represented by any viewer (anywhere) — generic fallback
            // table items. navSection/navOrder may be declared in the table .jsonc.
            foreach ($module->tables as $tableName) {
                if ($this->shouldSkip($tableName)
                    || isset($hiddenTables[$tableName])
                    || isset($tablesWithViewer[$tableName])
                    || isset($seenTables[$tableName])
                ) {
                    continue;
                }
                $seenTables[$tableName] = true;

                if ($this->isItemForbidden($tableName, null, $isAdmin, $tables)) {
                    continue;
                }

                $meta = $this->loadTableMeta($modulePath, $tableName, $language);

                $item = [
                    'id'       => $tableName,
                    'label'    => $meta['name'] ?? $tableName,
                    'type'     => 'table',
                    'table'    => $tableName,
                    '_section' => isset($meta['navSection']) ? (string) $meta['navSection'] : self::FALLBACK_SECTION,
                    '_order'   => isset($meta['navOrder']) ? (int) $meta['navOrder'] : self::NAV_ORDER_DEFAULT,
                ];
                if (isset($meta['icon'])) {
                    $item['icon'] = $meta['icon'];
                }
                $items[] = $item;
            }

            // Panels declaring navSection enter the main navigation as
            // {type: 'panel'} items (portál hostingu). Panels without
            // navSection stay settings/account-only — fully backwards
            // compatible. Item shape mirrors SettingsController.
            foreach ($module->panels as $panel) {
                $panelId = $panel['id'];
                if (!isset($panel['navSection']) || isset($seenPanels[$panelId])) {
                    continue;
                }
                $seenPanels[$panelId] = true;

                if ($this->isItemForbidden(null, $panel, $isAdmin, $tables)) {
                    continue;
                }

                $item = [
                    'id'       => 'panel:' . $panelId,
                    'label'    => $panel['name:' . $language]
                        ?? $panel['name:en']
                        ?? $panel['name']
                        ?? $panelId,
                    'type'     => 'panel',
                    'panelId'  => $panelId,
                    '_section' => (string) $panel['navSection'],
                    '_order'   => isset($panel['navOrder']) ? (int) $panel['navOrder'] : self::NAV_ORDER_DEFAULT,
                ];
                if (isset($panel['icon'])) {
                    $item['icon'] = $panel['icon'];
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Zrcadlí bariéry TableAccessGuard::guardTable() pro navigaci (D4):
     * ne-adminovi se nevrací item nad tabulkou s prefixem core_system_
     * nebo s adminOnly na runtime TableDefinition, ani item, jehož
     * deklarace (viewer/panel v module.jsonc) nese `adminOnly: true`.
     * Admin: žádná filtrace.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    private function isItemForbidden(?string $table, ?array $decl, bool $isAdmin, array $tables): bool
    {
        if ($isAdmin) {
            return false;
        }
        if (!empty($decl['adminOnly'])) {
            return true;
        }
        if ($table === null) {
            return false;
        }
        if (str_starts_with($table, TableAccessGuard::SYSTEM_TABLE_PREFIX)) {
            return true;
        }
        return isset($tables[$table]) && $tables[$table]->adminOnly === true;
    }

    /**
     * Dynamické položky z navigation providerů modulů (`navigationProviders`
     * v module.jsonc, třídy implementující NavigationItemsProvider).
     *
     * Bez DB spojení (settings/account mód, degradovaný kontext) se providery
     * přeskočí. Navigace nesmí spadnout: chybějící/nevalidní třída → warn,
     * výjimka z provideru → logException a pokračuje se dalším providerem.
     *
     * @param  ModuleDefinition[]  $resolvedModules
     * @return array<int, array<string, mixed>>
     */
    private function collectProviderItems(
        array $resolvedModules,
        ?DataSourceConnection $db,
        string $language,
        bool $isAdmin,
        array $tables,
    ): array {
        if ($db === null) {
            return [];
        }

        $items = [];
        foreach ($resolvedModules as $module) {
            foreach ($module->navigationProviders as $reg) {
                $class = $reg['class'];
                try {
                    if (!class_exists($class) || !is_subclass_of($class, NavigationItemsProvider::class)) {
                        ErrorLogger::warn("Navigation provider '{$class}' missing or not a NavigationItemsProvider", [
                            'module' => $module->id,
                        ]);
                        continue;
                    }
                    $provider = new $class();
                    foreach ($provider->items($db, $language) as $item) {
                        if ($this->isItemForbidden($item['table'] ?? null, null, $isAdmin, $tables)) {
                            continue;
                        }
                        // Bucketování počítá s _section/_order — doplnit defaulty,
                        // ať neúplná položka provider nerozbije sort/merge.
                        $item['_section'] ??= self::FALLBACK_SECTION;
                        $item['_order']   ??= self::NAV_ORDER_DEFAULT;
                        $items[] = $item;
                    }
                } catch (\Throwable $e) {
                    ErrorLogger::logException($e, "Navigation provider '{$class}' failed");
                }
            }
        }
        return $items;
    }

    /**
     * Skupina Reporty per sekce z report deklarací (`reports` v module.jsonc,
     * viz docs/reports.md D7/D10), id `reports:<sekce>`. Child = leaf `{type: 'panel', panelId:
     * 'reports', panelParams: {reportId}}` — jednu generickou stránku
     * parametrizuje id reportu. Reporty bez `navSection` do navigace
     * nevstupují. Loader se volá jen když má některý modul deklarace;
     * selhání loaderu navigaci neshodí (vzor collectProviderItems).
     *
     * @param  ModuleDefinition[]  $resolvedModules
     * @return array<int, array<string, mixed>>
     */
    private function collectReportItems(
        array $resolvedModules,
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language,
    ): array {
        $hasReports = false;
        foreach ($resolvedModules as $module) {
            if ($module->reports !== []) {
                $hasReports = true;
                break;
            }
        }
        if (!$hasReports) {
            return [];
        }

        try {
            $registry = \Shipard\Api\ReportDefinitionLoader::load($config, $resolver, $language);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'Report definitions failed to load for navigation');
            return [];
        }

        $bySection = [];
        foreach ($registry->getAll() as $definition) {
            if ($definition->navSection === null) {
                continue;
            }
            $bySection[$definition->navSection][] = $definition;
        }

        $items = [];
        foreach ($bySection as $section => $definitions) {
            usort($definitions, fn ($a, $b) => $a->navOrder <=> $b->navOrder);
            $children = [];
            foreach ($definitions as $definition) {
                $children[] = [
                    'id'          => 'report:' . $definition->id,
                    'label'       => $definition->name,
                    'type'        => 'panel',
                    'panelId'     => 'reports',
                    'panelParams' => ['reportId' => $definition->id],
                    'icon'        => 'chart',
                ];
            }
            // Id skupiny nese sekci (`reports:accounting`, `reports:assets`) —
            // reporty žijí ve víc sekcích a id uzlu musí být ve stromu
            // jedinečné: frontend podle něj drží rozbalení skupin.
            $items[] = [
                'id'       => 'reports:' . $section,
                'label'    => $language === 'cs' ? 'Reporty' : 'Reports',
                'children' => $children,
                '_section' => (string) $section,
                '_order'   => $definitions[0]->navOrder,
            ];
        }
        return $items;
    }

    /**
     * Načte sekce z cfgItem `global.navSections` (jako settingsSections), nebo
     * vestavěný fallback když compiled config chybí. Vrací seznam seřazený dle
     * `order`, s lokalizovaným `label`.
     *
     * @return array<int, array{id: string, label: string, icon: ?string, order: int}>
     */
    private function loadSections(?ConfigRuntime $configRuntime, string $language): array
    {
        $raw = $configRuntime?->cfgItem('global.navSections');
        if (is_array($raw) && !empty($raw['sections']) && is_array($raw['sections'])) {
            $sections = [];
            foreach ($raw['sections'] as $section) {
                if (!isset($section['id'])) {
                    continue;
                }
                $sections[] = [
                    'id'    => (string) $section['id'],
                    'label' => $section['name:' . $language]
                        ?? $section['name:en']
                        ?? $section['name']
                        ?? (string) $section['id'],
                    'icon'  => $section['icon'] ?? null,
                    'order' => isset($section['order']) ? (int) $section['order'] : 0,
                ];
            }
            usort($sections, fn($a, $b) => $a['order'] <=> $b['order']);
            return $sections;
        }

        // Fallback — compiled config missing. Degraded but functional.
        $sections = [];
        foreach (self::SECTIONS_FALLBACK as $section) {
            $sections[] = [
                'id'    => $section['id'],
                'label' => $section[$language] ?? $section['en'],
                'icon'  => null,
                'order' => $section['order'],
            ];
        }
        return $sections;
    }

    /** Seřadí itemy in-place dle interního `_order` (stabilní v PHP 8+). */
    private function sortItems(array &$items): void
    {
        usort(
            $items,
            fn($a, $b) => ($a['_order'] ?? self::NAV_ORDER_DEFAULT) <=> ($b['_order'] ?? self::NAV_ORDER_DEFAULT),
        );
    }

    /** Odstraní interní klíče (`_section`, `_order`) — nepatří do API výstupu. */
    private function cleanItem(array $item): array
    {
        unset($item['_section'], $item['_order']);
        return $item;
    }

    private function loadTableMeta(string $modulePath, string $tableName, string $language): array
    {
        $filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
        if (!file_exists($filePath)) {
            return ['name' => $tableName];
        }

        $raw       = JsoncParser::parseFile($filePath);
        $localized = ConfigLocalizer::localize($raw, $language);

        return $localized;
    }

    private function isTableHiddenFromNavigation(string $modulePath, string $tableName): bool
    {
        $filePath = $modulePath . '/tables/' . $tableName . '.jsonc';
        if (!file_exists($filePath)) {
            return false;
        }
        $raw = JsoncParser::parseFile($filePath);
        return !empty($raw['hideFromNavigation']);
    }

    private function shouldSkip(string $tableName): bool
    {
        foreach (self::SKIP_PATTERNS as $pattern) {
            if (str_contains($tableName, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
