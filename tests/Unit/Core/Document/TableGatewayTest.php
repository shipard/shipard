<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Auth\CurrentUser;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DefaultDocument;
use Shipard\Core\Document\Document;
use Shipard\Core\Document\DocStatesDefinition;
use Shipard\Core\Document\DocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Document\ValidationResult;

// ---------------------------------------------------------------------------
// Testable subclass — overrides all DB-touching protected methods
// ---------------------------------------------------------------------------

class TestableTableGateway extends TableGateway
{
    /** @var array<int, array> id → row data */
    public array $storedRows = [];

    /** @var array<string, array[]> "$table:$parentId" → rows */
    public array $storedChildren = [];

    public int $nextInsertId = 1;
    public ?string $throwOnInsertTable = null;

    // Recorded calls
    public array $insertCalls = [];
    public array $updateCalls = [];
    public array $deleteRowCalls = [];
    public array $deleteChildrenCalls = [];
    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    protected function fetchRow(int $id): ?array
    {
        return $this->storedRows[$id] ?? null;
    }

    protected function fetchChildren(string $table, string $foreignKey, int $parentId): array
    {
        return $this->storedChildren["{$table}:{$parentId}"] ?? [];
    }

    protected function insertRow(string $table, array $data): int
    {
        if ($this->throwOnInsertTable === $table) {
            throw new \RuntimeException("Simulated DB error on insert into {$table}");
        }
        $id = $this->nextInsertId++;
        $this->insertCalls[] = ['table' => $table, 'data' => $data];
        return $id;
    }

    protected function updateRow(string $table, int $id, array $data): void
    {
        $this->updateCalls[] = ['table' => $table, 'id' => $id, 'data' => $data];
    }

    protected function deleteRow(string $table, int $id): void
    {
        $this->deleteRowCalls[] = ['table' => $table, 'id' => $id];
    }

    protected function deleteChildren(string $table, string $foreignKey, int $parentId): void
    {
        $this->deleteChildrenCalls[] = [
            'table' => $table,
            'foreignKey' => $foreignKey,
            'parentId' => $parentId,
        ];
    }

    protected function beginTransaction(): void { $this->beginCount++; }
    protected function commitTransaction(): void { $this->commitCount++; }
    protected function rollbackTransaction(): void { $this->rollbackCount++; }
}

// ---------------------------------------------------------------------------
// Document stubs for hook tracking
// ---------------------------------------------------------------------------

class TrackingDocument extends Document
{
    public array $beforeSaveData = [];
    public array $afterSaveData = [];
    public array $beforeDeleteData = [];
    public array $afterDeleteData = [];

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $this->beforeSaveData = $data;
    }

    public function afterSave(array $data): void
    {
        $this->afterSaveData = $data;
    }

    public function beforeDelete(array $data): void
    {
        $this->beforeDeleteData = $data;
    }

    public function afterDelete(array $data): void
    {
        $this->afterDeleteData = $data;
    }
}

class ModifyingDocument extends Document
{
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        $data['computed'] = 'added-by-beforeSave';
    }
}

class RejectingDocument extends Document
{
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();
        $result->addError('name', 'Name is required', 'required');
        return $result;
    }
}

// ---------------------------------------------------------------------------
// Helper to build a registry with a fixed Document instance
// ---------------------------------------------------------------------------

class SingletonRegistry extends DocumentRegistry
{
    public function __construct(private Document $doc) {}

    public function getDocument(string $tableId, array $data = []): Document
    {
        return $this->doc;
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

class TableGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        CurrentUser::reset();
    }

    private function makeDb(): \Dibi\Connection
    {
        return $this->createMock(\Dibi\Connection::class);
    }

    private function makeGateway(
        string $tableId,
        Document $doc,
        ?array $childTables = null,
    ): TestableTableGateway {
        return new TestableTableGateway(
            $tableId,
            $this->makeDb(),
            new SingletonRegistry($doc),
            $childTables,
        );
    }

    // --- loadRecord ---------------------------------------------------------

    public function testLoadRecordReturnsData(): void
    {
        $gw = $this->makeGateway('persons', new DefaultDocument());
        $gw->storedRows[5] = ['id' => 5, 'name' => 'Alice'];

        $result = $gw->loadRecord(5);
        $this->assertSame(['id' => 5, 'name' => 'Alice'], $result);
    }

    public function testLoadRecordReturnsNullForMissingId(): void
    {
        $gw = $this->makeGateway('persons', new DefaultDocument());

        $this->assertNull($gw->loadRecord(999));
    }

    // --- saveDocument — insert -----------------------------------------------

    public function testSaveDocumentInsertNewRecord(): void
    {
        $gw = $this->makeGateway('persons', new DefaultDocument());

        $result = $gw->saveDocument(['name' => 'Alice']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, $result->getData()['id']);
        $this->assertCount(1, $gw->insertCalls);
        $this->assertSame('persons', $gw->insertCalls[0]['table']);
        $this->assertSame('Alice', $gw->insertCalls[0]['data']['name']);
        $this->assertSame(1, $gw->beginCount);
        $this->assertSame(1, $gw->commitCount);
        $this->assertSame(0, $gw->rollbackCount);
    }

    // --- saveDocument — update -----------------------------------------------

    public function testSaveDocumentUpdateExistingRecord(): void
    {
        $gw = $this->makeGateway('persons', new DefaultDocument());

        $result = $gw->saveDocument(['id' => 7, 'name' => 'Bob']);

        $this->assertTrue($result->isSuccess());
        $this->assertEmpty($gw->insertCalls);
        $this->assertCount(1, $gw->updateCalls);
        $this->assertSame('persons', $gw->updateCalls[0]['table']);
        $this->assertSame(7, $gw->updateCalls[0]['id']);
        $this->assertSame('Bob', $gw->updateCalls[0]['data']['name']);
        $this->assertArrayNotHasKey('id', $gw->updateCalls[0]['data']);
    }

    // --- saveDocument — validation failure -----------------------------------

    public function testSaveDocumentValidationFailureSkipsDb(): void
    {
        $gw = $this->makeGateway('persons', new RejectingDocument());

        $result = $gw->saveDocument(['name' => '']);

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->getValidation());
        $this->assertFalse($result->getValidation()->isValid());
        $this->assertEmpty($gw->insertCalls);
        $this->assertSame(0, $gw->beginCount);
    }

    // --- saveDocument — docStateMain dopočet ---------------------------------

    /**
     * @param array<string, array{mainState:int}> $states
     */
    private function makeGatewayWithDocStates(
        Document $doc,
        ?DocStatesDefinition $docStates,
        array $states = [],
    ): TestableTableGateway {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturn($states);

        return new TestableTableGateway(
            'heads',
            $this->makeDb(),
            new SingletonRegistry($doc),
            null,
            $config,
            null,
            null,
            $docStates,
        );
    }

    public function testSaveDocumentDerivesDocStateMainOnInsert(): void
    {
        $gw = $this->makeGatewayWithDocStates(
            new DefaultDocument(),
            new DocStatesDefinition('docState', 'docStateMain', 'core.system.docStatesArchive'),
            ['10' => ['mainState' => 1], '40' => ['mainState' => 3]],
        );

        $result = $gw->saveDocument(['title' => 'Invoice', 'docState' => 40]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $gw->insertCalls);
        $this->assertSame(3, $gw->insertCalls[0]['data']['docStateMain']);
        $this->assertSame(3, $result->getData()['docStateMain']);
    }

    public function testSaveDocumentRecomputesDocStateMainOnUpdate(): void
    {
        $gw = $this->makeGatewayWithDocStates(
            new DefaultDocument(),
            new DocStatesDefinition('docState', 'docStateMain', 'core.system.docStatesArchive'),
            ['20' => ['mainState' => 2], '40' => ['mainState' => 4]],
        );
        // Plný řádek z DB má zastaralý docStateMain (např. default 1) —
        // recompute z docState musí být idempotentní a přepsat ho.
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice', 'docState' => 20, 'docStateMain' => 1];

        $result = $gw->saveDocument(['id' => 5, 'title' => 'Invoice', 'docState' => 20, 'docStateMain' => 1]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $gw->updateCalls);
        $this->assertSame(2, $gw->updateCalls[0]['data']['docStateMain']);
    }

    public function testSaveDocumentSkipsDocStateMainWithoutDocStatesDefinition(): void
    {
        $gw = $this->makeGatewayWithDocStates(new DefaultDocument(), null, []);

        $result = $gw->saveDocument(['title' => 'Invoice', 'docState' => 40]);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayNotHasKey('docStateMain', $gw->insertCalls[0]['data']);
    }

    // --- saveDocument — injektáž efektivního docState na update ---------------

    public function testSaveDocumentInjectsOriginalDocStateIntoUpdatePayload(): void
    {
        $doc = new TrackingDocument();
        $gw = $this->makeGatewayWithDocStates(
            $doc,
            new DocStatesDefinition('docState', 'docStateMain', 'core.system.docStatesArchive'),
            ['10' => ['mainState' => 1], '20' => ['mainState' => 2]],
        );
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice', 'docState' => 20, 'docStateMain' => 2];

        // Data-save z formuláře: docState je system sloupec, v payloadu chybí.
        $result = $gw->saveDocument(['id' => 5, 'title' => 'Invoice edited']);

        $this->assertTrue($result->isSuccess());
        // Hooky (beforeSave) už vidí efektivní stav — ne fallback na Koncept.
        $this->assertSame(20, $doc->beforeSaveData['docState']);
        // UPDATE dostane původní docState + přepočtený docStateMain.
        $this->assertCount(1, $gw->updateCalls);
        $this->assertSame(20, $gw->updateCalls[0]['data']['docState']);
        $this->assertSame(2, $gw->updateCalls[0]['data']['docStateMain']);
    }

    public function testSaveDocumentInsertWithoutDocStateStaysUntouched(): void
    {
        $doc = new TrackingDocument();
        $gw = $this->makeGatewayWithDocStates(
            $doc,
            new DocStatesDefinition('docState', 'docStateMain', 'core.system.docStatesArchive'),
            ['10' => ['mainState' => 1]],
        );

        $result = $gw->saveDocument(['title' => 'Invoice']);

        $this->assertTrue($result->isSuccess());
        // Insert bez docState: žádná injektáž, žádný dopočet docStateMain.
        $this->assertArrayNotHasKey('docState', $doc->beforeSaveData);
        $this->assertArrayNotHasKey('docState', $gw->insertCalls[0]['data']);
        $this->assertArrayNotHasKey('docStateMain', $gw->insertCalls[0]['data']);
    }

    public function testSaveDocumentExplicitDocStateInPayloadWinsOverOriginal(): void
    {
        $gw = $this->makeGatewayWithDocStates(
            new DefaultDocument(),
            new DocStatesDefinition('docState', 'docStateMain', 'core.system.docStatesArchive'),
            ['10' => ['mainState' => 1], '20' => ['mainState' => 2]],
        );
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice', 'docState' => 20, 'docStateMain' => 2];

        // State-only PUT: explicitní docState v payloadu se nesmí přepsat.
        $result = $gw->saveDocument(['id' => 5, 'docState' => 10]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10, $gw->updateCalls[0]['data']['docState']);
        $this->assertSame(1, $gw->updateCalls[0]['data']['docStateMain']);
    }

    // --- saveDocument — with child rows -------------------------------------

    public function testSaveDocumentSyncsChildRows(): void
    {
        $childTables = [
            ['table' => 'doc_rows', 'foreignKey' => 'head_id', 'dataKey' => 'rows'],
        ];
        $gw = $this->makeGateway('doc_heads', new DefaultDocument(), $childTables);
        $gw->nextInsertId = 10;

        // Existing child row with id=3, will be updated
        // Row with id=99 exists in DB but is missing from input → should be deleted
        $gw->storedChildren['doc_rows:10'] = [
            ['id' => 3, 'head_id' => 10, 'item' => 'old'],
            ['id' => 99, 'head_id' => 10, 'item' => 'to-be-deleted'],
        ];

        $result = $gw->saveDocument([
            'rows' => [
                ['item' => 'new-row'],          // no id → INSERT
                ['id' => 3, 'item' => 'updated'], // with id → UPDATE
                // id=99 missing → DELETE
            ],
        ]);

        $this->assertTrue($result->isSuccess());

        // Head insert
        $headInsert = array_filter($gw->insertCalls, fn($c) => $c['table'] === 'doc_heads');
        $this->assertCount(1, $headInsert);

        // Child insert (new-row)
        $childInserts = array_filter($gw->insertCalls, fn($c) => $c['table'] === 'doc_rows');
        $this->assertCount(1, $childInserts);
        $childInsert = array_values($childInserts)[0];
        $this->assertSame('new-row', $childInsert['data']['item']);
        $this->assertSame(10, $childInsert['data']['head_id']); // foreignKey injected

        // Child update
        $this->assertCount(1, $gw->updateCalls);
        $this->assertSame('doc_rows', $gw->updateCalls[0]['table']);
        $this->assertSame(3, $gw->updateCalls[0]['id']);
        $this->assertSame('updated', $gw->updateCalls[0]['data']['item']);

        // Child delete (id=99)
        $this->assertCount(1, $gw->deleteRowCalls);
        $this->assertSame('doc_rows', $gw->deleteRowCalls[0]['table']);
        $this->assertSame(99, $gw->deleteRowCalls[0]['id']);
    }

    // --- saveDocument — exception triggers rollback -------------------------

    public function testSaveDocumentExceptionTriggersRollback(): void
    {
        $gw = $this->makeGateway('persons', new DefaultDocument());
        $gw->throwOnInsertTable = 'persons';

        $result = $gw->saveDocument(['name' => 'Alice']);

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->getErrorMessage());
        $this->assertSame(1, $gw->beginCount);
        $this->assertSame(0, $gw->commitCount);
        $this->assertSame(1, $gw->rollbackCount);
    }

    // --- deleteDocument -----------------------------------------------------

    public function testDeleteDocumentDeletesChildrenAndHead(): void
    {
        $childTables = [
            ['table' => 'doc_rows', 'foreignKey' => 'head_id', 'dataKey' => 'rows'],
        ];
        $gw = $this->makeGateway('doc_heads', new DefaultDocument(), $childTables);
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice'];

        $result = $gw->deleteDocument(5);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['id' => 5, 'title' => 'Invoice', 'rows' => []], $result->getData());

        // Child rows deleted
        $this->assertCount(1, $gw->deleteChildrenCalls);
        $this->assertSame('doc_rows', $gw->deleteChildrenCalls[0]['table']);
        $this->assertSame('head_id', $gw->deleteChildrenCalls[0]['foreignKey']);
        $this->assertSame(5, $gw->deleteChildrenCalls[0]['parentId']);

        // Head deleted
        $this->assertCount(1, $gw->deleteRowCalls);
        $this->assertSame('doc_heads', $gw->deleteRowCalls[0]['table']);
        $this->assertSame(5, $gw->deleteRowCalls[0]['id']);
    }

    public function testDeleteDocumentReturnsErrorForMissingRecord(): void
    {
        $gw = $this->makeGateway('persons', new DefaultDocument());

        $result = $gw->deleteDocument(999);

        $this->assertFalse($result->isSuccess());
        $this->assertNotNull($result->getErrorMessage());
        $this->assertEmpty($gw->deleteRowCalls);
    }

    // --- beforeSave modifies data -------------------------------------------

    public function testBeforeSaveModifiedDataIsInserted(): void
    {
        $gw = $this->makeGateway('persons', new ModifyingDocument());

        $result = $gw->saveDocument(['name' => 'Alice']);

        $this->assertTrue($result->isSuccess());
        $insertedData = $gw->insertCalls[0]['data'];
        $this->assertSame('added-by-beforeSave', $insertedData['computed']);
        $this->assertSame('added-by-beforeSave', $result->getData()['computed']);
    }

    // --- hooks called in correct order --------------------------------------

    public function testLifecycleHooksCalledForSave(): void
    {
        $doc = new TrackingDocument();
        $gw = $this->makeGateway('persons', $doc);

        $gw->saveDocument(['name' => 'Alice']);

        $this->assertSame('Alice', $doc->beforeSaveData['name']);
        $this->assertSame(1, $doc->afterSaveData['id']); // id assigned after insert
    }

    public function testLifecycleHooksCalledForDelete(): void
    {
        $doc = new TrackingDocument();
        $gw = $this->makeGateway('persons', $doc);
        $gw->storedRows[3] = ['id' => 3, 'name' => 'Alice'];

        $gw->deleteDocument(3);

        $this->assertSame(3, $doc->beforeDeleteData['id']);
        $this->assertSame(3, $doc->afterDeleteData['id']);
    }

    // --- zámek záznamu (documentLockProviders, #55 D24) -----------------------

    private function makeLockedGateway(Document $doc): TestableTableGateway
    {
        GatewayTestLockProvider::reset();
        return new TestableTableGateway('heads', $this->makeDb(), new LockingRegistry($doc));
    }

    public function testLockedUpdateFailsWithFormErrorAndSkipsDb(): void
    {
        $doc = new TrackingDocument();
        $gw = $this->makeLockedGateway($doc);
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice', 'docState' => 40];

        $result = $gw->saveDocument(['id' => 5, 'title' => 'Invoice edited']);

        $this->assertFalse($result->isSuccess());
        $errors = $result->getValidation()->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('_form', $errors[0]->column);
        $this->assertSame('locked', $errors[0]->code);
        $this->assertSame('Období je uzamčené', $errors[0]->message);
        $this->assertEmpty($gw->updateCalls);
        $this->assertSame(0, $gw->beginCount);
        // beforeSave nikdy neběžel — zámek se ptá dřív.
        $this->assertSame([], $doc->beforeSaveData);
        // Provider viděl nový stav i uložený originál.
        $this->assertSame('Invoice edited', GatewayTestLockProvider::$lastData['title']);
        $this->assertSame('Invoice', GatewayTestLockProvider::$lastOriginal['title']);
    }

    public function testLockedInsertFailsWithNullOriginal(): void
    {
        $gw = $this->makeLockedGateway(new DefaultDocument());

        $result = $gw->saveDocument(['title' => 'New into locked range']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('locked', $result->getValidation()->getErrors()[0]->code);
        $this->assertNull(GatewayTestLockProvider::$lastOriginal);
        $this->assertEmpty($gw->insertCalls);
    }

    public function testLockExemptDocumentSkipsProviders(): void
    {
        $gw = $this->makeLockedGateway(new ExemptDocument());
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice'];

        $result = $gw->saveDocument(['id' => 5, 'title' => 'Mirrored', '_importNumber' => ['docNumber' => 'X']]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(0, GatewayTestLockProvider::$calls);
        $this->assertCount(1, $gw->updateCalls);
    }

    public function testForceUnlockBypassesLockAndNeverReachesSql(): void
    {
        $gw = $this->makeLockedGateway(new DefaultDocument());
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice'];

        $result = $gw->saveDocument(['id' => 5, 'title' => 'Forced', '_forceUnlock' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, GatewayTestLockProvider::$calls);
        $this->assertCount(1, $gw->updateCalls);
        $this->assertArrayNotHasKey('_forceUnlock', $gw->updateCalls[0]['data']);
        $this->assertSame('Forced', $gw->updateCalls[0]['data']['title']);
    }

    public function testForceMarkerStrippedEvenWhenNothingIsLocked(): void
    {
        $gw = $this->makeLockedGateway(new DefaultDocument());
        GatewayTestLockProvider::$locked = false;

        $result = $gw->saveDocument(['title' => 'Free', '_forceUnlock' => true]);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayNotHasKey('_forceUnlock', $gw->insertCalls[0]['data']);
    }

    public function testUnlockedRecordSavesNormally(): void
    {
        $gw = $this->makeLockedGateway(new DefaultDocument());
        GatewayTestLockProvider::$locked = false;
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice'];

        $result = $gw->saveDocument(['id' => 5, 'title' => 'Edited']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(1, GatewayTestLockProvider::$calls);
    }

    public function testProviderExceptionFailsSaveClosed(): void
    {
        $gw = $this->makeLockedGateway(new DefaultDocument());
        GatewayTestLockProvider::$throw = true;
        $gw->storedRows[5] = ['id' => 5, 'title' => 'Invoice'];

        $result = $gw->saveDocument(['id' => 5, 'title' => 'Edited']);

        $this->assertFalse($result->isSuccess());
        $this->assertNull($result->getValidation());
        $this->assertStringContainsString('provider down', (string) $result->getErrorMessage());
        $this->assertEmpty($gw->updateCalls);
    }

    public function testLockedDeleteIsDomainErrorWithoutTouchingDb(): void
    {
        $doc = new TrackingDocument();
        $gw = $this->makeLockedGateway($doc);
        $gw->storedRows[3] = ['id' => 3, 'title' => 'Invoice'];

        $result = $gw->deleteDocument(3);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isDomainError());
        $this->assertSame('DOCUMENT_LOCKED', $result->getDomainErrorCode());
        $this->assertSame('Období je uzamčené', $result->getErrorMessage());
        $this->assertEmpty($gw->deleteRowCalls);
        $this->assertSame(0, $gw->beginCount);
        $this->assertSame([], $doc->beforeDeleteData);
    }

    public function testUnlockedDeleteProceeds(): void
    {
        $gw = $this->makeLockedGateway(new TrackingDocument());
        GatewayTestLockProvider::$locked = false;
        $gw->storedRows[3] = ['id' => 3, 'title' => 'Invoice'];

        $result = $gw->deleteDocument(3);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(1, $gw->deleteRowCalls);
    }

    // --- created_by (#93 D10) -----------------------------------------------

    private function makeAuditGateway(bool $withCreatedBy = true): TestableTableGateway
    {
        $columns = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
            ['id' => 'title', 'name' => 'Title', 'type' => 'varchar', 'length' => 50],
        ];
        if ($withCreatedBy) {
            $columns[] = ['id' => 'created_by', 'name' => 'Created by', 'type' => 'int', 'nullable' => true];
        }

        return new TestableTableGateway(
            'notes',
            $this->makeDb(),
            new SingletonRegistry(new DefaultDocument()),
            tableDef: TableDefinition::fromArray(['tableId' => 9201, 'name' => 'notes', 'columns' => $columns]),
        );
    }

    public function testInsertFillsCreatedByFromCurrentUser(): void
    {
        CurrentUser::set(7);
        $gw = $this->makeAuditGateway();

        $gw->saveDocument(['title' => 'A']);

        $this->assertSame(7, $gw->insertCalls[0]['data']['created_by']);
    }

    public function testInsertKeepsExplicitCreatedByEvenWhenNull(): void
    {
        CurrentUser::set(7);
        $gw = $this->makeAuditGateway();

        $gw->saveDocument(['title' => 'A', 'created_by' => null]);
        $gw->saveDocument(['title' => 'B', 'created_by' => 3]);

        $this->assertArrayHasKey('created_by', $gw->insertCalls[0]['data']);
        $this->assertNull($gw->insertCalls[0]['data']['created_by']);
        $this->assertSame(3, $gw->insertCalls[1]['data']['created_by']);
    }

    public function testUpdateNeverTouchesCreatedBy(): void
    {
        CurrentUser::set(7);
        $gw = $this->makeAuditGateway();
        $gw->storedRows[5] = ['id' => 5, 'title' => 'A', 'created_by' => 3];

        $gw->saveDocument(['id' => 5, 'title' => 'B']);

        $this->assertArrayNotHasKey('created_by', $gw->updateCalls[0]['data']);
    }

    public function testInsertWithoutCreatedByColumnStaysUntouched(): void
    {
        CurrentUser::set(7);
        $gw = $this->makeAuditGateway(withCreatedBy: false);

        $gw->saveDocument(['title' => 'A']);

        $this->assertArrayNotHasKey('created_by', $gw->insertCalls[0]['data']);
    }

    public function testInsertWithoutTableDefinitionStaysUntouched(): void
    {
        CurrentUser::set(7);
        $gw = $this->makeGateway('notes', new DefaultDocument());

        $gw->saveDocument(['title' => 'A']);

        $this->assertArrayNotHasKey('created_by', $gw->insertCalls[0]['data']);
    }

    public function testInsertInMachineContextLeavesCreatedByNull(): void
    {
        CurrentUser::reset();
        $gw = $this->makeAuditGateway();

        $gw->saveDocument(['title' => 'A']);

        $this->assertArrayNotHasKey('created_by', $gw->insertCalls[0]['data']);
    }
}

// ---------------------------------------------------------------------------
// Lock fixtures (#55 D24)
// ---------------------------------------------------------------------------

final class GatewayTestLockProvider implements DocumentLockProvider
{
    public static bool $locked = true;
    public static bool $throw = false;
    public static int $calls = 0;
    /** @var array<string, mixed> */
    public static array $lastData = [];
    /** @var array<string, mixed>|null */
    public static ?array $lastOriginal = null;

    public static function reset(): void
    {
        self::$locked = true;
        self::$throw = false;
        self::$calls = 0;
        self::$lastData = [];
        self::$lastOriginal = null;
    }

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        self::$calls++;
        self::$lastData = $data;
        self::$lastOriginal = $original;
        if (self::$throw) {
            throw new \RuntimeException('provider down');
        }
        return self::$locked ? [new DocumentLockReason('test', 'Období je uzamčené')] : [];
    }
}

/** SingletonRegistry s jedním providerem zámku na tabulce `heads`. */
class LockingRegistry extends SingletonRegistry
{
    public function getLockProviders(): array
    {
        return [['table' => 'heads', 'class' => GatewayTestLockProvider::class]];
    }

    public function hasLockProviders(string $tableId): bool
    {
        return $tableId === 'heads';
    }
}

class ExemptDocument extends Document
{
    public function isLockExempt(array $data): bool
    {
        return isset($data['_importNumber']);
    }

    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        unset($data['_importNumber']);
    }
}
