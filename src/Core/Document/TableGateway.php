<?php

declare(strict_types=1);

namespace Shipard\Core\Document;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\StructuredFields\StructuredFieldResolver;
use Shipard\Core\StructuredFields\StructuredFieldValidator;
use Shipard\Core\StructuredFields\StructuredFieldValues;
use Shipard\Core\StructuredFields\StructuredSchema;

class TableGateway
{
    /**
     * Virtuální klíč payloadu: vědomé obejití zámku záznamu (#55 D27). Smí
     * ho poslat jen CLI (`--force`) — HTTP allow-listy (FormController,
     * CrudController) neznámé klíče zahazují. Gateway ho z dat strhne před
     * SQL a použití zaloguje (warn).
     */
    public const FORCE_UNLOCK_KEY = '_forceUnlock';

    /** Lazy, sdílený všemi dokumenty gatewaye — viz injectDocServices(). */
    private ?SettingsStore $settings = null;

    /** Lazy — providery zámku z DocumentRegistry s DB/konfigurací gatewaye. */
    private ?DocumentLockRegistry $lockRegistry = null;

    /**
     * Schémata strukturovaných sloupců rozhodnutá v tomto save (sloupec =>
     * schéma). Krok před `beforeSave` je naplní, serializace po hooku z nich
     * bere `_schema` — aby se schéma nerozhodovalo dvakrát a jinak.
     *
     * @var array<string, StructuredSchema>
     */
    private array $structuredSchemas = [];

    /**
     * `$tableDef` je volitelná jen kvůli zpětné kompatibilitě volajících,
     * kteří definici po ruce nemají. **Tabulka se strukturovaným sloupcem
     * (#74) ji vyžaduje** — bez ní gateway neví, které sloupce mají schéma.
     * Chybějící definice ale nezpůsobí tiché uložení bez validace: virtuální
     * sloupec `<sloupec>.<pole>` skončí jako neznámý SQL sloupec a pole
     * v hodnotě rozbije dibi insert, takže zápis selže hlasitě.
     */
    public function __construct(
        private string $tableId,
        private \Dibi\Connection $db,
        private DocumentRegistry $registry,
        private ?array $childTables = null,
        private ?ConfigRuntime $config = null,
        private ?DataSourceConfig $dsConfig = null,
        private ?DocumentEventDispatcher $eventDispatcher = null,
        private ?DocStatesDefinition $docStates = null,
        private ?TableDefinition $tableDef = null,
    ) {}

    /**
     * Instance dokumentu pro `$data` (třída podle `typeColumn` registrace)
     * s injektovanými službami — stejná, jakou dostane uložení. Veřejná pro
     * výpočty bez uložení (`DocDocument::computeAmounts()` v náhledu návrhu,
     * tasks/exchange-preview-vat-recompute.md D1). Nová instance per volání:
     * žádné sdílení stavu s uložením.
     */
    public function createDocument(array $data): Document
    {
        $doc = $this->registry->getDocument($this->tableId, $data);
        $this->injectDocServices($doc);
        return $doc;
    }

    private function injectDocServices(Document $doc): void
    {
        $doc->setDb($this->db);
        $doc->setExternalTransaction($this->transactionsExternal());
        if ($this->config !== null) {
            $doc->setConfig($this->config);
        }
        if ($this->dsConfig !== null) {
            $doc->setDsConfig($this->dsConfig);
        }
        // Jedna instance per gateway → cache settings přežívá dávku dokladů.
        $doc->setSettings(
            $this->settings ??= new SettingsStore(new DataSourceConnection($this->db)),
        );
    }

    private function lockRegistry(): DocumentLockRegistry
    {
        return $this->lockRegistry ??= DocumentLockRegistry::forDocuments(
            $this->registry,
            $this->db,
            $this->config,
            $this->dsConfig,
        );
    }

    /**
     * Vynucení zámku při uložení: každý důvod = chyba formuláře (`_form`,
     * kód `locked`). Import mód (Document::isLockExempt) providery nevolá;
     * force (CLI) je pustí, ale zaloguje.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $originalData
     */
    private function enforceLock(
        Document $doc,
        array $data,
        ?array $originalData,
        bool $force,
        ValidationResult $validation,
    ): void {
        if (!$this->lockRegistry()->hasProviders($this->tableId) || $doc->isLockExempt($data)) {
            return;
        }
        $reasons = $this->lockRegistry()->reasons($this->tableId, $data, $originalData);
        if ($reasons === []) {
            return;
        }
        if ($force) {
            ErrorLogger::warn('document lock bypassed by force', [
                'table'   => $this->tableId,
                'id'      => $data['id'] ?? null,
                'reasons' => array_map(
                    static fn(DocumentLockReason $r): string => $r->source . ':' . (string) ($r->subjectRowId ?? ''),
                    $reasons,
                ),
            ]);
            return;
        }
        foreach ($reasons as $reason) {
            $validation->addError(ValidationError::FIELD_FORM, $reason->title, DocumentLockRegistry::ERROR_CODE);
        }
    }

    /**
     * True = transakci vlastní volající gatewaye (TransactionlessTableGateway),
     * dokumenty si nesmí otevírat vlastní — viz Document::$externalTransaction.
     */
    protected function transactionsExternal(): bool
    {
        return false;
    }

    public function loadRecord(int $id): ?array
    {
        return $this->fetchRow($id);
    }

    public function loadDocument(int $id): ?array
    {
        $data = $this->fetchRow($id);
        if ($data === null) {
            return null;
        }

        foreach ($this->childTables ?? [] as $ct) {
            $data[$ct['dataKey']] = $this->fetchChildren($ct['table'], $ct['foreignKey'], $id);
        }

        $doc = $this->createDocument($data);
        $doc->onLoad($data);

        return $data;
    }

    public function saveDocument(array $inputData): DocumentResult
    {
        $doc = $this->createDocument($inputData);
        $data = $inputData;

        // Force marker ven z dat hned — nikdy nesmí dojít do SQL. Rozhoduje
        // se až po validate (enforceLock).
        $forceUnlock = !empty($data[self::FORCE_UNLOCK_KEY]);
        unset($data[self::FORCE_UNLOCK_KEY]);

        // Load original record (head + child rows) on update — Document hooks
        // need it to detect what changed (partner, docState, …). On insert: null.
        $originalData = null;
        if (isset($data['id']) && (int) $data['id'] > 0) {
            $originalData = $this->loadDocument((int) $data['id']);
        }

        // Chybějící docState v update payloadu = stav se nemění. Injektáž před
        // validate/beforeSave zajistí, že všechny Document hooky vidí efektivní
        // stav — payload bez docState se nikdy netváří jako Koncept (10).
        if ($this->docStates !== null && $originalData !== null) {
            $stateCol = $this->docStates->stateColumn;
            if (!array_key_exists($stateCol, $data) && isset($originalData[$stateCol])) {
                $data[$stateCol] = (int) $originalData[$stateCol];
            }
        }

        // Strukturovaná pole (#74, I3): unflatten virtuálních sloupců →
        // validace → normalizovaná hodnota jako POLE v $data. Běží před
        // Document::validate, aby dokument viděl dekódovanou hodnotu a mohl
        // nad ní validovat dál; serializace do JSONu je až po beforeSave.
        //
        // Nedostupné schéma je konfigurační chyba (nezkompilovaná konfigurace,
        // neaktivní modul) — zápis se nekoná, protože bez schématu nejde
        // hodnotu zvalidovat.
        try {
            $structuredErrors = $this->applyStructuredFields($doc, $data, $originalData);
        } catch (\RuntimeException $e) {
            ErrorLogger::logException($e, 'TableGateway::saveDocument structured fields for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        $validation = $doc->validate($data);
        foreach ($structuredErrors as $error) {
            $validation->addError($error->column, $error->message, $error->code);
        }

        // Zámek záznamu (documentLockProviders, #55 D24) — po validate, před
        // beforeSave: providery vidí nový stav i originál, import marker je
        // ještě v datech. Výjimka providera = zápis selže (fail-closed).
        try {
            $this->enforceLock($doc, $data, $originalData, $forceUnlock, $validation);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'TableGateway::saveDocument lock providers for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        if (!$validation->isValid()) {
            return DocumentResult::validationFailed($validation);
        }

        $doc->beforeSave($data, $originalData);

        // Hook dostal pole, DB chce string — a `_schema` stampuje jedině
        // gateway, nikdy klient. Nedekódovatelná hodnota v tuhle chvíli může
        // vzniknout jen v beforeSave, tedy chybou v kódu dokumentu.
        try {
            $this->serializeStructuredFields($data);
        } catch (\InvalidArgumentException $e) {
            ErrorLogger::logException($e, 'TableGateway::saveDocument structured fields for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        // Odvození docStateMain z cfgItemu — jediné místo pravdy pro všechny
        // zápisové cesty přes Document/Gateway (import Applier i FormController).
        if ($this->docStates !== null && $this->config !== null) {
            $stateCol = $this->docStates->stateColumn;
            $mainCol  = $this->docStates->mainColumn;
            if (array_key_exists($stateCol, $data) && $data[$stateCol] !== null) {
                $cfg = DocStateConfig::fromCfgItem($this->config->cfgItem($this->docStates->cfgItem));
                $data[$mainCol] = $cfg->getMainState((int) $data[$stateCol]);
            }
        }

        try {
            $this->beginTransaction();

            // beforeSave handlery cizích modulů — uvnitř transakce, před
            // zápisem hlavičky, s child sety ještě v $data (dopočet extension
            // sloupců, např. economy.vat → vat_period/cs_period/rs_period).
            // Výjimka = rollback, uložení selže.
            $this->eventDispatcher?->dispatchBeforeSave($this->tableId, $data, $originalData);

            // Separate child data from head data. Only track child sets that
            // were actually provided in $data — either by the client or set
            // by Document::beforeSave. Children NOT present in $data stay
            // untouched on disk: this is what protects sub-form managed rows
            // (docs_core_rows) from being wiped when only the header is saved.
            $childDataByKey = [];
            foreach ($this->childTables ?? [] as $ct) {
                if (!array_key_exists($ct['dataKey'], $data)) {
                    continue;
                }
                $childDataByKey[$ct['dataKey']] = is_array($data[$ct['dataKey']]) ? $data[$ct['dataKey']] : [];
                unset($data[$ct['dataKey']]);
            }

            $headId = isset($data['id']) && $data['id'] > 0
                ? (int) $data['id']
                : null;

            if ($headId !== null) {
                $headData = $data;
                unset($headData['id']);
                $this->updateRow($this->tableId, $headId, $headData);
            } else {
                $headData = $data;
                unset($headData['id']);
                $headId = $this->insertRow($this->tableId, $headData);
                $data['id'] = $headId;
            }

            foreach ($this->childTables ?? [] as $ct) {
                if (!array_key_exists($ct['dataKey'], $childDataByKey)) {
                    continue;
                }
                $this->syncChildren($ct['table'], $ct['foreignKey'], $headId, $childDataByKey[$ct['dataKey']]);
                $data[$ct['dataKey']] = $childDataByKey[$ct['dataKey']];
            }

            $doc->afterPersist($data);

            $this->commitTransaction();
        } catch (\DomainException $e) {
            // Domain errors are expected business outcomes (e.g. "can't release
            // number with gap in sequence") — surface to caller, don't log.
            $this->rollbackTransaction();
            return DocumentResult::domainError($e->getMessage(), $e->getCode() !== 0 ? (string) $e->getCode() : null);
        } catch (\Throwable $e) {
            // Unexpected failure (SQL syntax, type mismatch, network) — log it
            // before returning the error. Without this, only exceptions that
            // bubble all the way up to index.php get logged; gateway-caught
            // ones produced silent 500 responses.
            $this->rollbackTransaction();
            ErrorLogger::logException($e, 'TableGateway::saveDocument failed for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        $doc->afterSave($data);

        // afterSave handlery — každé uložení, po commitu; výjimky loguje
        // a polyká dispatcher.
        $this->eventDispatcher?->dispatchAfterSave($this->tableId, $data, $originalData);

        // Dispatch documentEventHandlers až po commitu a po afterSave —
        // přechod poskytuje Document (trackStateChange), gateway nic
        // nedopočítává. Výjimky handlerů loguje a polyká dispatcher.
        $transition = $doc->getStateTransition();
        if ($transition !== null && $this->eventDispatcher !== null) {
            $this->eventDispatcher->dispatchStateChanged(
                $this->tableId,
                $data,
                $transition['old'],
                $transition['new'],
            );
        }

        return DocumentResult::ok($data, $validation);
    }

    public function deleteDocument(int $id): DocumentResult
    {
        $data = $this->loadDocument($id);
        if ($data === null) {
            return DocumentResult::error("Record {$id} not found");
        }

        $doc = $this->createDocument($data);

        // Zamčený záznam nejde smazat — stejné providery jako u uložení,
        // nad uloženým řádkem. Výjimka providera = mazání selže.
        try {
            $reasons = $this->lockRegistry()->hasProviders($this->tableId) && !$doc->isLockExempt($data)
                ? $this->lockRegistry()->reasons($this->tableId, $data, $data)
                : [];
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'TableGateway::deleteDocument lock providers for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }
        if ($reasons !== []) {
            return DocumentResult::domainError(
                DocumentLockRegistry::summarize($reasons),
                DocumentLockRegistry::DOMAIN_CODE,
            );
        }

        $doc->beforeDelete($data);

        $this->beginTransaction();
        try {
            // beforeDelete handlery uvnitř transakce, před child delete —
            // mažou závislá data (deník). Výjimka = rollback, dokument
            // zůstává netknutý.
            $this->eventDispatcher?->dispatchBeforeDelete($this->tableId, $data);

            foreach ($this->childTables ?? [] as $ct) {
                $this->deleteChildren($ct['table'], $ct['foreignKey'], $id);
            }
            $this->deleteRow($this->tableId, $id);
            $this->commitTransaction();
        } catch (\Throwable $e) {
            $this->rollbackTransaction();
            ErrorLogger::logException($e, 'TableGateway::deleteDocument failed for table ' . $this->tableId);
            return DocumentResult::error($e->getMessage());
        }

        $doc->afterDelete($data);
        return DocumentResult::ok($data);
    }

    // ── Strukturovaná pole (#74) ─────────────────────────────────────────────

    /**
     * Krok „structured fields" před `Document::validate`/`beforeSave` (I3):
     * pro každý sloupec se `schema`, kterého se zápis dotýká, složí hodnotu
     * z virtuálních sloupců nad uloženým základem, zvaliduje ji a nechá
     * v `$data` jako **pole**. Virtuální sloupce z `$data` zmizí, aby se
     * nedostaly do SQL.
     *
     * Sloupec, kterého se zápis nedotýká (není v payloadu ani jako
     * `<sloupec>`, ani jako `<sloupec>.<pole>`), zůstává na disku nedotčený —
     * stejná zásada jako u child setů.
     *
     * @param array<string, mixed>      $data
     * @param array<string, mixed>|null $originalData
     * @return list<ValidationError>
     */
    private function applyStructuredFields(Document $doc, array &$data, ?array $originalData): array
    {
        $this->structuredSchemas = [];
        $structuredColumns = $this->tableDef?->getStructuredColumns() ?? [];
        if ($structuredColumns === []) {
            return [];
        }

        $resolver = new StructuredFieldResolver($this->config);
        $errors = [];

        foreach ($structuredColumns as $column => $staticKey) {
            $hasColumn = array_key_exists($column, $data);
            if (!$hasColumn && !$this->hasVirtualKeys($column, $data)) {
                continue;
            }

            $schema = $resolver->requireForWrite(
                $this->tableId . '.' . $column,
                $doc->structuredSchemaFor($column, $data),
                $staticKey,
            );
            $this->structuredSchemas[$column] = $schema;

            // Poslaný celý sloupec hodnotu NAHRAZUJE (import, applier);
            // jinak je základem to, co je uložené, a virtuální sloupce ho
            // jen přepisují po polích.
            try {
                $base = StructuredFieldValues::decodeStrict(
                    $hasColumn ? $data[$column] : ($originalData[$column] ?? null),
                );
            } catch (\InvalidArgumentException $e) {
                $errors[] = new ValidationError($column, $e->getMessage(), 'invalid_value');
                $data = $this->stripVirtualKeys($column, $data);
                unset($data[$column]);
                continue;
            }

            $value = StructuredFieldValues::unflatten($column, $data, $base, $schema);
            $data = $this->stripVirtualKeys($column, $data);

            $result = StructuredFieldValidator::validate($column, $value, $schema, $this->config);
            foreach ($result['errors'] as $error) {
                $errors[] = $error;
            }
            $data[$column] = $result['value'];
        }

        return $errors;
    }

    /**
     * Strukturované sloupce z pole na JSON string (nebo NULL u prázdné
     * hodnoty — nikdy `{}`). Volá se po `Document::beforeSave`, takže
     * respektuje i hodnotu, kterou hook dopočítal; `_schema` doplní gateway.
     *
     * @param array<string, mixed> $data
     */
    private function serializeStructuredFields(array &$data): void
    {
        foreach ($this->structuredSchemas as $column => $schema) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = StructuredFieldValues::decodeStrict($data[$column]);
            $data[$column] = $value === null ? null : StructuredFieldValues::encode($value, $schema);
        }
    }

    /**
     * Nese payload virtuální sloupec `<sloupec>.<cokoli>`? Prefixem, ne přes
     * schéma — na tuhle otázku se odpovídá dřív, než je jasné, které schéma
     * platí.
     */
    private function hasVirtualKeys(string $column, array $data): bool
    {
        $prefix = $column . StructuredSchema::PATH_SEPARATOR;
        foreach (array_keys($data) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Odstraní všechny `<sloupec>.*` klíče — i ty, které schéma nezná.
     * Do SQL nesmí projít nic s tečkou (byl by to neznámý sloupec).
     */
    private function stripVirtualKeys(string $column, array $data): array
    {
        $prefix = $column . StructuredSchema::PATH_SEPARATOR;
        foreach (array_keys($data) as $key) {
            if (is_string($key) && str_starts_with($key, $prefix)) {
                unset($data[$key]);
            }
        }
        return $data;
    }

    private function syncChildren(string $table, string $foreignKey, int $parentId, array $inputRows): void
    {
        $existing = $this->fetchChildren($table, $foreignKey, $parentId);
        $existingIds = array_map(fn($row) => (int) $row['id'], $existing);
        $inputIds = [];

        foreach ($inputRows as $row) {
            if (!empty($row['id'])) {
                $rowId = (int) $row['id'];
                $inputIds[] = $rowId;
                $rowData = $row;
                unset($rowData['id']);
                $this->updateRow($table, $rowId, $rowData);
            } else {
                $rowData = $row;
                $rowData[$foreignKey] = $parentId;
                $this->insertRow($table, $rowData);
            }
        }

        foreach ($existingIds as $existingId) {
            if (!in_array($existingId, $inputIds, true)) {
                $this->deleteRow($table, $existingId);
            }
        }
    }

    protected function fetchRow(int $id): ?array
    {
        $row = $this->db->fetch('SELECT * FROM %n WHERE id = %i', $this->tableId, $id);
        return $row ? $row->toArray() : null;
    }

    protected function fetchChildren(string $table, string $foreignKey, int $parentId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM %n WHERE %n = %i', $table, $foreignKey, $parentId);
        return array_map(fn($row) => $row->toArray(), $rows);
    }

    protected function insertRow(string $table, array $data): int
    {
        $this->db->insert($table, $data)->execute();
        return (int) $this->db->getInsertId();
    }

    protected function updateRow(string $table, int $id, array $data): void
    {
        $this->db->update($table, $data)->where('id = %i', $id)->execute();
    }

    protected function deleteRow(string $table, int $id): void
    {
        $this->db->delete($table)->where('id = %i', $id)->execute();
    }

    protected function deleteChildren(string $table, string $foreignKey, int $parentId): void
    {
        $this->db->delete($table)->where('%n = %i', $foreignKey, $parentId)->execute();
    }

    protected function beginTransaction(): void
    {
        $this->db->begin();
    }

    protected function commitTransaction(): void
    {
        $this->db->commit();
    }

    protected function rollbackTransaction(): void
    {
        $this->db->rollback();
    }
}
