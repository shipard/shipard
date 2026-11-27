<?php

declare(strict_types=1);

namespace Shipard\Core\Module;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Server\CronProvisioner;

class ModuleDefinition
{
    /** Úrovně modelu AI funkce (`aiFunctions[].tier`, #85 D36) — mapu na modely nese cfgItem `core.ai.tiers`. */
    public const array AI_FUNCTION_TIERS = ['fast', 'balanced', 'strong'];

    /** Požadavky AI funkce na model (`aiFunctions[].requires`): tool smyčka, PDF přílohy. */
    public const array AI_FUNCTION_REQUIRES = ['tools', 'pdf'];

    /** Výchozí pořadí úlohy cronu ve slotu (`cronJobs[].order`, #172 D6). */
    public const int CRON_JOB_DEFAULT_ORDER = 500;

    /** Výchozí pořadí rozšíření UI (`detailExtensions` / `formTabs` / `rowDecorators` → `order`, #172 D19). */
    public const int UI_EXTENSION_DEFAULT_ORDER = 500;

    /** Výchozí pořadí provisioneru modulu (`provisioners[].order`, #174 D28). */
    public const int PROVISIONER_DEFAULT_ORDER = 500;

    /** Cesta sekce `server.json` (`serverConfig[].key`, #172 D10): segmenty `[a-z][a-zA-Z0-9]*` oddělené tečkou. */
    public const string SERVER_CONFIG_KEY_PATTERN = '/^[a-z][a-zA-Z0-9]*(\.[a-z][a-zA-Z0-9]*)*$/';

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
        /** @var list<array{key: string, table: string}> nastavení s id řádku tabulky, které `ds-reset` smaže spolu s ní */
        public readonly array $clearSettingsOnReset = [],
        public readonly array $documentEventHandlers = [],
        public readonly array $accountItems = [],
        public readonly array $journalEventHandlers = [],
        public readonly array $panels = [],
        public readonly array $navigationProviders = [],
        public readonly array $reports = [],
        public readonly array $attachmentGuards = [],
        public readonly array $documentLockProviders = [],
        public readonly ?string $openItemLookup = null,
        public readonly array $journalContributors = [],
        public readonly array $journalDimensions = [],
        public readonly array $prints = [],
        public readonly array $sendPurposes = [],
        public readonly array $recordSenderProviders = [],
        public readonly array $periodicInvoicingContributors = [],
        public readonly array $periodicInvoicingSources = [],
        public readonly array $aiFunctions = [],
        public readonly array $overviewBlocks = [],
        /** Druh modulu (#170 D4): instalační podle skupiny, doplněk podle pole `kind`, jinak jádro. */
        public readonly ModuleKind $kind = ModuleKind::Core,
        /** @var list<array<string, mixed>> vlastní sekce hlavního sidebaru (#170 D13) */
        public readonly array $navSections = [],
        /** @var list<array<string, mixed>> vlastní sekce Nastavení, volitelně s podsekcemi (#170 D13) */
        public readonly array $settingsSections = [],
        /** @var list<class-string> příkazy `shpd-ds` modulu — FQCN tříd (#172 D2) */
        public readonly array $commands = [],
        /** @var list<class-string> příkazy `shpd-server` modulu — FQCN tříd (#172 D2) */
        public readonly array $serverCommands = [],
        /** @var list<array{command: string, slot: string, order: int, states: list<string>}> úlohy cronu modulu (#172 D6) */
        public readonly array $cronJobs = [],
        /** @var list<array{key: string, class: class-string}> sekce `server.json` modulu (#172 D7, D10) */
        public readonly array $serverConfig = [],
        /** @var list<array{table: string, class: class-string, order: int}> taby a štítky detailu cizí tabulky (#172 D16) */
        public readonly array $detailExtensions = [],
        /** @var list<array{table: string, class: class-string, order: int}> záložky formuláře cizí tabulky (#172 D17) */
        public readonly array $formTabs = [],
        /** @var list<array{table: string, class: class-string, order: int}> štítky řádku seznamu cizí tabulky (#172 D18) */
        public readonly array $rowDecorators = [],
        /** @var list<array{id: string, table: string, class: class-string, admin: bool, order: int}> akce záznamu s obsluhou v modulu (#172 D12) */
        public readonly array $recordActions = [],
        /** @var list<array{class: class-string, order: int}> provisionery výchozích dat modulu (#174 D28) */
        public readonly array $provisioners = [],
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

        // kind — druh modulu (#170 D4). Jediná povolená hodnota je "addon";
        // bez pole je modul jádro. Instalační modul (skupina `install`) má
        // druh daný skupinou a pole mít nesmí.
        $isInstall = str_starts_with($data['id'], 'install.');
        $kind = $isInstall ? ModuleKind::Install : ModuleKind::Core;
        if (array_key_exists('kind', $data)) {
            if ($data['kind'] !== ModuleKind::Addon->value) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': kind must be \"addon\" (omit the field for a core module)",
                );
            }
            if ($isInstall) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': an install module must not declare kind",
                );
            }
            $kind = ModuleKind::Addon;
        }

        // navSections / settingsSections — vlastní sekce modulu v hlavním
        // sidebaru a v Nastavení (#170 D13). ConfigCompiler je připojí
        // k sekcím instalačního modulu (`global.navSections`,
        // `global.settingsSections`); položky do nich modul dává jako do
        // každé jiné sekce (`navSection` vieweru, `section` u settingsItems).
        $navSections = self::parseSections($data, 'navSections', false);
        $settingsSections = self::parseSections($data, 'settingsSections', true);

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
                    if (!in_array($type, ['text', 'image', 'theme', 'language', 'avatar', 'shell', 'select', 'color', 'checkbox', 'section'], true)) continue;
                    // section — mezinadpis s úvodním textem (`name`, `hint`),
                    // ne pole: nemá hodnotu ani klíč nastavení, takže
                    // `default` a nabídka u něj nic neznamenají (#162 D138).
                    if ($type === 'section') {
                        unset($field['default'], $field['options'], $field['optionsProvider']);
                    }
                    // select — pevná nabídka {value, label(:lang)}, nebo
                    // dynamická z `optionsProvider` (FQCN třídy implementující
                    // SettingsOptionsProvider — číselník z dat zdroje). Bez
                    // jedné z nich by pole nešlo uložit (hodnota mimo
                    // nabídku = chyba).
                    if ($type === 'select') {
                        $options = [];
                        foreach ($field['options'] ?? [] as $option) {
                            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                                $options[] = $option;
                            }
                        }
                        $provider = $field['optionsProvider'] ?? null;
                        $hasProvider = is_string($provider) && $provider !== '';
                        if ($options === [] && !$hasProvider) continue;
                        $field['options'] = $options;
                        if ($hasProvider) {
                            $field['optionsProvider'] = $provider;
                        } else {
                            unset($field['optionsProvider']);
                        }
                    }
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

        // recordSenderProviders — odesílatel podle záznamu (#90 D39).
        // Tvar {table, class}; třída implementuje RecordSenderProvider
        // a ptá se jí SenderResolver (doklady: číselná řada).
        $recordSenderProviders = [];
        if (isset($data['recordSenderProviders']) && is_array($data['recordSenderProviders'])) {
            foreach ($data['recordSenderProviders'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['table']) || !is_string($reg['table']) || $reg['table'] === ''
                    || !isset($reg['class']) || !is_string($reg['class']) || $reg['class'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': recordSenderProviders[{$idx}] requires 'table' and 'class'",
                    );
                }
                $recordSenderProviders[] = ['table' => $reg['table'], 'class' => $reg['class']];
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

        // journalContributors — příspěvky modulu do deníku zdroje (rozhraní
        // Shipard\Core\Accounting\JournalContributor, #79 D3b): oba účtovací
        // enginy je volají před zápisem deníku. Seznam FQCN — víc modulů smí
        // přispívat, pořadí = pořadí resolvovaných modulů × pořadí pole;
        // instanciaci hlídá JournalContributorLoader.
        $journalContributors = [];
        if (array_key_exists('journalContributors', $data)) {
            if (!is_array($data['journalContributors']) || !array_is_list($data['journalContributors'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': journalContributors must be a JSON array of class names",
                );
            }
            foreach ($data['journalContributors'] as $idx => $class) {
                if (!is_string($class) || $class === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalContributors[{$idx}] must be a non-empty class name",
                    );
                }
                $journalContributors[] = $class;
            }
        }

        // journalDimensions — analytické dimenze deníku (docs/accounting.md
        // „Dimenze deníku“, assets D47): sloupec řádku dokladu, volitelně
        // výchozí hodnota z hlavičky, sloupec deníku a cílová tabulka.
        // Sloupce zakládá modul dimenze přes extensions; ConfigCompiler
        // dimenze aktivních modulů složí do cfgItem
        // `core.accounting.journalDimensions` (JournalDimensionSet).
        // Volitelné `rowFlag` = vlajka řádkové operace, jejíž řádek nese
        // hodnotu sám a z hlavičky ji nedědí.
        // Volitelné `forms` = na kterých formulářích dokladů se pole dimenze
        // nabízí (typy dokladů, hlavička / řádky, případně jen se zapnutým
        // nastavením `enabledBySetting`).
        // Přirozený klíč pro výměnný formát dokladu (objekt `dimensions`)
        // dimenze nedeklaruje — říká ho `exchangeKey` v definici cílové
        // tabulky (#166 D8); pole `exchangeKey` tady je chyba.
        $journalDimensions = [];
        if (array_key_exists('journalDimensions', $data)) {
            if (!is_array($data['journalDimensions']) || !array_is_list($data['journalDimensions'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': journalDimensions must be a JSON array",
                );
            }
            foreach ($data['journalDimensions'] as $idx => $dim) {
                if (!is_array($dim)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalDimensions[{$idx}] must be an object",
                    );
                }
                foreach (['id', 'rowColumn', 'journalColumn', 'table', 'name'] as $key) {
                    if (!isset($dim[$key]) || !is_string($dim[$key]) || $dim[$key] === '') {
                        throw new \InvalidArgumentException(
                            "Module '{$data['id']}': journalDimensions[{$idx}] requires '{$key}'",
                        );
                    }
                }
                if (array_key_exists('exchangeKey', $dim)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalDimensions[{$idx}].exchangeKey is no longer supported"
                        . " — declare exchangeKey in the definition of table '{$dim['table']}'",
                    );
                }
                foreach (['id', 'rowColumn', 'journalColumn', 'headColumn', 'table', 'rowFlag'] as $key) {
                    if (isset($dim[$key]) && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', (string) $dim[$key])) {
                        throw new \InvalidArgumentException(
                            "Module '{$data['id']}': journalDimensions[{$idx}].{$key} must be an identifier",
                        );
                    }
                }
                if (isset($dim['headColumn']) && !is_string($dim['headColumn'])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalDimensions[{$idx}].headColumn must be a string or null",
                    );
                }
                if (isset($journalDimensions[$dim['id']])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': journalDimensions has duplicate id '{$dim['id']}'",
                    );
                }
                $entry = [
                    'id'            => $dim['id'],
                    'rowColumn'     => $dim['rowColumn'],
                    'headColumn'    => $dim['headColumn'] ?? null,
                    'journalColumn' => $dim['journalColumn'],
                    'table'         => $dim['table'],
                ];
                if (isset($dim['rowFlag'])) {
                    $entry['rowFlag'] = (string) $dim['rowFlag'];
                }
                if (array_key_exists('forms', $dim)) {
                    $entry['forms'] = self::journalDimensionForms($dim['forms'], $entry, "Module '{$data['id']}': journalDimensions[{$idx}].forms");
                }
                // Název vč. jazykových variant — lokalizuje až kompilace.
                foreach ($dim as $key => $value) {
                    if (($key === 'name' || str_starts_with((string) $key, 'name:')) && is_string($value)) {
                        $entry[$key] = $value;
                    }
                }
                $journalDimensions[$dim['id']] = $entry;
            }
            $journalDimensions = array_values($journalDimensions);
        }

        // sendPurposes — účely odesílání (#90 D34): k čemu slouží kontakt
        // osoby a co posílá tisk (`prints[].sendPurpose`). ConfigCompiler
        // účely aktivních modulů složí do cfgItem `base.persons.sendPurposes`,
        // takže modul přidá vlastní účel bez zásahu do base.persons.
        $sendPurposes = [];
        if (array_key_exists('sendPurposes', $data)) {
            if (!is_array($data['sendPurposes']) || !array_is_list($data['sendPurposes'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': sendPurposes must be a JSON array",
                );
            }
            foreach ($data['sendPurposes'] as $idx => $purpose) {
                if (!is_array($purpose)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': sendPurposes[{$idx}] must be an object",
                    );
                }
                $purposeId = $purpose['id'] ?? null;
                if (!is_string($purposeId) || !preg_match('/^[a-z][a-zA-Z0-9]*$/', $purposeId)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': sendPurposes[{$idx}].id must be an identifier",
                    );
                }
                if (!isset($purpose['name']) || !is_string($purpose['name']) || $purpose['name'] === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': sendPurposes[{$idx}] requires 'name'",
                    );
                }
                $order = $purpose['order'] ?? 1000;
                if (!is_int($order)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': sendPurposes[{$idx}].order must be an integer",
                    );
                }
                if (isset($sendPurposes[$purposeId])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': sendPurposes has duplicate id '{$purposeId}'",
                    );
                }
                $entry = ['id' => $purposeId, 'order' => $order];
                // Název vč. jazykových variant — lokalizuje až kompilace.
                foreach ($purpose as $key => $value) {
                    if (($key === 'name' || str_starts_with((string) $key, 'name:')) && is_string($value)) {
                        $entry[$key] = $value;
                    }
                }
                $sendPurposes[$purposeId] = $entry;
            }
            $sendPurposes = array_values($sendPurposes);
        }

        // periodicInvoicingContributors — přispěvatelé obsahu faktury
        // periodické fakturace, společní pro všechny zdroje (#110 D7, D10;
        // #175 D5): {id, class, name[:lang]}. ConfigCompiler je složí do
        // cfgItem economy.periodicInvoicing.contributors (nabídka řádku
        // předpisu, validace id); instance staví
        // InvoiceContributorRegistry::fromConfig.
        $periodicInvoicingContributors = [];
        if (array_key_exists('periodicInvoicingContributors', $data)) {
            if (!is_array($data['periodicInvoicingContributors']) || !array_is_list($data['periodicInvoicingContributors'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': periodicInvoicingContributors must be a JSON array",
                );
            }
            foreach ($data['periodicInvoicingContributors'] as $idx => $contributor) {
                if (!is_array($contributor)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingContributors[{$idx}] must be an object",
                    );
                }
                $contributorId = $contributor['id'] ?? null;
                if (!is_string($contributorId) || !preg_match('/^[a-z][a-zA-Z0-9_.]*$/', $contributorId)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingContributors[{$idx}].id must be an identifier",
                    );
                }
                if (!isset($contributor['class']) || !is_string($contributor['class']) || $contributor['class'] === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingContributors[{$idx}] requires 'class'",
                    );
                }
                if (!isset($contributor['name']) || !is_string($contributor['name']) || $contributor['name'] === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingContributors[{$idx}] requires 'name'",
                    );
                }
                if (isset($periodicInvoicingContributors[$contributorId])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingContributors has duplicate id '{$contributorId}'",
                    );
                }
                $entry = ['id' => $contributorId, 'class' => $contributor['class']];
                foreach ($contributor as $key => $value) {
                    if (($key === 'name' || str_starts_with((string) $key, 'name:')) && is_string($value)) {
                        $entry[$key] = $value;
                    }
                }
                $periodicInvoicingContributors[$contributorId] = $entry;
            }
            $periodicInvoicingContributors = array_values($periodicInvoicingContributors);
        }

        // periodicInvoicingSources — zdroje periodické fakturace (#175 D3):
        // {id, class}. ConfigCompiler je složí do cfgItem
        // economy.periodicInvoicing.sources; instance staví
        // InvoicingSources::fromConfig.
        $periodicInvoicingSources = [];
        if (array_key_exists('periodicInvoicingSources', $data)) {
            if (!is_array($data['periodicInvoicingSources']) || !array_is_list($data['periodicInvoicingSources'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': periodicInvoicingSources must be a JSON array",
                );
            }
            foreach ($data['periodicInvoicingSources'] as $idx => $source) {
                if (!is_array($source)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingSources[{$idx}] must be an object",
                    );
                }
                $sourceId = $source['id'] ?? null;
                if (!is_string($sourceId) || !preg_match('/^[a-z][a-zA-Z0-9]*$/', $sourceId)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingSources[{$idx}].id must be an identifier",
                    );
                }
                if (!isset($source['class']) || !is_string($source['class']) || $source['class'] === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingSources[{$idx}] requires 'class'",
                    );
                }
                if (isset($periodicInvoicingSources[$sourceId])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': periodicInvoicingSources has duplicate id '{$sourceId}'",
                    );
                }
                $periodicInvoicingSources[$sourceId] = ['id' => $sourceId, 'class' => $source['class']];
            }
            $periodicInvoicingSources = array_values($periodicInvoicingSources);
        }

        // overviewBlocks — bloky Přehledu firmy (#120 D2): {id, section, order,
        // class}. ConfigCompiler je složí do cfgItem core.overview.blocks
        // (sekce z core.overview.sections), instance staví
        // OverviewBlockRegistry::fromConfig bezparametrickým konstruktorem.
        $overviewBlocks = [];
        if (array_key_exists('overviewBlocks', $data)) {
            if (!is_array($data['overviewBlocks']) || !array_is_list($data['overviewBlocks'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': overviewBlocks must be a JSON array",
                );
            }
            foreach ($data['overviewBlocks'] as $idx => $block) {
                if (!is_array($block)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': overviewBlocks[{$idx}] must be an object",
                    );
                }
                $blockId = $block['id'] ?? null;
                if (!is_string($blockId) || !preg_match('/^[a-z][a-zA-Z0-9_.]*$/', $blockId)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': overviewBlocks[{$idx}].id must be an identifier",
                    );
                }
                $section = $block['section'] ?? null;
                if (!is_string($section) || !preg_match('/^[a-z][a-zA-Z0-9_]*$/', $section)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': overviewBlocks[{$idx}].section must be an identifier",
                    );
                }
                if (!isset($block['class']) || !is_string($block['class']) || $block['class'] === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': overviewBlocks[{$idx}] requires 'class'",
                    );
                }
                $order = $block['order'] ?? 1000;
                if (!is_int($order)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': overviewBlocks[{$idx}].order must be an integer",
                    );
                }
                if (isset($overviewBlocks[$blockId])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': overviewBlocks has duplicate id '{$blockId}'",
                    );
                }
                $overviewBlocks[$blockId] = [
                    'id' => $blockId, 'section' => $section, 'order' => $order, 'class' => $block['class'],
                ];
            }
            $overviewBlocks = array_values($overviewBlocks);
        }

        // aiFunctions — AI funkce modulu (#85 D36): co modul volá na jazykovém
        // modelu (`mail.extraction`, `exchange.contentTags`, …). Deklarace nese
        // úroveň modelu (`tier`), výchozí ladění (`defaults`) a požadavky na
        // model (`requires`). ConfigCompiler je složí do cfgItem
        // `core.ai.functions`; čte ho AiFunctionRegistry a AiFunctionResolver.
        $aiFunctions = [];
        if (array_key_exists('aiFunctions', $data)) {
            if (!is_array($data['aiFunctions']) || !array_is_list($data['aiFunctions'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': aiFunctions must be a JSON array",
                );
            }
            foreach ($data['aiFunctions'] as $idx => $function) {
                if (!is_array($function)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}] must be an object",
                    );
                }
                $functionId = $function['id'] ?? null;
                if (!is_string($functionId) || !preg_match('/^[a-z][a-zA-Z0-9]*\.[a-z][a-zA-Z0-9]*$/', $functionId)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].id must be '<area>.<name>'",
                    );
                }
                if (!isset($function['name']) || !is_string($function['name']) || $function['name'] === '') {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}] requires 'name'",
                    );
                }
                $tier = $function['tier'] ?? null;
                if (!is_string($tier) || !in_array($tier, self::AI_FUNCTION_TIERS, true)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].tier must be one of "
                        . implode(', ', self::AI_FUNCTION_TIERS),
                    );
                }
                $defaults = $function['defaults'] ?? null;
                if (!is_array($defaults) || array_is_list($defaults)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].defaults must be an object with maxTokens",
                    );
                }
                $unknownDefaults = array_diff(array_keys($defaults), ['maxTokens', 'temperature']);
                if ($unknownDefaults !== []) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].defaults has unknown key '"
                        . implode("', '", array_map('strval', $unknownDefaults)) . "'",
                    );
                }
                $maxTokens = $defaults['maxTokens'] ?? null;
                if (!is_int($maxTokens) || $maxTokens <= 0) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].defaults.maxTokens must be a positive integer",
                    );
                }
                $temperature = $defaults['temperature'] ?? null;
                if ($temperature !== null && (!is_int($temperature) && !is_float($temperature))) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].defaults.temperature must be a number or omitted",
                    );
                }
                $requires = $function['requires'] ?? [];
                if (!is_array($requires) || !array_is_list($requires)) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions[{$idx}].requires must be a JSON array",
                    );
                }
                foreach ($requires as $requirement) {
                    if (!is_string($requirement) || !in_array($requirement, self::AI_FUNCTION_REQUIRES, true)) {
                        throw new \InvalidArgumentException(
                            "Module '{$data['id']}': aiFunctions[{$idx}].requires must contain only "
                            . implode(', ', self::AI_FUNCTION_REQUIRES),
                        );
                    }
                }
                if (isset($aiFunctions[$functionId])) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': aiFunctions has duplicate id '{$functionId}'",
                    );
                }
                $entry = [
                    'id' => $functionId,
                    'tier' => $tier,
                    'defaults' => ['maxTokens' => $maxTokens]
                        + ($temperature !== null ? ['temperature' => (float) $temperature] : []),
                    'requires' => array_values(array_unique($requires)),
                ];
                // Název a popis vč. jazykových variant — lokalizuje až kompilace.
                foreach ($function as $key => $value) {
                    $key = (string) $key;
                    $isText = $key === 'name' || str_starts_with($key, 'name:')
                        || $key === 'description' || str_starts_with($key, 'description:');
                    if ($isText && is_string($value)) {
                        $entry[$key] = $value;
                    }
                }
                $aiFunctions[$functionId] = $entry;
            }
            $aiFunctions = array_values($aiFunctions);
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

        // prints — deklarace tisků (PDF nad jedním záznamem) v samostatných
        // JSONC souborech, stejný tvar jako `reports`. Parsování a duplicit
        // detection napříč moduly dělá PrintDefinitionLoader/PrintRegistry.
        $prints = [];
        if (isset($data['prints']) && is_array($data['prints'])) {
            foreach ($data['prints'] as $idx => $reg) {
                if (!is_array($reg)
                    || !isset($reg['file']) || !is_string($reg['file']) || $reg['file'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': prints[{$idx}] requires 'file'",
                    );
                }
                $prints[] = ['file' => $reg['file']];
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

        // clearSettingsOnReset — keys in `core_system_settings` (a keepOnReset
        // table) that hold the id of a row of `table`. `ds-reset` deletes the
        // key whenever it drops that table: the row is gone and the id would
        // otherwise point at whatever row gets the same id next (#131 D42).
        // Declared by the module that owns the key; `table` may belong to
        // another module (it names where the id points).
        $clearSettingsOnReset = [];
        if (isset($data['clearSettingsOnReset'])) {
            if (!is_array($data['clearSettingsOnReset']) || !array_is_list($data['clearSettingsOnReset'])) {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': clearSettingsOnReset must be a JSON array of {key, table} objects",
                );
            }
            foreach ($data['clearSettingsOnReset'] as $i => $item) {
                if (!is_array($item)
                    || !isset($item['key']) || !is_string($item['key']) || $item['key'] === ''
                    || !isset($item['table']) || !is_string($item['table']) || $item['table'] === ''
                ) {
                    throw new \InvalidArgumentException(
                        "Module '{$data['id']}': clearSettingsOnReset[{$i}] requires non-empty 'key' and 'table'",
                    );
                }
                $clearSettingsOnReset[] = ['key' => $item['key'], 'table' => $item['table']];
            }
        }

        // commands / serverCommands / cronJobs — příkazy a úlohy cronu, které
        // patří jen modulu (#172 D2, D6). Tady jen tvar deklarace; existenci
        // tříd, jejich namespace, jedinečnost jmen a vazbu úlohy na příkaz
        // modulu hlídá ModuleCommandRules nad celým stromem.
        $commands = self::parseCommandClasses($data, 'commands');
        $serverCommands = self::parseCommandClasses($data, 'serverCommands');
        $cronJobs = self::parseCronJobs($data);

        // serverConfig — sekce `server.json`, které čte jen kód modulu
        // (#172 D7, D10). Tady jen tvar; třídu, shodu klíče a kolize cest
        // hlídá ModuleServerConfigRules nad celým stromem.
        $serverConfig = self::parseServerConfig($data);

        // detailExtensions / formTabs / rowDecorators — body rozšíření v UI
        // cizí tabulky (#172 D16–D19). Tady jen tvar; třídu, rozhraní,
        // namespace a existenci tabulky hlídá ModuleUiExtensionRules nad
        // celým stromem.
        $detailExtensions = self::parseUiExtensions($data, 'detailExtensions');
        $formTabs = self::parseUiExtensions($data, 'formTabs');
        $rowDecorators = self::parseUiExtensions($data, 'rowDecorators');
        // recordActions — akce záznamu s obsluhou v modulu (#172 D12): totéž
        // a navíc `id` akce a příznak `admin`.
        $recordActions = self::parseUiExtensions($data, 'recordActions', withAction: true);

        // provisioners — výchozí data modulu zakládaná v `ds-upgrade`
        // (#174 D28). Tady jen tvar; třídu, rozhraní a namespace hlídá
        // ModuleProvisionerRules nad celým stromem.
        $provisioners = self::parseProvisioners($data);

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
            clearSettingsOnReset: $clearSettingsOnReset,
            documentEventHandlers: $documentEventHandlers,
            accountItems: $accountItems,
            journalEventHandlers: $journalEventHandlers,
            panels: $panels,
            navigationProviders: $navigationProviders,
            reports: $reports,
            attachmentGuards: $attachmentGuards,
            documentLockProviders: $documentLockProviders,
            openItemLookup: $openItemLookup,
            journalContributors: $journalContributors,
            journalDimensions: $journalDimensions,
            prints: $prints,
            sendPurposes: $sendPurposes,
            recordSenderProviders: $recordSenderProviders,
            periodicInvoicingContributors: $periodicInvoicingContributors,
            periodicInvoicingSources: $periodicInvoicingSources,
            aiFunctions: $aiFunctions,
            overviewBlocks: $overviewBlocks,
            kind: $kind,
            navSections: $navSections,
            settingsSections: $settingsSections,
            commands: $commands,
            serverCommands: $serverCommands,
            cronJobs: $cronJobs,
            serverConfig: $serverConfig,
            detailExtensions: $detailExtensions,
            formTabs: $formTabs,
            rowDecorators: $rowDecorators,
            recordActions: $recordActions,
            provisioners: $provisioners,
        );
    }

    /**
     * Rozšíření UI cizí tabulky (`detailExtensions`, `formTabs`,
     * `rowDecorators`, #172 D16–D19): seznam `{table, class, order?}` —
     * `table` je tabulka, do jejíhož detailu / formuláře / seznamu modul
     * přispívá (vlastní i cizí), `class` třída poskytovatele, `order` celé
     * číslo (výchozí {@see self::UI_EXTENSION_DEFAULT_ORDER}). Neznámý klíč
     * položky je chyba, překlep by jinak tiše zmizel. Třída se tu nenačítá.
     *
     * Akce záznamu (`recordActions`, #172 D12, `$withAction`) mají navíc
     * povinné `id` (`[a-z][a-zA-Z0-9]*` — globální id akce je
     * `<id modulu>.<id>`) a volitelné `admin` (jen pro správce, výchozí
     * `false`). Jedinečnost `id` v modulu hlídá `ModuleUiExtensionRules`.
     *
     * @param array<string, mixed> $data
     * @return ($withAction is true
     *     ? list<array{id: string, table: string, class: class-string, admin: bool, order: int}>
     *     : list<array{table: string, class: class-string, order: int}>)
     * @throws \InvalidArgumentException
     */
    private static function parseUiExtensions(array $data, string $key, bool $withAction = false): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }
        if (!is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new \InvalidArgumentException("Module '{$data['id']}': {$key} must be a JSON array");
        }

        $allowed = $withAction ? ['id', 'table', 'class', 'admin', 'order'] : ['table', 'class', 'order'];

        $extensions = [];
        foreach ($data[$key] as $idx => $raw) {
            $where = "Module '{$data['id']}': {$key}[{$idx}]";
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                throw new \InvalidArgumentException("{$where} must be an object");
            }
            $unknown = array_diff(array_keys($raw), $allowed);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("{$where} has unknown key '" . reset($unknown) . "'");
            }

            $action = [];
            if ($withAction) {
                $id = $raw['id'] ?? null;
                if (!is_string($id) || preg_match('/^[a-z][a-zA-Z0-9]*$/', $id) !== 1) {
                    throw new \InvalidArgumentException("{$where}.id must match [a-z][a-zA-Z0-9]*");
                }
                $action = ['id' => $id];
            }

            $table = $raw['table'] ?? null;
            if (!is_string($table) || preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                throw new \InvalidArgumentException("{$where}.table must be a table name");
            }

            $class = $raw['class'] ?? null;
            if (!is_string($class) || ltrim($class, '\\') === '') {
                throw new \InvalidArgumentException("{$where}.class must be a non-empty class name");
            }

            $admin = [];
            if ($withAction) {
                if (!is_bool($raw['admin'] ?? false)) {
                    throw new \InvalidArgumentException("{$where}.admin must be a boolean");
                }
                $admin = ['admin' => $raw['admin'] ?? false];
            }

            $order = $raw['order'] ?? self::UI_EXTENSION_DEFAULT_ORDER;
            if (!is_int($order)) {
                throw new \InvalidArgumentException("{$where}.order must be an integer");
            }

            $extensions[] = $action + ['table' => $table, 'class' => ltrim($class, '\\')] + $admin + ['order' => $order];
        }
        return $extensions;
    }


    /**
     * Provisionery modulu (`provisioners`, #174 D28): seznam `{class, order?}`
     * — `class` třída s rozhraním `ModuleProvisioner`, `order` celé číslo
     * (výchozí {@see self::PROVISIONER_DEFAULT_ORDER}) pro pořadí v rámci
     * modulu. Neznámý klíč položky je chyba, třída smí být v seznamu jednou.
     * Třída se tu nenačítá.
     *
     * @param array<string, mixed> $data
     * @return list<array{class: class-string, order: int}>
     * @throws \InvalidArgumentException
     */
    private static function parseProvisioners(array $data): array
    {
        if (!array_key_exists('provisioners', $data)) {
            return [];
        }
        if (!is_array($data['provisioners']) || !array_is_list($data['provisioners'])) {
            throw new \InvalidArgumentException("Module '{$data['id']}': provisioners must be a JSON array");
        }

        $provisioners = [];
        $seen = [];
        foreach ($data['provisioners'] as $idx => $raw) {
            $where = "Module '{$data['id']}': provisioners[{$idx}]";
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                throw new \InvalidArgumentException("{$where} must be an object");
            }
            $unknown = array_diff(array_keys($raw), ['class', 'order']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("{$where} has unknown key '" . reset($unknown) . "'");
            }

            $class = $raw['class'] ?? null;
            if (!is_string($class) || ltrim($class, '\\') === '') {
                throw new \InvalidArgumentException("{$where}.class must be a non-empty class name");
            }
            $class = ltrim($class, '\\');
            if (isset($seen[$class])) {
                throw new \InvalidArgumentException("Module '{$data['id']}': provisioners lists '{$class}' twice");
            }
            $seen[$class] = true;

            $order = $raw['order'] ?? self::PROVISIONER_DEFAULT_ORDER;
            if (!is_int($order)) {
                throw new \InvalidArgumentException("{$where}.order must be an integer");
            }

            $provisioners[] = ['class' => $class, 'order' => $order];
        }
        return $provisioners;
    }

    /**
     * Sekce `server.json` modulu (`serverConfig`, #172 D7, D10): seznam
     * `{key, class}` — `key` je cesta segmentů oddělených tečkou
     * (`exchangeRates`, `ai.analysis`), `class` třída, která sekci načte
     * (`ServerConfigSection`). Neznámý klíč položky je chyba. Třída se tu
     * nenačítá a kolize cest se tu neposuzují.
     *
     * @param array<string, mixed> $data
     * @return list<array{key: string, class: class-string}>
     * @throws \InvalidArgumentException
     */
    private static function parseServerConfig(array $data): array
    {
        if (!array_key_exists('serverConfig', $data)) {
            return [];
        }
        if (!is_array($data['serverConfig']) || !array_is_list($data['serverConfig'])) {
            throw new \InvalidArgumentException("Module '{$data['id']}': serverConfig must be a JSON array");
        }

        $sections = [];
        foreach ($data['serverConfig'] as $idx => $raw) {
            $where = "Module '{$data['id']}': serverConfig[{$idx}]";
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                throw new \InvalidArgumentException("{$where} must be an object");
            }
            $unknown = array_diff(array_keys($raw), ['key', 'class']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("{$where} has unknown key '" . reset($unknown) . "'");
            }

            $key = $raw['key'] ?? null;
            if (!is_string($key) || preg_match(self::SERVER_CONFIG_KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException(
                    "{$where}.key must be a path of segments [a-z][a-zA-Z0-9]* separated by dots",
                );
            }

            $class = $raw['class'] ?? null;
            if (!is_string($class) || ltrim($class, '\\') === '') {
                throw new \InvalidArgumentException("{$where}.class must be a non-empty class name");
            }

            $sections[] = ['key' => $key, 'class' => ltrim($class, '\\')];
        }
        return $sections;
    }

    /**
     * Seznam tříd příkazů (`commands`, `serverCommands`, #172 D2): pole
     * neprázdných řetězců bez opakování. Třída se tu nenačítá.
     *
     * @param array<string, mixed> $data
     * @return list<class-string>
     * @throws \InvalidArgumentException
     */
    private static function parseCommandClasses(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }
        if (!is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new \InvalidArgumentException("Module '{$data['id']}': {$key} must be a JSON array of class names");
        }

        $classes = [];
        foreach ($data[$key] as $idx => $class) {
            if (!is_string($class) || $class === '') {
                throw new \InvalidArgumentException(
                    "Module '{$data['id']}': {$key}[{$idx}] must be a non-empty class name",
                );
            }
            $class = ltrim($class, '\\');
            if (in_array($class, $classes, true)) {
                throw new \InvalidArgumentException("Module '{$data['id']}': {$key} lists '{$class}' twice");
            }
            $classes[] = $class;
        }
        return $classes;
    }

    /**
     * Úlohy cronu modulu (`cronJobs`, #172 D6): `command` = jméno příkazu,
     * případně s volbami oddělenými mezerou; `slot` jeden ze slotů cronu;
     * `order` celé číslo (výchozí {@see self::CRON_JOB_DEFAULT_ORDER});
     * `states` lifecycle stavy zdroje dat, ve kterých úloha běží (výchozí
     * jen `active` — fail-closed). Neznámý klíč je chyba, překlep by jinak
     * tiše zmizel.
     *
     * @param array<string, mixed> $data
     * @return list<array{command: string, slot: string, order: int, states: list<string>}>
     * @throws \InvalidArgumentException
     */
    private static function parseCronJobs(array $data): array
    {
        if (!array_key_exists('cronJobs', $data)) {
            return [];
        }
        if (!is_array($data['cronJobs']) || !array_is_list($data['cronJobs'])) {
            throw new \InvalidArgumentException("Module '{$data['id']}': cronJobs must be a JSON array");
        }

        $jobs = [];
        foreach ($data['cronJobs'] as $idx => $raw) {
            $where = "Module '{$data['id']}': cronJobs[{$idx}]";
            if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
                throw new \InvalidArgumentException("{$where} must be an object");
            }
            $unknown = array_diff(array_keys($raw), ['command', 'slot', 'order', 'states']);
            if ($unknown !== []) {
                throw new \InvalidArgumentException("{$where} has unknown key '" . reset($unknown) . "'");
            }

            $command = $raw['command'] ?? null;
            if (!is_string($command) || trim($command) === '') {
                throw new \InvalidArgumentException("{$where}.command must be a non-empty string");
            }

            $slot = $raw['slot'] ?? null;
            if (!is_string($slot) || !in_array($slot, CronProvisioner::SLOTS, true)) {
                throw new \InvalidArgumentException(
                    "{$where}.slot is missing or unknown (expected one of: " . implode(', ', CronProvisioner::SLOTS) . ')',
                );
            }

            $order = $raw['order'] ?? self::CRON_JOB_DEFAULT_ORDER;
            if (!is_int($order)) {
                throw new \InvalidArgumentException("{$where}.order must be an integer");
            }

            $states = $raw['states'] ?? [DataSourceState::ACTIVE];
            if (!is_array($states) || !array_is_list($states) || $states === []) {
                throw new \InvalidArgumentException("{$where}.states must be a non-empty JSON array of data source states");
            }
            foreach ($states as $state) {
                if (!is_string($state) || !in_array($state, DataSourceState::STATES, true)) {
                    throw new \InvalidArgumentException(
                        "{$where}.states has an unknown state (expected: " . implode(', ', DataSourceState::STATES) . ')',
                    );
                }
            }

            $jobs[] = [
                'command' => trim($command),
                'slot' => $slot,
                'order' => $order,
                'states' => array_values(array_unique($states)),
            ];
        }
        return $jobs;
    }

    /**
     * Vlastní sekce modulu (`navSections`, `settingsSections`, #170 D13) —
     * tvar položky jako v `navSections.jsonc` / `settingsSections.jsonc`
     * instalačního modulu: povinné `id`, `name` (+ varianty `name:<jazyk>`)
     * a celočíselné `order`, volitelný `icon`; sekce Nastavení smí nést
     * `subsections`. Varianty názvu zůstávají — lokalizuje až kompilace.
     *
     * @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     * @throws \InvalidArgumentException
     */
    private static function parseSections(array $data, string $key, bool $allowSubsections): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }
        if (!is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new \InvalidArgumentException("Module '{$data['id']}': {$key} must be a JSON array");
        }

        $sections = [];
        foreach ($data[$key] as $idx => $raw) {
            $where = "Module '{$data['id']}': {$key}[{$idx}]";
            $section = self::parseSection($raw, $where, '/^[a-z][a-zA-Z0-9]*$/', $allowSubsections ? ['icon', 'subsections'] : ['icon']);
            $sectionId = $section['id'];
            if (isset($sections[$sectionId])) {
                throw new \InvalidArgumentException("Module '{$data['id']}': {$key} has duplicate id '{$sectionId}'");
            }

            if (array_key_exists('subsections', $section)) {
                if (!is_array($section['subsections']) || !array_is_list($section['subsections'])) {
                    throw new \InvalidArgumentException("{$where}.subsections must be a JSON array");
                }
                // Podsekce nese id sekce jako prefix (`other.system`) — id
                // podsekce je tak jedinečné napříč sekcemi.
                $subsections = [];
                foreach ($section['subsections'] as $subIdx => $rawSub) {
                    $sub = self::parseSection(
                        $rawSub,
                        "{$where}.subsections[{$subIdx}]",
                        '/^' . preg_quote($sectionId, '/') . '\.[a-z][a-zA-Z0-9]*$/',
                        [],
                    );
                    if (isset($subsections[$sub['id']])) {
                        throw new \InvalidArgumentException("{$where}.subsections has duplicate id '{$sub['id']}'");
                    }
                    $subsections[$sub['id']] = $sub;
                }
                $section['subsections'] = array_values($subsections);
            }

            $sections[$sectionId] = $section;
        }
        return array_values($sections);
    }

    /**
     * Jedna sekce nebo podsekce: `id` podle vzoru, `name`, `order`
     * a volitelné klíče; neznámý klíč je chyba (překlep by jinak tiše zmizel).
     *
     * @param list<string> $optionalKeys
     * @return array<string, mixed>
     */
    private static function parseSection(mixed $raw, string $where, string $idPattern, array $optionalKeys): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw new \InvalidArgumentException("{$where} must be an object");
        }
        $id = $raw['id'] ?? null;
        if (!is_string($id) || !preg_match($idPattern, $id)) {
            throw new \InvalidArgumentException("{$where}.id is missing or invalid (expected {$idPattern})");
        }
        if (!is_string($raw['name'] ?? null) || $raw['name'] === '') {
            throw new \InvalidArgumentException("{$where} requires a non-empty 'name'");
        }
        if (!is_int($raw['order'] ?? null)) {
            throw new \InvalidArgumentException("{$where}.order must be an integer");
        }

        $section = ['id' => $id];
        foreach ($raw as $field => $value) {
            $field = (string) $field;
            if ($field === 'id') {
                continue;
            }
            if ($field === 'name' || preg_match('/^name:[a-z]{2}$/', $field)) {
                if (!is_string($value) || $value === '') {
                    throw new \InvalidArgumentException("{$where}.{$field} must be a non-empty string");
                }
            } elseif ($field === 'icon') {
                if (!in_array('icon', $optionalKeys, true)) {
                    throw new \InvalidArgumentException("{$where} has unknown key 'icon'");
                }
                if (!is_string($value) || $value === '') {
                    throw new \InvalidArgumentException("{$where}.icon must be a non-empty string");
                }
            } elseif ($field !== 'order' && !in_array($field, $optionalKeys, true)) {
                throw new \InvalidArgumentException("{$where} has unknown key '{$field}'");
            }
            $section[$field] = $value;
        }
        return $section;
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

    /**
     * `journalDimensions[].forms` — formuláře dokladů, které pole dimenze
     * nabízejí. Pole na hlavičce potřebuje `headColumn` (kam by se uložilo).
     *
     * @param array<string, mixed> $entry dimenze (kvůli `headColumn`)
     * @return array{docTypes: list<string>, head: bool, rows: bool, enabledBySetting: ?string}
     */
    private static function journalDimensionForms(mixed $forms, array $entry, string $where): array
    {
        if (!is_array($forms) || array_is_list($forms)) {
            throw new \InvalidArgumentException("{$where} must be an object");
        }
        $docTypes = $forms['docTypes'] ?? null;
        if (!is_array($docTypes) || !array_is_list($docTypes) || $docTypes === []) {
            throw new \InvalidArgumentException("{$where}.docTypes must be a non-empty array");
        }
        foreach ($docTypes as $docType) {
            if (!is_string($docType) || $docType === '') {
                throw new \InvalidArgumentException("{$where}.docTypes must contain non-empty strings");
            }
        }
        foreach (['head', 'rows'] as $key) {
            if (isset($forms[$key]) && !is_bool($forms[$key])) {
                throw new \InvalidArgumentException("{$where}.{$key} must be a boolean");
            }
        }
        $setting = $forms['enabledBySetting'] ?? null;
        if ($setting !== null && (!is_string($setting) || $setting === '')) {
            throw new \InvalidArgumentException("{$where}.enabledBySetting must be a non-empty string or null");
        }
        $head = $forms['head'] ?? false;
        if ($head && ($entry['headColumn'] ?? null) === null) {
            throw new \InvalidArgumentException("{$where}.head requires headColumn");
        }
        return [
            'docTypes'         => array_values(array_unique($docTypes)),
            'head'             => $head,
            'rows'             => $forms['rows'] ?? false,
            'enabledBySetting' => $setting,
        ];
    }
}
