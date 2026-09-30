<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Navigation\NavItemVisibilityGate;
use Shipard\Core\Settings\KeyValueStore;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Settings\UserSettingsStore;
use Shipard\Core\Utils\JsoncParser;

class SettingsController
{
    /**
     * Allowlist jmen shellů pro field typ `shell` — nový shell = přidat
     * jméno. Chrání DS config před překlepy; klientský fallback na
     * `sidebar` je druhá pojistka.
     */
    private const SHELLS = ['sidebar', 'classic', 'wild'];

    public function navigation(
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language,
        ?ConfigRuntime $configRuntime,
        string $kind = 'settings',
        ?AuthContext $auth = null,
        array $tables = [],
        ?DataSourceConnection $db = null,
    ): Response {
        if ($configRuntime === null) {
            return Response::success([]);
        }

        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        // Nastavení účtu jede z vlastní stromové konfigurace (global.accountSections)
        // a vlastních položek (accountItems) — žádná kontaminace se settings módem.
        $cfgItemId   = $kind === 'account' ? 'global.accountSections' : 'global.settingsSections';
        $sectionsCfg = $configRuntime->cfgItem($cfgItemId);
        if (!is_array($sectionsCfg) || empty($sectionsCfg['sections'])) {
            return Response::success([]);
        }

        $sections = $sectionsCfg['sections'];
        usort($sections, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        $itemsBySection = $this->collectItems($resolvedModules, $resolver, $language, $kind, $auth, $tables, $db);

        $tree = [];
        foreach ($sections as $section) {
            $sectionId = $section['id'];

            // Položky patřící přímo do sekce (bez subsection).
            $directItems = $itemsBySection[$sectionId] ?? [];

            // Podsekce — každá sbírá své položky z klíče "section\0subsection".
            $subChildren = [];
            if (!empty($section['subsections']) && is_array($section['subsections'])) {
                $subsections = $section['subsections'];
                usort($subsections, fn($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
                foreach ($subsections as $sub) {
                    $subId    = $sub['id'];
                    $subKey   = $this->sectionKey($sectionId, $subId);
                    $subItems = $itemsBySection[$subKey] ?? [];
                    if ($subItems === []) {
                        continue; // prázdnou podsekci nevykreslujeme
                    }
                    $subLabel = $sub['name:' . $language]
                        ?? $sub['name:en']
                        ?? $sub['name']
                        ?? $subId;
                    $subChildren[] = [
                        'id'       => $subId,
                        'label'    => $subLabel,
                        'children' => $subItems,
                    ];
                }
            }

            // Sekce bez přímých položek i bez naplněných podsekcí se vynechá.
            if ($directItems === [] && $subChildren === []) {
                continue;
            }

            $label = $section['name:' . $language]
                ?? $section['name:en']
                ?? $section['name']
                ?? $sectionId;

            // Pořadí children: nejdřív přímé položky, pak podsekce.
            // (Pro sekci "other" nejsou žádné přímé položky — jen podsekce.)
            $tree[] = [
                'id'       => $sectionId,
                'label'    => $label,
                'icon'     => $section['icon'] ?? null,
                'children' => array_merge($directItems, $subChildren),
            ];
        }

        return Response::success($tree);
    }

    /**
     * GET /_ui/settings/page/{pageId}
     *
     * Vrátí lokalizovanou definici settings page + aktuální hodnoty polí.
     * Pro `image` pole je hodnotou stav slotu ({url, hash, filename, mime,
     * size} nebo null), textová pole čtou přímo z SettingsStore.
     */
    public function page(
        string $pageId,
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        string $language,
        AuthContext $auth,
        DataSourceConnection $db,
    ): Response {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $pageDef = $this->findPage($pageId, $config, $resolver);
        if ($pageDef === null) {
            return Response::error('NOT_FOUND', "Settings page not found: {$pageId}", 404);
        }

        // adminOnly stránky (např. hosting.oidc.issuer) nesmí číst ne-admin —
        // hosting DS má z definice ne-admin portálové uživatele.
        if (($pageDef['adminOnly'] ?? false) && !$auth->isAdmin) {
            return Response::error('FORBIDDEN', 'Admin access required', 403);
        }

        if (($pageDef['scope'] ?? 'ds') === 'user' && $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'User scope requires a user session', 401);
        }

        $store = $this->storeForPage($pageDef, $db, $auth);

        return Response::success([
            'definition' => $this->localizePageDefinition($pageDef, $language),
            'values'     => $this->buildPageValues($pageDef, $store),
        ]);
    }

    /**
     * Vybere úložiště podle scope stránky: `user` → per-user
     * {@see UserSettingsStore} (vyžaduje přihlášeného uživatele s userId),
     * jinak DS-scoped {@see SettingsStore}.
     */
    private function storeForPage(array $pageDef, DataSourceConnection $db, AuthContext $auth): KeyValueStore
    {
        if (($pageDef['scope'] ?? 'ds') === 'user') {
            return new UserSettingsStore($db, (int) $auth->userId);
        }
        return new SettingsStore($db);
    }

    /**
     * POST /_ui/settings/page/{pageId}
     *
     * Body: { values: { "app.name": "...", ... } }. Ukládá jen textová pole
     * definovaná ve stránce (whitelist), trim, maxLength validace, prázdný
     * string maže klíč (fallback na default). `image` klíče se ignorují —
     * obrázky mají vlastní upload endpoint (/_app/branding/{slot}).
     */
    public function savePage(
        string $pageId,
        Request $request,
        DataSourceConfig $config,
        ModulePathResolver $resolver,
        AuthContext $auth,
        DataSourceConnection $db,
    ): Response {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $pageDef = $this->findPage($pageId, $config, $resolver);
        if ($pageDef === null) {
            return Response::error('NOT_FOUND', "Settings page not found: {$pageId}", 404);
        }

        if (($pageDef['adminOnly'] ?? false) && !$auth->isAdmin) {
            return Response::error('FORBIDDEN', 'Admin access required', 403);
        }

        if (($pageDef['scope'] ?? 'ds') === 'user' && $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'User scope requires a user session', 401);
        }

        $body   = $request->getBody();
        $values = $body['values'] ?? null;
        if (!is_array($values)) {
            return Response::error('BAD_REQUEST', 'Body must contain a `values` object', 400);
        }

        // Whitelist: jen pole z definice, která chodí přes Uložit (text,
        // theme, language). `image` má vlastní upload endpoint a tiše se
        // ignoruje. Klíče mimo definici taky.
        $toSave = [];
        $errors = [];
        foreach ($pageDef['fields'] as $field) {
            $id   = $field['id'];
            $type = $field['type'];
            if (!array_key_exists($id, $values)) {
                continue;
            }
            $raw = $values[$id];

            if ($type === 'text') {
                if ($raw !== null && !is_scalar($raw)) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_TYPE', 'message' => 'Value must be a string'];
                    continue;
                }
                $value     = trim((string) ($raw ?? ''));
                $maxLength = isset($field['maxLength']) ? (int) $field['maxLength'] : null;
                if ($maxLength !== null && mb_strlen($value) > $maxLength) {
                    $errors[] = ['field' => $id, 'code' => 'MAX_LENGTH', 'message' => "Value exceeds maximum length of {$maxLength} characters"];
                    continue;
                }
                // Prázdný string = smazat klíč → čtenáři padnou na fallback.
                $toSave[$id] = $value === '' ? null : $value;
            } elseif ($type === 'theme') {
                if (!is_array($raw)) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_VALUE', 'message' => 'Invalid theme value'];
                    continue;
                }
                // User-scope theme (account.theme) nese follow flag:
                //   {follow:true}                → sleduj DS default
                //   {follow:false, mode, custom} → vlastní override
                //   {mode, custom} (legacy)      → ber jako override (follow:false)
                // DS-scope theme (app.theme) follow nemá — případný flag zahodíme.
                $isUserScope = ($pageDef['scope'] ?? 'ds') === 'user';
                if ($isUserScope && ($raw['follow'] ?? false) === true) {
                    $toSave[$id] = ['follow' => true];
                    continue;
                }
                // Strukturovaná hodnota { mode: light|dark|custom, custom: {...} }.
                // U light/dark může custom nést poslední známou konfiguraci.
                if (!in_array($raw['mode'] ?? null, ['light', 'dark', 'custom'], true)
                    || (isset($raw['custom']) && !is_array($raw['custom']))
                ) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_VALUE', 'message' => 'Invalid theme value'];
                    continue;
                }
                $clean = [
                    'mode'   => $raw['mode'],
                    'custom' => is_array($raw['custom'] ?? null) ? $raw['custom'] : null,
                ];
                // Override z user-scope si nese explicitní follow:false, aby se
                // odlišil od „sleduj DS"; ds-scope follow nezná.
                $toSave[$id] = $isUserScope ? ['follow' => false] + $clean : $clean;
            } elseif ($type === 'shell') {
                if (!is_array($raw)) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_VALUE', 'message' => 'Invalid shell value'];
                    continue;
                }
                // Stejný follow kontrakt jako theme: user-scope {follow:true}
                // = sleduj DS default, jinak {follow:false, shell, params};
                // DS-scope follow nezná — případný flag zahodíme.
                $isUserScope = ($pageDef['scope'] ?? 'ds') === 'user';
                if ($isUserScope && ($raw['follow'] ?? false) === true) {
                    $toSave[$id] = ['follow' => true];
                    continue;
                }
                // `shell` proti allowlistu, `params` volitelný objekt
                // (v1 prázdný — passthrough pro budoucí volby shellu).
                if (!in_array($raw['shell'] ?? null, self::SHELLS, true)
                    || (isset($raw['params']) && !is_array($raw['params']))
                ) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_VALUE', 'message' => 'Invalid shell value'];
                    continue;
                }
                $clean = [
                    'shell'  => $raw['shell'],
                    'params' => is_array($raw['params'] ?? null) ? $raw['params'] : [],
                ];
                $toSave[$id] = $isUserScope ? ['follow' => false] + $clean : $clean;
            } elseif ($type === 'language') {
                if (!in_array($raw, ['cs', 'en', 'auto'], true)) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_VALUE', 'message' => 'Invalid language value'];
                    continue;
                }
                $toSave[$id] = $raw;
            } elseif ($type === 'select') {
                // Hodnota z nabídky definice; prázdná = smazat klíč (čtenáři
                // padnou na svůj výchozí stav).
                $allowed = array_map(static fn(array $o): string => (string) $o['value'], $field['options'] ?? []);
                $value = $raw === null ? '' : (is_scalar($raw) ? (string) $raw : null);
                if ($value === null || ($value !== '' && !in_array($value, $allowed, true))) {
                    $errors[] = ['field' => $id, 'code' => 'INVALID_VALUE', 'message' => 'Value is not one of the options'];
                    continue;
                }
                $toSave[$id] = $value === '' ? null : $value;
            }
            // image / avatar — ignorováno (vlastní upload endpoint).
        }

        if ($errors !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
        }

        $store = $this->storeForPage($pageDef, $db, $auth);
        foreach ($toSave as $key => $value) {
            $store->set($key, $value);
        }

        return Response::success([
            'values' => $this->buildPageValues($pageDef, $store),
        ]);
    }

    /** Najde definici stránky napříč resolved moduly (první výskyt vyhrává). */
    private function findPage(string $pageId, DataSourceConfig $config, ModulePathResolver $resolver): ?array
    {
        $allModules      = ModuleLoader::loadAllModules($resolver);
        $errors          = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $config->getModules(), $errors);

        foreach ($resolvedModules as $module) {
            foreach ($module->settingsPages as $page) {
                if (($page['id'] ?? '') === $pageId) {
                    return $page;
                }
            }
        }

        return null;
    }

    private function localizePageDefinition(array $page, string $language): array
    {
        $fields = [];
        foreach ($page['fields'] as $field) {
            $localized = [
                'id'    => $field['id'],
                'type'  => $field['type'],
                'label' => $this->localizeViewerName($field, $language),
            ];
            $hint = $field['hint:' . $language] ?? $field['hint:en'] ?? $field['hint'] ?? null;
            if ($hint !== null) {
                $localized['hint'] = $hint;
            }
            if (isset($field['maxLength'])) {
                $localized['maxLength'] = (int) $field['maxLength'];
            }
            if (isset($field['slot'])) {
                $localized['slot'] = $field['slot'];
            }
            if ($field['type'] === 'select') {
                $localized['options'] = array_map(
                    fn(array $o): array => [
                        'value' => (string) $o['value'],
                        'label' => (string) ($o['label:' . $language] ?? $o['label:en'] ?? $o['label'] ?? $o['value']),
                    ],
                    $field['options'] ?? [],
                );
            }
            $fields[] = $localized;
        }

        return [
            'id'     => $page['id'],
            'label'  => $this->localizeViewerName($page, $language),
            'icon'   => $page['icon'] ?? null,
            // scope (ds|user) řídí na klientovi render theme pole: user →
            // živý ThemeField vázaný na themeStore (+ follow přepínač), ds →
            // DsThemeField ukládaný přes Uložit do app.theme.
            'scope'  => $page['scope'] ?? 'ds',
            'fields' => $fields,
        ];
    }

    /**
     * Hodnoty polí stránky. Textová pole čtou klíč přímo, image pole vrací
     * stav slotu — metadata uploadu + relativní URL pro <img>.
     */
    private function buildPageValues(array $page, KeyValueStore $store): array
    {
        $keys = array_map(static fn(array $f): string => $f['id'], $page['fields']);
        $raw  = $store->getMany($keys);

        $values = [];
        foreach ($page['fields'] as $field) {
            $id = $field['id'];
            if ($field['type'] === 'image') {
                $metadata = $raw[$id];
                $slot     = $field['slot'] ?? null;
                $info     = is_string($slot) ? AppController::slotInfo($slot, $metadata) : null;
                $values[$id] = $info === null ? null : [
                    'url'      => $info['url'],
                    'hash'     => $info['hash'],
                    'filename' => $metadata['filename'] ?? null,
                    'mime'     => $metadata['mime'] ?? null,
                    'size'     => $metadata['size'] ?? null,
                ];
            } elseif ($field['type'] === 'avatar') {
                // Avatar nem\u00e1 slot v URL \u2014 info nese URL /_app/avatar?h={hash}.
                $metadata = $raw[$id];
                $info     = AppController::avatarInfo($metadata);
                $values[$id] = $info === null ? null : [
                    'url'      => $info['url'],
                    'hash'     => $info['hash'],
                    'filename' => $metadata['filename'] ?? null,
                    'mime'     => $metadata['mime'] ?? null,
                ];
            } else {
                $values[$id] = $raw[$id];
            }
        }

        return $values;
    }

    /**
     * @param  ModuleDefinition[]  $resolvedModules
     * @return array<string, array>
     */
    /** Cílová tabulka viewer-položky (z registrace vieweru v modulu), jinak null. */
    private function viewerTargetTable(ModuleDefinition $module, array $item): ?string
    {
        if (($item['viewer'] ?? null) === null) {
            return null;
        }
        foreach ($module->viewers as $v) {
            if (($v['id'] ?? '') === $item['viewer']) {
                return $v['table'] ?? null;
            }
        }
        return null;
    }

    /**
     * Vyhodnotí NavItemVisibilityGate položky s memoizací per třída.
     * Fail-open: chybějící/špatná třída i výjimka z gate = zobrazit (log).
     *
     * @param array<string, bool> $gateResults
     */
    private function gateAllowsItem(string $gateClass, DataSourceConnection $db, array &$gateResults): bool
    {
        if (array_key_exists($gateClass, $gateResults)) {
            return $gateResults[$gateClass];
        }

        $visible = true;
        if (!class_exists($gateClass) || !is_subclass_of($gateClass, NavItemVisibilityGate::class)) {
            ErrorLogger::warn('Nav visibility gate missing or not a NavItemVisibilityGate, showing item', [
                'class' => $gateClass,
            ]);
        } else {
            try {
                /** @var NavItemVisibilityGate $gate */
                $gate = new $gateClass();
                $visible = $gate->isVisible($db);
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, "Nav visibility gate '{$gateClass}' failed — showing item");
            }
        }

        return $gateResults[$gateClass] = $visible;
    }

    private function collectItems(array $resolvedModules, ModulePathResolver $resolver, string $language, string $kind = 'settings', ?AuthContext $auth = null, array $tables = [], ?DataSourceConnection $db = null): array
    {
        $itemsBySection = [];
        $seenViewers = [];
        $seenTables = [];
        $seenPages = [];
        $seenPanels = [];
        // Memo výsledků visibility gate — jedna instance / jeden dotaz per
        // třída per request (VatAgendaNavGate sdílí obě položky agendy DPH).
        $gateResults = [];
        // Systémové (core_system_*) a adminOnly tabulky jsou pro ne-adminy
        // zavřené na CRUD/viewer/form vrstvě (TableAccessGuard) — mrtvé
        // odkazy do stromu nedáváme.
        $isAdmin = $auth?->isAdmin ?? false;

        foreach ($resolvedModules as $module) {
            $moduleItems = $kind === 'account' ? $module->accountItems : $module->settingsItems;
            foreach ($moduleItems as $item) {
                $section    = $item['section'];
                $subsection = $item['subsection'] ?? null;

                // Runtime visibility gate (NavItemVisibilityGate) — fail-open:
                // bez DB (degradovaný kontext) se položka zobrazí.
                $gateClass = $item['visibilityClass'] ?? null;
                if ($gateClass !== null && $db !== null
                    && !$this->gateAllowsItem($gateClass, $db, $gateResults)
                ) {
                    continue;
                }

                $itemTable = $item['table'] ?? $this->viewerTargetTable($module, $item);
                if (!$isAdmin && $itemTable !== null
                    && (str_starts_with($itemTable, TableAccessGuard::SYSTEM_TABLE_PREFIX)
                        || ($tables[$itemTable] ?? null)?->adminOnly === true)) {
                    continue;
                }

                if ($item['viewer'] !== null) {
                    $viewerId = $item['viewer'];
                    if (isset($seenViewers[$viewerId])) {
                        continue;
                    }

                    $viewerDef = null;
                    foreach ($module->viewers as $v) {
                        if (($v['id'] ?? '') === $viewerId) {
                            $viewerDef = $v;
                            break;
                        }
                    }

                    if ($viewerDef === null) {
                        ErrorLogger::warn('Viewer not found in module, skipping', [
                            'viewer_id' => $viewerId,
                            'module_id' => $module->id,
                        ]);
                        continue;
                    }

                    // Respect hideFromNavigation on the viewer's target table —
                    // a designer marking a table as hidden should not have to
                    // separately remove it from settingsItems[]. Mismatch is a
                    // configuration error worth logging.
                    $targetTable = $viewerDef['table'] ?? null;
                    if ($targetTable !== null) {
                        $modulePath = $resolver->getPath($module->id);
                        if ($modulePath !== null && $this->isTableHiddenFromNavigation($modulePath, $targetTable)) {
                            ErrorLogger::warn('Viewer targets hidden table, skipping', [
                                'viewer_id'  => $viewerId,
                                'table_name' => $targetTable,
                                'module_id'  => $module->id,
                            ]);
                            continue;
                        }
                    }

                    $seenViewers[$viewerId] = true;
                    $label = $this->localizeViewerName($viewerDef, $language);

                    $navItem = [
                        'id'       => 'viewer:' . $viewerId,
                        'label'    => $label,
                        'type'     => 'viewer',
                        'viewerId' => $viewerId,
                    ];
                    if (isset($viewerDef['icon'])) {
                        $navItem['icon'] = $viewerDef['icon'];
                    }

                    if ($item['order'] !== null) {
                        $navItem['_order'] = $item['order'];
                    }

                    $itemsBySection[$this->sectionKey($section, $subsection)][] = $navItem;
                } elseif ($item['table'] !== null) {
                    $tableName = $item['table'];
                    if (isset($seenTables[$tableName])) {
                        continue;
                    }
                    if (!in_array($tableName, $module->tables, true)) {
                        ErrorLogger::warn('Table not found in module, skipping', [
                            'table_name' => $tableName,
                            'module_id'  => $module->id,
                        ]);
                        continue;
                    }

                    $modulePath = $resolver->getPath($module->id);
                    if ($modulePath === null) {
                        continue;
                    }

                    if ($this->isTableHiddenFromNavigation($modulePath, $tableName)) {
                        ErrorLogger::warn('Table is marked hideFromNavigation, skipping', [
                            'table_name' => $tableName,
                            'module_id'  => $module->id,
                        ]);
                        continue;
                    }

                    $seenTables[$tableName] = true;
                    $tableData  = $this->loadTableMeta($modulePath, $tableName, $language);

                    $navItem = [
                        'id'    => $tableName,
                        'label' => $tableData['name'] ?? $tableName,
                        'type'  => 'table',
                        'table' => $tableName,
                    ];
                    if (isset($tableData['icon'])) {
                        $navItem['icon'] = $tableData['icon'];
                    }
                    if ($item['order'] !== null) {
                        $navItem['_order'] = $item['order'];
                    }

                    $itemsBySection[$this->sectionKey($section, $subsection)][] = $navItem;
                } elseif (($item['page'] ?? null) !== null) {
                    $pageId = $item['page'];
                    if (isset($seenPages[$pageId])) {
                        continue;
                    }

                    $pageDef = null;
                    foreach ($module->settingsPages as $p) {
                        if (($p['id'] ?? '') === $pageId) {
                            $pageDef = $p;
                            break;
                        }
                    }

                    if ($pageDef === null) {
                        ErrorLogger::warn('Settings page not found in module, skipping', [
                            'page_id'   => $pageId,
                            'module_id' => $module->id,
                        ]);
                        continue;
                    }

                    // adminOnly stránka je pro ne-adminy zavřená v page/
                    // savePage — mrtvý odkaz do stromu nedáváme.
                    if (($pageDef['adminOnly'] ?? false) && !$isAdmin) {
                        continue;
                    }

                    $seenPages[$pageId] = true;

                    $navItem = [
                        'id'     => 'page:' . $pageId,
                        'label'  => $this->localizeViewerName($pageDef, $language),
                        'type'   => 'page',
                        'pageId' => $pageId,
                    ];
                    if (isset($pageDef['icon'])) {
                        $navItem['icon'] = $pageDef['icon'];
                    }
                    if ($item['order'] !== null) {
                        $navItem['_order'] = $item['order'];
                    }

                    $itemsBySection[$this->sectionKey($section, $subsection)][] = $navItem;
                } elseif (($item['panel'] ?? null) !== null) {
                    // Panel = klientská komponenta (mapa panelId → komponenta
                    // v ContentArea.svelte); server dodává jen id + label.
                    $panelId = $item['panel'];
                    if (isset($seenPanels[$panelId])) {
                        continue;
                    }

                    $panelDef = null;
                    foreach ($module->panels as $p) {
                        if (($p['id'] ?? '') === $panelId) {
                            $panelDef = $p;
                            break;
                        }
                    }

                    if ($panelDef === null) {
                        ErrorLogger::warn('Panel not found in module, skipping', [
                            'panel_id'  => $panelId,
                            'module_id' => $module->id,
                        ]);
                        continue;
                    }

                    $seenPanels[$panelId] = true;

                    $navItem = [
                        'id'      => 'panel:' . $panelId,
                        'label'   => $this->localizeViewerName($panelDef, $language),
                        'type'    => 'panel',
                        'panelId' => $panelId,
                    ];
                    if (isset($panelDef['icon'])) {
                        $navItem['icon'] = $panelDef['icon'];
                    }
                    if ($item['order'] !== null) {
                        $navItem['_order'] = $item['order'];
                    }

                    $itemsBySection[$this->sectionKey($section, $subsection)][] = $navItem;
                }
            }
        }

        foreach ($itemsBySection as $section => $items) {
            $hasOrder = array_any($items, fn($i) => isset($i['_order']));
            if ($hasOrder) {
                usort($itemsBySection[$section], fn($a, $b) => ($a['_order'] ?? PHP_INT_MAX) <=> ($b['_order'] ?? PHP_INT_MAX));
            }
            $itemsBySection[$section] = array_map(static function (array $i): array {
                unset($i['_order']);
                return $i;
            }, $itemsBySection[$section]);
        }

        return $itemsBySection;
    }

    /**
     * Flat key for $itemsBySection: bare section id for items belonging
     * directly to a section, or "section\0subsection" (NUL separator — can't
     * occur in an id) for items inside a subsection. navigation() parses
     * these back when assembling the two-level tree.
     */
    private function sectionKey(string $section, ?string $subsection): string
    {
        return $subsection === null ? $section : $section . "\0" . $subsection;
    }

    private function localizeViewerName(array $viewer, string $language): string
    {
        return $viewer['name:' . $language] ?? $viewer['name:en'] ?? $viewer['name'] ?? $viewer['id'];
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
}
