<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Item;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\PartyToPersonCanonical;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\Resolve\AccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\KindResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Resolve\SupplierCodesResolver;
use Shipard\Module\Core\Exchange\Resolve\UnitResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Orchestrator of the canonical Item → DB save pipeline. See
 * docs/exchange-format-items.md §10 for the full step sequence.
 *
 *   /validate  — schema + ItemValidator, no DB writes, no resolve.
 *   /preview   — validate + full resolve (header + kind + unit + supplierCodes
 *                + code_conflict probe). No DB writes.
 *   /apply     — validate + resolve + reconcile with userAction + outer
 *                transaction { side-create kind, header upsert,
 *                supplier-code mappings, lineage stamp }.
 *
 * Items Phase 1 has one sub-collection (supplierCodes), so the pipeline
 * is strictly simpler than the Person flow.
 *
 * Header save goes through ItemDocument (via TransactionlessTableGateway):
 *   - ItemDocument::beforeSave auto-generates `code` when missing — so
 *     the applier drops `code` from the payload when the canonical sends
 *     null/empty.
 *   - ItemDocument::beforeSave denormalizes `item_type` from `item_kind`
 *     — applier does not set `item_type` itself.
 *
 * Kind side-create goes through ItemKindDocument (in the same
 * transaction) when `_resolve.kind.status == canCreate` and
 * `_resolve.kind.userAction == "create"`.
 *
 * Supplier autocreate delegates to PersonApplier::apply with a payload
 * built via {@see PartyToPersonCanonical} — only when
 * `_resolve.supplierCodes[i].supplier.userAction == "create"`. Default
 * (`null` userAction with `supplier.status == canCreate`) is SKIP with
 * a `supplier_unknown` warning. See spec §6.2.
 */
class ItemApplier
{
    public const FORMAT_ID = 'shpd.items.item';
    public const FORMAT_VERSION = '1';

    /** Default targetDocState when canonical does not specify. */
    private const DEFAULT_TARGET_DOC_STATE = 10;

    /** Fallback unit `system_code` when payload's `unit` doesn't resolve. */
    private const FALLBACK_UNIT_SYSTEM_CODE = 'pcs';

    /** Fresh applier inserts for sub-records (V pořádku, řazení 2). */
    private const SUB_DOC_STATE = 40;
    private const SUB_DOC_STATE_MAIN = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly TransactionlessTableGateway $itemsGateway,
        private readonly TransactionlessTableGateway $kindsGateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly ItemValidator $itemValidator,
        private readonly ItemFlowResolver $flowResolver,
        private readonly UnitResolver $unitResolver,
        private readonly ?PersonApplier $personApplier = null,
        private readonly ?AccountResolver $accountResolver = null,
    ) {}

    /**
     * Production factory wiring a full Applier from a DataSourceConnection-
     * backed environment.
     *
     * @param array<string, TableDefinition> $tables
     */
    public static function create(
        Connection $db,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        DocumentRegistry $registry,
        array $tables,
        ?PersonApplier $personApplier = null,
    ): self {
        $own = new OwnCompanyResolver($db);
        $partyResolver = new PartyResolver($db, $own);
        $itemResolver = new ItemResolver($db);
        $kindResolver = new KindResolver($db);
        $unitResolver = new UnitResolver($db);
        $supplierCodesResolver = new SupplierCodesResolver($db, $partyResolver);
        $flowResolver = new ItemFlowResolver(
            $db, $itemResolver, $kindResolver, $unitResolver, $supplierCodesResolver,
        );

        $itemsGateway = self::buildGateway('economy_items', $db, $registry, $config, $dsConfig, $tables);
        $kindsGateway = self::buildGateway('economy_items_kinds', $db, $registry, $config, $dsConfig, $tables);

        return new self(
            db: $db,
            config: $config,
            itemsGateway: $itemsGateway,
            kindsGateway: $kindsGateway,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            itemValidator: new ItemValidator(),
            flowResolver: $flowResolver,
            unitResolver: $unitResolver,
            personApplier: $personApplier,
            accountResolver: new AccountResolver($db),
        );
    }

    /**
     * @param array<string, TableDefinition> $tables
     */
    private static function buildGateway(
        string $tableName,
        Connection $db,
        DocumentRegistry $registry,
        ConfigRuntime $config,
        DataSourceConfig $dsConfig,
        array $tables,
    ): TransactionlessTableGateway {
        $childTables = $tables[$tableName]?->childTables ?? [];
        return new TransactionlessTableGateway(
            $tableName, $db, $registry, $childTables, $config, $dsConfig,
            docStates: $tables[$tableName]?->docStates,
            tableDef: $tables[$tableName] ?? null,
        );
    }

    // ── Public entry points ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    public function validate(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                code: 'schema_invalid',
                message: 'Struktura položky neodpovídá schématu.',
                canonical: $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $validatorIssues = $this->itemValidator->validate($canonical);
        $enriched = $this->withResolveIssues($canonical, $validatorIssues);

        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error(
                code: 'validation_failed',
                message: 'Validace položky selhala.',
                canonical: $enriched,
                statusCode: 422,
            );
        }
        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function preview(array $canonical): ApplyResult
    {
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura položky neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $issues = $this->itemValidator->validate($canonical);
        $resolve = $this->flowResolver->resolve($canonical);
        $enriched = $this->withResolve($canonical, $resolve, $issues);

        return ApplyResult::ok($enriched);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    public function apply(array $canonical): ApplyResult
    {
        // 1. Schema
        $schemaIssues = $this->schemaValidator->validate($canonical, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return ApplyResult::error(
                'schema_invalid',
                'Struktura položky neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        // 2. ItemValidator
        $validatorIssues = $this->itemValidator->validate($canonical);

        // 3. Resolve (fresh — client's _resolve may be stale)
        $strategy = MergeStrategy::fromCanonical($canonical['applyOptions']['mergeStrategy'] ?? null);
        $resolve = $this->flowResolver->resolve($canonical);

        // 3b. Účet položky (číslo z účtového rozvrhu, datové sady #40) → id.
        //     Neznámý účet = warning, položka se uloží bez účtu.
        [$accountingAccountId, $accountIssue] = $this->resolveAccountingAccount($canonical);
        if ($accountIssue !== null) {
            $validatorIssues[] = $accountIssue;
        }

        // 4. Reconcile header userAction
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $headerUserAction = $clientResolve['header']['userAction'] ?? null;
        $headerDecision = $this->reconcileHeader($resolve->header, $headerUserAction, $strategy);
        $allIssues = array_merge($validatorIssues, $resolve->issues);
        $enriched = $this->withResolve($canonical, $resolve, $allIssues);

        if ($headerDecision['errorCode'] !== null) {
            return ApplyResult::error(
                $headerDecision['errorCode'],
                $headerDecision['errorMessage'],
                $enriched,
                statusCode: $headerDecision['statusCode'],
            );
        }

        // 5. Validation gate
        if ($this->hasErrors($allIssues)) {
            $codeConflict = $this->findIssue($allIssues, 'code_conflict');
            if ($codeConflict !== null) {
                return ApplyResult::error('code_conflict', $codeConflict['message'], $enriched, statusCode: 409);
            }
            return ApplyResult::error('validation_failed', 'Validace položky selhala.', $enriched);
        }
        // Kind unresolved guard
        $kindCheck = $this->checkKindResolution($resolve, $clientResolve);
        if ($kindCheck !== null) {
            return ApplyResult::error(
                'validation_failed',
                $kindCheck,
                $this->withResolve($canonical, $resolve, array_merge($allIssues, [[
                    'severity' => 'error',
                    'path'     => 'kind',
                    'code'     => 'kind_unresolved',
                    'message'  => $kindCheck,
                ]])),
            );
        }
        if ($this->shouldRejectOnIssues($canonical, $allIssues)) {
            return ApplyResult::error('validation_failed', 'Apply odmítnut: existují issues.', $enriched);
        }

        // 6–11. Transactional save
        $this->db->begin();
        try {
            $kindId = $this->resolveOrSideCreateKind($resolve, $clientResolve);
            $unitId = $this->resolveOrFallbackUnit($resolve);
            $savedItemId = $this->saveHeader($canonical, $resolve, $headerDecision, $kindId, $unitId, $accountingAccountId);
            $this->saveSupplierCodes($canonical, $resolve, $savedItemId, $clientResolve);
            $this->writeLineage($canonical, $savedItemId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ApplyResult::error('internal_error', $e->getMessage(), $enriched, statusCode: 500);
        }

        $finalCanonical = $this->annotateApplied($enriched, $savedItemId);
        $finalCanonical['savedItemId'] = $savedItemId;

        return ApplyResult::ok($finalCanonical, $savedItemId);
    }

    // ── Header reconcile + decision ─────────────────────────────────────────

    /**
     * @return array{errorCode: ?string, errorMessage: ?string, statusCode: int, useExistingId: ?int, forceCreate: bool}
     */
    private function reconcileHeader(ResolveResult $header, ?string $userAction, MergeStrategy $strategy): array
    {
        $default = [
            'errorCode' => null, 'errorMessage' => null, 'statusCode' => 200,
            'useExistingId' => null, 'forceCreate' => false,
        ];

        if (is_string($userAction) && str_starts_with($userAction, 'useExisting:')) {
            $idStr = substr($userAction, strlen('useExisting:'));
            if (!ctype_digit($idStr) || (int) $idStr <= 0) {
                return ['errorCode' => 'conflict', 'errorMessage' => 'Neplatné id v userAction.', 'statusCode' => 409,
                    'useExistingId' => null, 'forceCreate' => false];
            }
            $default['useExistingId'] = (int) $idStr;
            return $default;
        }
        if ($userAction === 'create') {
            $default['forceCreate'] = true;
            return $default;
        }

        switch ($header->status) {
            case ResolveStatus::Matched:
                if ($strategy === MergeStrategy::CreateOnly) {
                    return [
                        'errorCode' => 'item_exists',
                        'errorMessage' => 'Položka se shodným identifikátorem už v DB existuje (mergeStrategy=createOnly).',
                        'statusCode' => 409,
                        'useExistingId' => null,
                        'forceCreate' => false,
                    ];
                }
                $default['useExistingId'] = $header->matchedId;
                return $default;

            case ResolveStatus::CanCreate:
                return $default;

            case ResolveStatus::Ambiguous:
                return [
                    'errorCode' => 'unresolved_required',
                    'errorMessage' => 'Hlavička položky je nejednoznačná; doplňte _resolve.header.userAction.',
                    'statusCode' => 422,
                    'useExistingId' => null,
                    'forceCreate' => false,
                ];

            case ResolveStatus::NotFound:
                return [
                    'errorCode' => 'unresolved_required',
                    'errorMessage' => 'Hlavičku položky nelze automaticky vyřešit.',
                    'statusCode' => 422,
                    'useExistingId' => null,
                    'forceCreate' => false,
                ];
        }
        return $default;
    }

    // ── Kind ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $clientResolve
     */
    private function checkKindResolution(ItemResolveResult $resolve, array $clientResolve): ?string
    {
        $status = $resolve->kind->status;
        if ($status === ResolveStatus::Matched) return null;

        $userAction = $clientResolve['kind']['userAction'] ?? null;

        if ($status === ResolveStatus::CanCreate) {
            if ($userAction === 'create') return null;  // applier will side-create
            return 'Druh položky neexistuje; nastavte _resolve.kind.userAction = "create" pro automatické vytvoření.';
        }
        if ($status === ResolveStatus::Ambiguous) {
            if (is_string($userAction) && str_starts_with($userAction, 'useExisting:')) return null;
            return 'Druh položky je nejednoznačný; doplňte _resolve.kind.userAction.';
        }
        return 'Druh položky nelze přiřadit (chybí code / name / itemType).';
    }

    /**
     * @param array<string, mixed> $clientResolve
     */
    private function resolveOrSideCreateKind(ItemResolveResult $resolve, array $clientResolve): int
    {
        $userAction = $clientResolve['kind']['userAction'] ?? null;

        if ($resolve->kind->status === ResolveStatus::Matched) {
            return (int) $resolve->kind->matchedId;
        }

        if (is_string($userAction) && str_starts_with($userAction, 'useExisting:')) {
            return (int) substr($userAction, strlen('useExisting:'));
        }

        // canCreate + userAction = "create"
        if ($resolve->kind->status === ResolveStatus::CanCreate && $userAction === 'create') {
            $payload = $resolve->kind->createPayload;
            $result = $this->kindsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException('Side-create kind failed: ' . $this->describeSaveFailure($result));
            }
            return (int) $result->getData()['id'];
        }

        throw new \RuntimeException('Kind unresolved at save time.');
    }

    // ── Unit ───────────────────────────────────────────────────────────────

    private function resolveOrFallbackUnit(ItemResolveResult $resolve): int
    {
        if ($resolve->unit->status === ResolveStatus::Matched) {
            return (int) $resolve->unit->matchedId;
        }
        // Fallback to seeded `pcs` unit. If the seed is missing, fail hard —
        // every Shipard DS ships with `pcs` from UnitsProvisioner.
        $fallback = $this->unitResolver->resolve(self::FALLBACK_UNIT_SYSTEM_CODE);
        if ($fallback->status !== ResolveStatus::Matched) {
            throw new \RuntimeException('Fallback unit `pcs` not found in core_units — DS seeding may be broken.');
        }
        return (int) $fallback->matchedId;
    }

    // ── Header save ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array{useExistingId: ?int, forceCreate: bool, errorCode: ?string, errorMessage: ?string, statusCode: int} $decision
     */
    private function saveHeader(
        array $canonical,
        ItemResolveResult $resolve,
        array $decision,
        int $kindId,
        int $unitId,
        ?int $accountingAccountId = null,
    ): int {
        $useExistingId = $decision['useExistingId'];
        $strategy = MergeStrategy::fromCanonical($canonical['applyOptions']['mergeStrategy'] ?? null);
        $targetDocState = (int) ($canonical['applyOptions']['targetDocState'] ?? self::DEFAULT_TARGET_DOC_STATE);

        if ($useExistingId === null) {
            $payload = $this->transformHeaderForCreate($canonical, $kindId, $unitId, $targetDocState, $accountingAccountId);
            $result = $this->itemsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException('Header create failed: ' . $this->describeSaveFailure($result));
            }
            return (int) $result->getData()['id'];
        }

        $existing = $this->loadHeader($useExistingId);
        if ($existing === null) {
            throw new \RuntimeException("Existing item #{$useExistingId} not found.");
        }

        if ($strategy === MergeStrategy::UpdateHeader || $strategy === MergeStrategy::FullSync) {
            $patch = $this->transformHeaderForUpdate($canonical, $existing, $kindId, $unitId, overwrite: true, accountingAccountId: $accountingAccountId);
        } elseif ($strategy === MergeStrategy::MergeAdd) {
            $patch = $this->transformHeaderForUpdate($canonical, $existing, $kindId, $unitId, overwrite: false, accountingAccountId: $accountingAccountId);
        } else {
            // CreateOnly should never reach here (rejected in reconcileHeader).
            return $useExistingId;
        }

        if ($patch === []) {
            return $useExistingId;
        }

        $payload = array_merge($existing, $patch);
        $payload['id'] = $useExistingId;
        $result = $this->itemsGateway->saveDocument($payload);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Header update failed: ' . $this->describeSaveFailure($result));
        }
        return $useExistingId;
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function transformHeaderForCreate(array $canonical, int $kindId, int $unitId, int $targetDocState, ?int $accountingAccountId = null): array
    {
        $status = is_array($canonical['status'] ?? null) ? $canonical['status'] : [];

        $payload = [
            'name'              => $this->normalize($canonical['name'] ?? null) ?? '',
            'description'       => $this->normalize($canonical['description'] ?? null),
            'sku'               => $this->normalize($canonical['sku'] ?? null),
            'ean'               => $this->normalize($canonical['ean'] ?? null),
            'item_kind'         => $kindId,
            'unit'              => $unitId,
            'sales_price_no_vat' => $this->floatOrNull($canonical['salesPriceNoVat'] ?? null),
            'valid_from'        => $this->normalize($canonical['validFrom'] ?? null),
            'valid_to'          => $this->normalize($canonical['validTo'] ?? null),
            'docState'          => $targetDocState,
        ];
        if ($accountingAccountId !== null) {
            $payload['accounting_account'] = $accountingAccountId;
        }
        $tags = $this->contentTags($canonical);
        if ($tags !== null) {
            $payload['content_tags'] = $tags;
        }

        // `code`: when explicit, pass through; when null/empty, drop the key
        // and let ItemDocument::beforeSave generate a hex.
        $code = $this->normalize($canonical['code'] ?? null);
        if ($code !== null) {
            $payload['code'] = $code;
        }

        return $payload;
    }

    /**
     * Build update patch for an existing header. `mergeAdd` only fills
     * DB-empty columns; `fullSync` / `updateHeader` overwrite everything
     * except `code` (business unique key) and status fields (`docState`,
     * `is_closed`-equivalent — items don't have a separate is_closed
     * column; status changes stay an explicit user decision).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function transformHeaderForUpdate(
        array $canonical,
        array $existing,
        int $kindId,
        int $unitId,
        bool $overwrite,
        ?int $accountingAccountId = null,
    ): array {
        $candidates = [
            'name'               => $this->normalize($canonical['name'] ?? null),
            'description'        => $this->normalize($canonical['description'] ?? null),
            'sku'                => $this->normalize($canonical['sku'] ?? null),
            'ean'                => $this->normalize($canonical['ean'] ?? null),
            'item_kind'          => $kindId,
            'unit'               => $unitId,
            'sales_price_no_vat' => $this->floatOrNull($canonical['salesPriceNoVat'] ?? null),
            'valid_from'         => $this->normalize($canonical['validFrom'] ?? null),
            'valid_to'           => $this->normalize($canonical['validTo'] ?? null),
            'accounting_account' => $accountingAccountId,
            'content_tags'       => $this->contentTags($canonical),
        ];

        $patch = [];
        foreach ($candidates as $col => $value) {
            if ($value === null) {
                continue;
            }
            if ($overwrite || $this->isDbEmpty($existing[$col] ?? null)) {
                $patch[$col] = $value;
            }
        }
        return $patch;
    }

    private function loadHeader(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM [economy_items] WHERE [id] = %i LIMIT 1', $id,
        );
        return $row !== null ? $row->toArray() : null;
    }

    // ── SupplierCodes save ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve
     */
    private function saveSupplierCodes(
        array $canonical,
        ItemResolveResult $resolve,
        int $itemId,
        array $clientResolve,
    ): void {
        $strategy = MergeStrategy::fromCanonical($canonical['applyOptions']['mergeStrategy'] ?? null);
        if ($strategy === MergeStrategy::UpdateHeader) {
            return;  // supplierCodes untouched
        }

        $payloadSupplierCodes = is_array($canonical['supplierCodes'] ?? null) ? $canonical['supplierCodes'] : [];
        foreach ($resolve->supplierCodes as $entryIdx => $entry) {
            // Locate the matching client-_resolve entry for userAction overrides.
            $clientEntry = $this->findSubEntry($clientResolve, 'supplierCodes', $entry['index'] ?? $entryIdx);
            $subAction = $clientEntry['userAction'] ?? null;
            if ($subAction === 'skip') continue;

            $supplierStatus = $entry['supplier']['status'] ?? null;
            $supplierId = $entry['supplier']['matchedId'] ?? null;
            $entryStatus = $entry['status'] ?? null;
            $supplierCode = $entry['supplierCode'] ?? null;
            $supplierName = $entry['supplierName'] ?? null;
            $i = (int) ($entry['index'] ?? $entryIdx);

            // Matched supplier + existing mapping → leave alone.
            if ($supplierStatus === 'matched' && $entryStatus === 'matched') {
                continue;
            }

            // Matched supplier + missing mapping → INSERT IGNORE.
            if ($supplierStatus === 'matched' && $entryStatus === 'canCreate' && $supplierCode !== null) {
                $this->insertSupplierCodeMapping((int) $supplierId, $itemId, $supplierCode, $supplierName);
                continue;
            }

            // canCreate supplier + userAction=create → autocreate partner,
            // then INSERT IGNORE.
            if ($entryStatus === 'skipped' && $subAction === 'create') {
                if ($this->personApplier === null) {
                    throw new \RuntimeException("Supplier autocreate requested but PersonApplier not wired (supplierCodes[{$i}]).");
                }
                $supplierFragment = $payloadSupplierCodes[$i]['supplier'] ?? [];
                if (!is_array($supplierFragment)) $supplierFragment = [];
                $personCanonical = PartyToPersonCanonical::toPersonCanonical($supplierFragment);
                $personCanonical['applyOptions'] = ['mergeStrategy' => 'createOnly', 'targetDocState' => 40];
                $personResult = $this->personApplier->apply($personCanonical);
                if (!$personResult->success || $personResult->savedId === null) {
                    throw new \RuntimeException("Supplier autocreate failed (supplierCodes[{$i}]): " . ($personResult->errorMessage ?? 'unknown'));
                }
                if ($supplierCode !== null) {
                    $this->insertSupplierCodeMapping($personResult->savedId, $itemId, $supplierCode, $supplierName);
                }
                continue;
            }

            // skipped + userAction null/other → leave (already noted as skipped in _resolve).
        }
    }

    private function insertSupplierCodeMapping(int $personId, int $itemId, string $supplierCode, ?string $supplierName): void
    {
        $this->executeSql(
            'INSERT IGNORE INTO [economy_items_supplier_codes]
             ([person], [item], [supplier_code], [supplier_name], [created])
             VALUES (%i, %i, %s, %sN, NOW())',
            $personId, $itemId, $supplierCode, $supplierName,
        );
    }

    /**
     * @param array<string, mixed> $clientResolve
     * @return array<string, mixed>|null
     */
    private function findSubEntry(array $clientResolve, string $key, int $index): ?array
    {
        $entries = $clientResolve[$key] ?? null;
        if (!is_array($entries)) return null;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            if (($entry['index'] ?? null) === $index) return $entry;
        }
        return null;
    }

    // ── Lineage ────────────────────────────────────────────────────────────

    /**
     * Stamp source_kind / source_ref / source_imported_at on the item
     * row — but only when the payload carries `source.kind`. Manual UI
     * edits which omit `source` therefore preserve the previous lineage.
     *
     * @param array<string, mixed> $canonical
     */
    private function writeLineage(array $canonical, int $itemId): void
    {
        $kind = $canonical['source']['kind'] ?? null;
        if (!is_string($kind) || trim($kind) === '') {
            return;
        }
        $registryRef = $this->normalize($canonical['source']['registryRef'] ?? null);
        $this->executeSql(
            'UPDATE [economy_items] SET ',
            [
                'source_kind'        => $kind,
                'source_ref'         => $registryRef,
                'source_imported_at' => new \DateTimeImmutable(),
            ],
            'WHERE [id] = %i', $itemId,
        );
    }

    /**
     * Thin wrapper around `Connection::query()` so subclasses (testing
     * doubles) can intercept.
     */
    protected function executeSql(mixed ...$args): mixed
    {
        return $this->db->query(...$args);
    }

    // ── Output enrichment ──────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolveIssues(array $canonical, array $issues): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['issues'] = array_merge(
            is_array($resolve['issues'] ?? null) ? $resolve['issues'] : [],
            $issues,
        );
        $resolve['summary'] = $this->buildSummary(null, $resolve['issues']);
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function withResolve(array $canonical, ItemResolveResult $resolve, array $issues): array
    {
        $serialized = $resolve->toArray();
        $serialized['issues'] = array_merge($serialized['issues'], $issues);
        $serialized['summary'] = $this->buildSummary($resolve, $serialized['issues']);
        $canonical['_resolve'] = $serialized;
        return $canonical;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function buildSummary(?ItemResolveResult $resolve, array $issues): array
    {
        $headerStatus = $resolve?->header->status->value ?? 'unknown';
        $kindStatus = $resolve?->kind->status->value ?? 'unknown';
        $unitStatus = $resolve?->unit->status->value ?? 'unknown';

        $supplierCounts = ['matched' => 0, 'canCreate' => 0, 'skipped' => 0];
        if ($resolve !== null) {
            foreach ($resolve->supplierCodes as $entry) {
                $status = $entry['status'] ?? null;
                if (isset($supplierCounts[$status])) {
                    $supplierCounts[$status]++;
                }
            }
        }

        $errors = 0;
        $warnings = 0;
        foreach ($issues as $i) {
            $sev = $i['severity'] ?? null;
            if ($sev === 'error') $errors++;
            elseif ($sev === 'warning') $warnings++;
        }

        $status = match (true) {
            $errors > 0 => 'hasErrors',
            ($resolve !== null && (
                $resolve->header->status === ResolveStatus::Ambiguous
                || $resolve->header->status === ResolveStatus::CanCreate
                || $resolve->kind->status === ResolveStatus::CanCreate
                || $resolve->kind->status === ResolveStatus::Ambiguous
                || $supplierCounts['canCreate'] > 0
                || $supplierCounts['skipped'] > 0
                || $warnings > 0
            )) => 'needsAttention',
            default => 'ok',
        };

        return [
            'status'             => $status,
            'headerStatus'       => $headerStatus,
            'kindStatus'         => $kindStatus,
            'unitStatus'         => $unitStatus,
            'supplierCodeCount'  => $supplierCounts,
            'errorCount'         => $errors,
            'warningCount'       => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function annotateApplied(array $canonical, int $savedItemId): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['summary'] = ($resolve['summary'] ?? []);
        $resolve['summary']['status'] = 'applied';
        if (is_array($resolve['header'] ?? null) && ($resolve['header']['status'] ?? null) === 'canCreate') {
            $resolve['header']['status'] = 'matched';
            $resolve['header']['itemId'] = $savedItemId;
            $resolve['header']['matchedBy'] = 'created';
        }
        $canonical['_resolve'] = $resolve;
        return $canonical;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? null) === 'error') return true;
        }
        return false;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return ?array{severity: string, path: string, code: string, message: string}
     */
    private function findIssue(array $issues, string $code): ?array
    {
        foreach ($issues as $issue) {
            if (($issue['code'] ?? null) === $code) return $issue;
        }
        return null;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     */
    private function shouldRejectOnIssues(array $canonical, array $issues): bool
    {
        $reject = $canonical['applyOptions']['rejectOnIssues'] ?? null;
        if (!is_array($reject) || !in_array('warning', $reject, true)) {
            return false;
        }
        foreach ($issues as $i) {
            if (($i['severity'] ?? null) === 'warning') return true;
        }
        return false;
    }

    private function describeSaveFailure(\Shipard\Core\Document\DocumentResult $result): string
    {
        $msg = $result->getErrorMessage();
        if ($msg !== null && $msg !== '') {
            return $msg;
        }
        $validation = $result->getValidation();
        if ($validation !== null) {
            $errors = [];
            foreach ($validation->getErrors() as $err) {
                $errors[] = ($err->column ?? '?') . ': ' . ($err->message ?? '?');
            }
            return 'validation: ' . implode('; ', $errors);
        }
        return 'unknown';
    }

    /**
     * `accountingAccount` (číslo účtu) → `economy_accounting_accounts.id`.
     *
     * @param array<string, mixed> $canonical
     * @return array{0: ?int, 1: ?array{severity: string, path: string, code: string, message: string}}
     */
    private function resolveAccountingAccount(array $canonical): array
    {
        $number = $canonical['accountingAccount'] ?? null;
        if (!is_string($number) || trim($number) === '') {
            return [null, null];
        }
        $issue = static fn(string $msg): array => [
            'severity' => 'warning',
            'path'     => 'accountingAccount',
            'code'     => 'account_not_found',
            'message'  => $msg,
        ];
        if ($this->accountResolver === null) {
            return [null, $issue("Účet '{$number}' nelze přiřadit: resolver účtů není k dispozici.")];
        }
        try {
            $id = $this->accountResolver->resolve($number);
        } catch (\Throwable) {
            // Extension economy.accounting na DS není → tabulka účtů chybí.
            $id = null;
        }
        if ($id === null) {
            return [null, $issue("Účet '{$number}' nebyl v účtovém rozvrhu nalezen; položka se uloží bez účtu.")];
        }
        return [$id, null];
    }

    /**
     * @param array<string, mixed> $canonical
     * @return list<string>|null
     */
    private function contentTags(array $canonical): ?array
    {
        $tags = $canonical['contentTags'] ?? null;
        if (!is_array($tags)) {
            return null;
        }
        $tags = array_values(array_filter($tags, static fn($t): bool => is_string($t) && $t !== ''));
        return $tags === [] ? null : $tags;
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) return (float) $value;
        if (is_string($value) && is_numeric($value)) return (float) $value;
        return null;
    }

    private function isDbEmpty(mixed $value): bool
    {
        if ($value === null) return true;
        if (is_string($value) && trim($value) === '') return true;
        return false;
    }
}
