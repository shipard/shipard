<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Import mode (legacy migration): DocDocument stores the document's own number
 * + sequence verbatim and syncs the series counter to GREATEST(last_assigned,
 * sequence) instead of generating a fresh number from the counter.
 */
class DocDocumentImportNumberTest extends TestCase
{
    /** Mock connection whose `fetch` returns the given reset_scope row. */
    private function dbWithResetScope(string $scope): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['reset_scope' => $scope]));
        return $db;
    }

    public function testWritesNumberAndSequence(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('fiscal_year'));

        $data = ['number_series' => 1, 'fiscal_year' => 100];
        $doc->applyImportNumberPub($data, ['docNumber' => '2024-0042', 'sequenceNumber' => 42]);

        $this->assertSame('2024-0042', $data['doc_number']);
        $this->assertSame(42, $data['sequence_number']);
    }

    public function testCounterSyncEmitsInsertIgnoreAndGreatestUpdate(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('fiscal_year'));

        $data = ['number_series' => 1, 'fiscal_year' => 100];
        $doc->applyImportNumberPub($data, ['docNumber' => '2024-0042', 'sequenceNumber' => 42]);

        // Two statements: INSERT IGNORE counter row + UPDATE … GREATEST.
        $this->assertCount(2, $doc->executedSql);

        $this->assertStringContainsString('INSERT IGNORE', $doc->executedSql[0]['sql']);
        $this->assertSame([1, 100], $doc->executedSql[0]['args']);

        $this->assertStringContainsString('GREATEST', $doc->executedSql[1]['sql']);
        // GREATEST([last_assigned], %i) WHERE number_series=%i AND fiscal_year <=> %iN
        $this->assertSame([42, 1, 100], $doc->executedSql[1]['args']);
    }

    public function testResetScopeNoneUsesNullFiscalYearKey(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('none'));

        // fiscal_year is resolved on the head, but a 'none'-scope series keys
        // the counter with fiscal_year = NULL (mirrors assignDocumentNumber).
        $data = ['number_series' => 7, 'fiscal_year' => 100];
        $doc->applyImportNumberPub($data, ['docNumber' => 'X-1', 'sequenceNumber' => 3]);

        $this->assertSame([7, null], $doc->executedSql[0]['args']);
        $this->assertSame([3, 7, null], $doc->executedSql[1]['args']);
    }

    public function testNullSequenceStoresNumberWithoutCounterSync(): void
    {
        // Migrated duplicate key: docNumber suffixed, number outside the
        // series formula → sequence_number = NULL, counter must NOT move.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('fiscal_year'));

        $data = ['number_series' => 1, 'fiscal_year' => 100];
        $doc->applyImportNumberPub($data, ['docNumber' => '2024-0042-2', 'sequenceNumber' => null]);

        $this->assertSame('2024-0042-2', $data['doc_number']);
        $this->assertArrayHasKey('sequence_number', $data);
        $this->assertNull($data['sequence_number']);
        $this->assertSame([], $doc->executedSql, 'Counter sync must be skipped for null sequence');
    }

    public function testNullSequenceWithEmptyDocNumberFallsBack(): void
    {
        // Null sequence alone is not a licence to store an empty number —
        // the malformed-payload guard still applies.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('fiscal_year'));

        $data = ['number_series' => 1, 'fiscal_year' => 100, 'docState' => 10];
        $doc->applyImportNumberPub($data, ['docNumber' => '', 'sequenceNumber' => null]);

        $this->assertArrayNotHasKey('doc_number', $data);
        $this->assertSame([], $doc->executedSql);
    }

    public function testMalformedPayloadFallsBackToStateTransition(): void
    {
        // Empty docNumber → defensive fallback to processStateTransition with
        // null original (10→10 no-op): no number written, no counter SQL.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('fiscal_year'));

        $data = ['number_series' => 1, 'fiscal_year' => 100, 'docState' => 10];
        $doc->applyImportNumberPub($data, ['docNumber' => '', 'sequenceNumber' => 0]);

        $this->assertArrayNotHasKey('doc_number', $data);
        $this->assertSame([], $doc->executedSql);
    }

    public function testNoCounterSyncWithoutSeries(): void
    {
        // doc_number/sequence still written, but no counter key without series.
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('fiscal_year'));

        $data = []; // no number_series
        $doc->applyImportNumberPub($data, ['docNumber' => 'A-9', 'sequenceNumber' => 9]);

        $this->assertSame('A-9', $data['doc_number']);
        $this->assertSame(9, $data['sequence_number']);
        $this->assertSame([], $doc->executedSql);
    }

    // ── numberSeriesResetScope helper ───────────────────────────────────────

    public function testResetScopeDefaultsToFiscalYearWithoutDb(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $this->assertSame('fiscal_year', $doc->numberSeriesResetScopePub(1));
    }

    public function testResetScopeReadsFromSeries(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithResetScope('none'));
        $this->assertSame('none', $doc->numberSeriesResetScopePub(1));
    }

    // ── beforeSave consumes the virtual field ───────────────────────────────

    public function testBeforeSaveConsumesImportNumberAndNeverLeaksToData(): void
    {
        // beforeSave must unset _importNumber before any SQL so the gateway
        // INSERT never sees an unknown column. We drive beforeSave with a
        // minimal payload (no rows, no config-dependent recap) on a new record.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            function (string $sql): ?Row {
                // denormalizeDocType / resolveAccountingPeriods / reset_scope:
                // return benign rows; only reset_scope matters for the counter.
                if (str_contains($sql, 'reset_scope')) {
                    return new Row(['reset_scope' => 'fiscal_year']);
                }
                return null;
            }
        );

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = [
            'docState'        => 80,
            'number_series'   => 1,
            'issue_date'      => '2024-06-01',
            'accounting_date' => '2024-06-01',
            'rows'            => [],
            '_importNumber'   => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
        ];
        $doc->beforeSavePub($data, null);

        $this->assertArrayNotHasKey('_importNumber', $data, 'Virtual field must be removed before SQL');
        $this->assertSame('2024-0042', $data['doc_number']);
        $this->assertSame(42, $data['sequence_number']);
    }

    // ── Trvalý příznak převzatého dokladu (`imported`, #171 U11) ────────────

    /** Benigní spojení pro beforeSave bez řádků. */
    private function benignDb(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            fn (string $sql): ?Row => str_contains($sql, 'reset_scope')
                ? new Row(['reset_scope' => 'fiscal_year'])
                : null,
        );
        return $db;
    }

    /** @return array<string, mixed> */
    private function headPayload(array $extra = []): array
    {
        return $extra + [
            'docState'        => 40,
            'number_series'   => 1,
            'issue_date'      => '2024-06-01',
            'accounting_date' => '2024-06-01',
            'rows'            => [],
        ];
    }

    public function testNewDocumentInImportModeIsMarkedImported(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->benignDb());

        $data = $this->headPayload(['_importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]]);
        $doc->beforeSavePub($data, null);

        $this->assertSame(1, $data['imported']);
    }

    public function testNewDocumentOutsideImportModeLeavesTheDefault(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->benignDb());

        // Hodnota z payloadu se nebere — klíč do INSERTu nejde, platí výchozí 0.
        $data = $this->headPayload(['docState' => 10, 'imported' => 1]);
        $doc->beforeSavePub($data, null);

        $this->assertArrayNotHasKey('imported', $data);
    }

    public function testUpdateNeverChangesTheFlag(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->benignDb());
        $original = $this->headPayload(['id' => 5, 'docState' => 80, 'doc_number' => '2024-0042']);

        // Úprava převzatého dokladu mimo importní mód příznak nesmaže …
        $data = $this->headPayload(['id' => 5, 'imported' => 0]);
        $doc->beforeSavePub($data, $original + ['imported' => 1]);
        $this->assertArrayNotHasKey('imported', $data);

        // … a úprava vlastního dokladu v importním módu ho nenastaví.
        $data = $this->headPayload([
            'id'            => 5,
            '_importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
        ]);
        $doc->beforeSavePub($data, $original + ['imported' => 0]);
        $this->assertArrayNotHasKey('imported', $data);
    }
}
