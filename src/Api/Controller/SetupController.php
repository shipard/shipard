<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\LayerCParameters;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Settings\SetupChecklist;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\AccountChartProvisioner;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;

/**
 * Endpoints:
 *   GET  /_setup/checklist                 — živý checklist (SetupChecklist::collect) + hodnoty parametrů vrstvy C
 *   POST /_setup/parameters                — zápis parametrů vrstvy C + okamžitý běh dotčených provisionerů
 *   GET  /_setup/vat-registration-prefill  — návrh hodnot Registrace DPH z vlastní Osoby + vrstvy A
 *   GET  /_setup/bank-account-candidates   — bankovní spojení vlastní Osoby k překlopení do číselníku
 *   POST /_setup/bank-accounts             — překlop vybraných spojení do economy_codebooks_bank_accounts
 *   GET  /_setup/accounting-items-offer    — nabídka účetních položek dle varianty osnovy (D18/D19)
 *   POST /_setup/accounting-items          — jednorázové vygenerování vybraných účetních položek
 *
 * Backend panelu dsSetup (docs/ds-setup.md D12/D14, Fáze 4 §5.4). Auth:
 * přihlášený uživatel, bez adminOnly — v jednouživatelských DS by to
 * zablokovalo majitele (stejná úroveň jako /_settings/page).
 */
class SetupController
{
    /** docState 10 = Koncept, 40 = V pořádku — "aktivní" záznamy (vzor checků). */
    private const ACTIVE_DOC_STATES = [10, 40];

    private const CODEBOOK_TABLE = 'economy_codebooks_bank_accounts';

    private const ITEMS_TABLE = 'economy_items';

    /** Memo pro ownPerson() — null = zatím nenačteno, false = žádná není. */
    private array|false|null $ownPersonCache = null;

    private ?AccountingItemsOffer $accountingItemsOfferCache = null;

    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly AlertCheckRegistry $registry,
        private readonly ConfigRuntime $config,
        private readonly string $language,
        private readonly ModulePathResolver $modulePathResolver,
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly array $tables = [],
        private readonly ?DocumentRegistry $documentRegistry = null,
        private readonly ?DocumentEventDispatcher $eventDispatcher = null,
    ) {}

    /** GET /_setup/checklist */
    public function checklist(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        return Response::success($this->buildState(new SettingsStore($this->db)));
    }

    /** POST /_setup/parameters — body: {"values": {"economy.accountChart": "npo", ...}} */
    public function saveParameters(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body   = $request->getBody();
        $values = $body['values'] ?? null;
        if (!is_array($values)) {
            return Response::error('BAD_REQUEST', 'Body must contain a `values` object', 400);
        }

        // Validace celého payloadu PŘED prvním zápisem — uloží se všechno,
        // nebo nic. Hodnoty validuje výhradně LayerCParameters::validate()
        // (jediné místo pravdy) — tady se jen normalizuje JSON typ na
        // stringovou formu, kterou validate() bere.
        $known  = LayerCParameters::keys();
        $toSave = [];
        $errors = [];
        foreach ($values as $key => $raw) {
            $key = (string) $key;
            if (!in_array($key, $known, true)) {
                $errors[] = [
                    'field'   => $key,
                    'code'    => 'UNKNOWN_PARAMETER',
                    'message' => "Unknown layer C parameter: {$key}",
                ];
                continue;
            }
            if ($raw === null) {
                // Smazání klíče = vrácení do nerozhodnutého stavu — legální akce.
                $toSave[$key] = null;
                continue;
            }
            if (!is_scalar($raw)) {
                $errors[] = [
                    'field'   => $key,
                    'code'    => 'INVALID_TYPE',
                    'message' => 'Value must be a scalar or null',
                ];
                continue;
            }
            try {
                $rawStr = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;
                $toSave[$key] = LayerCParameters::validate($key, $rawStr);
            } catch (\InvalidArgumentException $e) {
                $errors[] = [
                    'field'   => $key,
                    'code'    => 'INVALID_VALUE',
                    'message' => $e->getMessage(),
                ];
            }
        }
        if ($errors !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
        }

        $settings = new SettingsStore($this->db);
        foreach ($toSave as $key => $value) {
            $settings->set($key, $value);
        }

        $warnings = $this->runProvisioners(array_keys($toSave), $settings);

        // Stejný tvar jako GET + warnings — panel po uložení nedělá druhý request.
        return Response::success($this->buildState($settings) + ['warnings' => $warnings]);
    }

    /**
     * GET /_setup/vat-registration-prefill — návrh hodnot Registrace DPH.
     * Uložení dělá frontend přes existující POST /_ui/form/.../save, aby
     * prošlo VatRegistrationDocument (afterSave event → seed instancí tvrzení);
     * tenhle endpoint jen skládá předvyplnění.
     *
     * valid_from a frekvence jsou záměrně null — registr datum registrace
     * ani příznak plátce nevrací a default by sváděl k odkliknutí (D2/D5).
     */
    public function vatRegistrationPrefill(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $own = $this->ownPerson();
        if ($own === null) {
            // Akce se bez vlastní Osoby v panelu nenabízí — přímé volání je
            // abnormální stav, ne prázdná odpověď.
            return Response::error('NO_OWN_PERSON', 'No active own Person exists', 409);
        }

        $vatId = trim((string) ($own['vat_id'] ?? ''));

        return Response::success([
            'values' => [
                'vat_id'             => $vatId === '' ? null : $vatId,
                'country'            => $this->dsConfig?->getCountry() ?? 'cz',
                // Default sloupce region — jemnější odvození ze země až bude
                // k čemu (jiné unie než EU zatím nikdo nezakládá).
                'region'             => 'eu',
                'name'               => mb_substr(trim((string) ($own['full_name'] ?? '')), 0, 50),
                'taxpayer_kind'      => 0,
                'valid_from'         => null,
                'tax_period_kind'    => null,
                'cs_period_kind'     => null,
                // Souhrnné hlášení je ze zákona měsíční, čtvrtletní jen za
                // podmínek § 102 odst. 6 — default 1 je bezpečný návrh.
                'rs_period_kind'     => 1,
            ],
            'periodKindOptions' => $this->periodKindOptions(),
        ]);
    }

    /**
     * GET /_setup/bank-account-candidates — bankovní spojení vlastní Osoby
     * s příznakem, jestli už v číselníku jsou (match IBAN, bez něj číslo účtu).
     */
    public function bankAccountCandidates(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $own = $this->ownPerson();
        if ($own === null) {
            return Response::error('NO_OWN_PERSON', 'No active own Person exists', 409);
        }

        [$codebookIbans, $codebookNumbers] = $this->existingCodebookKeys();

        $candidates = [];
        foreach ($this->ownPersonBankAccounts((int) $own['id']) as $row) {
            $candidates[] = [
                'id'               => (int) $row['id'],
                'name'             => trim((string) ($row['name'] ?? '')),
                'accountNumber'    => trim((string) ($row['account_number'] ?? '')),
                'iban'             => strtoupper(trim((string) ($row['iban'] ?? ''))),
                'bic'              => strtoupper(trim((string) ($row['bic'] ?? ''))),
                'currency'         => strtolower(trim((string) ($row['currency'] ?? ''))),
                'source'           => (int) ($row['source'] ?? 0),
                'validFrom'        => $row['valid_from'] ?? null,
                'validTo'          => $row['valid_to'] ?? null,
                'existsInCodebook' => $this->existsInCodebook($row, $codebookIbans, $codebookNumbers),
            ];
        }

        return Response::success(['candidates' => $candidates]);
    }

    /**
     * POST /_setup/bank-accounts — body {"personBankAccountIds": [12, 13], "defaultId": 12}.
     * Překlopí vybraná bankovní spojení vlastní Osoby do číselníku. Každý
     * řádek jde přes BankAccountDocument (TableGateway) — validace,
     * normalizace měny/IBAN i per-currency unikátnost is_default
     * (afterPersist) se přebírají z dokumentu, ne duplikují.
     *
     * All-or-nothing validace PŘED prvním zápisem (vzor saveParameters):
     * neznámé id nebo účet už v číselníku → 422 a nic se neuloží.
     */
    public function bridgeBankAccounts(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body = $request->getBody();
        $ids  = $body['personBankAccountIds'] ?? null;
        if (!is_array($ids) || $ids === [] || $ids !== array_filter($ids, is_int(...))) {
            return Response::error('BAD_REQUEST', '`personBankAccountIds` must be a non-empty list of integers', 400);
        }
        $ids = array_values(array_unique($ids));

        $defaultId = $body['defaultId'] ?? null;
        if ($defaultId !== null && !is_int($defaultId)) {
            return Response::error('BAD_REQUEST', '`defaultId` must be an integer or null', 400);
        }
        // Jediný překlápěný účet je výchozí automaticky (server-side pojistka
        // stejného pravidla, které drží frontend).
        if (count($ids) === 1) {
            $defaultId = $ids[0];
        }
        if ($defaultId !== null && !in_array($defaultId, $ids, true)) {
            return Response::error('BAD_REQUEST', '`defaultId` must be one of `personBankAccountIds`', 400);
        }

        $own = $this->ownPerson();
        if ($own === null) {
            return Response::error('NO_OWN_PERSON', 'No active own Person exists', 409);
        }

        // Jen spojení vlastní Osoby — cizí id je chyba klienta, ne no-op.
        $rows = [];
        foreach ($this->ownPersonBankAccounts((int) $own['id']) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $errors = [];
        foreach ($ids as $id) {
            if (!isset($rows[$id])) {
                $errors[] = [
                    'field'   => (string) $id,
                    'code'    => 'UNKNOWN_ACCOUNT',
                    'message' => "Bank account #{$id} does not belong to the own Person",
                ];
            }
        }

        [$codebookIbans, $codebookNumbers] = $this->existingCodebookKeys();
        foreach ($ids as $id) {
            if (isset($rows[$id]) && $this->existsInCodebook($rows[$id], $codebookIbans, $codebookNumbers)) {
                $errors[] = [
                    'field'   => (string) $id,
                    'code'    => 'ALREADY_IN_CODEBOOK',
                    'message' => "Bank account #{$id} is already present in the codebook",
                ];
            }
        }
        if ($errors !== []) {
            return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
        }

        // Pořadí překlopu: order_pos (null u ručně pořízených až nakonec), pak id.
        $selected = array_map(static fn(int $id): array => $rows[$id], $ids);
        usort($selected, static function (array $a, array $b): int {
            $pa = $a['order_pos'] ?? null;
            $pb = $b['order_pos'] ?? null;
            return [$pa === null, (int) $pa] <=> [$pb === null, (int) $pb]
                ?: (int) $a['id'] <=> (int) $b['id'];
        });

        $codes     = $this->generateCodes(count($selected));
        $sortOrder = (int) $this->db->fetchSingle(
            'SELECT COALESCE(MAX(sort_order), 0) FROM ' . self::CODEBOOK_TABLE,
        );

        $created = [];
        foreach ($selected as $i => $row) {
            $payload = $this->codebookPayload($row, $codes[$i], ++$sortOrder, (int) $row['id'] === $defaultId);
            $result  = $this->saveBankAccountRow($payload);
            if (!$result->isSuccess()) {
                // Dřívější řádky už jsou uložené — opakovaný pokus je díky
                // existsInCodebook přeskočí, duplicita nevznikne.
                ErrorLogger::error('SetupController: bank account bridge failed', [
                    'personBankAccountId' => (int) $row['id'],
                    'message'             => $result->getErrorMessage(),
                ]);
                return Response::error(
                    'SAVE_FAILED',
                    "Saving bank account #{$row['id']} failed: " . ($result->getErrorMessage() ?? 'unknown error'),
                    500,
                );
            }
            $saved = $result->getData() ?? [];
            $created[] = [
                'id'   => (int) ($saved['id'] ?? 0),
                'code' => $payload['code'],
                'name' => $payload['name'],
            ];
        }

        return Response::success(['created' => $created]);
    }

    /**
     * GET /_setup/accounting-items-offer — nabídka účetních položek pro
     * sekci „Volitelné" panelu (D18: jednorázová akce, ne provisioner;
     * D19: není to alert — žádný check, žádná položka checklistu).
     *
     * Sada se vybírá podle varianty osnovy, NE filtrem podle existence
     * čísel — obě osnovy používají stejná čísla pro jiné účty (548100 =
     * Ostatní provozní náklady versus Manka a škody).
     */
    public function accountingItemsOffer(AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $variant = $this->accountChartVariant();
        $reason  = $this->accountingItemsUnavailableReason($variant);
        if ($reason !== null) {
            return Response::success([
                'available'         => false,
                'chartVariant'      => $variant,
                'groups'            => [],
                'candidates'        => [],
                'unavailableReason' => $reason,
            ]);
        }

        $seed = $this->loadAccountingItemsSeed((string) $variant);
        if ($seed === null) {
            return Response::error('INTERNAL_ERROR', 'Accounting items seed file not found', 500);
        }

        $existing = $this->existingItemCodes(array_keys($seed['items']));

        $candidates = [];
        $usedGroups = [];
        foreach ($seed['items'] as $code => $entry) {
            // Klíč pole: PHP numerický stringový kód (568201) drží jako int.
            $code         = (string) $code;
            $group        = (string) ($entry['group'] ?? '');
            $candidates[] = [
                'code'          => $code,
                'name'          => $this->seedName($entry, $code),
                'accountNumber' => (string) ($entry['account'] ?? ''),
                'group'         => $group,
                'exists'        => isset($existing[$code]),
            ];
            $usedGroups[$group] = true;
        }

        // Jen skupiny s aspoň jedním kandidátem — prázdná sekce v UI nemá co
        // říct. Neznámou skupinu kandidáta klient zobrazí v sekci „Ostatní".
        $groups = [];
        foreach ($seed['groups'] as $entry) {
            $id = (string) $entry['id'];
            if (!isset($usedGroups[$id])) {
                continue;
            }
            $groups[] = [
                'id'    => $id,
                'name'  => $this->localizedField($entry, 'name', $id),
                'order' => (int) ($entry['order'] ?? 0),
            ];
        }
        usort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return Response::success([
            'available'         => true,
            'chartVariant'      => $variant,
            'groups'            => $groups,
            'candidates'        => $candidates,
            'unavailableReason' => null,
        ]);
    }

    /**
     * POST /_setup/accounting-items — body {"codes": ["UP-BANK", ...]}.
     * Jednorázově vygeneruje vybrané účetní položky. Každá jde přes
     * ItemDocument (TableGateway) — item_type = 2 denormalizuje beforeSave
     * z druhu, kód i účet projdou stejnou validací jako u ručního pořízení.
     *
     * Existující kód → skipped (opakované generování je bezpečné); účet
     * ze seedu v osnově chybí → skipped s důvodem (položka s prázdným
     * účtem by v acc.entry tiše nefungovala).
     */
    public function generateAccountingItems(Request $request, AuthContext $auth): Response
    {
        if (!$auth->isAuthenticated) {
            return Response::error('UNAUTHORIZED', 'Authentication required', 401);
        }

        $body  = $request->getBody();
        $codes = $body['codes'] ?? null;
        if (!is_array($codes) || $codes === [] || $codes !== array_filter($codes, is_string(...))) {
            return Response::error('BAD_REQUEST', '`codes` must be a non-empty list of strings', 400);
        }
        $codes = array_values(array_unique($codes));

        $variant = $this->accountChartVariant();
        $reason  = $this->accountingItemsUnavailableReason($variant);
        if ($reason !== null) {
            return Response::error('OFFER_UNAVAILABLE', "Accounting items offer is not available: {$reason}", 409);
        }

        $seed = $this->loadAccountingItemsSeed((string) $variant);
        if ($seed === null) {
            return Response::error('INTERNAL_ERROR', 'Accounting items seed file not found', 500);
        }

        // Generátor jedné položky je sdílená služba (content-tag-ui D26) —
        // tady jen orchestrace requestu: prerekvizity hlasitě, per kód
        // skip/created, save selhání fail-fast.
        $materializer = $this->itemMaterializer();
        $prereq = $materializer->missingPrerequisite();
        if ($prereq === 'item_kind_missing') {
            return Response::error(
                'ITEM_KIND_MISSING',
                "Item kind with system_code 'accounting' not found — run ds-upgrade first",
                409,
            );
        }
        if ($prereq === 'unit_missing') {
            return Response::error(
                'UNIT_MISSING',
                "Unit with system_code 'pcs' not found — run ds-upgrade first",
                409,
            );
        }

        $created = [];
        $skipped = [];
        foreach ($codes as $code) {
            $result = $materializer->materializeOfferCode($code);
            if ($result['status'] === 'created') {
                $created[] = ['id' => $result['id'], 'code' => $result['code'], 'name' => $result['name']];
                continue;
            }
            if ($result['status'] === 'skipped') {
                $skip = ['code' => $code, 'reason' => $result['reason']];
                if (isset($result['accountNumber'])) {
                    $skip['accountNumber'] = $result['accountNumber'];
                }
                $skipped[] = $skip;
                continue;
            }
            ErrorLogger::error('SetupController: accounting item generation failed', [
                'code'    => $code,
                'message' => $result['message'] ?? $result['reason'],
            ]);
            return Response::error(
                'SAVE_FAILED',
                "Saving accounting item {$code} failed: " . ($result['message'] ?? $result['reason']),
                500,
            );
        }

        return Response::success(['created' => $created, 'skipped' => $skipped]);
    }

    /**
     * Sdílený generátor jedné účetní položky — zápis jde přes seam
     * {@see saveItemRow()}, aby testovací subclassy fungovaly beze změny.
     */
    private function itemMaterializer(): AccountingItemMaterializer
    {
        return new AccountingItemMaterializer(
            db: $this->db,
            offer: $this->itemsOffer(),
            language: $this->language,
            config: $this->config,
            tables: $this->tables,
            dsConfig: $this->dsConfig,
            documentRegistry: $this->documentRegistry,
            eventDispatcher: $this->eventDispatcher,
            saveItem: fn (array $payload): DocumentResult => $this->saveItemRow($payload),
        );
    }

    /** Hodnota economy.accountChart, nebo null = nerozhodnuto. */
    private function accountChartVariant(): ?string
    {
        return $this->itemsOffer()->variant();
    }

    /** Sdílená čtečka nabídky účetních položek (memoizovaně). */
    private function itemsOffer(): AccountingItemsOffer
    {
        return $this->accountingItemsOfferCache
            ??= new AccountingItemsOffer($this->db, $this->modulePathResolver);
    }

    /**
     * Proč nabídka účetních položek není dostupná; null = dostupná.
     * accounting_inactive: sloupec accounting_account je extension
     * z economy.accounting — bez aktivního modulu neexistuje a položky
     * by neměly kam dostat účet.
     */
    private function accountingItemsUnavailableReason(?string $variant): ?string
    {
        if ($variant === null) {
            return 'chart_undecided';
        }
        if ($variant === 'none') {
            return 'chart_none';
        }

        $def = $this->tables[self::ITEMS_TABLE] ?? null;
        foreach ($def?->columns ?? [] as $column) {
            if ($column->id === 'accounting_account') {
                return null;
            }
        }
        return 'accounting_inactive';
    }

    /**
     * Seed sady podle varianty osnovy — deleguje na sdílenou čtečku
     * {@see AccountingItemsOffer::loadSeed()} (tvar {groups, items},
     * items klíčované kódem položky).
     *
     * @return array{groups: list<array<string, mixed>>, items: array<string, array<string, mixed>>}|null
     *         null = soubor chybí / nečitelný / bez items
     */
    private function loadAccountingItemsSeed(string $variant): ?array
    {
        return $this->itemsOffer()->loadSeed($variant);
    }

    /**
     * Kódy z $codes, které už v economy_items existují (unq_code) → set.
     *
     * @param list<string> $codes
     * @return array<string, true>
     */
    private function existingItemCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $set = [];
        $rows = $this->db->fetchAll(
            'SELECT code FROM ' . self::ITEMS_TABLE . ' WHERE code IN %in',
            $codes,
        );
        foreach ($rows as $row) {
            $set[(string) ($row['code'] ?? '')] = true;
        }
        return $set;
    }

    /**
     * Lokalizovaný název ze seed záznamu — fallback chain jazyk → en → holé
     * pole (stejně jako ConfigLocalizer).
     *
     * @param array<string, mixed> $entry
     */
    private function seedName(array $entry, string $code): string
    {
        return $this->localizedField($entry, 'name', $code);
    }

    /**
     * Lokalizované pole z JSONC záznamu — `{base}:{jazyk}` → `{base}:en` →
     * `{base}` → $fallback (stejný chain jako ConfigLocalizer).
     *
     * @param array<string, mixed> $entry
     */
    private function localizedField(array $entry, string $base, string $fallback): string
    {
        return AccountingItemsOffer::localizedField($entry, $base, $this->language, $fallback);
    }

    /**
     * Aktivní vlastní Osoba (is_own, docState 10/40) — memoizovaně; jeden
     * request se na ni ptá z více míst (suggestion, panelové akce, prefill,
     * můstek). null = žádná není.
     *
     * @return array{id: int|string, full_name: ?string, vat_id: ?string}|null
     */
    private function ownPerson(): ?array
    {
        if ($this->ownPersonCache === null) {
            $row = $this->db->fetchRow(
                'SELECT id, full_name, vat_id FROM base_persons_persons'
                    . ' WHERE is_own = %i AND docState IN %in ORDER BY id LIMIT 1',
                1,
                self::ACTIVE_DOC_STATES,
            );
            $this->ownPersonCache = $row ?? false;
        }
        return $this->ownPersonCache === false ? null : $this->ownPersonCache;
    }

    /** @return list<array<string, mixed>> aktivní bankovní spojení vlastní Osoby */
    private function ownPersonBankAccounts(int $personId): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, account_number, iban, bic, currency, source,'
                . ' order_pos, valid_from, valid_to'
                . ' FROM base_persons_bank_accounts'
                . ' WHERE person = %i AND docState IN %in'
                . ' ORDER BY (order_pos IS NULL), order_pos, id',
            $personId,
            self::ACTIVE_DOC_STATES,
        );
    }

    /**
     * Klíče aktivních řádků číselníku pro detekci „už překlopeno":
     * množina IBANů (uppercase) a čísel účtů.
     *
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    private function existingCodebookKeys(): array
    {
        $ibans   = [];
        $numbers = [];
        $rows = $this->db->fetchAll(
            'SELECT iban, account_number FROM ' . self::CODEBOOK_TABLE
                . ' WHERE docState IN %in',
            self::ACTIVE_DOC_STATES,
        );
        foreach ($rows as $row) {
            $iban   = strtoupper(trim((string) ($row['iban'] ?? '')));
            $number = trim((string) ($row['account_number'] ?? ''));
            if ($iban !== '') {
                $ibans[$iban] = true;
            }
            if ($number !== '') {
                $numbers[$number] = true;
            }
        }
        return [$ibans, $numbers];
    }

    /**
     * Match bankovního spojení proti číselníku: primárně IBAN, bez něj
     * číslo účtu. Spojení bez obojího (nemělo by projít validací Osoby)
     * se považuje za nepřeklopené.
     *
     * @param array<string, mixed> $row řádek base_persons_bank_accounts
     * @param array<string, true> $codebookIbans
     * @param array<string, true> $codebookNumbers
     */
    private function existsInCodebook(array $row, array $codebookIbans, array $codebookNumbers): bool
    {
        $iban = strtoupper(trim((string) ($row['iban'] ?? '')));
        if ($iban !== '') {
            return isset($codebookIbans[$iban]);
        }
        $number = trim((string) ($row['account_number'] ?? ''));
        return $number !== '' && isset($codebookNumbers[$number]);
    }

    /**
     * Krátké sekvenční kódy BU1, BU2, … s posunem přes existující kódy
     * (kolize řeší posun sekvence, ne selhání). Číselník žádnou konvenci
     * kódů nemá — seedy jiných číselníků mají fixní kódy a
     * NumberSeriesProvisioner řeší řady dokladů, ne kódy záznamů.
     *
     * @return list<string>
     */
    private function generateCodes(int $count): array
    {
        $existing = [];
        foreach ($this->db->fetchAll('SELECT code FROM ' . self::CODEBOOK_TABLE) as $row) {
            $existing[strtoupper(trim((string) ($row['code'] ?? '')))] = true;
        }

        $codes = [];
        $n = 1;
        while (count($codes) < $count) {
            $candidate = 'BU' . $n++;
            if (!isset($existing[$candidate])) {
                $codes[] = $candidate;
                $existing[$candidate] = true;
            }
        }
        return $codes;
    }

    /**
     * Mapování řádku base_persons_bank_accounts → economy_codebooks_bank_accounts.
     * bank_name zůstává null (v bankovních spojeních Osoby neexistuje a název
     * banky z kódu banky se nedopočítává — číselník bank není). Normalizaci
     * měny/IBAN/BIC dotáhne BankAccountDocument::beforeSave, tady jen
     * fallbacky pro not null sloupce.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function codebookPayload(array $row, string $code, int $sortOrder, bool $isDefault): array
    {
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            // Název banky není z čeho vzít — poslední čtyřčíslí účtu je
            // nejkratší identifikace, kterou uživatel pozná.
            $digits = preg_replace('/\D/', '', (string) ($row['account_number'] ?? ''));
            if ($digits === null || $digits === '') {
                $digits = preg_replace('/\D/', '', (string) ($row['iban'] ?? '')) ?? '';
            }
            $tail = $digits !== '' ? substr($digits, -4) : $code;
            $name = ($this->language === 'cs' ? 'Účet …' : 'Account …') . $tail;
        }

        $currency = strtolower(trim((string) ($row['currency'] ?? '')));

        return [
            'code'           => $code,
            'name'           => mb_substr($name, 0, 150),
            'account_number' => trim((string) ($row['account_number'] ?? '')),
            'iban'           => trim((string) ($row['iban'] ?? '')),
            'bic'            => trim((string) ($row['bic'] ?? '')),
            'currency'       => $currency === '' ? 'czk' : $currency,
            'is_default'     => $isDefault ? 1 : 0,
            'valid_from'     => $row['valid_from'] ?? null,
            'valid_to'       => $row['valid_to'] ?? null,
            'sort_order'     => $sortOrder,
            // Data z registru / evidence Osoby — rovnou V pořádku,
            // stejně jako targetDocState registrového importu (Task 08).
            'docState'       => 40,
        ];
    }

    /**
     * Uložení jednoho řádku číselníku přes Document flow. Protected seam
     * pro testy (subclassing, vzor TestableDsCreateCommand) — TableGateway
     * potřebuje živé dibi spojení, které unit test nemá.
     */
    protected function saveBankAccountRow(array $payload): DocumentResult
    {
        $gateway = $this->buildGateway(self::CODEBOOK_TABLE);
        if ($gateway === null) {
            return DocumentResult::error('Table definition or document registry unavailable');
        }
        return $gateway->saveDocument($payload);
    }

    /** Uložení jedné položky přes ItemDocument — stejný seam vzor. */
    protected function saveItemRow(array $payload): DocumentResult
    {
        $gateway = $this->buildGateway(self::ITEMS_TABLE);
        if ($gateway === null) {
            return DocumentResult::error('Table definition or document registry unavailable');
        }
        return $gateway->saveDocument($payload);
    }

    /** Paralela k AnalysisController::buildHeadsGateway(). */
    private function buildGateway(string $table): ?TableGateway
    {
        $def = $this->tables[$table] ?? null;
        if ($def === null || $this->documentRegistry === null) {
            return null;
        }
        return new TableGateway(
            $table,
            $this->db->getDibiConnection(),
            $this->documentRegistry,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            $this->eventDispatcher,
            $def->docStates,
            $def,
        );
    }

    /**
     * Options frekvencí DPH z cfgItem vatPeriodKinds — obsahuje jen 1/2
     * (Měsíční/Čtvrtletní), rezervovaná 0 v cfgItem není, takže se do
     * nabídky nedostane.
     *
     * @return list<array{value: int|string, label: string}>
     */
    private function periodKindOptions(): array
    {
        $cfg = $this->config->cfgItem('economy.codebooks.vatPeriodKinds');
        if (!is_array($cfg)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfg, 'enumInt', 'economy.codebooks.vatPeriodKinds');
    }

    /**
     * @return array{items: list<array<string, mixed>>, parameters: array<string, mixed>,
     *               currencyOptions: list<array{value: int|string, label: string}>}
     */
    private function buildState(SettingsStore $settings): array
    {
        $checklist = new SetupChecklist($this->db, $this->registry, $this->config, $this->language);

        $items = [];
        foreach ($checklist->collect() as $item) {
            $finding = $item['finding'];
            $items[] = [
                'checkId'   => $item['checkId'],
                'name'      => $item['name'],
                'title'     => $finding->title,
                'message'   => $finding->message,
                'severity'  => $finding->severity,
                // Panelové akce se skládají TADY, ne v checku — finding
                // checku putuje cronem do core_alerts_alerts a feed/viewer
                // alertů umí jen open_form/open_viewer/open_panel.
                'actions'   => $this->panelActions($item['checkId'], $finding->actions),
                // U položek nad nerozhodnutým parametrem klíč vrstvy C —
                // panel podle něj vykreslí ovládání. Mapování drží server.
                'parameter' => SetupChecklist::PARAMETER_BY_CHECK[$item['checkId']] ?? null,
            ];
        }

        $items = $this->attachVatAgendaSuggestion($items);

        return [
            'items'           => $items,
            // Hodnoty VŠECH klíčů průvodce včetně null — panel potřebuje
            // i rozhodnuté parametry, aby šly změnit, ne jen doplnit.
            // Volitelné parametry (řada dokladu přiznání DPH) do průvodce
            // nepatří — nastavují se přes ds-setting, až když jsou potřeba.
            'parameters'      => $settings->getMany(LayerCParameters::setupKeys()),
            'currencyOptions' => $this->currencyOptions(),
        ];
    }

    /**
     * Panelové úpravy akcí položky (docs/ds-setup.md §5.4). Vybrané checky
     * dostanou předřazenou primární akci s kindem, který umí obsloužit
     * jedině panel — proto vzniká jen tady, ne v checku: finding checku
     * putuje cronem do core_alerts_alerts a ve feedu/vieweru alertů by
     * neznámý kind skončil s console.warn. Akce z checku (open_form) se
     * degradují na sekundární „Zadat ručně".
     *
     *   - missing_own_person       → registry_import_own (Task 08, D16)
     *   - missing_vat_registration → prefill_vat_registration (Task 09);
     *     jen s vlastní Osobou — bez ní není z čeho předvyplnit
     *   - missing_own_bank_account → bridge_bank_accounts (Task 09, D17);
     *     jen když má vlastní Osoba aspoň jedno bankovní spojení
     *
     * @param list<array<string, mixed>> $checkActions
     * @return list<array<string, mixed>>
     */
    private function panelActions(string $checkId, array $checkActions): array
    {
        $isCs = $this->language === 'cs';

        switch ($checkId) {
            case 'base.persons.missing_own_person':
                return $this->withPrimaryPanelAction($checkActions, [
                    'id'      => 'import_own_person_from_registry',
                    'label'   => $isCs ? 'Načíst z registru' : 'Load from registry',
                    'kind'    => 'registry_import_own',
                    'target'  => [],
                    'primary' => true,
                ]);

            case 'economy.codebooks.missing_vat_registration':
                if ($this->ownPerson() === null) {
                    return $checkActions;
                }
                return $this->withPrimaryPanelAction($checkActions, [
                    'id'      => 'prefill_vat_registration',
                    'label'   => $isCs ? 'Založit Registraci DPH' : 'Add VAT registration',
                    'kind'    => 'prefill_vat_registration',
                    'target'  => [],
                    'primary' => true,
                ]);

            case 'economy.codebooks.missing_own_bank_account':
                $own = $this->ownPerson();
                if ($own === null || $this->ownPersonBankAccounts((int) $own['id']) === []) {
                    return $checkActions;
                }
                return $this->withPrimaryPanelAction($checkActions, [
                    'id'      => 'bridge_bank_accounts',
                    'label'   => $isCs ? 'Převzít z vlastní Osoby' : 'Take over from own Person',
                    'kind'    => 'bridge_bank_accounts',
                    'target'  => [],
                    'primary' => true,
                ]);
        }

        return $checkActions;
    }

    /**
     * Předřadí panelovou primární akci; akce z checku degraduje na
     * sekundární „Zadat ručně" (ruční cesta zůstává dostupná vždy).
     *
     * @param list<array<string, mixed>> $checkActions
     * @param array<string, mixed> $primary
     * @return list<array<string, mixed>>
     */
    private function withPrimaryPanelAction(array $checkActions, array $primary): array
    {
        $isCs = $this->language === 'cs';

        $secondary = array_map(
            static fn(array $action): array => [
                'label'   => $isCs ? 'Zadat ručně' : 'Enter manually',
                'primary' => false,
            ] + $action,
            $checkActions,
        );

        return [$primary, ...$secondary];
    }

    /**
     * Návrh hodnoty `economy.vatAgenda` podle DIČ vlastní Osoby (D5:
     * přítomnost DIČ = použitelný default, ne pravda). Jen předvolba v UI —
     * `parameters` dál drží null, dokud uživatel nepotvrdí (D2).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function attachVatAgendaSuggestion(array $items): array
    {
        foreach ($items as $i => $item) {
            if ($item['checkId'] !== 'economy.codebooks.undecided_vat_agenda') {
                continue;
            }
            $vatId = trim((string) ($this->ownPerson()['vat_id'] ?? ''));
            if ($vatId === '') {
                break;
            }
            $items[$i]['suggestion'] = [
                'value'  => true,
                'reason' => $this->language === 'cs'
                    ? "Vlastní Osoba má vyplněné DIČ {$vatId} — pravděpodobně jste plátce DPH."
                    : "The own Person has VAT ID {$vatId} filled in — you are probably a VAT payer.",
            ];
            break;
        }

        return $items;
    }

    /**
     * Options pro select domácí měny — cfgItem world.base.currencies žije
     * v compiled configu, frontend seznam měn nezná (a nemá hardcodovat).
     *
     * @return list<array{value: int|string, label: string}>
     */
    private function currencyOptions(): array
    {
        $cfg = $this->config->cfgItem('world.base.currencies');
        if (!is_array($cfg)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfg, 'enumString', 'world.base.currencies');
    }

    /**
     * Okamžitý běh provisionerů dotčených zapsanými klíči — bez něj by
     * uživatel parametr rozhodl a nic by se nestalo až do dalšího
     * ds-upgrade (D12). Selhání provisioneru parametr NEODUKLÁDÁ:
     * uloženo-a-neprovisionováno dorovná ds-upgrade, opačný stav je horší.
     *
     * @param list<string> $writtenKeys
     * @return list<string> lidsky čitelná varování pro panel
     */
    private function runProvisioners(array $writtenKeys, SettingsStore $settings): array
    {
        if ($this->dsConfig?->shouldSkipProvisioning() === true) {
            // Provisionery by běžely jen pro tyhle klíče — bez nich by varování
            // bylo šum (samotné vatAgenda žádný provisioner nespouští).
            $triggering = ['economy.accountChart', 'economy.fiscalYearStartMonth', 'economy.homeCurrency'];
            if (array_intersect($writtenKeys, $triggering) !== []) {
                return [$this->warnProvisioningDisabled()];
            }
            return [];
        }

        $warnings = [];

        if (in_array('economy.accountChart', $writtenKeys, true)) {
            $warnings = [...$warnings, ...$this->provisionAccountChart($settings)];
        }

        if (in_array('economy.fiscalYearStartMonth', $writtenKeys, true)
            || in_array('economy.homeCurrency', $writtenKeys, true)) {
            $warnings = [...$warnings, ...$this->provisionFiscalYears($settings)];
        }

        return $warnings;
    }

    /** @return list<string> */
    private function provisionAccountChart(SettingsStore $settings): array
    {
        // none = vlastní osnova (neseeduje se), null = vráceno do nerozhodnuto.
        $variant = $settings->get('economy.accountChart');
        $file = match ($variant) {
            'default' => 'accountChartDefault.jsonc',
            'npo'     => 'accountChartNpo.jsonc',
            default   => null,
        };
        if ($file === null) {
            return [];
        }

        // Stejná cesta k seed souboru jako DsUpgradeCommand::provisionAccountChart.
        $modulePath = $this->modulePathResolver->getPath('economy.accounting');
        $seedFile   = ($modulePath ?? '') . '/config/' . $file;
        if ($modulePath === null || !is_file($seedFile)) {
            ErrorLogger::error('SetupController: account chart seed file not found', ['file' => $file]);
            return [$this->warnProvisionerFailed('accountChart')];
        }

        try {
            (new AccountChartProvisioner($this->db, $seedFile))->provision();
        } catch (\Throwable $e) {
            ErrorLogger::error('SetupController: account chart provisioning failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return [$this->warnProvisionerFailed('accountChart')];
        }
        return [];
    }

    /** @return list<string> */
    private function provisionFiscalYears(SettingsStore $settings): array
    {
        // Gate na OBA klíče — stejná logika jako DsUpgradeCommand (D6).
        $startMonth = $settings->get('economy.fiscalYearStartMonth');
        $currency   = $settings->get('economy.homeCurrency');
        if ($startMonth === null || !is_string($currency) || $currency === '') {
            return [];
        }

        try {
            (new FiscalYearsProvisioner($this->db, $this->config, (int) $startMonth, $currency))->provision();
        } catch (\Throwable $e) {
            ErrorLogger::error('SetupController: fiscal years provisioning failed', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
            return [$this->warnProvisionerFailed('fiscalYears')];
        }
        return [];
    }

    private function warnProvisioningDisabled(): string
    {
        return $this->language === 'cs'
            ? 'Provisioning je na tomto zdroji dat vypnutý (skipProvisioning) — parametry jsou uložené, seed proběhne až po jeho zapnutí přes ds-upgrade.'
            : 'Provisioning is disabled on this data source (skipProvisioning) — the parameters are saved; seeding will run once it is re-enabled via ds-upgrade.';
    }

    private function warnProvisionerFailed(string $what): string
    {
        $isCs = $this->language === 'cs';
        $label = match ($what) {
            'accountChart' => $isCs ? 'účtové osnovy' : 'account chart',
            default        => $isCs ? 'fiskálních roků' : 'fiscal years',
        };
        return $isCs
            ? "Parametr je uložený, ale naseedování {$label} selhalo — dorovná ho příští ds-upgrade."
            : "The parameter was saved, but seeding of the {$label} failed — the next ds-upgrade will catch up.";
    }
}
