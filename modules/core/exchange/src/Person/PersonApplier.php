<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Person;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Resolve\AddressResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ContactResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

/**
 * Orchestrator of the canonical Person → DB save pipeline. See
 * docs/exchange-format-persons.md §11 for the full step sequence.
 *
 *   /validate  — schema + PersonValidator, no DB writes, no resolve.
 *   /preview   — validate + full resolve (header + sub-collections +
 *                closingExisting for fullSync). No DB writes.
 *   /apply     — validate + resolve + reconcile with userAction +
 *                outer transaction { header upsert + sub-collection
 *                insert/update/close + lineage update }.
 *
 * The Applier never reaches below the Document/SQL layer for the
 * header — PersonDocument::beforeSave handles `person_id` generation
 * and `full_name` composition. Sub-collections currently bypass
 * Document classes (there are none for addresses/contacts/bank
 * accounts) and are written via direct `$db->insert`/`$db->update`.
 */
class PersonApplier
{
    public const FORMAT_ID = 'shpd.persons.person';
    public const FORMAT_VERSION = '1';

    private const ACTIVE_STATES = [10, 40, 80];

    /** Sub-record `docState` for fresh applier inserts (V pořádku). */
    private const SUB_DOC_STATE = 40;
    private const SUB_DOC_STATE_MAIN = 2;

    public function __construct(
        private readonly Connection $db,
        private readonly ConfigRuntime $config,
        private readonly TransactionlessTableGateway $personsGateway,
        private readonly SchemaValidator $schemaValidator,
        private readonly PersonValidator $personValidator,
        private readonly PersonResolver $personResolver,
        private readonly AddressResolver $addressResolver,
        /** Má tabulka osob sloupec `send_attachments_merged` (extension docs.core)? */
        private readonly bool $hasSendAttachmentsMerged = true,
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
    ): self {
        $own = new OwnCompanyResolver($db);
        $partyResolver = new PartyResolver($db, $own);
        $addressResolver = new AddressResolver($db);
        $bankResolver = new BankAccountResolver($db);
        $contactResolver = new ContactResolver($db);

        $personsGateway = self::buildGateway('base_persons_persons', $db, $registry, $config, $dsConfig, $tables);

        return new self(
            db: $db,
            config: $config,
            personsGateway: $personsGateway,
            schemaValidator: new SchemaValidator(SchemaLoader::default()),
            personValidator: new PersonValidator(self::documentLanguages($config)),
            personResolver: new PersonResolver(
                $db, $partyResolver, $addressResolver, $bankResolver, $contactResolver,
            ),
            addressResolver: $addressResolver,
            hasSendAttachmentsMerged: self::hasColumn(
                $tables['base_persons_persons'] ?? null,
                'send_attachments_merged',
            ),
        );
    }

    /** @return list<string>|null Null = cfgItem chybí (zdroj dat před ds-upgrade). */
    private static function documentLanguages(ConfigRuntime $config): ?array
    {
        $languages = $config->cfgItem('world.base.documentLanguages');
        return is_array($languages) ? array_map('strval', array_keys($languages)) : null;
    }

    private static function hasColumn(?TableDefinition $table, string $column): bool
    {
        foreach ($table?->columns ?? [] as $col) {
            if ($col->id === $column) {
                return true;
            }
        }
        return false;
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
                message: 'Struktura osoby neodpovídá schématu.',
                canonical: $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $validatorIssues = $this->validatorIssues($canonical);
        $enriched = $this->withResolveIssues($canonical, $validatorIssues);

        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error(
                code: 'validation_failed',
                message: 'Validace osoby selhala.',
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
                'Struktura osoby neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        $issues = $this->validatorIssues($canonical);
        $strategy = MergeStrategy::fromCanonical($canonical['applyOptions']['mergeStrategy'] ?? null);
        $resolve = $this->personResolver->resolve($canonical, $strategy);
        $enriched = $this->withResolve($canonical, $resolve, $issues);

        // preview always succeeds; client renders the payload and decides.
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
                'Struktura osoby neodpovídá schématu.',
                $this->withResolveIssues($canonical, $schemaIssues),
                statusCode: 400,
            );
        }

        // 2. PersonValidator
        $validatorIssues = $this->validatorIssues($canonical);

        // 3. Resolve (fresh — client's _resolve may be stale)
        $strategy = MergeStrategy::fromCanonical($canonical['applyOptions']['mergeStrategy'] ?? null);
        $resolve = $this->personResolver->resolve($canonical, $strategy);

        // 4. Reconcile header userAction
        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $headerUserAction = $clientResolve['header']['userAction'] ?? null;
        $headerDecision = $this->reconcileHeader($resolve->header, $headerUserAction, $strategy);
        $enriched = $this->withResolve($canonical, $resolve, array_merge($validatorIssues, $resolve->issues));

        if ($headerDecision['errorCode'] !== null) {
            return ApplyResult::error(
                $headerDecision['errorCode'],
                $headerDecision['errorMessage'],
                $enriched,
                statusCode: $headerDecision['statusCode'],
            );
        }

        // 5. Validation gate — errors block save.
        if ($this->hasErrors($validatorIssues)) {
            return ApplyResult::error('validation_failed', 'Validace osoby selhala.', $enriched);
        }
        if ($this->shouldRejectOnIssues($canonical, $validatorIssues, $resolve->issues)) {
            return ApplyResult::error('validation_failed', 'Apply odmítnut: existují issues.', $enriched);
        }

        // 6–9. Transactional save.
        $this->db->begin();
        try {
            $savedPersonId = $this->saveHeader($canonical, $resolve, $headerDecision);
            $this->saveSubCollections($canonical, $resolve, $strategy, $savedPersonId);
            $this->writeLineage($canonical, $savedPersonId);
            $this->db->commit();
        } catch (PersonIdConflictException $e) {
            $this->db->rollback();
            return ApplyResult::error(
                'person_id_conflict',
                $e->getMessage(),
                $enriched,
                statusCode: 409,
            );
        } catch (\Throwable $e) {
            $this->db->rollback();
            return ApplyResult::error('internal_error', $e->getMessage(), $enriched, statusCode: 500);
        }

        // Annotate canCreate references as matched (id known now) and stamp
        // `applied` status on the summary.
        $finalCanonical = $this->annotateApplied($enriched, $savedPersonId);
        $finalCanonical['savedPersonId'] = $savedPersonId;

        return ApplyResult::ok($finalCanonical, $savedPersonId);
    }

    /**
     * Issues validátoru + to, co ví jen applier: zdroj dat bez extension
     * `docs.core` nemá kam uložit spojování příloh — hodnota se ignoruje
     * a výsledek to řekne varováním (#94 D9).
     *
     * @param array<string, mixed> $canonical
     * @return array<int, array{severity: string, path: string, code: string, message: string}>
     */
    private function validatorIssues(array $canonical): array
    {
        $issues = $this->personValidator->validate($canonical);

        if (!$this->hasSendAttachmentsMerged
            && ($canonical['documents']['sendAttachmentsMerged'] ?? null) === true) {
            $issues[] = [
                'severity' => 'warning',
                'path'     => 'documents.sendAttachmentsMerged',
                'code'     => 'column_unavailable',
                'message'  => 'Spojování příloh do PDF dokladu tento zdroj dat neukládá — hodnota se ignoruje.',
            ];
        }

        return $issues;
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

        // Explicit userAction overrides default behaviour.
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
        // Other userActions ('skip' etc.) do not apply to header.

        // Default behaviour per resolve status + strategy.
        switch ($header->status) {
            case ResolveStatus::Matched:
                if ($strategy === MergeStrategy::CreateOnly) {
                    return [
                        'errorCode' => 'person_exists',
                        'errorMessage' => 'Osoba se shodným identifikátorem už v DB existuje (mergeStrategy=createOnly).',
                        'statusCode' => 409,
                        'useExistingId' => null,
                        'forceCreate' => false,
                    ];
                }
                $default['useExistingId'] = $header->matchedId;
                return $default;

            case ResolveStatus::CanCreate:
                return $default;  // create new

            case ResolveStatus::Ambiguous:
                return [
                    'errorCode' => 'unresolved_required',
                    'errorMessage' => 'Hlavička osoby je nejednoznačná; doplňte _resolve.header.userAction.',
                    'statusCode' => 422,
                    'useExistingId' => null,
                    'forceCreate' => false,
                ];

            case ResolveStatus::NotFound:
                return [
                    'errorCode' => 'unresolved_required',
                    'errorMessage' => 'Hlavičku osoby nelze automaticky vyřešit.',
                    'statusCode' => 422,
                    'useExistingId' => null,
                    'forceCreate' => false,
                ];
        }
        return $default;
    }

    // ── Header save ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     * @param array{useExistingId: ?int, forceCreate: bool, errorCode: ?string, errorMessage: ?string, statusCode: int} $decision
     */
    private function saveHeader(array $canonical, PersonResolveResult $resolve, array $decision): int
    {
        $useExistingId = $decision['useExistingId'];
        $strategy = MergeStrategy::fromCanonical($canonical['applyOptions']['mergeStrategy'] ?? null);
        $targetDocState = (int) ($canonical['applyOptions']['targetDocState'] ?? 10);

        if ($useExistingId === null) {
            // Create new
            $payload = $this->transformHeaderForCreate($canonical, $targetDocState);
            $this->guardPersonIdCollision($payload['person_id'] ?? null, existingId: null);
            $result = $this->personsGateway->saveDocument($payload);
            if (!$result->isSuccess()) {
                throw new \RuntimeException('Header create failed: ' . ($result->getErrorMessage() ?? 'unknown'));
            }
            return (int) $result->getData()['id'];
        }

        // Update existing per strategy
        $existing = $this->loadHeader($useExistingId);
        if ($existing === null) {
            throw new \RuntimeException("Existing person #{$useExistingId} not found.");
        }

        if ($strategy === MergeStrategy::UpdateHeader || $strategy === MergeStrategy::FullSync) {
            $payload = $this->transformHeaderForUpdate($canonical, $existing, overwrite: true);
        } elseif ($strategy === MergeStrategy::MergeAdd) {
            $payload = $this->transformHeaderForUpdate($canonical, $existing, overwrite: false);
        } else {
            // CreateOnly should never reach here (rejected in reconcileHeader).
            return $useExistingId;
        }

        $this->guardPersonIdMismatch($canonical, $existing);

        if ($payload === []) {
            // Nothing to update.
            return $useExistingId;
        }

        // TableGateway::saveDocument validates the supplied payload as-is
        // (no merge with the original row), so we must hand it the FULL
        // record including invariants like `person_type` that
        // PersonDocument::validate insists on. Merge order: existing
        // overridden by the canonical-derived patch.
        $payload = array_merge($existing, $payload);
        $payload['id'] = $useExistingId;
        $result = $this->personsGateway->saveDocument($payload);
        if (!$result->isSuccess()) {
            throw new \RuntimeException('Header update failed: ' . $this->describeSaveFailure($result));
        }
        return $useExistingId;
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
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function transformHeaderForCreate(array $canonical, int $targetDocState): array
    {
        $personType = $canonical['personType'] === 'company'
            ? PersonType::Company
            : PersonType::Person;

        $name = is_array($canonical['name'] ?? null) ? $canonical['name'] : [];
        $personal = is_array($canonical['personal'] ?? null) ? $canonical['personal'] : [];
        $contact = is_array($canonical['contact'] ?? null) ? $canonical['contact'] : [];
        $status = is_array($canonical['status'] ?? null) ? $canonical['status'] : [];

        $payload = [
            'person_type'        => $personType->value,
            'person_id'          => $this->normalize($canonical['personId'] ?? null) ?? '',
            'company_id'         => $this->normalize($canonical['companyId'] ?? null) ?? '',
            'tax_id'             => $this->normalize($canonical['taxId'] ?? null) ?? '',
            'vat_id'             => $this->normalize($canonical['vatId'] ?? null) ?? '',
            'court_registration' => $this->normalize($canonical['courtRegistration'] ?? null) ?? '',
            'gov_e_box_id'       => $this->normalize($canonical['govEBoxId'] ?? null) ?? '',
            'full_name'          => $this->normalize($name['fullName'] ?? null) ?? '',
            'complex_name'       => $this->deriveComplexName($name),
            'title_before'       => $this->normalize($name['titleBefore'] ?? null) ?? '',
            'first_name'         => $this->normalize($name['firstName'] ?? null) ?? '',
            'middle_name'        => $this->normalize($name['middleName'] ?? null) ?? '',
            'last_name'          => $this->normalize($name['lastName'] ?? null) ?? '',
            'title_after'        => $this->normalize($name['titleAfter'] ?? null) ?? '',
            'email'              => $this->normalize($contact['email'] ?? null) ?? '',
            'phone'              => $this->normalize($contact['phone'] ?? null) ?? '',
            'web'                => $this->normalize($contact['web'] ?? null) ?? '',
            'is_closed'          => ($status['isClosed'] ?? false) === true ? 1 : 0,
            'closed_date'        => $this->normalize($status['closedDate'] ?? null),
            'is_own'             => ($status['isOwn'] ?? false) === true ? 1 : 0,
            'docState'           => $targetDocState,
        ];

        if ($personType === PersonType::Person) {
            $payload['birth_date']     = $this->normalize($personal['birthDate'] ?? null);
            $payload['national_id']    = $this->normalize($personal['nationalId'] ?? null) ?? '';
            $payload['id_card_number'] = $this->normalize($personal['idCardNumber'] ?? null) ?? '';
        }

        return array_merge($payload, $this->documentsPayload($canonical, existing: null, overwrite: true));
    }

    /**
     * Blok `documents` (#94 D9): jazyk dokumentů a spojování příloh.
     *
     * Blok chybí → sloupce se nemění — obnova z registru ani starší payload
     * nesmí vynulovat ruční nastavení. Blok je → při založení a přepisu
     * (`updateHeader` / `fullSync`) se zapíšou obě hodnoty, `language: null`
     * jazyk smaže; `mergeAdd` doplní jen to, co v databázi nastavené není.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed>|null $existing Null = zakládá se nová osoba.
     * @return array<string, mixed>
     */
    private function documentsPayload(array $canonical, ?array $existing, bool $overwrite): array
    {
        $documents = $canonical['documents'] ?? null;
        if (!is_array($documents)) {
            return [];
        }

        $language = $this->normalize($documents['language'] ?? null);
        $merged   = ($documents['sendAttachmentsMerged'] ?? null) === true;

        $payload = [];
        if ($overwrite || ($language !== null && $this->isDbEmpty($existing['language'] ?? null))) {
            $payload['language'] = $language;
        }
        if ($this->hasSendAttachmentsMerged
            && ($overwrite || ($merged && (int) ($existing['send_attachments_merged'] ?? 0) === 0))) {
            $payload['send_attachments_merged'] = $merged ? 1 : 0;
        }

        return $payload;
    }

    /**
     * Build update payload for an existing header. `mergeAdd` only fills
     * DB-empty columns; `fullSync` / `updateHeader` overwrite everything.
     *
     * is_closed / closed_date / docState are NEVER auto-updated — closing
     * the person is an explicit user decision (spec §9 note).
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $existing  Current DB row.
     * @return array<string, mixed>  Empty when nothing changes.
     */
    private function transformHeaderForUpdate(array $canonical, array $existing, bool $overwrite): array
    {
        $name = is_array($canonical['name'] ?? null) ? $canonical['name'] : [];
        $personal = is_array($canonical['personal'] ?? null) ? $canonical['personal'] : [];
        $contact = is_array($canonical['contact'] ?? null) ? $canonical['contact'] : [];

        $candidates = [
            'company_id'         => $this->normalize($canonical['companyId'] ?? null),
            'tax_id'             => $this->normalize($canonical['taxId'] ?? null),
            'vat_id'             => $this->normalize($canonical['vatId'] ?? null),
            'court_registration' => $this->normalize($canonical['courtRegistration'] ?? null),
            'gov_e_box_id'       => $this->normalize($canonical['govEBoxId'] ?? null),
            'full_name'          => $this->normalize($name['fullName'] ?? null),
            'title_before'       => $this->normalize($name['titleBefore'] ?? null),
            'first_name'         => $this->normalize($name['firstName'] ?? null),
            'middle_name'        => $this->normalize($name['middleName'] ?? null),
            'last_name'          => $this->normalize($name['lastName'] ?? null),
            'title_after'        => $this->normalize($name['titleAfter'] ?? null),
            'email'              => $this->normalize($contact['email'] ?? null),
            'phone'              => $this->normalize($contact['phone'] ?? null),
            'web'                => $this->normalize($contact['web'] ?? null),
            'birth_date'         => $this->normalize($personal['birthDate'] ?? null),
            'national_id'        => $this->normalize($personal['nationalId'] ?? null),
            'id_card_number'     => $this->normalize($personal['idCardNumber'] ?? null),
        ];

        $payload = [];
        foreach ($candidates as $col => $value) {
            if ($value === null) {
                continue;  // payload has nothing to say about this column
            }
            if ($overwrite || $this->isDbEmpty($existing[$col] ?? null)) {
                $payload[$col] = $value;
            }
        }

        $complex = $this->deriveComplexName($name);
        if ($overwrite || (int) ($existing['complex_name'] ?? 0) === 0) {
            $payload['complex_name'] = $complex;
        }

        return array_merge($payload, $this->documentsPayload($canonical, $existing, $overwrite));
    }

    /**
     * @param array<string, mixed> $name
     */
    private function deriveComplexName(array $name): int
    {
        foreach (['titleBefore', 'middleName', 'titleAfter'] as $field) {
            if ($this->normalize($name[$field] ?? null) !== null) {
                return 1;
            }
        }
        return 0;
    }

    private function loadHeader(int $id): ?array
    {
        $row = $this->db->fetch(
            'SELECT * FROM [base_persons_persons] WHERE [id] = %i LIMIT 1', $id,
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * If the canonical carries a `personId` and another person row already
     * uses it, refuse with 409 person_id_conflict.
     */
    private function guardPersonIdCollision(?string $personId, ?int $existingId): void
    {
        if ($personId === null || $personId === '') return;
        $sql = 'SELECT [id] FROM [base_persons_persons]
                WHERE [person_id] = %s AND [docState] != 90';
        $params = [$personId];
        if ($existingId !== null) {
            $sql .= ' AND [id] != %i';
            $params[] = $existingId;
        }
        $sql .= ' LIMIT 1';
        $row = $this->db->fetch($sql, ...$params);
        if ($row !== null) {
            throw new PersonIdConflictException(
                "Kód osoby „{$personId}\" už používá záznam #{$row['id']}.",
            );
        }
    }

    /**
     * Spec §3 — for matched headers, a mismatching `personId` in payload is
     * a warning, not an error. The existing id is kept. We record the
     * warning into the canonical's _resolve.issues post-save.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $existing
     */
    private function guardPersonIdMismatch(array $canonical, array $existing): void
    {
        // No-op in Phase 1 — kept as a hook for future warning emission.
        // The mismatch only matters for documentation; existing person_id
        // is authoritative and the applier does not overwrite it on update.
    }

    private function isDbEmpty(mixed $value): bool
    {
        if ($value === null) return true;
        if (is_string($value) && trim($value) === '') return true;
        return false;
    }

    // ── Sub-collection save ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $canonical
     */
    private function saveSubCollections(
        array $canonical,
        PersonResolveResult $resolve,
        MergeStrategy $strategy,
        int $personId,
    ): void {
        if ($strategy === MergeStrategy::UpdateHeader) {
            return;  // sub-collections untouched
        }

        $clientResolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];

        $this->saveAddresses($canonical, $resolve, $strategy, $personId, $clientResolve);
        $this->saveBankAccounts($canonical, $resolve, $strategy, $personId, $clientResolve);
        $this->saveContacts($canonical, $resolve, $strategy, $personId, $clientResolve);

        if ($strategy === MergeStrategy::FullSync) {
            $this->closeMissingSubRecords($resolve);
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve
     */
    private function saveAddresses(
        array $canonical,
        PersonResolveResult $resolve,
        MergeStrategy $strategy,
        int $personId,
        array $clientResolve,
    ): void {
        $payloadAddresses = is_array($canonical['addresses'] ?? null) ? $canonical['addresses'] : [];
        foreach ($payloadAddresses as $i => $addr) {
            if (!is_array($addr)) continue;
            $result = $resolve->addresses[$i] ?? null;
            if ($result === null) continue;
            if ($this->subRecordSkipped($clientResolve, 'addresses', $i)) continue;

            if ($result->status === ResolveStatus::Matched) {
                if ($strategy === MergeStrategy::FullSync
                    || ($strategy === MergeStrategy::MergeAdd && $result->authoritativeRefresh)) {
                    $this->updateAddress($result->matchedId, $addr);
                }
                // mergeAdd without authoritativeRefresh → leave alone.
                continue;
            }
            if ($result->status === ResolveStatus::CanCreate) {
                $payload = $result->createPayload;
                $payload['person'] = $personId;
                $this->insertSubRecord('base_persons_addresses', $payload);
            }
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve
     */
    private function saveBankAccounts(
        array $canonical,
        PersonResolveResult $resolve,
        MergeStrategy $strategy,
        int $personId,
        array $clientResolve,
    ): void {
        $payloadBanks = is_array($canonical['bankAccounts'] ?? null) ? $canonical['bankAccounts'] : [];
        foreach ($payloadBanks as $i => $bank) {
            if (!is_array($bank)) continue;
            $result = $resolve->bankAccounts[$i] ?? null;
            if ($result === null) continue;
            if ($this->subRecordSkipped($clientResolve, 'bankAccounts', $i)) continue;

            if ($result->status === ResolveStatus::Matched) {
                if ($strategy === MergeStrategy::FullSync) {
                    $this->updateBankAccount($result->matchedId, $bank);
                }
                continue;
            }
            if ($result->status === ResolveStatus::CanCreate) {
                $payload = $this->buildBankAccountInsertPayload($bank);
                $payload['person'] = $personId;
                $this->insertSubRecord('base_persons_bank_accounts', $payload);
            }
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $clientResolve
     */
    private function saveContacts(
        array $canonical,
        PersonResolveResult $resolve,
        MergeStrategy $strategy,
        int $personId,
        array $clientResolve,
    ): void {
        $payloadContacts = is_array($canonical['contacts'] ?? null) ? $canonical['contacts'] : [];
        foreach ($payloadContacts as $i => $contact) {
            if (!is_array($contact)) continue;
            $result = $resolve->contacts[$i] ?? null;
            if ($result === null) continue;
            if ($this->subRecordSkipped($clientResolve, 'contacts', $i)) continue;

            if ($result->status === ResolveStatus::Matched) {
                if ($strategy === MergeStrategy::FullSync) {
                    $this->updateContact($result->matchedId, $contact);
                }
                continue;
            }
            if ($result->status === ResolveStatus::CanCreate) {
                $payload = $result->createPayload;
                $payload['person'] = $personId;
                $this->insertSubRecord('base_persons_contacts', $payload);
            }
        }
    }

    /**
     * @param array<string, mixed> $clientResolve
     */
    private function subRecordSkipped(array $clientResolve, string $key, int $index): bool
    {
        $entries = $clientResolve[$key] ?? null;
        if (!is_array($entries)) return false;
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            if (($entry['index'] ?? null) === $index && ($entry['userAction'] ?? null) === 'skip') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function insertSubRecord(string $table, array $payload): int
    {
        $payload['docState']     = self::SUB_DOC_STATE;
        $payload['docStateMain'] = self::SUB_DOC_STATE_MAIN;
        // Strip nulls — let DB defaults apply where columns are NOT NULL.
        $clean = array_filter($payload, static fn($v) => $v !== null);
        $this->executeSql('INSERT INTO %n', $table, '%v', $clean);
        return $this->lastInsertId();
    }

    /**
     * Thin wrapper around `Connection::query()` so subclasses (testing
     * doubles) can intercept. `Connection::query()` is final and cannot
     * be mocked directly. Pattern matches DocumentApplier.
     */
    protected function executeSql(mixed ...$args): mixed
    {
        return $this->db->query(...$args);
    }

    /**
     * Returns the last insert id. Carved out as a protected seam for
     * testing doubles; production reads `Dibi\Connection::getInsertId()`.
     */
    protected function lastInsertId(): int
    {
        return (int) $this->db->getInsertId();
    }

    /**
     * @param array<string, mixed> $address
     */
    private function updateAddress(int $id, array $address): void
    {
        // Re-use AddressResolver.buildCreatePayload via a fresh canCreate
        // resolve with personId=null; we strip `person` to avoid stomping
        // on the FK that already points to the matched person.
        $payload = $this->addressResolverCreatePayload($address);
        unset($payload['person']);
        $this->updateSubRecord('base_persons_addresses', $id, $payload);
    }

    /**
     * @param array<string, mixed> $bank
     */
    private function updateBankAccount(int $id, array $bank): void
    {
        $payload = $this->buildBankAccountInsertPayload($bank);
        unset($payload['person']);
        $this->updateSubRecord('base_persons_bank_accounts', $id, $payload);
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function updateContact(int $id, array $contact): void
    {
        $payload = [
            'name'       => $this->normalize($contact['name'] ?? null) ?? '',
            'role'       => $this->normalize($contact['role'] ?? null),
            'email'      => $this->normalize($contact['email'] ?? null),
            'phone'      => $this->normalize($contact['phone'] ?? null),
            'note'       => $this->normalize($contact['note'] ?? null),
            'valid_from' => $this->normalize($contact['validFrom'] ?? null),
            'valid_to'   => $this->normalize($contact['validTo'] ?? null),
        ];
        $this->updateSubRecord('base_persons_contacts', $id, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateSubRecord(string $table, int $id, array $payload): void
    {
        // Update overwrites supplied fields only; docState is NEVER touched.
        unset($payload['docState'], $payload['docStateMain']);
        if ($payload === []) return;
        $this->executeSql('UPDATE %n SET ', $table, $payload, 'WHERE [id] = %i', $id);
    }

    /**
     * Re-derive the address insert payload from a canonical address
     * fragment. Replicates AddressResolver::buildCreatePayload via the
     * public resolve() with personId=null (which falls straight to
     * canCreate). Cheaper than duplicating the field map here.
     *
     * @param array<string, mixed> $address
     * @return array<string, mixed>
     */
    private function addressResolverCreatePayload(array $address): array
    {
        $r = $this->addressResolver->resolve($address, personId: null);
        return $r->createPayload;
    }

    /**
     * @param array<string, mixed> $bank
     * @return array<string, mixed>
     */
    private function buildBankAccountInsertPayload(array $bank): array
    {
        $currency = $bank['currency'] ?? null;
        if (is_string($currency)) {
            $currency = strtolower(trim($currency));
            if ($currency === '') $currency = null;
        }

        return [
            'name'           => $this->normalize($bank['name'] ?? null),
            'account_number' => $this->normalize($bank['accountNumber'] ?? null) ?? '',
            'iban'           => $this->normalize($bank['iban'] ?? null),
            'bic'            => $this->normalize($bank['bic'] ?? null),
            'currency'       => $currency,
            'source'         => is_int($bank['source'] ?? null) ? $bank['source'] : 0,
            'order_pos'      => is_int($bank['orderPos'] ?? null) ? $bank['orderPos'] : 0,
            'valid_from'     => $this->normalize($bank['validFrom'] ?? null),
            'valid_to'       => $this->normalize($bank['validTo'] ?? null),
        ];
    }

    /**
     * For each sub-record in `_resolve.closingExisting`, set `valid_to`
     * to today. `docState` is intentionally NOT changed — closing is
     * about validity, not deletion (spec §4a).
     */
    private function closeMissingSubRecords(PersonResolveResult $resolve): void
    {
        $today = date('Y-m-d');
        foreach (['addresses' => 'base_persons_addresses',
                  'bankAccounts' => 'base_persons_bank_accounts',
                  'contacts' => 'base_persons_contacts'] as $key => $table) {
            foreach ($resolve->closingExisting[$key] ?? [] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id === 0) continue;
                $this->executeSql(
                    'UPDATE %n SET [valid_to] = %s WHERE [id] = %i',
                    $table, $today, $id,
                );
            }
        }
    }

    // ── Lineage ─────────────────────────────────────────────────────────────

    /**
     * Stamp source_kind / source_ref / source_imported_at on the person
     * row — but only when the payload carries `source.kind`. Manual UI
     * edits which omit `source` therefore preserve the previous lineage
     * (spec "Otevřené body 7").
     *
     * @param array<string, mixed> $canonical
     */
    private function writeLineage(array $canonical, int $personId): void
    {
        $kind = $canonical['source']['kind'] ?? null;
        if (!is_string($kind) || trim($kind) === '') {
            return;
        }
        $registryRef = $this->normalize($canonical['source']['registryRef'] ?? null);
        $this->executeSql(
            'UPDATE [base_persons_persons] SET ',
            [
                'source_kind'        => $kind,
                'source_ref'         => $registryRef,
                'source_imported_at' => new \DateTimeImmutable(),
            ],
            'WHERE [id] = %i', $personId,
        );
    }

    // ── Output enrichment helpers ─────────────────────────────────────────

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
    private function withResolve(array $canonical, PersonResolveResult $resolve, array $issues): array
    {
        $serialized = $resolve->toArray();
        // Merge external issues into the resolve issues array.
        $serialized['issues'] = array_merge($serialized['issues'], $issues);
        $serialized['summary'] = $this->buildSummary($resolve, $serialized['issues']);
        $canonical['_resolve'] = $serialized;
        return $canonical;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array<string, mixed>
     */
    private function buildSummary(?PersonResolveResult $resolve, array $issues): array
    {
        $matched = 0;
        $canCreate = 0;
        $closing = 0;
        if ($resolve !== null) {
            $sets = [
                'addresses'    => $resolve->addresses,
                'bankAccounts' => $resolve->bankAccounts,
                'contacts'     => $resolve->contacts,
            ];
            foreach ($sets as $rs) {
                foreach ($rs as $r) {
                    if ($r->status === ResolveStatus::Matched) $matched++;
                    elseif ($r->status === ResolveStatus::CanCreate) $canCreate++;
                }
            }
            foreach (['addresses', 'bankAccounts', 'contacts'] as $key) {
                $closing += count($resolve->closingExisting[$key] ?? []);
            }
        }
        $errors = 0;
        foreach ($issues as $i) {
            if (($i['severity'] ?? null) === 'error') $errors++;
        }
        $headerStatus = $resolve?->header->status->value ?? 'unknown';
        $status = match (true) {
            $errors > 0 => 'hasErrors',
            ($resolve !== null && $resolve->header->status === ResolveStatus::Ambiguous) => 'needsAttention',
            ($canCreate > 0 || $closing > 0) => 'needsAttention',
            default => 'ok',
        };
        return [
            'status'       => $status,
            'headerStatus' => $headerStatus,
            'matched'      => $matched,
            'canCreate'    => $canCreate,
            'closing'      => $closing,
            'errorCount'   => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    private function annotateApplied(array $canonical, int $savedPersonId): array
    {
        $resolve = is_array($canonical['_resolve'] ?? null) ? $canonical['_resolve'] : [];
        $resolve['summary'] = ($resolve['summary'] ?? []) + ['status' => 'applied'];
        $resolve['summary']['status'] = 'applied';
        // Annotate canCreate header as matched with the freshly minted id.
        if (is_array($resolve['header'] ?? null) && ($resolve['header']['status'] ?? null) === 'canCreate') {
            $resolve['header']['status'] = 'matched';
            $resolve['header']['matchedId'] = $savedPersonId;
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
     * Honor `applyOptions.rejectOnIssues`. Default `["error"]` blocks only
     * errors; clients can pass `["error","warning"]` to also reject on
     * any warning (e.g. division_unknown).
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $validatorIssues
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $resolveIssues
     */
    private function shouldRejectOnIssues(array $canonical, array $validatorIssues, array $resolveIssues): bool
    {
        $reject = $canonical['applyOptions']['rejectOnIssues'] ?? null;
        if (!is_array($reject) || !in_array('warning', $reject, true)) {
            return false;  // errors are already checked separately
        }
        foreach (array_merge($validatorIssues, $resolveIssues) as $i) {
            if (($i['severity'] ?? null) === 'warning') return true;
        }
        return false;
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
