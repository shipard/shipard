<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Import;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\Assets\AssetCategories;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\AssetEventDocument;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;

/**
 * Import karty majetku s hodnotovou historií — formát `shpd.assets.asset.v1`
 * (docs/assets.md D74, D75, D79; §5.7). Jeden request = jedna karta.
 *
 * Párování podle inventárního čísla přes všechny stavy karty (unikátní
 * index). Nová karta se založí; existující dostane přepsanou hlavičku
 * a všechny její události původu `import` se nahradí (fyzicky smažou
 * a vloží znovu). Karta s ručními nebo systémovými událostmi se přeskočí
 * (`skipped`, varování `asset_has_local_events`) — nic se nemění.
 *
 * Zápis jde přes `AssetDocument` a `AssetEventDocument` s markerem
 * importního módu (`_import`): dokument nastaví původ `import`, nevolá
 * lock providery a uvolní validace, které historická data splnit nemohou
 * (§5.7). Potvrzené vyřazení v importu nezakládá poslední odpisy — ty
 * posílá runner. Pořadí: smazání importovaných událostí → hlavička →
 * události chronologicky (datum, pořadí druhu v rámci dne, pořadí
 * v payloadu). Data pořízení a vyřazení dlouhodobé karty dosazují
 * události (D38); potvrzené vyřazení kartu samo přesune do archivu.
 *
 * Dlouhodobá karta bez úplné účetní skupiny se uloží jako koncept
 * s varováním `accounting_group_incomplete` (D57, D79); její události
 * jsou přesto potvrzené, aby karta po doplnění skupiny rovnou běžela.
 * Chyba plánu po uložení importu nebrání — vrátí se jako varování
 * `plan_error`.
 *
 * `validate` = celý průběh v transakci s rollbackem (vzor
 * `vat-filing-import --dry-run`): tvarová i kontextová pravidla událostí
 * závisejí na uložené kartě a sourozencích, dry-run proto dává totožný
 * výsledek jako `apply`. Celá karta je jedna transakce.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class AssetImportApplier
{
    public const FORMAT_ID = 'shpd.assets.asset';
    public const FORMAT_VERSION = '1';

    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_SKIPPED = 'skipped';

    public const WARNING_LOCAL_EVENTS = 'asset_has_local_events';
    public const WARNING_GROUP_INCOMPLETE = 'accounting_group_incomplete';
    public const WARNING_ARCHIVED_WITHOUT_DISPOSAL = 'archived_without_disposal';
    public const WARNING_PLAN_ERROR = 'plan_error';

    public const STATE_DRAFT = 10;

    /** Klíč payloadu → sloupec karty. */
    private const HEAD_COLUMNS = [
        'assetNumber'     => 'asset_number',
        'name'            => 'name',
        'shortName'       => 'short_name',
        'note'            => 'note',
        'type'            => 'asset_type',
        'category'        => 'category',
        'tracking'        => 'tracking',
        'accountingGroup' => 'accounting_group',
        'foreign'         => 'is_foreign',
        'owner'           => 'owner',
        'acquiredDate'    => 'acquired_date',
        'disposedDate'    => 'disposed_date',
        'price'           => 'price',
        'taxMethod'       => 'tax_method',
        'taxRule'         => 'tax_rule',
        'accMethod'       => 'acc_method',
        'accMonths'       => 'acc_months',
        'state'           => 'docState',
    ];

    /** Klíč události payloadu → sloupec události. */
    private const EVENT_COLUMNS = [
        'kind'            => 'event_kind',
        'scope'           => 'scope',
        'date'            => 'event_date',
        'periodBegin'     => 'period_begin',
        'periodEnd'       => 'period_end',
        'amount'          => 'amount',
        'claimUnrecorded' => 'claim_unrecorded',
        'halfYear'        => 'half_year',
        'priceIncreased'  => 'price_increased',
        'note'            => 'note',
    ];

    private const STATES = [
        'confirmed' => AssetDocument::STATE_CONFIRMED,
        'archived'  => AssetDocument::STATE_ARCHIVED,
    ];

    /** Odkazy payloadu: klíč → [tabulka, kód chyby]. */
    private const REFERENCES = [
        'type'            => ['economy_assets_types', 'type_not_found'],
        'accountingGroup' => ['economy_assets_accounting_groups', 'accounting_group_not_found'],
        'owner'           => ['base_persons_persons', 'owner_not_found'],
    ];

    private ?SettingsStore $settings = null;

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly ?DataSourceConfig $dsConfig,
        protected readonly ?DocumentRegistry $documents,
        protected readonly array $tables,
        private readonly SchemaValidator $schemaValidator,
    ) {
    }

    /** @param array<string, TableDefinition> $tables */
    public static function create(
        \Dibi\Connection $db,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
        ?DocumentRegistry $documents,
        array $tables,
    ): self {
        return new self($db, $config, $dsConfig, $documents, $tables, new SchemaValidator(SchemaLoader::default()));
    }

    /**
     * Kontrola bez trvalého zápisu — průběh `apply` s rollbackem.
     *
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): AssetImportResult
    {
        return $this->run($payload, true);
    }

    /**
     * Status 201 = karta založena, 200 = přepsána nebo přeskočena.
     *
     * @param array<string, mixed> $payload
     */
    public function apply(array $payload): AssetImportResult
    {
        return $this->run($payload, false);
    }

    /** @param array<string, mixed> $payload */
    private function run(array $payload, bool $dryRun): AssetImportResult
    {
        $schemaIssues = $this->schemaValidator->validate($payload, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return AssetImportResult::error(
                'schema_invalid',
                'Struktura karty neodpovídá schématu.',
                $this->withSourceRefs($schemaIssues, $payload),
                400,
            );
        }
        if ($this->documents === null
            || !isset($this->tables[AssetDocument::TABLE], $this->tables[AssetEventDocument::TABLE])
        ) {
            return AssetImportResult::error('assets_unavailable', 'Majetek není na tomto zdroji dat dostupný — spusťte ds-upgrade.', [], 500);
        }
        $issues = $this->referenceIssues($payload);
        if ($issues !== []) {
            return AssetImportResult::error('validation_failed', 'Validace karty selhala.', $issues, 422);
        }

        $this->begin();
        try {
            $result = $this->import($payload);
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
        // Přeskočená karta nic nezapsala — commit jen po skutečném zápisu.
        if ($result->success && !$dryRun && $result->status !== self::STATUS_SKIPPED) {
            $this->commit();
        } else {
            $this->rollback();
        }
        return $result;
    }

    // ── Průběh importu ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function import(array $payload): AssetImportResult
    {
        $asset = $payload['asset'];
        $number = trim((string) $asset['assetNumber']);
        $existing = $this->findCard($number);
        $existingId = $existing !== null ? (int) $existing['id'] : null;

        if ($existingId !== null && $this->hasLocalEvents($existingId)) {
            return AssetImportResult::ok(self::STATUS_SKIPPED, $existingId, [[
                'code'    => self::WARNING_LOCAL_EVENTS,
                'message' => "Karta {$number} má ruční nebo systémové události — import ji nemění.",
                'path'    => 'asset.assetNumber',
            ]]);
        }

        $categories = new AssetCategories($this->config);
        $category = (string) $asset['category'];
        $longTerm = $categories->isLongTerm($category);
        $targetState = self::STATES[(string) $asset['state']];
        $warnings = [];

        // D57, D79: dlouhodobá karta bez úplné účetní skupiny jde jen jako koncept.
        $group = !empty($asset['accountingGroup']) ? $this->loadGroupAccounts((int) $asset['accountingGroup']) : null;
        $groupIncomplete = $longTerm && ($group === null || ($categories->isDepreciable($category)
            && (empty($group['account_depreciation']) || empty($group['account_accumulated']))));
        if ($groupIncomplete) {
            $warnings[] = [
                'code'    => self::WARNING_GROUP_INCOMPLETE,
                'message' => $group === null
                    ? 'Dlouhodobý majetek bez účetní skupiny — karta je uložena jako koncept.'
                    : 'Účetní skupina nemá účet odpisů a oprávek — karta je uložena jako koncept.',
                'path'    => 'asset.accountingGroup',
            ];
        }

        if ($existingId !== null) {
            $this->deleteImportedEvents($existingId);
            if ($longTerm) {
                // Data pořízení a vyřazení dosadí znovu události (D38); bez
                // vynulování by přepis hlavičky vyřazené karty narazil na
                // `disposedAssetActive`.
                $this->resetCardDates($existingId);
            }
        }

        $head = $this->headRow($asset, $longTerm);
        // Dlouhodobou kartu do archivu přesune až potvrzené vyřazení (D38).
        $head['docState'] = $longTerm ? ($groupIncomplete ? self::STATE_DRAFT : AssetDocument::STATE_CONFIRMED) : $targetState;
        $head[AssetDocument::IMPORT_KEY] = true;
        if ($existingId !== null) {
            $head['id'] = $existingId;
        }
        $saved = $this->saveCard($head);
        if (!$saved->isSuccess()) {
            return $this->failure('Validace karty selhala.', $saved, 'asset', self::HEAD_COLUMNS, null);
        }
        $assetId = $existingId ?? (int) ($saved->getData()['id'] ?? 0);

        $hasDisposal = false;
        foreach ($this->chronological($payload['events']) as $index) {
            $event = $payload['events'][$index];
            $saved = $this->saveEvent($this->eventRow($event, $assetId));
            if (!$saved->isSuccess()) {
                return $this->failure(
                    'Validace události selhala.',
                    $saved,
                    "events.{$index}",
                    self::EVENT_COLUMNS,
                    isset($event['sourceRef']) ? (string) $event['sourceRef'] : null,
                );
            }
            $hasDisposal = $hasDisposal || (string) $event['kind'] === AssetEvent::KIND_DISPOSAL;
        }

        if ($longTerm && $targetState === AssetDocument::STATE_ARCHIVED && !$hasDisposal) {
            $warnings[] = [
                'code'    => self::WARNING_ARCHIVED_WITHOUT_DISPOSAL,
                'message' => 'Vyřazená karta bez události vyřazení — zůstává V pořádku; do archivu ji přesune až vyřazení.',
                'path'    => 'asset.state',
            ];
        }
        foreach ($this->planWarnings($assetId) as $warning) {
            $warnings[] = $warning;
        }

        return AssetImportResult::ok(
            $existingId !== null ? self::STATUS_UPDATED : self::STATUS_CREATED,
            $assetId,
            $warnings,
            $existingId !== null ? 200 : 201,
        );
    }

    /**
     * Řádek karty z payloadu. Dlouhodobý majetek data pořízení a vyřazení
     * i cenu nenese — vznikají z událostí (D13, D38).
     *
     * @param array<string, mixed> $asset
     * @return array<string, mixed>
     */
    private function headRow(array $asset, bool $longTerm): array
    {
        $row = [];
        foreach (self::HEAD_COLUMNS as $key => $column) {
            if ($key !== 'state' && array_key_exists($key, $asset)) {
                $row[$column] = $asset[$key];
            }
        }
        $row['asset_number'] = trim((string) $asset['assetNumber']);
        $row['is_foreign'] = !empty($asset['foreign']) ? 1 : 0;
        $row['price'] = isset($asset['price']) && !$longTerm ? $asset['price'] : null;
        if ($longTerm) {
            $row['acquired_date'] = null;
            $row['disposed_date'] = null;
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function eventRow(array $event, int $assetId): array
    {
        $row = ['asset' => $assetId];
        foreach (self::EVENT_COLUMNS as $key => $column) {
            if (array_key_exists($key, $event)) {
                $row[$column] = $event[$key];
            }
        }
        foreach (['claim_unrecorded', 'half_year', 'price_increased'] as $flag) {
            $row[$flag] = !empty($row[$flag]) ? 1 : 0;
        }
        $row['docState'] = AssetEventDocument::STATE_CONFIRMED;
        $row[AssetEventDocument::IMPORT_KEY] = true;
        return $row;
    }

    /**
     * Indexy událostí payloadu chronologicky: datum, pořadí druhu v rámci
     * dne, pořadí v payloadu.
     *
     * @param list<array<string, mixed>> $events
     * @return list<int>
     */
    private function chronological(array $events): array
    {
        $keys = [];
        foreach ($events as $index => $event) {
            $keys[$index] = [(string) $event['date'], AssetEvent::kindOrder((string) $event['kind']), $index];
        }
        uasort($keys, static fn(array $a, array $b): int => $a <=> $b);
        return array_keys($keys);
    }

    /**
     * Odmítnutý zápis dokumentu → nálezy s cestou do payloadu.
     *
     * @param array<string, string> $columns klíč payloadu → sloupec
     */
    private function failure(string $message, DocumentResult $saved, string $prefix, array $columns, ?string $sourceRef): AssetImportResult
    {
        $keys = array_flip($columns);
        $issues = [];
        $validation = $saved->getValidation();
        if ($validation !== null) {
            foreach ($validation->getErrors() as $error) {
                $column = $error->column;
                $path = $column === ValidationError::FIELD_FORM ? $prefix : $prefix . '.' . ($keys[$column] ?? $column);
                $issues[] = $this->issue('error', $path, $error->code !== '' ? $error->code : 'invalid', $error->message, $sourceRef);
            }
        } else {
            $issues[] = $this->issue(
                'error',
                $prefix,
                $saved->getDomainErrorCode() ?: 'error',
                (string) ($saved->getErrorMessage() ?? 'Uložení selhalo.'),
                $sourceRef,
            );
        }
        return AssetImportResult::error('validation_failed', $message, $issues, 422);
    }

    /**
     * Existence odkazovaných záznamů (nová id — mapu drží runner).
     *
     * @param array<string, mixed> $payload
     * @return list<array{severity: string, path: string, code: string, message: string}>
     */
    private function referenceIssues(array $payload): array
    {
        $issues = [];
        $asset = $payload['asset'];
        foreach (self::REFERENCES as $key => [$table, $code]) {
            $id = $asset[$key] ?? null;
            if ($id !== null && !$this->referenceExists($table, (int) $id)) {
                $issues[] = $this->issue('error', "asset.{$key}", $code, "Záznam {$table} #{$id} v tomto zdroji dat neexistuje.");
            }
        }
        $categories = new AssetCategories($this->config);
        if ($categories->isUnknown((string) $asset['category'])) {
            $issues[] = $this->issue('error', 'asset.category', 'category_unknown', 'Neznámý druh majetku.');
        }
        return $issues;
    }

    /**
     * Nálezy schématu doplněné o `sourceRef` události, do které patří.
     *
     * @param list<array{severity: string, path: string, code: string, message: string}> $issues
     * @param array<string, mixed> $payload
     * @return list<array{severity: string, path: string, code: string, message: string, sourceRef?: ?string}>
     */
    private function withSourceRefs(array $issues, array $payload): array
    {
        $out = [];
        foreach ($issues as $issue) {
            if (preg_match('/^events\.(\d+)(\.|$)/', $issue['path'], $m) === 1) {
                $ref = $payload['events'][(int) $m[1]]['sourceRef'] ?? null;
                if ($ref !== null) {
                    $issue['sourceRef'] = (string) $ref;
                }
            }
            $out[] = $issue;
        }
        return $out;
    }

    /** @return array{severity: string, path: string, code: string, message: string, sourceRef?: ?string} */
    private function issue(string $severity, string $path, string $code, string $message, ?string $sourceRef = null): array
    {
        $issue = ['severity' => $severity, 'path' => $path, 'code' => $code, 'message' => $message];
        if ($sourceRef !== null) {
            $issue['sourceRef'] = $sourceRef;
        }
        return $issue;
    }

    // ── Služby a DB přístup (přepsatelné v testech) ─────────────────────────

    protected function planService(): AssetPlanService
    {
        return new AssetPlanService(
            $this->db,
            $this->config,
            $this->dsConfig?->getCountry() ?? 'cz',
            $this->settingsStore(),
        );
    }

    private function settingsStore(): ?SettingsStore
    {
        if ($this->db === null) {
            return null;
        }
        return $this->settings ??= new SettingsStore(new \Shipard\Core\Database\DataSourceConnection($this->db));
    }

    /**
     * Chyby plánu obou okruhů po uložení (`assets.planError`) — importu
     * nebrání, karta je ukazuje jako dnes.
     *
     * @return list<array{code: string, message: string}>
     */
    protected function planWarnings(int $assetId): array
    {
        $plans = $this->planService()->planForAsset($assetId);
        if ($plans === null) {
            return [];
        }
        $texts = new PlanMessageTexts($this->config);
        $labels = [AssetEvent::SCOPE_TAX => 'Plán daňových odpisů', AssetEvent::SCOPE_ACC => 'Plán účetních odpisů'];
        $out = [];
        foreach ($plans as $circuit => $plan) {
            foreach ($plan->allMessages() as $message) {
                if ($message->isError()) {
                    $out[] = ['code' => self::WARNING_PLAN_ERROR, 'message' => ($labels[$circuit] ?? $circuit) . ': ' . $texts->text($message)];
                }
            }
        }
        return $out;
    }

    protected function gateway(string $table): TableGateway
    {
        $def = $this->tables[$table] ?? null;
        if ($this->documents === null || $def === null || $this->db === null) {
            throw new \RuntimeException("Tabulka {$table} není dostupná — spusťte ds-upgrade.");
        }
        return new TransactionlessTableGateway(
            $table,
            $this->db,
            $this->documents,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            null,
            $def->docStates,
            $def,
        );
    }

    /** @param array<string, mixed> $head */
    protected function saveCard(array $head): DocumentResult
    {
        return $this->gateway(AssetDocument::TABLE)->saveDocument($head);
    }

    /** @param array<string, mixed> $event */
    protected function saveEvent(array $event): DocumentResult
    {
        return $this->gateway(AssetEventDocument::TABLE)->saveDocument($event);
    }

    /**
     * Karta s inventárním číslem v libovolném stavu (unikátní index platí
     * přes všechny stavy).
     *
     * @return array{id: int, docState: int, category: string}|null
     */
    protected function findCard(string $number): ?array
    {
        $row = $this->db?->fetch(
            'SELECT [id], [docState], [category] FROM [' . AssetDocument::TABLE . '] WHERE [asset_number] = %s',
            $number,
        );
        return $row === null || $row === false
            ? null
            : ['id' => (int) $row['id'], 'docState' => (int) $row['docState'], 'category' => (string) $row['category']];
    }

    /** Má karta nesmazanou událost jiného původu než `import`? */
    protected function hasLocalEvents(int $assetId): bool
    {
        $row = $this->db?->fetch(
            'SELECT [id] FROM [' . AssetEventDocument::TABLE . ']'
            . ' WHERE [asset] = %i AND [origin] <> %s AND [docState] <> %i LIMIT 1',
            $assetId,
            AssetEvent::ORIGIN_IMPORT,
            AssetEventDocument::STATE_DELETED,
        );
        return $row !== null && $row !== false;
    }

    protected function deleteImportedEvents(int $assetId): void
    {
        $this->db?->query(
            'DELETE FROM [' . AssetEventDocument::TABLE . '] WHERE [asset] = %i AND [origin] = %s',
            $assetId,
            AssetEvent::ORIGIN_IMPORT,
        );
    }

    protected function resetCardDates(int $assetId): void
    {
        $this->db?->query(
            'UPDATE [' . AssetDocument::TABLE . '] SET [acquired_date] = NULL, [disposed_date] = NULL WHERE [id] = %i',
            $assetId,
        );
    }

    protected function referenceExists(string $table, int $id): bool
    {
        if ($this->db === null || !isset($this->tables[$table])) {
            return true;
        }
        $row = $this->db->fetch('SELECT [id] FROM [' . $table . '] WHERE [id] = %i AND [docState] <> 90', $id);
        return $row !== null && $row !== false;
    }

    /**
     * Účty odpisů a oprávek účetní skupiny, null = skupina neexistuje.
     *
     * @return array{account_depreciation: mixed, account_accumulated: mixed}|null
     */
    protected function loadGroupAccounts(int $id): ?array
    {
        $row = $this->db?->fetch(
            'SELECT [account_depreciation], [account_accumulated] FROM [economy_assets_accounting_groups]'
            . ' WHERE [id] = %i AND [docState] <> 90',
            $id,
        );
        return $row === null || $row === false ? null : iterator_to_array($row);
    }

    protected function begin(): void
    {
        $this->db?->begin();
    }

    protected function commit(): void
    {
        $this->db?->commit();
    }

    protected function rollback(): void
    {
        $this->db?->rollback();
    }
}
