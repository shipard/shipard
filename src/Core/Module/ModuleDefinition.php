<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

class ModuleDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly array $dependencies,
        public readonly array $tables,
        public readonly array $extensions,
        public readonly array $config,
        public readonly array $documentClasses,
        public readonly array $viewers,
        public readonly array $forms,
        public readonly array $settingsItems,
        public readonly array $settingsPages = [],
        public readonly array $lookups = [],
        public readonly array $alertChecks = [],
        public readonly array $keepOnReset = [],
        public readonly array $documentEventHandlers = [],
        public readonly array $accountItems = [],
        public readonly array $journalEventHandlers = [],
        public readonly array $panels = [],
        public readonly array $navigationProviders = [],
        public readonly array $reports = [],
        public readonly array $attachmentGuards = [],
        public readonly array $documentLockProviders = [],
        public readonly ?string $openItemLookup = null,
    ) {}

    public static function fromArray(array $data): self
    {
        if (!isset($data['id']) || !is_string($data['id']) || $data['id'] === '') {
            throw new \InvalidArgumentException('Module definition missing required field: id');
        }

        if (!isset($data['name']) || !is_string($data['name']) || $data['name'] === '') {
            throw new \InvalidArgumentException('Module definition missing required field: name');
        }

        // Module id format: <group>.<module>
        //   <group>  — lowercase alphanumeric (group directory name)
        //   <module> — alphanumeric, must start lowercase. camelCase is allowed
        //              for multi-word module names like `docs.invoicesOut`.
        if (!preg_match('/^[a-z][a-z0-9]*\.[a-z][a-zA-Z0-9]*$/', $data['id'])) {
            throw new \InvalidArgumentException("Invalid module id format: '{$data['id']}'");
        }

        // settingsItems (Nastavení aplikace) i accountItems (Nastavení účtu)
        // sdílí stejný tvar položky (viewer|table|page + section/order) —
        // parser je společný, liší se jen zdrojový klíč v module.jsonc.
        $settingsItems = self::parseNavItems($data, 'settingsItems');
        $accountItems  = self::parseNavItems($data, 'accountItems');

        // settingsPages — server-driven stránky vlastností v Nastavení.
        // `scope` (ds|user) určuje, kam jdou hodnoty: ds → core_system_settings
        // (field id = klíč), user → core_system_user_settings scoped na usera.
        $settingsPages = [];
        if (isset($data['settingsPages']) && is_array($data['settingsPages'])) {
            foreach ($data['settingsPages'] as $page) {
                if (!is_array($page)) continue;
                if (!isset($page['id']) || !is_string($page['id']) || $page['id'] === '') continue;
                if (!isset($page['fields']) || !is_array($page['fields'])) continue;

                $fields = [];
                foreach ($page['fields'] as $field) {
                    if (!is_array($field)) continue;
                    if (!isset($field['id']) || !is_string($field['id']) || $field['id'] === '') continue;
                    $type = $field['type'] ?? 'text';
                    if (!in_array($type, ['text', 'image', 'theme', 'language', 'avatar', 'shell'], true)) continue;
                    $field['type'] = $type;
                    $fields[]      = $field;
                }

                $page['scope']   = (isset($page['scope']) && $page['scope'] === 'user') ? 'user' : 'ds';
                // adminOnly — stránku vidí a ukládá jen admin (403 pro
                // ostatní, skrytí z navigace). Pro DS s ne-admin uživateli
                // (hosting portál) u citlivých klíčů typu hosting.oidc.issuer.
                $page['adminOnly'] = (bool) ($page['adminOnly'] ?? false);
                $page['fields']  = $fields;
                $settingsPages[] = $page;
            }
        }

        // panels — klientské komponenty v navigaci Nastavení/Účtu (protějšek
        // settingsPages pro obsah, který nejde poskládat z generických
        // fieldů — např. změna hesla + relace). Server dodává jen id + label,
        // vykreslení řeší frontend mapou panelId → komponenta.
        $panels = [];
        if (isset($data['panels']) && is_array($data['panels'])) {
            foreach ($data['panels'] as $panel) {
                if (!is_array($panel)) continue;
                if (!isset($panel['id']) || !is_string($panel['id']) || $panel['id'] === '') continue;
                if (!isset($panel['name']) || !is_string($panel['name']) || $panel['name'] === '') continue;
                $panels[] = $panel;
            }
        }

        $lookups = [];
        if (isset($data['lookups']) && is_array($data['lookups'])) {
            foreach ($data['lookups'] as $lookup) {
                if (!is_array($lookup)) continue;
                if (!isset($lookup['table']) || !is_string($lookup['table']) || $lookup['table'] === '') continue;
                if (!isset($lookup['class']) || !is_string($lookup['class']) || $lookup['class'] === '') continue;
                $lookups[] = [
                    'table' => $lookup['table'],
                    'class' => $lookup['class'],
                ];
            }
        }

        // alertChecks — raw array passthrough. Plný parser (s lokalizací
        // a duplicit detection napříč moduly) je v AlertCheckRegistry.
        // Tady jen validujeme strukturu a duplicity uvnitř téhož modulu.
        $alertChecks = [];
        if (isset($data['alertChecks']) && is_array($data['alertChecks'])) {
            $seenIds = [];
            foreach ($data['alertChecks'] as $idx => $check) {
                if (!is_array($check)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': alertChecks[{$idx}] must be an object",
                    );
                }
                $checkId = $check['id'] ?? null;
                if (!is_string($checkId) || $checkId === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': alertChecks[{$idx}] missing 'id'",
                    );
                }
                if (isset($seenIds[$checkId])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': duplicate alertChecks id '{$checkId}'",
                    );
                }
                $seenIds[$checkId] = true;
                $alertChecks[] = $check;
            }
        }

        // documentEventHandlers — hooky na události dokumentů cizích tabulek
        // (beforeSave v transakci před zápisem, afterSave + stateChanged po
        // commitu, beforeDelete v transakci). Dispatch dělá TableGateway přes
        // DocumentEventDispatcher.
        $documentEventHandlers = [];
        if (isset($data['documentEventHandlers']) && is_array($data['documentEventHandlers'])) {
            $knownEvents = ['beforeSave', 'afterSave', 'stateChanged', 'beforeDelete'];
            foreach ($data['documentEventHandlers'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['table']) || !is_string($reg['table']) || $reg['table'] === ''
                    || !isset($reg['class']) || !is_string($reg['class']) || $reg['class'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': documentEventHandlers[{$idx}] requires 'table' and 'class'",
                    );
                }
                $events = $reg['events'] ?? $knownEvents;
                if (!is_array($events) || $events === []
                    || array_diff($events, $knownEvents) !== []
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': documentEventHandlers[{$idx}] has invalid 'events' "
                        . '(allowed: ' . implode(', ', $knownEvents) . ')',
                    );
                }
                $documentEventHandlers[] = [
                    'table'  => $reg['table'],
                    'class'  => $reg['class'],
                    'events' => array_values($events),
                ];
            }
        }

        // attachmentGuards — ochrana příloh záznamu před změnou (#55 X16).
        // Tvar {table, class}; třída implementuje AttachmentGuard a ptá se
        // jí AttachmentService u mazání, přejmenování a řazení.
        $attachmentGuards = [];
        if (isset($data['attachmentGuards']) && is_array($data['attachmentGuards'])) {
            foreach ($data['attachmentGuards'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['table']) || !is_string($reg['table']) || $reg['table'] === ''
                    || !isset($reg['class']) || !is_string($reg['class']) || $reg['class'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': attachmentGuards[{$idx}] requires 'table' and 'class'",
                    );
                }
                $attachmentGuards[] = ['table' => $reg['table'], 'class' => $reg['class']];
            }
        }

        // documentLockProviders — zámek záznamu cizí tabulky (#55 D24).
        // Tvar {table, class}; třída implementuje DocumentLockProvider,
        // registrace nese DocumentRegistry, ptá se TableGateway (save/delete),
        // generické CRUD, nabídka přechodů a UI meta.
        $documentLockProviders = [];
        if (isset($data['documentLockProviders']) && is_array($data['documentLockProviders'])) {
            foreach ($data['documentLockProviders'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['table']) || !is_string($reg['table']) || $reg['table'] === ''
                    || !isset($reg['class']) || !is_string($reg['class']) || $reg['class'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': documentLockProviders[{$idx}] requires 'table' and 'class'",
                    );
                }
                $documentLockProviders[] = ['table' => $reg['table'], 'class' => $reg['class']];
            }
        }

        // journalEventHandlers — hooky na zápis účetního deníku (journalWritten
        // po commitu (pře)zápisu/vymazání). Mirror documentEventHandlers, ale
        // registrace je {class, events} bez `table` (události nejsou per-tabulka);
        // dispatch dělá účtovací engine přes JournalEventDispatcher.
        $journalEventHandlers = [];
        if (isset($data['journalEventHandlers']) && is_array($data['journalEventHandlers'])) {
            $knownEvents = ['journalWritten'];
            foreach ($data['journalEventHandlers'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['class']) || !is_string($reg['class']) || $reg['class'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalEventHandlers[{$idx}] requires 'class'",
                    );
                }
                $events = $reg['events'] ?? $knownEvents;
                if (!is_array($events) || $events === []
                    || array_diff($events, $knownEvents) !== []
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalEventHandlers[{$idx}] has invalid 'events' "
                        . '(allowed: ' . implode(', ', $knownEvents) . ')',
                    );
                }
                $journalEventHandlers[] = [
                    'class'  => $reg['class'],
                    'events' => array_values($events),
                ];
            }
        }

        // openItemLookup — poskytovatel dohledání otevřeného předpisu
        // saldokonta pro bankovní účtovací engine (rozhraní
        // Shipard\Core\Accounting\OpenItemLookup, #69 D3/D8). Jeden per DS,
        // proto holý FQCN, ne seznam; instanciaci a unikátnost napříč moduly
        // hlídá OpenItemLookupLoader.
        $openItemLookup = null;
        if (array_key_exists('openItemLookup', $data)) {
            if (!is_string($data['openItemLookup']) || $data['openItemLookup'] === '') {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': openItemLookup must be a non-empty class name",
                );
            }
            $openItemLookup = $data['openItemLookup'];
        }

        // navigationProviders — třídy dodávající dynamické položky hlavní
        // navigace z dat (NavigationItemsProvider). Registrace je jen {class};
        // instancování a merge dělá NavigationController.
        $navigationProviders = [];
        if (isset($data['navigationProviders']) && is_array($data['navigationProviders'])) {
            foreach ($data['navigationProviders'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['class']) || !is_string($reg['class']) || $reg['class'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': navigationProviders[{$idx}] requires 'class'",
                    );
                }
                $navigationProviders[] = ['class' => $reg['class']];
            }
        }

        // reports — deklarace reportů (datových výstupů) v samostatných JSONC
        // souborech, mirror file-based `config` klíče. Jeden soubor může
        // deklarovat víc reportů; parsování a duplicit detection napříč
        // moduly dělá ReportDefinitionLoader/ReportRegistry.
        $reports = [];
        if (isset($data['reports']) && is_array($data['reports'])) {
            foreach ($data['reports'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['file']) || !is_string($reg['file']) || $reg['file'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': reports[{$idx}] requires 'file'",
                    );
                }
                $reports[] = ['file' => $reg['file']];
            }
        }

        // keepOnReset — names of this module's OWN tables that `ds-reset`
        // must not drop (system/config tables vs. data). Items must be
        // strings and must be tables owned by this module (catches typos
        // and forbids "protecting" a foreign table).
        $keepOnReset = [];
        if (isset($data['keepOnReset'])) {
            if (!is_array($data['keepOnReset']) || !array_is_list($data['keepOnReset'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': keepOnReset must be a JSON array of table names",
                );
            }
            $ownTables = $data['tables'] ?? [];
            foreach ($data['keepOnReset'] as $i => $t) {
                if (!is_string($t) || $t === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': keepOnReset[{$i}] must be a non-empty string",
                    );
                }
                if (!in_array($t, $ownTables, true)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': keepOnReset[{$i}] '{$t}' is not a table owned by this module",
                    );
                }
                $keepOnReset[] = $t;
            }
        }

        return new self(
            id: $data['id'],
            name: $data['name'],
            description: isset($data['description']) && is_string($data['description']) ? $data['description'] : '',
            dependencies: $data['dependencies'] ?? [],
            tables: $data['tables'] ?? [],
            extensions: $data['extensions'] ?? [],
            config: $data['config'] ?? [],
            documentClasses: $data['documentClasses'] ?? [],
            viewers: $data['viewers'] ?? [],
            forms: $data['forms'] ?? [],
            settingsItems: $settingsItems,
            settingsPages: $settingsPages,
            lookups: $lookups,
            alertChecks: $alertChecks,
            keepOnReset: $keepOnReset,
            documentEventHandlers: $documentEventHandlers,
            accountItems: $accountItems,
            journalEventHandlers: $journalEventHandlers,
            panels: $panels,
            navigationProviders: $navigationProviders,
            reports: $reports,
            attachmentGuards: $attachmentGuards,
            documentLockProviders: $documentLockProviders,
            openItemLookup: $openItemLookup,
        );
    }

    /**
     * Parser navigačních položek pro Nastavení (settingsItems) i Nastavení
     * účtu (accountItems) — sdílený tvar: právě jedno z viewer|table|page|
     * panel, povinná `section`, volitelné `subsection`/`order`. Panel je
     * klientská komponenta (registrace v `panels[]`), ne server-driven
     * stránka — používá ho Nastavení účtu pro Zabezpečení.
     *
     * @param  array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private static function parseNavItems(array $data, string $key): array
    {
        $items = [];
        if (isset($data[$key]) && is_array($data[$key])) {
            foreach ($data[$key] as $item) {
                if (!is_array($item)) continue;
                if (!isset($item['section'])) continue;
                // Právě jedno z viewer|table|page|panel.
                $targets = count(array_filter([
                    isset($item['viewer']),
                    isset($item['table']),
                    isset($item['page']),
                    isset($item['panel']),
                ]));
                if ($targets !== 1) continue;
                $items[] = [
                    'viewer'     => $item['viewer'] ?? null,
                    'table'      => $item['table']  ?? null,
                    'page'       => $item['page']   ?? null,
                    'panel'      => $item['panel']  ?? null,
                    'section'    => (string) $item['section'],
                    'subsection' => isset($item['subsection']) ? (string) $item['subsection'] : null,
                    'order'      => isset($item['order']) ? (int) $item['order'] : null,
                    // Runtime gate viditelnosti (NavItemVisibilityGate) —
                    // vyhodnocuje SettingsController, fail-open.
                    'visibilityClass' => isset($item['visibilityClass']) ? (string) $item['visibilityClass'] : null,
                ];
            }
        }
        return $items;
    }
}
