<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Api\TableAccessGuard;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\ColumnDefinition;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\AutoFormBuilder;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\JsoncFormLoader;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Document\DocStateConfig;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\StructuredFields\StructuredFieldResolver;
use Shipard\Core\StructuredFields\StructuredFieldValues;
use Shipard\Core\StructuredFields\StructuredSchema;

class FormController
{
    /**
     * @param array<string, TableDefinition> $tables
     */
    public function meta(
        string $table,
        ?int $id,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        LookupRegistry $lookupRegistry,
        ModulePathResolver $modulePathResolver,
        string $language = 'en',
        array $newRecordDefaults = [],
        ?AuthContext $auth = null,
        ?DocumentRegistry $documentRegistry = null,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $guardErr = TableAccessGuard::guardTable($table, $auth ?? new AuthContext(false), $def);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $data = [];
        $isNew = $id === null;

        if ($id !== null) {
            $data = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $id);
            if ($data === null) {
                return Response::error('RECORD_NOT_FOUND', "Record {$id} not found", 404);
            }
            $data = TableAccessGuard::stripSensitive($data, $def);
            $data = $this->decodeJsonColumns($data, $def);
        } else {
            // Pro nový záznam sestav výchozí data z defaultů sloupců
            foreach ($def->columns as $col) {
                if ($col->primaryKey || $col->system) {
                    continue;
                }
                if ($col->default !== null) {
                    $data[$col->id] = $col->default;
                }
            }
            // Klient může poslat per-typ prefill (např. doc_type z per-type
            // vieweru) přes query string ?defaults[doc_type]=invno. Tyto
            // hodnoty mají přednost před column defaults a viz je server-side
            // form (DocsHeadsForm::applyClientDefaults), který z nich může
            // odvodit další pole (číselná řada apod.).
            //
            // Query string vždy přijde jako string — pro číselné/bool sloupce
            // zkoercujeme na cílový typ. Bez toho by Svelte `<select bind:value>`
            // nerozpoznal shodu (string '2' !== int 2) a roletka zůstala prázdná.
            $colByName = [];
            foreach ($def->columns as $c) {
                $colByName[$c->id] = $c;
            }
            foreach ($newRecordDefaults as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $col = $colByName[$key] ?? null;
                $data[$key] = $col !== null ? $this->coerceDefaultValue($value, $col->type) : $value;
            }

            // Server-side defaulty odvozené z prefillu (např. default pohyb
            // řádku podle doc_type hlavičky z defaults[doc_head]). Na rozdíl
            // od mutací uvnitř buildFormDefinition se tyto propíší do
            // response `data`.
            $tableForm = $formRegistry->createForm($table, $data, $db, $config);
            if ($tableForm !== null) {
                $tableForm->setTableDef($def);
                $tableForm->applyNewRecordDefaults($data);
            }
        }

        // Strukturovaná pole (#74) — klient je edituje jako virtuální sloupce
        // `<sloupec>.<pole>`. Až po defaultech, aby se do nich propsaly.
        $structuredForm = $formRegistry->createForm($table, $data, $db, $config);
        $structuredForm?->setTableDef($def);
        $data = $this->flattenStructuredColumns($data, $def, $config, $structuredForm, $isNew);

        $formDefinition = $this->resolveFormDefinition(
            $table, $def, $data, $isNew, $formRegistry, $db, $config, $modulePathResolver, $language,
        );

        // Enrich with docStates if applicable
        if ($def->docStates !== null && $config !== null) {
            // Pro nový záznam použij výchozí stav (10 = Koncept)
            $docData = $isNew ? [$def->docStates->stateColumn => 10] : $data;
            $docStatesInfo = $this->buildDocStatesInfo(
                $def, $docData, $config, $table, $documentRegistry, $db,
                $structuredForm?->getReadOnlyEditableColumns() ?? [],
            );
            $formDefinition = $formDefinition->withDocStates($docStatesInfo);
        }

        // Header info — jen pro existující záznam. Pro nový formulář nemá co
        // zobrazovat; nechává se na fallback `title_new`.
        if (!$isNew) {
            $formDefinition = $this->enrichHeaderInfo(
                $formDefinition, $table, $data, $formRegistry, $db, $config,
            );
        }

        $dataResolved = $this->buildDataResolved(
            $formDefinition, $data, $lookupRegistry, $db, $config, $tables,
        );

        return Response::success([
            'formDefinition' => $formDefinition->toArray(),
            'data'           => $isNew ? ($data ?: null) : $data,
            'dataResolved'   => $dataResolved,
        ]);
    }

    public function save(
        string $table,
        ?int $id,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        FormRegistry $formRegistry,
        ModulePathResolver $modulePathResolver,
        LookupRegistry $lookupRegistry,
        string $language = 'en',
        ?DocumentRegistry $documentRegistry = null,
        ?\Shipard\Core\Config\DataSourceConfig $dsConfig = null,
        ?AuthContext $auth = null,
        ?\Shipard\Core\Document\DocumentEventDispatcher $eventDispatcher = null,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $guardErr = TableAccessGuard::guardTable($table, $auth ?? new AuthContext(false), $def);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        // Form může opt-in whitelistem povolit editaci konkrétních sensitive
        // sloupců (TableForm::getEditableSensitiveColumns) — např. mail_token
        // na hosting DS. Bez registrované form třídy platí plný zákaz.
        $form = $formRegistry->createForm($table, $body, $db, $config);
        $sensitiveAllowed = $form?->getEditableSensitiveColumns() ?? [];
        $sensitiveErr = TableAccessGuard::rejectSensitiveInput($body, $def, $sensitiveAllowed);
        if ($sensitiveErr !== null) {
            return $sensitiveErr;
        }

        $dsDef    = $def->docStates;
        $stateCol = $dsDef?->stateColumn ?? 'docState';
        $mainCol  = $dsDef?->mainColumn  ?? 'docStateMain';

        // ── Detekce přechodu stavu ────────────────────────────────────────────
        // Přechod stavu = tělo obsahuje pouze docState (žádná běžná data).
        // Prochází přímým UPDATE bez Document lifecycle.
        $bodyKeys = array_keys($body);
        $isStateTransition = $id !== null
            && $dsDef !== null
            && count($bodyKeys) === 1
            && $bodyKeys[0] === $stateCol;

        if ($isStateTransition) {
            // Tables that opt-in via `stateTransitionsRunDocumentHooks` route
            // through saveDocument — Document::beforeSave fires and can run
            // business logic (assign number, build snapshots, …).
            if ($def->stateTransitionsRunDocumentHooks) {
                return $this->applyStateTransitionViaDocument(
                    $table, $id, (int) $body[$stateCol], $def, $db, $config, $documentRegistry, $dsConfig,
                    $formRegistry, $modulePathResolver, $lookupRegistry, $language, $tables,
                    $eventDispatcher,
                );
            }
            return $this->applyStateTransition(
                $table, $id, $body, $def, $db, $config,
                $formRegistry, $modulePathResolver, $lookupRegistry, $language, $tables,
            );
        }

        // ── Běžné uložení přes TableGateway + Document lifecycle ──────────────
        $inputData = $this->filterWritableFields($body, $def);
        if ($id !== null) {
            $inputData['id'] = $id;
        }

        // Auto-manage timestamps — only add if column exists in table definition
        $now = date('Y-m-d H:i:s');
        if ($id === null && $this->hasColumn($def, 'created')) {
            $inputData['created'] = $now;
        }
        if ($this->hasColumn($def, 'modified')) {
            $inputData['modified'] = $now;
        }

        // `created_by` doplňuje TableGateway (CurrentUser, #93 D10) — sloupec
        // je system:true, přes filterWritableFields nepřijde a klient ho
        // nepodvrhne.

        // Read-only stav dokumentu (`readOnly` v docStates cfgItem): uložení
        // existujícího záznamu projde jen s payloadem složeným ze sloupců,
        // které form výslovně pouští (TableForm::getReadOnlyEditableColumns);
        // cokoli jiného = 422 DOCUMENT_READONLY. Klient v tom stavu posílá
        // právě jen tyto sloupce (FormEditor), takže Document vidí částečné
        // uložení. Přechody stavu jdou jinou větví (výše).
        if ($id !== null) {
            $readOnlyErr = $this->guardReadOnlyUpdate(
                $table, $id, $def, $inputData, $db, $config,
                $form?->getReadOnlyEditableColumns() ?? [],
            );
            if ($readOnlyErr !== null) {
                return $readOnlyErr;
            }
        }

        // Init docState for new records
        if ($id === null) {
            $this->initDocState($body, $def, $inputData, $config);
        }

        $registry = $documentRegistry ?? new DocumentRegistry();
        $gateway  = new TableGateway(
            $table,
            $db->getDibiConnection(),
            $registry,
            $def->childTables,
            $config,
            $dsConfig,
            $eventDispatcher,
            $def->docStates,
            $def,
        );
        $result   = $gateway->saveDocument($inputData);

        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                $errors = array_map(
                    fn($e) => ['field' => $e->column, 'code' => $e->code ?: 'INVALID', 'message' => $e->message],
                    $validation->getErrors(),
                );
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
            }
            if ($result->isDomainError()) {
                return Response::error(
                    $result->getDomainErrorCode() ?: 'DOMAIN_ERROR',
                    $result->getErrorMessage() ?? 'Domain rule violated',
                    422,
                );
            }
            return Response::error('INTERNAL_ERROR', $result->getErrorMessage() ?? 'Save failed', 500);
        }

        $saved   = $result->getData();
        $savedId = $saved['id'] ?? $id;
        $record  = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $savedId);
        if ($record !== null) {
            $record = TableAccessGuard::stripSensitive($record, $def);
            // Stejný tvar jako v meta — strukturované sloupce ploché (#74).
            $record = $this->flattenStructuredColumns(
                $record,
                $def,
                $config,
                $formRegistry->createForm($table, $record, $db, $config),
                false,
            );
        }

        $httpStatus = ($id === null) ? 201 : 200;
        $dataResolved = $this->resolveLookupValuesForRecord(
            $table, $def, $record ?? [], $formRegistry, $db, $config,
            $lookupRegistry, $modulePathResolver, $language, $tables,
        );
        $payload = [
            'id'           => $savedId,
            'data'         => $record,
            'dataResolved' => $dataResolved,
        ];
        $warnings = $this->validationWarnings($result);
        if ($warnings !== null) {
            $payload['warnings'] = $warnings;
        }
        return Response::success($payload, $httpStatus);
    }

    /**
     * Neblokující warningy z Document::validate() pro success response —
     * stejný tvar položek jako errors ve 422 (field/code/message). Null,
     * když žádné nejsou (klíč `warnings` se do response nepřidá).
     *
     * @return list<array{field: string, code: string, message: string}>|null
     */
    /**
     * GET /_ui/form/{table}/subtable/{tabId}/{parentId}
     *
     * Sloupce + vyrenderované řádky sub-tabulky (tab typu `subtable`) pro
     * existující rodičovský záznam — kontrakt v docs/edit-forms.md kap. 15.
     * Rodič se načítá stejně jako v meta({id}): renderer na rodičovském
     * formu potřebuje jeho data (doklad bez DPH nemá DPH sloupce). Rodič
     * bez PHP form třídy (JSONC / auto) nebo neznámý tab → 404
     * SUBTABLE_NOT_FOUND. Přístup = čtení: guard rodiče i dětské tabulky,
     * sensitive sloupce se z řádků odstraní před renderem.
     *
     * Řazení: tab s `orderColumn` VŽDY `orderColumn ASC, id ASC` (stejné
     * pořadí, jaké vidí endpoint přesunu); jinak `sort` tabu.
     *
     * @param array<string, TableDefinition> $tables
     */
    public function subtable(
        string $table,
        ?string $tabId,
        ?int $parentId,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        ?AuthContext $auth = null,
    ): Response {
        $ctx = $this->resolveSubtableContext(
            $table, $tabId, $parentId, $tables, $db, $formRegistry, $config, $auth ?? new AuthContext(false),
        );
        if ($ctx instanceof Response) {
            return $ctx;
        }

        if ($ctx['orderColumn'] !== null) {
            $orderBy = "`{$ctx['orderColumn']}` ASC, `id` ASC";
        } else {
            $orderBy = $this->subtableOrderBy($ctx['tab']->subtable['sort'] ?? null, $ctx['childCols'], (string) $tabId);
            if ($orderBy instanceof Response) {
                return $orderBy;
            }
        }

        $childDef = $ctx['childDef'];
        $rows = $db->fetchAll(
            "SELECT * FROM `{$ctx['childTable']}` WHERE `{$ctx['fk']}` = %i ORDER BY {$orderBy}",
            $parentId,
        );
        $rows = array_map(
            static fn(array $row): array => TableAccessGuard::stripSensitive($row, $childDef),
            $rows,
        );

        $rendered = $ctx['form']->renderSubtable($ctx['tab'], $rows, $ctx['data']);

        return Response::success([
            'columns'      => array_values($rendered['columns'] ?? []),
            'rows'         => array_values($rendered['rows'] ?? []),
            'order_column' => $ctx['orderColumn'],
        ]);
    }

    /**
     * POST /_ui/form/{table}/subtable/{tabId}/{parentId}/move
     * body `{id, direction: 'up'|'down'}` — přesun řádku sub-tabulky
     * o jednu pozici (issue #53, fáze 3).
     *
     * Server v jedné transakci načte skupinu (`SELECT … FOR UPDATE`,
     * `orderColumn ASC, id ASC`), přečísluje ji 1..N (řádky přidané
     * sub-formulářem měly historicky všechny 0 — prohození dvou nul by nic
     * nezměnilo), prohodí řádek se sousedem (na kraji no-op, přečíslování
     * se přesto provede) a zapíše jen řádky, kde se hodnota liší. Zapisuje
     * přímo přes DB, ne přes Document hooky: přesun úmyslně NEspouští
     * přepočet hlavičky (součty ani rekapitulace na pořadí nezávisí).
     *
     * Tab bez `orderColumn` → 400 SUBTABLE_NOT_ORDERED; rodič v read-only
     * doc state → 422 DOCUMENT_READONLY (stejně jako save); řádek mimo
     * rodiče → 404. Read-only DS odmítá ReadOnlyPolicy (fail-closed).
     * Souběh: zámek řádků skupiny; souběžný insert bez zámku může dostat
     * duplicitní pořadí — další přesun ho srovná.
     *
     * @param array<string, TableDefinition> $tables
     */
    public function subtableMove(
        string $table,
        ?string $tabId,
        ?int $parentId,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        ?AuthContext $auth = null,
    ): Response {
        $ctx = $this->resolveSubtableContext(
            $table, $tabId, $parentId, $tables, $db, $formRegistry, $config, $auth ?? new AuthContext(false),
        );
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $orderColumn = $ctx['orderColumn'];
        if ($orderColumn === null) {
            return Response::error('SUBTABLE_NOT_ORDERED', "Subtable '{$tabId}' has no order column", 400);
        }

        $body = $request->getBody() ?? [];
        $rawId = $body['id'] ?? null;
        $direction = $body['direction'] ?? null;
        if (!is_numeric($rawId) || (int) $rawId <= 0 || !in_array($direction, ['up', 'down'], true)) {
            return Response::error('BAD_REQUEST', 'Body must contain positive "id" and "direction" up|down', 400);
        }
        $rowId = (int) $rawId;

        $readOnlyErr = $this->guardParentWritable($ctx['def'], $ctx['data'], $config);
        if ($readOnlyErr !== null) {
            return $readOnlyErr;
        }

        $childTable = $ctx['childTable'];
        $db->begin();
        try {
            $group = $db->fetchAll(
                "SELECT `id`, `{$orderColumn}` AS `pos` FROM `{$childTable}` WHERE `{$ctx['fk']}` = %i"
                . " ORDER BY `{$orderColumn}` ASC, `id` ASC FOR UPDATE",
                $parentId,
            );
            $ids = [];
            $current = [];
            foreach ($group as $row) {
                $id = (int) $row['id'];
                $ids[] = $id;
                $current[$id] = (int) ($row['pos'] ?? 0);
            }

            $index = array_search($rowId, $ids, true);
            if ($index === false) {
                $db->rollback();
                return Response::error('RECORD_NOT_FOUND', "Row {$rowId} does not belong to record {$parentId}", 404);
            }

            $swap = $direction === 'up' ? $index - 1 : $index + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$index], $ids[$swap]] = [$ids[$swap], $ids[$index]];
            }

            foreach ($ids as $i => $id) {
                $pos = $i + 1;
                if ($current[$id] !== $pos) {
                    $db->execute(
                        "UPDATE `{$childTable}` SET `{$orderColumn}` = %i WHERE `id` = %i",
                        $pos,
                        $id,
                    );
                }
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return Response::success(['order' => $ids]);
    }

    /**
     * Společné resolvování pro endpointy sub-tabulky: rodič (tabulka, guard,
     * záznam), jeho PHP form, tab typu `subtable`, dětská tabulka (guard),
     * FK a pořadový sloupec (oba musí být sloupce dětské tabulky — jinak
     * chyba konfigurace formu, 500).
     *
     * @param array<string, TableDefinition> $tables
     * @return array{
     *     def: TableDefinition, data: array<string, mixed>, form: TableForm, tab: FormTab,
     *     childTable: string, childDef: TableDefinition, childCols: array<string, ColumnDefinition>,
     *     fk: string, orderColumn: ?string
     * }|Response
     */
    private function resolveSubtableContext(
        string $table,
        ?string $tabId,
        ?int $parentId,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        AuthContext $authCtx,
    ): array|Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }
        $guardErr = TableAccessGuard::guardTable($table, $authCtx, $def);
        if ($guardErr !== null) {
            return $guardErr;
        }
        if ($tabId === null || $tabId === '' || $parentId === null || $parentId <= 0) {
            return Response::error('NOT_FOUND', 'Not found', 404);
        }

        $data = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $parentId);
        if ($data === null) {
            return Response::error('RECORD_NOT_FOUND', "Record {$parentId} not found", 404);
        }
        $data = TableAccessGuard::stripSensitive($data, $def);
        $data = $this->decodeJsonColumns($data, $def);

        $form = $formRegistry->createForm($table, $data, $db, $config);
        if ($form === null) {
            return Response::error('SUBTABLE_NOT_FOUND', "Table '{$table}' has no form class", 404);
        }
        $form->setTableDef($def);
        $form->setTables($tables);

        $tab = $this->findSubtableTab($form->buildFormDefinition($data, false), $tabId);
        if ($tab === null) {
            return Response::error('SUBTABLE_NOT_FOUND', "Subtable '{$tabId}' not found on '{$table}'", 404);
        }

        $childTable = (string) $tab->subtable['table'];
        $childDef = $tables[$childTable] ?? null;
        if ($childDef === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$childTable}' not found", 404);
        }
        $guardErr = TableAccessGuard::guardTable($childTable, $authCtx, $childDef);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $childCols = [];
        foreach ($childDef->columns as $col) {
            $childCols[$col->id] = $col;
        }
        $fk = (string) $tab->subtable['foreignKey'];
        if (!isset($childCols[$fk])) {
            return Response::error(
                'INTERNAL_ERROR',
                "Subtable '{$tabId}': foreign key '{$fk}' is not a column of '{$childTable}'",
                500,
            );
        }
        $orderColumn = $tab->subtable['orderColumn'] ?? null;
        $orderColumn = is_string($orderColumn) && $orderColumn !== '' ? $orderColumn : null;
        if ($orderColumn !== null && !isset($childCols[$orderColumn])) {
            return Response::error(
                'INTERNAL_ERROR',
                "Subtable '{$tabId}': order column '{$orderColumn}' is not a column of '{$childTable}'",
                500,
            );
        }

        return [
            'def'         => $def,
            'data'        => $data,
            'form'        => $form,
            'tab'         => $tab,
            'childTable'  => $childTable,
            'childDef'    => $childDef,
            'childCols'   => $childCols,
            'fk'          => $fk,
            'orderColumn' => $orderColumn,
        ];
    }

    /**
     * Rodič v read-only doc state (zaúčtovaná faktura…) nesmí měnit ani
     * pořadí řádků — stejný kód, status i hláška jako u save
     * (processDocState), aby frontend nemusel mapovat dvě varianty.
     *
     * @param array<string, mixed> $parentData
     */
    private function guardParentWritable(TableDefinition $def, array $parentData, ?ConfigRuntime $config): ?Response
    {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return null;
        }
        $cfgData = $config->cfgItem($dsDef->cfgItem);
        $cfg = DocStateConfig::fromCfgItem(is_array($cfgData) ? $cfgData : null);
        $currentState = (int) ($parentData[$dsDef->stateColumn] ?? 10);
        if (!$cfg->isReadOnly($currentState)) {
            return null;
        }
        return Response::error(
            'DOCUMENT_READONLY',
            "Document is read-only in state {$currentState}.",
            422,
        );
    }

    private function findSubtableTab(FormDefinition $formDef, string $tabId): ?FormTab
    {
        foreach ($formDef->tabs as $tab) {
            if ($tab->type === 'subtable' && $tab->id === $tabId && $tab->subtable !== null) {
                return $tab;
            }
        }
        return null;
    }

    /**
     * ORDER BY klauzule pro řádky sub-tabulky ze `sort` tabu
     * (`col:dir[,col:dir]`, stejná syntaxe jako `?sort=` CRUD endpointu).
     * Default `order_pos:asc`, má-li dětská tabulka ten sloupec, jinak
     * `id:asc`; `id ASC` je vždy tiebreaker. `sort` je serverová
     * konfigurace formu, ne vstup uživatele — neznámý / sensitive sloupec
     * nebo špatný směr je proto 500, ne 400.
     *
     * @param array<string, ColumnDefinition> $cols
     */
    private function subtableOrderBy(?string $sort, array $cols, string $tabId): string|Response
    {
        if ($sort === null || trim($sort) === '') {
            $sort = isset($cols['order_pos']) ? 'order_pos:asc' : 'id:asc';
        }

        $parts = [];
        $seen = [];
        foreach (array_map('trim', explode(',', $sort)) as $part) {
            if ($part === '') {
                continue;
            }
            $pieces    = explode(':', $part, 2);
            $column    = $pieces[0];
            $direction = strtoupper($pieces[1] ?? 'asc');
            $colDef    = $cols[$column] ?? null;
            if ($colDef === null || $colDef->sensitive || !in_array($direction, ['ASC', 'DESC'], true)) {
                return Response::error(
                    'INTERNAL_ERROR',
                    "Subtable '{$tabId}': invalid sort '{$part}'",
                    500,
                );
            }
            $parts[] = "`{$column}` {$direction}";
            $seen[$column] = true;
        }
        if (!isset($seen['id'])) {
            $parts[] = '`id` ASC';
        }
        return implode(', ', $parts);
    }

    private function validationWarnings(DocumentResult $result): ?array
    {
        $warnings = $result->getValidation()?->getWarnings() ?? [];
        if ($warnings === []) {
            return null;
        }
        return array_map(
            fn($w) => ['field' => $w->column, 'code' => $w->code ?: 'WARNING', 'message' => $w->message],
            $warnings,
        );
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    public function recalculate(
        string $table,
        Request $request,
        array $tables,
        DataSourceConnection $db,
        FormRegistry $formRegistry,
        ?ConfigRuntime $config,
        LookupRegistry $lookupRegistry,
        ModulePathResolver $modulePathResolver,
        string $language = 'en',
        ?AuthContext $auth = null,
        ?DocumentRegistry $documentRegistry = null,
    ): Response {
        $def = $tables[$table] ?? null;
        if ($def === null) {
            return Response::error('TABLE_NOT_FOUND', "Table '{$table}' not found", 404);
        }

        $guardErr = TableAccessGuard::guardTable($table, $auth ?? new AuthContext(false), $def);
        if ($guardErr !== null) {
            return $guardErr;
        }

        $body = $request->getBody();
        if ($body === null) {
            return Response::error('BAD_REQUEST', 'Request body must be a JSON object', 400);
        }

        $changedColumn = $body['changedColumn'] ?? '';
        $data = $body['data'] ?? [];
        $isNew = !isset($data['id']) || $data['id'] === null;

        // Try PHP class form first
        $tableForm = $formRegistry->createForm($table, $data, $db, $config);
        if ($tableForm !== null) {
            $tableForm->setTableDef($def);
            $result = $tableForm->recalculate($changedColumn, $data);
        } else {
            // JSONC or Auto — no custom recalculate logic, just rebuild definition
            $formDefinition = $this->resolveFormDefinition(
                $table, $def, $data, $isNew, $formRegistry, $db, $config, $modulePathResolver, $language,
            );
            $result = new RecalculateResult($formDefinition, $data);
        }

        // Doplň doc_states stejně jako v meta endpointu
        $formDefinition = $result->formDefinition;
        if ($def->docStates !== null && $config !== null) {
            $docData = $isNew
                ? [$def->docStates->stateColumn => ($data[$def->docStates->stateColumn] ?? 10)]
                : $data;
            $formDefinition = $formDefinition->withDocStates(
                $this->buildDocStatesInfo(
                    $def, $docData, $config, $table, $documentRegistry, $db,
                    $tableForm?->getReadOnlyEditableColumns() ?? [],
                )
            );
        }

        // Strukturovaná pole: klient posílá ploché klíče a dostane je zpátky.
        // Sáhne se jen na sloupec, který form dopočítal jako celek (#74).
        $outData = $this->flattenStructuredColumns($result->data, $def, $config, $tableForm, $isNew);

        $dataResolved = $this->buildDataResolved(
            $formDefinition, $outData, $lookupRegistry, $db, $config, $tables,
        );

        return Response::success([
            'formDefinition' => $formDefinition->toArray(),
            'data'           => $outData,
            'dataResolved'   => $dataResolved,
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────────

    private function resolveFormDefinition(
        string $table,
        TableDefinition $def,
        array $data,
        bool $isNew,
        FormRegistry $formRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        ModulePathResolver $modulePathResolver,
        string $language = 'en',
    ): FormDefinition {
        // 1. PHP class from registry
        $tableForm = $formRegistry->createForm($table, $data, $db, $config);
        if ($tableForm !== null) {
            $tableForm->setTableDef($def);
            return $tableForm->buildFormDefinition($data, $isNew);
        }

        // 2. JSONC form file
        $jsoncPath = $this->findJsoncFormPath($table, $modulePathResolver);
        if ($jsoncPath !== null) {
            $loader = new JsoncFormLoader();
            return $loader->load($jsoncPath, $def, $config, $table, $language);
        }

        // 3. Auto-generate from TableDefinition
        $builder = new AutoFormBuilder();
        return $builder->build($def, $config, $table);
    }

    private function findJsoncFormPath(string $table, ModulePathResolver $resolver): ?string
    {
        foreach ($resolver->allModuleIds() as $moduleId) {
            $moduleDir = $resolver->getPath($moduleId);
            if ($moduleDir === null) continue;
            $candidate = $moduleDir . '/forms/' . $table . '.jsonc';
            if (is_file($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Doplní `headerInfo` do FormDefinition, pokud table-specific form
     * (`PersonsForm` apod.) override vrací non-null `FormHeaderInfo`. Pro
     * JSONC / Auto formuláře (TableForm bez override) zůstává null.
     */
    private function enrichHeaderInfo(
        FormDefinition $formDefinition,
        string $table,
        array $data,
        FormRegistry $formRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
    ): FormDefinition {
        $tableForm = $formRegistry->createForm($table, $data, $db, $config);
        if ($tableForm === null) {
            return $formDefinition;
        }
        $headerInfo = $tableForm->buildHeaderInfo($data);
        if ($headerInfo === null) {
            return $formDefinition;
        }
        return $formDefinition->withHeaderInfo($headerInfo);
    }

    /**
     * @param list<string> $readOnlyEditable sloupce editovatelné i v read-only
     *        stavu (TableForm::getReadOnlyEditableColumns) — klient je dostane
     *        jako `editable_columns`, jen když stav read-only je a záznam
     *        nedrží zámek (zámek je silnější než whitelist)
     */
    private function buildDocStatesInfo(
        TableDefinition $def,
        array $data,
        ConfigRuntime $config,
        string $table = '',
        ?DocumentRegistry $documentRegistry = null,
        ?DataSourceConnection $db = null,
        array $readOnlyEditable = [],
    ): array {
        $dsDef = $def->docStates;
        $cfg = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));
        $currentState = (int) ($data[$dsDef->stateColumn] ?? 10);
        $stateData = $cfg->getState($currentState);

        $transitions = $cfg->getAvailableTransitions($currentState);
        if ($table !== '' && $documentRegistry !== null && $db !== null) {
            $transitions = \Shipard\Core\Document\DocStateTransitionFilter::apply(
                $table, $data, $transitions, $documentRegistry, $db->getDibiConnection(), $config,
            );
        }

        // Zámek záznamu (documentLockProviders, #55 D24) — jen u uloženého
        // záznamu; zamčený = read-only + banner, přechody už vyřadil filtr.
        // Nový záznam do zamčeného období odmítne až save (chyba `locked`).
        $lock = ['locked' => false, 'reasons' => []];
        if ($table !== '' && $documentRegistry !== null && $db !== null
            && !empty($data['id']) && $documentRegistry->hasLockProviders($table)
        ) {
            $lock = \Shipard\Core\Document\DocumentLockRegistry::forDocuments(
                $documentRegistry, $db->getDibiConnection(), $config,
            )->describe($table, $data);
        }

        $stateReadOnly = $cfg->isReadOnly($currentState);
        $info = [
            'currentState' => $currentState,
            'stateName'    => $stateData['stateName'] ?? '',
            'stateStyle'   => $stateData['stateStyle'] ?? '',
            'read_only'    => $stateReadOnly || $lock['locked'],
            'transitions'  => $transitions,
            'lock'         => $lock,
        ];
        if ($stateReadOnly && !$lock['locked'] && $readOnlyEditable !== []) {
            $info['editable_columns'] = array_values($readOnlyEditable);
        }
        return $info;
    }

    private function filterWritableFields(array $data, TableDefinition $def): array
    {
        $excluded = ['id', 'created', 'modified'];
        $colMap = [];
        // Prefixy virtuálních sloupců strukturovaných polí (#74, S4) —
        // `filing_profile.typ_ds` není sloupec tabulky, ale je to legitimní
        // vstup formuláře. Neznámá pole schématu zahodí gateway.
        $structuredPrefixes = [];
        foreach ($def->columns as $col) {
            if (in_array($col->id, $excluded, true) || $col->system) {
                continue;
            }
            $colMap[$col->id] = true;
            if ($col->schema !== null) {
                $structuredPrefixes[] = $col->id . StructuredSchema::PATH_SEPARATOR;
            }
        }

        $result = [];
        foreach ($data as $k => $v) {
            $k = (string) $k;
            if (isset($colMap[$k])) {
                $result[$k] = $v;
                continue;
            }
            foreach ($structuredPrefixes as $prefix) {
                if (str_starts_with($k, $prefix)) {
                    $result[$k] = $v;
                    break;
                }
            }
        }
        return $result;
    }

    private function initDocState(array $rawBody, TableDefinition $def, array &$data, ?ConfigRuntime $config): void
    {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return;
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol = $dsDef->mainColumn;
        $cfg = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $newState = isset($rawBody[$stateCol]) ? (int) $rawBody[$stateCol] : 10;
        $data[$stateCol] = $newState;
        $data[$mainCol] = $cfg->getMainState($newState);
    }

    /**
     * Read-only stav dokumentu při uložení existujícího záznamu: payload smí
     * obsahovat jen sloupce z whitelistu formu (`getReadOnlyEditableColumns`),
     * jinak 422 DOCUMENT_READONLY. Neexistující záznam nechává na gateway.
     *
     * @param array<string, mixed> $data  zapisovaná data (po filterWritableFields)
     * @param list<string> $allowedColumns
     */
    private function guardReadOnlyUpdate(
        string $table,
        int $id,
        TableDefinition $def,
        array $data,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        array $allowedColumns,
    ): ?Response {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return null;
        }

        $stateCol = $dsDef->stateColumn;
        $currentRow = $db->fetchRow("SELECT `{$stateCol}` FROM `{$table}` WHERE `id` = %i", $id);
        if ($currentRow === null) {
            return null;
        }
        $currentState = (int) ($currentRow[$stateCol] ?? 10);
        $cfg = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));
        if (!$cfg->isReadOnly($currentState)) {
            return null;
        }

        $extra = array_diff(array_keys($data), $allowedColumns, ['id', 'modified']);
        if ($extra === []) {
            return null;
        }
        return Response::error(
            'DOCUMENT_READONLY',
            "Document is read-only in state {$currentState}.",
            422,
        );
    }

    /**
     * State transition routed through TableGateway::saveDocument so Document
     * hooks fire (assignDocumentNumber on Concept→Confirmed, etc.). Triggered
     * by `stateTransitionsRunDocumentHooks: true` on the table definition.
     */
    private function applyStateTransitionViaDocument(
        string $table,
        int $id,
        int $newState,
        TableDefinition $def,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        ?DocumentRegistry $documentRegistry,
        ?\Shipard\Core\Config\DataSourceConfig $dsConfig,
        FormRegistry $formRegistry,
        ModulePathResolver $modulePathResolver,
        LookupRegistry $lookupRegistry,
        string $language,
        array $tables,
        ?\Shipard\Core\Document\DocumentEventDispatcher $eventDispatcher = null,
    ): Response {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return Response::error('BAD_REQUEST', 'Table does not support doc states', 400);
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol  = $dsDef->mainColumn;
        $cfg      = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $registry = $documentRegistry ?? new DocumentRegistry();
        $gateway  = new TableGateway(
            $table,
            $db->getDibiConnection(),
            $registry,
            $def->childTables,
            $config,
            $dsConfig,
            $eventDispatcher,
            $def->docStates,
            $def,
        );

        $existing = $gateway->loadDocument($id);
        if ($existing === null) {
            return Response::error('NOT_FOUND', 'Record not found', 404);
        }

        $currentState = (int) ($existing[$stateCol] ?? 0);
        if ($newState !== $currentState && !$cfg->isTransitionAllowed($currentState, $newState)) {
            return Response::error(
                'INVALID_STATE_TRANSITION',
                "Transition from state {$currentState} to {$newState} is not allowed.",
                422,
            );
        }

        $existing[$stateCol] = $newState;
        $existing[$mainCol]  = $cfg->getMainState($newState);

        $result = $gateway->saveDocument($existing);

        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                $errors = array_map(
                    fn($e) => ['field' => $e->column, 'code' => $e->code ?: 'INVALID', 'message' => $e->message],
                    $validation->getErrors(),
                );
                return Response::error('VALIDATION_ERROR', 'Validation failed', 422, $errors);
            }
            if ($result->isDomainError()) {
                return Response::error(
                    $result->getDomainErrorCode() ?: 'INVALID_STATE_TRANSITION',
                    $result->getErrorMessage() ?? 'State transition failed',
                    422,
                );
            }
            return Response::error('INTERNAL_ERROR', $result->getErrorMessage() ?? 'Save failed', 500);
        }

        $record = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $id);
        if ($record !== null) {
            $record = TableAccessGuard::stripSensitive($record, $def);
        }
        $dataResolved = $this->resolveLookupValuesForRecord(
            $table, $def, $record ?? [], $formRegistry, $db, $config,
            $lookupRegistry, $modulePathResolver, $language, $tables,
        );
        $payload = [
            'id'           => $id,
            'data'         => $record,
            'dataResolved' => $dataResolved,
        ];
        $warnings = $this->validationWarnings($result);
        if ($warnings !== null) {
            $payload['warnings'] = $warnings;
        }
        return Response::success($payload);
    }

    private function applyStateTransition(
        string $table,
        int $id,
        array $body,
        TableDefinition $def,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        FormRegistry $formRegistry,
        ModulePathResolver $modulePathResolver,
        LookupRegistry $lookupRegistry,
        string $language,
        array $tables,
    ): Response {
        $dsDef = $def->docStates;
        if ($dsDef === null || $config === null) {
            return Response::error('BAD_REQUEST', 'Table does not support doc states', 400);
        }

        $stateCol = $dsDef->stateColumn;
        $mainCol  = $dsDef->mainColumn;
        $cfg      = DocStateConfig::fromCfgItem($config->cfgItem($dsDef->cfgItem));

        $currentRow   = $db->fetchRow("SELECT `{$stateCol}` FROM `{$table}` WHERE `id` = %i", $id);
        if ($currentRow === null) {
            return Response::error('NOT_FOUND', 'Record not found', 404);
        }
        $currentState = (int) $currentRow[$stateCol];
        $newState     = (int) $body[$stateCol];

        if ($newState !== $currentState && !$cfg->isTransitionAllowed($currentState, $newState)) {
            return Response::error(
                'INVALID_STATE_TRANSITION',
                "Transition from state {$currentState} to {$newState} is not allowed.",
                422,
            );
        }

        $db->updateWhere($table, [
            $stateCol => $newState,
            $mainCol  => $cfg->getMainState($newState),
        ], 'id = %i', $id);

        $record = $db->fetchRow("SELECT * FROM `{$table}` WHERE `id` = %i", $id);
        if ($record !== null) {
            $record = TableAccessGuard::stripSensitive($record, $def);
        }
        $dataResolved = $this->resolveLookupValuesForRecord(
            $table, $def, $record ?? [], $formRegistry, $db, $config,
            $lookupRegistry, $modulePathResolver, $language, $tables,
        );
        return Response::success([
            'id'           => $id,
            'data'         => $record,
            'dataResolved' => $dataResolved,
        ]);
    }

    private function hasColumn(TableDefinition $def, string $colId): bool
    {
        foreach ($def->columns as $col) {
            if ($col->id === $colId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Vrátí všechny lookup elementy z FormDefinition (rekurze přes
     * tabs → sections → columns → elements). Inline group neobsahuje
     * lookup elementy (validace na úrovni FormElement to zaručuje).
     *
     * @return list<FormElement>
     */
    private function collectLookupElements(FormDefinition $formDef): array
    {
        $out = [];
        foreach ($formDef->tabs as $tab) {
            if ($tab->type !== 'fields') {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $column) {
                    foreach ($column->elements as $el) {
                        if ($el->type === 'lookup') {
                            $out[] = $el;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Vrátí mapu `{column → {id, primary, secondary}}` pro každý lookup element,
     * jehož hodnota v `$data` je ne-null a kde resolve uspěl. Klíče se nevkládají
     * pro null hodnoty ani pro lookup elementy ukazující na nezaregistrovanou tabulku.
     *
     * @param array<string, TableDefinition> $tables
     * @return array<string, array{id: int|string, primary: string, secondary: string|null}>
     */
    private function buildDataResolved(
        FormDefinition $formDef,
        array $data,
        LookupRegistry $lookupRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        array $tables,
    ): array {
        $result = [];
        foreach ($this->collectLookupElements($formDef) as $element) {
            $column = $element->column;
            if ($column === null || $column === '') {
                continue;
            }
            $value = $data[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $lookupCfg = $element->lookup;
            if (!is_array($lookupCfg)) {
                continue;
            }
            $targetTable = $lookupCfg['table'] ?? null;
            if (!is_string($targetTable) || $targetTable === '') {
                continue;
            }
            $targetDef = $tables[$targetTable] ?? null;
            $lookup = $lookupRegistry->create($targetTable, $db, $config, $targetDef);
            if ($lookup === null) {
                continue;
            }
            $items = $lookup->resolve([$value]);
            if ($items === []) {
                continue;
            }
            $result[$column] = $items[0]->toArray();
        }
        return $result;
    }

    /**
     * Rebuild FormDefinition for a saved record and resolve all its lookup
     * values. Used in save() and state-transition responses to keep the
     * client's `dataResolved` keš in sync.
     *
     * @param array<string, TableDefinition> $tables
     * @return array<string, array{id: int|string, primary: string, secondary: string|null}>
     */
    private function resolveLookupValuesForRecord(
        string $table,
        TableDefinition $def,
        array $record,
        FormRegistry $formRegistry,
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        LookupRegistry $lookupRegistry,
        ModulePathResolver $modulePathResolver,
        string $language,
        array $tables,
    ): array {
        if ($record === []) {
            return [];
        }
        $formDef = $this->resolveFormDefinition(
            $table, $def, $record, /*$isNew*/ false,
            $formRegistry, $db, $config, $modulePathResolver, $language,
        );
        return $this->buildDataResolved($formDef, $record, $lookupRegistry, $db, $config, $tables);
    }

    /**
     * Convert a defaults[] query-string value (always string) to the column's
     * native PHP type, so the frontend can match it against typed option lists
     * (Svelte `<select bind:value>` is strict-equality).
     */
    private function coerceDefaultValue(mixed $value, string $columnType): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($columnType) {
            'tinyint', 'smallint', 'int', 'bigint', 'enumInt' => is_numeric($value) ? (int) $value : $value,
            'numeric', 'float' => is_numeric($value) ? (float) $value : $value,
            'boolean' => is_string($value)
                ? in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)
                : (bool) $value,
            default => $value,
        };
    }

    /**
     * Strukturované sloupce (#74) → virtuální sloupce `<sloupec>.<pole>` (S4):
     * klient dostane `filing_profile.c_ufo` a edituje je běžnými elementy
     * formuláře, o JSONu neví. Zpátky je skládá `TableGateway` (I3), ne
     * controller.
     *
     * Pravidla:
     *  - surový sloupec v datech (načtený záznam, hodnota dopočtená formem)
     *    je autoritativní — rozpadne se na pole schématu a z dat zmizí;
     *  - bez surového sloupce se plochých klíčů od klienta nikdo nedotýká
     *    (recalculate posílá jen je), u nového záznamu se chybějící doplní
     *    z `default` schématu;
     *  - `sensitive` sloupec se přeskakuje — `stripSensitive` ho z dat
     *    odstranil, takže bychom klientovi poslali samá null a uložení by
     *    hodnotu smazalo;
     *  - schéma, které se nepodařilo načíst (nezkompilovaná konfigurace),
     *    nechává sloupec být.
     *
     * @param array<string, mixed> $data
     */
    private function flattenStructuredColumns(
        array $data,
        TableDefinition $def,
        ?ConfigRuntime $config,
        ?TableForm $form,
        bool $isNew,
    ): array {
        $columns = $def->getStructuredColumns();
        if ($columns === []) {
            return $data;
        }

        $resolver = new StructuredFieldResolver($config);
        $sensitive = $def->getSensitiveColumns();

        foreach ($columns as $column => $staticKey) {
            if (in_array($column, $sensitive, true)) {
                continue;
            }
            $hasColumn = array_key_exists($column, $data);
            if (!$hasColumn && !$isNew) {
                continue;
            }
            $schema = $resolver->forWrite($form?->structuredSchemaFor($column, $data), $staticKey);
            if ($schema === null) {
                continue;
            }

            $value = $hasColumn ? StructuredFieldValues::decode($data[$column]) : null;
            unset($data[$column]);

            foreach ($schema->fields as $fieldId => $field) {
                $key = StructuredSchema::virtualColumn($column, $fieldId);
                if ($hasColumn) {
                    $data[$key] = $value[$fieldId] ?? null;
                } elseif (!array_key_exists($key, $data)) {
                    $data[$key] = $field->default;
                }
            }
        }

        return $data;
    }

    /**
     * JSON sloupce jdou z DB jako string (žádná auto-deserializace v gateway) —
     * pro form editaci (multiselect apod.) je klient potřebuje jako hodnotu.
     * Nevalidní JSON se ponechá beze změny, ať o data nepřijdeme.
     */
    private function decodeJsonColumns(array $data, TableDefinition $def): array
    {
        foreach ($def->columns as $col) {
            if ($col->type !== 'json') {
                continue;
            }
            $value = $data[$col->id] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $decoded = json_decode($value, true);
            if ($decoded !== null) {
                $data[$col->id] = $decoded;
            }
        }
        return $data;
    }
}
