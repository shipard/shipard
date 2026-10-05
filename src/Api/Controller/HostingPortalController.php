<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\InstallModuleRegistry;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Hosting\Core\HostingDataSourceDocument;

/**
 * Portálové endpointy hostingu (D10) — data scopovaná na session uživatele.
 * Hosting tabulky jsou adminOnly (D9), portáloví ne-admini se k evidenci
 * dostanou výhradně tudy; server vrací jen řádky přihlášeného uživatele
 * a nikdy serverové detaily.
 *
 * Endpoints:
 *   GET  /_hosting/portal/my-datasources     Seznam „moje DS" pro portál
 *   GET  /_hosting/portal/create-meta        Meta pro wizard nového DS (hosting-08)
 *   GET  /_hosting/portal/check-web-id       Živá kontrola web_id
 *   POST /_hosting/portal/create-datasource  Založení požadavku na nový DS
 */
class HostingPortalController
{
    /** Ne-archivní stavy modelu core.system.docStatesArchive. */
    private const ACTIVE_DOC_STATES = [10, 40, 80];

    /** Strop vlastněných DS, když setting hosting.selfService.maxOwned chybí. */
    private const DEFAULT_MAX_OWNED = 5;

    /** Otevřené (pending) stavy lifecycle požadavku. */
    private const OPEN_LIFECYCLES = ['request', 'creating'];

    /**
     * GET /_hosting/portal/my-datasources
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function myDatasources(AuthContext $auth, DataSourceConnection $db, array $tables): Response
    {
        $guard = $this->portalGuard($auth, $tables);
        if ($guard !== null) {
            return $guard;
        }

        // Snapshoty (D7) přišly až s Fází 5 — hosting bez ds-upgrade jede
        // dál bez nich (stats: null).
        $hasStats = isset($tables['hosting_core_ds_stats']);
        $select = 'SELECT ds.`id`, ds.`ds_id`, ds.`name`, ds.`url_app`, du.`role`';
        $statsJoin = '';
        if ($hasStats) {
            $select .= ', st.`alerts_count`, st.`mail_count`, st.`collected_at`';
            $statsJoin = ' LEFT JOIN `hosting_core_ds_stats` AS st ON st.`data_source` = ds.`id`';
        }

        $rows = $db->fetchAll(
            $select
            . ' FROM `hosting_core_ds_users` AS du'
            . ' JOIN `hosting_core_data_sources` AS ds ON ds.`id` = du.`data_source`'
            . $statsJoin
            . ' WHERE du.`user` = %i'
            . ' AND ds.`lifecycle` = %s'
            . ' AND du.`docState` IN %in'
            . ' AND ds.`docState` IN %in'
            . ' ORDER BY ds.`name` ASC, ds.`id` ASC',
            $auth->userId,
            'active',
            self::ACTIVE_DOC_STATES,
            self::ACTIVE_DOC_STATES,
        );

        // Pending požadavky uživatele (hosting-08 D5) — vlastní řádky ve
        // stavech request/creating/failed, ještě bez vazby v ds_users.
        // request i creating shodně `creating` (rozlišení je pro uživatele
        // bezcenné); failed bez detailu chyby (ten vidí admin v evidenci).
        $pendingRows = $db->fetchAll(
            'SELECT `id`, `ds_id`, `name`, `url_app`, `lifecycle`'
            . ' FROM `hosting_core_data_sources`'
            . ' WHERE `owner` = %i'
            . ' AND `lifecycle` IN %in'
            . ' AND `docState` IN %in'
            . ' ORDER BY `id` DESC',
            $auth->userId,
            ['request', 'creating', 'failed'],
            self::ACTIVE_DOC_STATES,
        );

        // Řazení: pending první (nejnovější nahoře), pak active dle názvu.
        $items = [];
        $seenIds = [];
        foreach ($pendingRows as $row) {
            $seenIds[(int) $row['id']] = true;
            $items[] = $this->pendingItem($row);
        }

        // Jen portálový kontrakt — žádné serverové detaily (server, install
        // modul, lifecycle) sem nepatří; stats jsou záměrně jen počty (§7).
        foreach ($rows as $row) {
            // Pojistka proti duplicitě (failed řádek s existující ds_users
            // vazbou nečekáme, ale kdyby, pending karta vyhrává).
            if (isset($seenIds[(int) $row['id']])) {
                continue;
            }
            $stats = null;
            if ($hasStats && ($row['collected_at'] ?? null) !== null) {
                $stats = [
                    'alerts' => $row['alerts_count'] !== null ? (int) $row['alerts_count'] : null,
                    'mail' => $row['mail_count'] !== null ? (int) $row['mail_count'] : null,
                    'collected_at' => $this->toAtom($row['collected_at']),
                ];
            }
            $items[] = [
                'id'      => (int) $row['id'],
                'ds_id'   => (string) $row['ds_id'],
                'name'    => (string) $row['name'],
                'url_app' => (string) $row['url_app'],
                'role'    => (string) $row['role'],
                'stats'   => $stats,
                'state'   => 'active',
            ];
        }

        return Response::success(['items' => $items]);
    }

    /**
     * GET /_hosting/portal/create-meta (hosting-08 D6/D7)
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function createMeta(
        AuthContext $auth,
        DataSourceConnection $db,
        array $tables,
        InstallModuleRegistry $installModules,
        ?ConfigRuntime $config,
        string $language,
    ): Response {
        $guard = $this->portalGuard($auth, $tables);
        if ($guard !== null) {
            return $guard;
        }

        [$canCreate, $reason] = $this->resolveCanCreate($db, (int) $auth->userId);

        return Response::success([
            'canCreate'      => $canCreate,
            'reason'         => $reason,
            'installModules' => $installModules->list($language, selfServiceOnly: true),
            'languages'      => $this->languageOptions($config),
            'countries'      => $this->countryOptions($config),
            'defaults'       => ['language' => 'cs', 'country' => 'cz'],
        ]);
    }

    /**
     * GET /_hosting/portal/check-web-id?value=… (hosting-08 D3/D4)
     *
     * Informativní — finální autorita je validace dokumentu při create.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function checkWebId(Request $request, AuthContext $auth, DataSourceConnection $db, array $tables): Response
    {
        $guard = $this->portalGuard($auth, $tables);
        if ($guard !== null) {
            return $guard;
        }

        $value = HostingDataSourceDocument::normalizeWebId(
            (string) ($request->getQueryParams()['value'] ?? ''),
        );

        $ruleError = HostingDataSourceDocument::checkWebIdRules($value);
        if ($ruleError !== null) {
            return Response::success(['available' => false, 'reason' => $ruleError]);
        }

        // Pokrývá i hodnoty obsazené pending požadavky — unq_web_id je
        // globální přes všechny lifecycle stavy.
        $taken = $db->fetchSingle(
            'SELECT `id` FROM `hosting_core_data_sources` WHERE `web_id` = %s',
            $value,
        );
        $isTaken = $taken !== null && $taken !== false;

        return Response::success([
            'available' => !$isTaken,
            'reason'    => $isTaken ? 'taken' : null,
        ]);
    }

    /**
     * POST /_hosting/portal/create-datasource (hosting-08 D4/D6)
     *
     * Založí řádek `lifecycle = request` — o zbytek se stará existující
     * provisioning pipeline (hosting-sync agent, beze změny).
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function createDatasource(
        Request $request,
        AuthContext $auth,
        DataSourceConnection $db,
        array $tables,
        InstallModuleRegistry $installModules,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
        DocumentRegistry $documentRegistry,
    ): Response {
        $guard = $this->portalGuard($auth, $tables);
        if ($guard !== null) {
            return $guard;
        }

        // Limity znovu — meta a create dělí čas (race), create je autorita.
        [$canCreate, $reason] = $this->resolveCanCreate($db, (int) $auth->userId);
        if (!$canCreate) {
            return Response::error(strtoupper((string) $reason), 'Cannot create a new data source', 409);
        }

        $body = $request->getBody() ?? [];

        // Install modul: z body jen pokud je v selfService nabídce; chybějící
        // → jediný nabízený (D2 — roletka se kreslí až při >1).
        $offered = array_column($installModules->list(selfServiceOnly: true), 'id');
        $installModule = trim((string) ($body['install_module'] ?? ''));
        if ($installModule === '') {
            if (count($offered) !== 1) {
                return Response::error('INVALID_MODULE', 'Install module is required', 400);
            }
            $installModule = $offered[0];
        } elseif (!in_array($installModule, $offered, true)) {
            return Response::error('INVALID_MODULE', 'Install module is not offered', 400);
        }

        $dsLanguage = trim((string) ($body['language'] ?? ''));
        if (!in_array($dsLanguage, array_column($this->languageOptions($config), 'id'), true)) {
            return Response::error('INVALID_LANGUAGE', 'Unknown language', 400);
        }
        $dsCountry = trim((string) ($body['country'] ?? ''));
        if (!in_array($dsCountry, array_column($this->countryOptions($config), 'id'), true)) {
            return Response::error('INVALID_COUNTRY', 'Unknown country', 400);
        }

        $serverId = $this->findDefaultServer($db);
        if ($serverId === null) {
            return Response::error('NO_SERVER', 'No default provisioning server', 409);
        }

        $gateway = $this->buildGateway($tables, $db, $documentRegistry, $config, $dsConfig);

        $result = $gateway->saveDocument([
            'name'           => trim((string) ($body['name'] ?? '')),
            'web_id'         => (string) ($body['web_id'] ?? ''),
            'language'       => $dsLanguage,
            'country'        => $dsCountry,
            'server'         => $serverId,
            'install_module' => $installModule,
            'lifecycle'      => 'request',
            'owner'          => (int) $auth->userId,
        ]);

        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                $errors = array_map(
                    fn($e) => ['field' => $e->column, 'code' => $e->code ?: 'INVALID', 'message' => $e->message],
                    $validation->getErrors(),
                );
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
            }
            return Response::error('INTERNAL_ERROR', $result->getErrorMessage() ?? 'Save failed', 500);
        }

        // Tvar pending karty z my-datasources — frontend vloží bez refetche.
        $saved = $result->getData() ?? [];
        return Response::success([
            'item' => $this->pendingItem([
                'id'        => $saved['id'] ?? 0,
                'ds_id'     => $saved['ds_id'] ?? '',
                'name'      => $saved['name'] ?? '',
                'url_app'   => $saved['url_app'] ?? '',
                'lifecycle' => 'request',
            ]),
        ]);
    }

    /**
     * Gateway pro zápis požadavku — protected kvůli testům (Dibi\Connection
     * je final, testovací subclass podstrčí fake gateway).
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    protected function buildGateway(
        array $tables,
        DataSourceConnection $db,
        DocumentRegistry $documentRegistry,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
    ): TableGateway {
        $def = $tables['hosting_core_data_sources'];
        return new TableGateway(
            'hosting_core_data_sources',
            $db->getDibiConnection(),
            $documentRegistry,
            $def->childTables,
            $config,
            $dsConfig,
            null,
            $def->docStates,
            $def,
        );
    }

    /**
     * Společný guard portálových endpointů: modul aktivní + autentizace.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    private function portalGuard(AuthContext $auth, array $tables): ?Response
    {
        // Modul hosting.core není na DS aktivní → endpoint neexistuje.
        if (!isset($tables['hosting_core_data_sources']) || !isset($tables['hosting_core_ds_users'])) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        // AuthMiddleware nepřihlášené nepustí (endpoint není exempt) —
        // přesto ověřit, ať kontroler nestojí jen na wiringu middlewaru.
        if (!$auth->isAuthenticated || $auth->userId === null) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        return null;
    }

    /**
     * Zda uživatel smí založit nový DS (hosting-08 D1/D6).
     *
     * @return array{bool, ?string} [canCreate, reason (no_server|open_request|max_owned)]
     */
    private function resolveCanCreate(DataSourceConnection $db, int $userId): array
    {
        // Existence default serveru = zapnutí self-service (D1).
        if ($this->findDefaultServer($db) === null) {
            return [false, 'no_server'];
        }

        $openRequests = (int) $db->fetchSingle(
            'SELECT COUNT(*) FROM `hosting_core_data_sources`'
            . ' WHERE `owner` = %i AND `lifecycle` IN %in AND `docState` IN %in',
            $userId,
            self::OPEN_LIFECYCLES,
            self::ACTIVE_DOC_STATES,
        );
        if ($openRequests > 0) {
            return [false, 'open_request'];
        }

        $maxOwned = $this->maxOwned($db);
        if ($maxOwned !== null) {
            $activeOwned = (int) $db->fetchSingle(
                'SELECT COUNT(*) FROM `hosting_core_ds_users` AS du'
                . ' JOIN `hosting_core_data_sources` AS ds ON ds.`id` = du.`data_source`'
                . ' WHERE du.`user` = %i AND ds.`lifecycle` = %s'
                . ' AND du.`docState` IN %in AND ds.`docState` IN %in',
                $userId,
                'active',
                self::ACTIVE_DOC_STATES,
                self::ACTIVE_DOC_STATES,
            );
            if ($activeOwned + $openRequests >= $maxOwned) {
                return [false, 'max_owned'];
            }
        }

        return [true, null];
    }

    /** Id default serveru (provision_default + can_provision + živý docState). */
    private function findDefaultServer(DataSourceConnection $db): ?int
    {
        $id = $db->fetchSingle(
            'SELECT `id` FROM `hosting_core_servers`'
            . ' WHERE `provision_default` = 1 AND `can_provision` = 1 AND `docState` IN %in'
            . ' ORDER BY `id` ASC LIMIT 1',
            self::ACTIVE_DOC_STATES,
        );
        return ($id === null || $id === false) ? null : (int) $id;
    }

    /**
     * Strop vlastněných DS ze settingu hosting.selfService.maxOwned:
     * klíč chybí/prázdný → default 5, hodnota 0 → null = bez limitu (D6).
     */
    private function maxOwned(DataSourceConnection $db): ?int
    {
        $raw = (new SettingsStore($db))->get('hosting.selfService.maxOwned');
        if ($raw === null || $raw === '') {
            return self::DEFAULT_MAX_OWNED;
        }
        $limit = (int) $raw;
        return $limit > 0 ? $limit : null;
    }

    /**
     * Položka pending požadavku pro portál (D5) — request i creating shodně
     * `creating`; bez stats a bez vstupního odkazu (rozhoduje frontend).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function pendingItem(array $row): array
    {
        return [
            'id'      => (int) ($row['id'] ?? 0),
            'ds_id'   => (string) ($row['ds_id'] ?? ''),
            'name'    => (string) ($row['name'] ?? ''),
            'url_app' => (string) ($row['url_app'] ?? ''),
            'role'    => 'owner',
            'stats'   => null,
            'state'   => ((string) ($row['lifecycle'] ?? '')) === 'failed' ? 'failed' : 'creating',
        ];
    }

    /** @return list<array{id: string, name: string}> Jazyky pro wizard (jen cs/en). */
    private function languageOptions(?ConfigRuntime $config): array
    {
        $options = $this->cfgOptions($config, 'world.base.languages');
        $filtered = array_values(array_filter(
            $options,
            static fn(array $o): bool => in_array($o['id'], ['cs', 'en'], true),
        ));
        // Degradace bez compiled configu — wizard musí zůstat použitelný.
        return $filtered !== [] ? $filtered : [['id' => 'cs', 'name' => 'cs'], ['id' => 'en', 'name' => 'en']];
    }

    /** @return list<array{id: string, name: string}> Země pro wizard. */
    private function countryOptions(?ConfigRuntime $config): array
    {
        $options = $this->cfgOptions($config, 'world.base.countries');
        return $options !== [] ? $options : [['id' => 'cz', 'name' => 'cz']];
    }

    /**
     * Options z cfgItem mapy (kód → záznam) — compiled config už je
     * lokalizovaný, `name` je resolved.
     *
     * @return list<array{id: string, name: string}>
     */
    private function cfgOptions(?ConfigRuntime $config, string $cfgItemId): array
    {
        if ($config === null) {
            return [];
        }
        $cfgData = $config->cfgItem($cfgItemId);
        if (!is_array($cfgData)) {
            return [];
        }

        $options = [];
        foreach ($cfgData as $code => $entry) {
            $options[] = [
                'id'   => (string) $code,
                'name' => is_array($entry) ? (string) ($entry['name'] ?? $code) : (string) $code,
            ];
        }
        return $options;
    }

    /** DB datetime (DibiDateTime i string) → ISO 8601 pro klientský freshness check. */
    private function toAtom(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        try {
            return (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
        } catch (\Throwable) {
            return (string) $value;
        }
    }
}
