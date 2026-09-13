<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\FilingDocument;
use Shipard\Module\Economy\Vat\FilingHeaderSchema;

/**
 * Testovatelná varianta — DB dotazy nahrazené in-memory daty: instance
 * tvrzení, existující podání (pro pořadí a koncept) a řádek sebe sama.
 */
final class TestableFilingDocument extends FilingDocument
{
    /** @var array<int, array<string, mixed>> id instance → řádek */
    public array $periods = [];

    /** @var array<int, array<string, mixed>> id podání → řádek */
    public array $filings = [];

    /** @var list<int> id podání, kterým se mazal snapshot */
    public array $snapshotsDeleted = [];

    /** @var array<int, array<string, mixed>> id dokladu → {id, doc_type, docState} */
    public array $heads = [];

    /** @var list<int> id podání, u kterých přechod do Podáno validoval XML */
    public array $xmlValidated = [];

    protected function validateXml(ValidationResult $result, int $filingId): void
    {
        $this->xmlValidated[] = $filingId;
    }

    protected function loadHead(int $headId): ?array
    {
        return $this->heads[$headId] ?? null;
    }

    protected function loadReportPeriod(int $periodId): ?array
    {
        return $this->periods[$periodId] ?? null;
    }

    protected function loadCurrent(int $id): ?array
    {
        return $this->filings[$id] ?? null;
    }

    protected function nextSequence(int $periodId): int
    {
        $max = 0;
        foreach ($this->filings as $row) {
            if ((int) $row['report_period'] === $periodId) {
                $max = max($max, (int) $row['sequence']);
            }
        }
        return $max + 1;
    }

    protected function lastFiledFiling(int $periodId, ?int $selfId): ?int
    {
        $found = null;
        foreach ($this->filings as $id => $row) {
            if ((int) $row['report_period'] === $periodId
                && (int) $row['docState'] === self::DOC_STATE_FILED
                && $id !== $selfId
            ) {
                $found = $id;
            }
        }
        return $found;
    }

    protected function findOtherDraft(int $periodId, ?int $selfId): ?int
    {
        foreach ($this->filings as $id => $row) {
            if ((int) $row['report_period'] === $periodId
                && (int) $row['docState'] === self::DOC_STATE_COMPOSED
                && $id !== $selfId
            ) {
                return $id;
            }
        }
        return null;
    }

    protected function deleteSnapshot(int $filingId): void
    {
        $this->snapshotsDeleted[] = $filingId;
    }
}

final class FilingDocumentTest extends TestCase
{
    private const MAPPING_CONFIG = __DIR__
        . '/../../../../../modules/economy/vat/config/vat-reports-cz.jsonc';
    private const KINDS_CONFIG = __DIR__
        . '/../../../../../modules/economy/vat/config/filingKinds.jsonc';

    /** @param array<int, array<string, mixed>> $filings */
    private function doc(string $periodType = 'return', array $filings = []): TestableFilingDocument
    {
        $doc = new TestableFilingDocument();
        $doc->periods = [7 => [
            'id' => 7, 'report_type' => $periodType, 'name' => '04/2026',
            'vat_registration' => 5, 'date_begin' => '2026-04-01', 'date_end' => '2026-04-30',
            'docState' => 40,
        ]];
        $doc->filings = $filings;
        $doc->setConfig($this->config());
        return $doc;
    }

    private function config(): ConfigRuntime
    {
        $mapping = JsoncParser::parseFile(self::MAPPING_CONFIG);
        // Kompilovaný cfgItem je už lokalizovaný — název druhu, který
        // Document vkládá do názvu podání, jinak zůstane anglický.
        $kinds = ConfigLocalizer::localize(JsoncParser::parseFile(self::KINDS_CONFIG), 'cs');
        $config  = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => match ($id) {
                'economy.vat.reports.cz'    => $mapping,
                'economy.vat.filingKinds'   => $kinds,
                default                     => null,
            },
        );
        return $config;
    }

    /** @return array<string, mixed> */
    private function newFiling(array $override = []): array
    {
        return array_merge([
            'report_period' => 7,
            'filing_kind'   => 'regular',
            'docState'      => 10,
        ], $override);
    }

    /** @return array<string, mixed> Podané podání v DB */
    private function filedRow(array $override = []): array
    {
        return array_merge([
            'id' => 1, 'report_period' => 7, 'report_type' => 'return', 'filing_kind' => 'regular',
            'origin' => FilingDocument::ORIGIN_COMPOSED,
            'sequence' => 1, 'name' => '04/2026 — Řádné 1', 'date_issue' => '2026-05-02',
            'date_filed' => '2026-05-04', 'date_found' => null, 'previous_filing' => null,
            'header' => null, 'result' => '{"row64":260864}', 'messages' => null, 'note' => null,
            'acc_document' => null,
            'docState' => FilingDocument::DOC_STATE_FILED,
        ], $override);
    }

    // ── Druhy podání per typ tvrzení ────────────────────────────────────

    public function testRegularFilingOnEmptyPeriodPasses(): void
    {
        $data = $this->newFiling();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testSupplementaryIsNotAllowedForControlStatement(): void
    {
        // KH zná následné, ne dodatečné (#55 D16).
        $doc  = $this->doc('cs', [1 => $this->filedRow(['report_type' => 'cs'])]);
        $data = $this->newFiling(['filing_kind' => 'supplementary', 'date_found' => '2026-06-01']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('filing_kind', $result->toArray()[0]['column']);
        $this->assertStringContainsString('regular, corrective, subsequent', $result->toArray()[0]['message']);
    }

    public function testCorrectiveIsNotAllowedForRecapitulativeStatement(): void
    {
        $doc  = $this->doc('rs', [1 => $this->filedRow(['report_type' => 'rs'])]);
        $data = $this->newFiling(['filing_kind' => 'corrective']);
        $this->assertFalse($doc->validate($data)->isValid());
    }

    public function testReportTypeMismatchWithPeriodFails(): void
    {
        $data = $this->newFiling(['report_type' => 'cs']);
        $result = $this->doc('return')->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('type_mismatch', $result->toArray()[0]['code']);
    }

    public function testFilingOnCancelledPeriodFails(): void
    {
        $doc = $this->doc();
        $doc->periods[7]['docState'] = 90;
        $data = $this->newFiling();
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('report_period', $result->toArray()[0]['column']);
    }

    // ── Datum zjištění důvodů ───────────────────────────────────────────

    public function testDateFoundRequiredForSubsequentControlStatement(): void
    {
        $doc  = $this->doc('cs', [1 => $this->filedRow(['report_type' => 'cs'])]);
        $data = $this->newFiling(['filing_kind' => 'subsequent']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('date_found', $result->toArray()[0]['column']);
        $this->assertSame('required', $result->toArray()[0]['code']);

        $data = $this->newFiling(['filing_kind' => 'subsequent', 'date_found' => '2026-06-01']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testDateFoundRequiredForSupplementaryReturn(): void
    {
        // Config `dateFoundRequiredFor` u přiznání nic neuvádí — povinnost
        // plyne z § 141 DŘ a hlídá ji Document.
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->newFiling(['filing_kind' => 'supplementary']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('date_found', $result->toArray()[0]['column']);
    }

    public function testDateFoundNotRequiredForCorrective(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->newFiling(['filing_kind' => 'corrective']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // ── Pořadí druhů a jediný koncept ───────────────────────────────────

    public function testSecondRegularAfterFiledOneFails(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->newFiling(['filing_kind' => 'regular']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('jen jedno', $result->toArray()[0]['message']);
    }

    public function testSupplementaryWithoutFiledFilingFails(): void
    {
        $data = $this->newFiling(['filing_kind' => 'supplementary', 'date_found' => '2026-06-01']);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('nejdřív podejte řádné', $result->toArray()[0]['message']);
    }

    public function testRegularAllowedAgainWhenPreviousWasCancelled(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_CANCELLED])]);
        $data = $this->newFiling(['filing_kind' => 'regular']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testOnlyOneDraftPerPeriod(): void
    {
        $doc = $this->doc('return', [
            3 => $this->filedRow(['id' => 3, 'docState' => FilingDocument::DOC_STATE_COMPOSED]),
        ]);
        $data = $this->newFiling();
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $error = $result->toArray()[0];
        $this->assertSame(ValidationError::FIELD_FORM, $error['column']);
        $this->assertSame('draft_exists', $error['code']);
    }

    public function testDraftGuardIgnoresItself(): void
    {
        $doc = $this->doc('return', [
            3 => $this->filedRow(['id' => 3, 'docState' => FilingDocument::DOC_STATE_COMPOSED]),
        ]);
        $data = $this->newFiling(['id' => 3]);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // ── Pořadí, název, datumy (beforeSave) ──────────────────────────────

    public function testBeforeSaveAssignsSequenceTypeAndName(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->newFiling(['filing_kind' => 'corrective']);
        $doc->beforeSave($data, null);

        $this->assertSame(2, $data['sequence'], 'pořadí = max + 1');
        $this->assertSame('return', $data['report_type'], 'typ z instance');
        $this->assertSame('04/2026 — Opravné 2', $data['name']);
        $this->assertSame(date('Y-m-d'), $data['date_issue']);
        $this->assertSame(1, $data['previous_filing'], 'základ pro rozdíly = poslední podané');
    }

    public function testBeforeSaveLeavesPreviousFilingEmptyForRegular(): void
    {
        $doc  = $this->doc();
        $data = $this->newFiling();
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['sequence']);
        $this->assertNull($data['previous_filing']);
    }

    public function testBeforeSaveFillsFiledDateOnTransition(): void
    {
        $doc     = $this->doc('return', [1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED])]);
        $data    = $this->filedRow(['date_filed' => null]);
        $original = $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED, 'date_filed' => null]);
        $doc->beforeSave($data, $original);
        $this->assertSame(date('Y-m-d'), $data['date_filed']);
    }

    public function testBeforeSaveKeepsExplicitFiledDate(): void
    {
        $doc      = $this->doc();
        $data     = $this->filedRow(['date_filed' => '2026-05-20']);
        $original = $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED, 'date_filed' => '2026-05-20']);
        $doc->beforeSave($data, $original);
        $this->assertSame('2026-05-20', $data['date_filed']);
    }

    public function testBeforeSaveKeepsSequenceOnUpdate(): void
    {
        $doc      = $this->doc('return', [4 => $this->filedRow(['id' => 4, 'sequence' => 3])]);
        $data     = ['id' => 4, 'report_period' => 7, 'filing_kind' => 'corrective', 'docState' => 10];
        $original = $this->filedRow(['id' => 4, 'sequence' => 3, 'docState' => 10]);
        $doc->beforeSave($data, $original);
        $this->assertSame('04/2026 — Opravné 3', $data['name'], 'pořadí se při update nepřečísluje');
    }

    // ── Přechod do Podáno ───────────────────────────────────────────────

    public function testFilingRequiresComposedSnapshot(): void
    {
        $doc  = $this->doc('return', [
            1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED, 'result' => null]),
        ]);
        $data = $this->filedRow(['result' => null]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $error = $result->toArray()[0];
        $this->assertSame(ValidationError::FIELD_FORM, $error['column']);
        $this->assertSame('not_composed', $error['code']);
    }

    public function testFilingPassesWithSnapshot(): void
    {
        $doc  = $this->doc('return', [
            1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED]),
        ]);
        $data = $this->filedRow();
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // ── Immutabilita podaného podání ────────────────────────────────────

    public function testFiledFilingRejectsHeaderChange(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->filedRow(['date_filed' => '2026-06-01']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $error = $result->toArray()[0];
        $this->assertSame('date_filed', $error['column']);
        $this->assertSame('immutable', $error['code']);
    }

    public function testFiledFilingAllowsNoteChange(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->filedRow(['note' => 'Podáno přes datovou schránku.']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testFiledFilingUnchangedRowPasses(): void
    {
        // Tvar z DB: datumy jako DateTime, čísla jako int — normalizace
        // nesmí hlásit změnu tam, kde žádná není.
        $doc  = $this->doc('return', [1 => $this->filedRow([
            'date_issue' => new \DateTimeImmutable('2026-05-02'),
            'date_filed' => new \DateTimeImmutable('2026-05-04'),
        ])]);
        $data = $this->filedRow(['date_issue' => '2026-05-02 00:00:00', 'date_filed' => '2026-05-04']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testCancelledFilingIsFrozenToo(): void
    {
        $doc  = $this->doc('return', [1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_CANCELLED])]);
        $data = $this->filedRow(['docState' => FilingDocument::DOC_STATE_CANCELLED, 'filing_kind' => 'corrective']);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('immutable', $result->toArray()[0]['code']);
    }

    // ── Částečná aktualizace (API pošle jen to, co mění) ────────────────

    public function testPartialUpdateOfFiledFilingDoesNotRecomposeName(): void
    {
        // Payload bez docState nesmí vypadat jako koncept — jinak by
        // beforeSave podanému podání přepsal název i previous_filing.
        $doc      = $this->doc('return', [1 => $this->filedRow()]);
        $data     = ['id' => 1, 'note' => 'Podáno datovou schránkou.'];
        $original = $this->filedRow();
        $doc->beforeSave($data, $original);

        $this->assertArrayNotHasKey('name', $data, 'název podaného podání se neskládá znovu');
        $this->assertArrayNotHasKey('previous_filing', $data);
        $this->assertArrayNotHasKey('date_filed', $data);
    }

    public function testPartialUpdateKeepsFilingKindFromStoredRow(): void
    {
        // Bez fallbacku na uložený řádek by validace hlásila „druh podání
        // je povinný" a název by vznikl s prázdným druhem.
        $doc  = $this->doc('return', [
            5 => $this->filedRow(['id' => 5]),
            1 => $this->filedRow([
                'docState' => FilingDocument::DOC_STATE_COMPOSED, 'filing_kind' => 'corrective', 'sequence' => 2,
            ]),
        ]);
        $data = ['id' => 1, 'note' => 'Ještě zkontrolovat.'];
        $this->assertTrue($doc->validate($data)->isValid());

        $original = $this->filedRow([
            'docState' => FilingDocument::DOC_STATE_COMPOSED, 'filing_kind' => 'corrective', 'sequence' => 2,
        ]);
        $doc->beforeSave($data, $original);
        $this->assertSame('04/2026 — Opravné 2', $data['name']);
    }

    public function testPartialUpdateOfDraftStillValidatesOrder(): void
    {
        // Koncept řádného podání v instanci, kde už je podané — pravidla
        // pořadí musí platit i pro částečnou aktualizaci.
        $doc = $this->doc('return', [
            1 => $this->filedRow(),
            2 => $this->filedRow(['id' => 2, 'sequence' => 2, 'docState' => FilingDocument::DOC_STATE_COMPOSED]),
        ]);
        $data = ['id' => 2, 'note' => 'x'];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('jen jedno', $result->toArray()[0]['message']);
    }

    // ── Mazání ──────────────────────────────────────────────────────────

    public function testFiledFilingCannotBeDeleted(): void
    {
        $doc = $this->doc();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('nelze smazat');
        $doc->beforeDelete($this->filedRow());
    }

    public function testDeletingDraftClearsSnapshot(): void
    {
        $doc = $this->doc();
        $doc->beforeDelete($this->filedRow(['id' => 9, 'docState' => FilingDocument::DOC_STATE_COMPOSED]));
        $this->assertSame([9], $doc->snapshotsDeleted);
    }

    // ── Povinná pole ────────────────────────────────────────────────────

    public function testRequiredFields(): void
    {
        $data = [];
        $result = $this->doc()->validate($data);
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('report_period', $columns);
        $this->assertContains('filing_kind', $columns);
    }

    public function testUnknownPeriodFails(): void
    {
        $data = $this->newFiling(['report_period' => 99]);
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('report_period', $result->toArray()[0]['column']);
    }

    // ── Schéma hlavičky per typ tvrzení (#55 Fáze 3, X2) ────────────────

    /**
     * Hook běží před `beforeSave()`, kde se teprve doplňuje denormalizovaný
     * `report_type` — schéma se proto musí dohledat přes instanci.
     */
    public function testHeaderSchemaFollowsPeriodTypeWithoutDenormalizedColumn(): void
    {
        foreach (FilingHeaderSchema::CFG_ITEM_BY_TYPE as $type => $cfgItem) {
            $doc = $this->doc($type);
            $this->assertSame(
                $cfgItem,
                $doc->structuredSchemaFor('header', ['report_period' => 7]),
            );
        }
    }

    public function testHeaderSchemaUsesDenormalizedTypeWhenPresent(): void
    {
        $doc = $this->doc('return');
        $this->assertSame(
            'economy.vat.filingHeaderCzKh1',
            $doc->structuredSchemaFor('header', ['report_type' => 'cs']),
        );
    }

    /** Částečná aktualizace posílá jen `note` — typ nese uložený řádek. */
    public function testHeaderSchemaFallsBackToStoredRow(): void
    {
        $doc = $this->doc('return', [3 => $this->filedRow(['id' => 3, 'report_type' => 'rs'])]);
        $this->assertSame(
            'economy.vat.filingHeaderCzShv',
            $doc->structuredSchemaFor('header', ['id' => 3, 'note' => 'x']),
        );
    }

    public function testOtherColumnsAndUnknownPeriodHaveNoSchemaOverride(): void
    {
        $doc = $this->doc();
        $this->assertNull($doc->structuredSchemaFor('result', ['report_period' => 7]));
        $this->assertNull($doc->structuredSchemaFor('header', []));
    }

    // ── acc_document — účetní doklad přiznání (#55 F4b) ──────────────────

    /**
     * @param array<int, array<string, mixed>> $filings
     * @param array<int, array<string, mixed>> $heads
     */
    private function docWithHeads(array $filings, array $heads): TestableFilingDocument
    {
        $doc = $this->doc('return', $filings);
        $doc->heads = $heads;
        return $doc;
    }

    /** @return array<string, mixed> */
    private function head(int $id, string $type = 'cmnbkp', int $state = 10): array
    {
        return ['id' => $id, 'doc_type' => $type, 'docState' => $state];
    }

    public function testFiledFilingAcceptsAccDocumentFromNull(): void
    {
        $doc  = $this->docWithHeads([1 => $this->filedRow()], [5 => $this->head(5)]);
        $data = ['id' => 1, 'acc_document' => 5];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testAccDocumentChangeAllowsMessagesInSameSave(): void
    {
        $doc  = $this->docWithHeads([1 => $this->filedRow()], [5 => $this->head(5)]);
        $data = ['id' => 1, 'acc_document' => 5, 'messages' => '[{"code":"vatReturn.accounted","docId":5}]'];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testMessagesAloneStayFrozen(): void
    {
        $doc    = $this->doc('return', [1 => $this->filedRow()]);
        $data   = ['id' => 1, 'messages' => '[{"code":"x"}]'];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('messages', $result->toArray()[0]['column']);
        $this->assertSame('immutable', $result->toArray()[0]['code']);
    }

    public function testLiveAccDocumentIsNotReplaced(): void
    {
        $doc = $this->docWithHeads(
            [1 => $this->filedRow(['acc_document' => 5])],
            [5 => $this->head(5, 'cmnbkp', 40), 6 => $this->head(6)],
        );
        $data   = ['id' => 1, 'acc_document' => 6];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('acc_document', $result->toArray()[0]['column']);
        $this->assertSame('acc_document_live', $result->toArray()[0]['code']);
    }

    public function testCancelledAccDocumentCanBeReplaced(): void
    {
        $doc = $this->docWithHeads(
            [1 => $this->filedRow(['acc_document' => 5])],
            [5 => $this->head(5, 'cmnbkp', 30), 6 => $this->head(6)],
        );
        $data = ['id' => 1, 'acc_document' => 6];
        $this->assertTrue($doc->validate($data)->isValid());

        // Odpojení mrtvého dokladu (NULL) projde taky.
        $data = ['id' => 1, 'acc_document' => null];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testAccDocumentMustBeLiveCmnbkp(): void
    {
        $doc = $this->docWithHeads(
            [1 => $this->filedRow()],
            [7 => $this->head(7, 'invno', 10), 8 => $this->head(8, 'cmnbkp', 90)],
        );
        foreach ([7, 8, 9] as $headId) {
            $data   = ['id' => 1, 'acc_document' => $headId];
            $result = $doc->validate($data);
            $this->assertFalse($result->isValid(), "doklad {$headId}");
            $this->assertSame('acc_document', $result->toArray()[0]['column']);
            $this->assertSame('invalid_value', $result->toArray()[0]['code']);
        }
    }

    public function testCancelledFilingRejectsAccDocument(): void
    {
        $doc = $this->docWithHeads(
            [1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_CANCELLED])],
            [5 => $this->head(5)],
        );
        $data   = ['id' => 1, 'acc_document' => 5];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('acc_document', $result->toArray()[0]['column']);
        $this->assertSame('immutable', $result->toArray()[0]['code']);
    }

    public function testDraftRejectsAccDocument(): void
    {
        $doc    = $this->doc('return', []);
        $data   = $this->newFiling(['acc_document' => 5]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('acc_document', $result->toArray()[0]['column']);
        $this->assertSame('invalid_state', $result->toArray()[0]['code']);
    }

    public function testUnchangedAccDocumentOnFiledRowPasses(): void
    {
        // Celý řádek zpět s nezměněným (živým) dokladem — žádná kontrola, žádná chyba.
        $doc  = $this->docWithHeads([1 => $this->filedRow(['acc_document' => 5])], [5 => $this->head(5, 'cmnbkp', 40)]);
        $data = $this->filedRow(['acc_document' => 5, 'note' => 'zaúčtováno']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // ── Import ze starého systému (origin = imported, #55 D34–D37) ───────

    public function testImportedFilingSkipsXmlValidationOnTransition(): void
    {
        $draft = $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED, 'origin' => FilingDocument::ORIGIN_IMPORTED]);
        $doc   = $this->doc('return', [1 => $draft]);
        $data  = $this->filedRow(['origin' => FilingDocument::ORIGIN_IMPORTED]);
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame([], $doc->xmlValidated, 'import XML nevaliduje — původní soubor je pravda');

        // Částečný payload přechodu (bez origin) — původ nese uložený řádek.
        $data = ['id' => 1, 'docState' => FilingDocument::DOC_STATE_FILED];
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame([], $doc->xmlValidated);

        // Sestavené podání validuje dál.
        $doc  = $this->doc('return', [1 => $this->filedRow(['docState' => FilingDocument::DOC_STATE_COMPOSED])]);
        $data = $this->filedRow();
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame([1], $doc->xmlValidated);
    }

    public function testImportedFilingIgnoresKindOrderButKeepsDraftGuard(): void
    {
        // Řádné po podaném — u importu jen zpráva, ne chyba (starý systém
        // mohl podat řádné až po ručně podaném tvrzení).
        $doc  = $this->doc('return', [1 => $this->filedRow()]);
        $data = $this->newFiling(['origin' => FilingDocument::ORIGIN_IMPORTED]);
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertTrue(FilingDocument::isOrderIrregular('regular', true));

        // Dodatečné bez podaného — totéž.
        $data = $this->newFiling([
            'origin' => FilingDocument::ORIGIN_IMPORTED, 'filing_kind' => 'supplementary', 'date_found' => '2019-09-01',
        ]);
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->assertTrue(FilingDocument::isOrderIrregular('supplementary', false));
        $this->assertFalse(FilingDocument::isOrderIrregular('supplementary', true));
        $this->assertFalse(FilingDocument::isOrderIrregular('regular', false));

        // Živý koncept v instanci import odmítne — přerušený běh se dokončí, ne zdvojí.
        $doc  = $this->doc('return', [3 => $this->filedRow(['id' => 3, 'docState' => FilingDocument::DOC_STATE_COMPOSED])]);
        $data = $this->newFiling(['origin' => FilingDocument::ORIGIN_IMPORTED]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('draft_exists', $result->toArray()[0]['code']);
    }

    public function testImportedDraftAcceptsLiveAccDocumentOnly(): void
    {
        $doc  = $this->docWithHeads([], [5 => $this->head(5), 8 => $this->head(8, 'cmnbkp', 90)]);
        $data = $this->newFiling(['origin' => FilingDocument::ORIGIN_IMPORTED, 'acc_document' => 5]);
        $this->assertTrue($doc->validate($data)->isValid(), 'import smí navázat doklad už na koncept (D37)');

        $data   = $this->newFiling(['origin' => FilingDocument::ORIGIN_IMPORTED, 'acc_document' => 8]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('acc_document', $result->toArray()[0]['column']);
        $this->assertSame('invalid_value', $result->toArray()[0]['code']);

        // Sestavený koncept doklad pořád nemá.
        $data   = $this->newFiling(['acc_document' => 5]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('invalid_state', $result->toArray()[0]['code']);
    }

    public function testOriginIsFrozenAfterFiling(): void
    {
        $doc    = $this->doc('return', [1 => $this->filedRow()]);
        $data   = $this->filedRow(['origin' => FilingDocument::ORIGIN_IMPORTED]);
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('origin', $result->toArray()[0]['column']);
        $this->assertSame('immutable', $result->toArray()[0]['code']);
    }

    public function testBeforeSaveKeepsImportedName(): void
    {
        $doc  = $this->doc();
        $data = $this->newFiling(['origin' => FilingDocument::ORIGIN_IMPORTED, 'name' => 'Přiznání DPH 2019/8']);
        $doc->beforeSave($data, null);
        $this->assertSame('Přiznání DPH 2019/8', $data['name'], 'název ze starého systému (D35)');
        $this->assertSame(1, $data['sequence']);
        $this->assertSame('return', $data['report_type']);

        // Bez dodaného názvu se skládá jako u sestaveného.
        $data = $this->newFiling(['origin' => FilingDocument::ORIGIN_IMPORTED]);
        $doc->beforeSave($data, null);
        $this->assertSame('04/2026 — Řádné 1', $data['name']);
    }
}
