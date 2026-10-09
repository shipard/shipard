<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Zakázka (tasks/work-orders-phase1.md §3): denormalizace druhu a typu
 * z řady, validace podle typu, nadřazená zakázka (typ, cyklus), číslo při
 * potvrzení přes engine čísel, chybějící fiskální rok, datum ukončení při
 * přechodu do koncového stavu. DB přístup je v protected metodách
 * (spy subclass).
 */
class WorkOrderDocumentTest extends TestCase
{
    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
        'overhead' => ['name' => 'Interní průběžná', 'external' => false, 'oneOff' => false, 'invoicing' => null],
        'internal' => ['name' => 'Interní jednorázová', 'external' => false, 'oneOff' => true, 'invoicing' => null],
    ];

    /** Řady: 1 = externí jednorázová (roční restart), 2 = interní průběžná (průběžně), 3 = periodická. */
    private const SERIES = [
        1 => ['id' => 1, 'kind' => 11, 'type' => 'project', 'number_code' => 'Z', 'number_pattern' => '%C%y%4', 'reset_scope' => 'fiscal_year', 'docState' => 40],
        2 => ['id' => 2, 'kind' => 12, 'type' => 'overhead', 'number_code' => 'R', 'number_pattern' => 'R-%5', 'reset_scope' => 'none', 'docState' => 40],
        3 => ['id' => 3, 'kind' => 13, 'type' => 'periodic', 'number_code' => 'P', 'number_pattern' => '%C%Y%3', 'reset_scope' => 'fiscal_year', 'docState' => 40],
        4 => ['id' => 4, 'kind' => 14, 'type' => 'internal', 'number_code' => 'I', 'number_pattern' => '%C%4', 'reset_scope' => 'none', 'docState' => 40],
        9 => ['id' => 9, 'kind' => 11, 'type' => 'project', 'number_code' => 'X', 'number_pattern' => '%C%4', 'reset_scope' => 'none', 'docState' => 90],
    ];

    /** @param array<int, array<string, mixed>> $rows uložené zakázky podle id */
    private function doc(array $rows = [], ?int $fiscalYearId = 26, string $homeCurrency = 'czk'): TestableWorkOrderDocument
    {
        $doc = new TestableWorkOrderDocument();
        $doc->rows = $rows;
        $doc->series = self::SERIES;
        $doc->fiscalYearId = $fiscalYearId;
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([[WorkOrderTypes::CFG_ITEM, self::TYPES]]);
        $doc->setConfig($config);
        $doc->setDb($this->createMock(\Dibi\Connection::class));
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->willReturnMap([[WorkOrderDocument::HOME_CURRENCY_SETTING, $homeCurrency]]);
        $doc->setSettings($settings);
        return $doc;
    }

    /** @return array<string, mixed> externí jednorázová zakázka v konceptu */
    private function project(array $overrides = []): array
    {
        return array_merge([
            'number_series' => 1,
            'title'         => 'Rekonstrukce haly',
            'customer'      => 50,
            'date_start'    => '2026-03-01',
            'docState'      => 10,
        ], $overrides);
    }

    /** @return list<string> column:code */
    private function codes(WorkOrderDocument $doc, array &$data): array
    {
        return array_map(
            static fn(array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    // --- řada, druh, typ -------------------------------------------------------

    public function testSeriesDenormalizesKindAndTypeOverClientValues(): void
    {
        $data = $this->project(['kind' => 99, 'type' => 'overhead']);
        $this->assertSame([], $this->codes($this->doc(), $data));
        $this->assertSame(11, $data['kind']);
        $this->assertSame('project', $data['type']);
    }

    public function testTitleAndSeriesRequiredAndSeriesMustBeAlive(): void
    {
        $data = ['title' => ' '];
        $this->assertSame(['title:required', 'number_series:required'], $this->codes($this->doc(), $data));

        $data = $this->project(['number_series' => 9]);
        $this->assertSame(['number_series:invalid_state'], $this->codes($this->doc(), $data));

        $data = $this->project(['number_series' => 77]);
        $this->assertSame(['number_series:not_found'], $this->codes($this->doc(), $data));
    }

    public function testSeriesOfConfirmedWorkOrderIsLocked(): void
    {
        $stored = [5 => ['id' => 5, 'number_series' => 1, 'kind' => 11, 'type' => 'project', 'parent' => null, 'docState' => 40]];
        $data = $this->project(['id' => 5, 'number_series' => 2, 'docState' => 80]);
        $this->assertSame(['number_series:seriesLocked'], $this->codes($this->doc($stored), $data));

        // Koncept řadu měnit smí.
        $stored[5]['docState'] = 10;
        $data = $this->project(['id' => 5, 'number_series' => 2, 'docState' => 10]);
        $this->assertSame([], $this->codes($this->doc($stored), $data));
        $this->assertSame('overhead', $data['type']);
    }

    // --- validace podle typu ---------------------------------------------------

    public function testExternalTypeNeedsCustomerOnlyAtConfirmation(): void
    {
        $draft = $this->project(['customer' => null]);
        $this->assertSame([], $this->codes($this->doc(), $draft));

        $confirm = $this->project(['customer' => null, 'docState' => 40]);
        $this->assertSame(['customer:required'], $this->codes($this->doc(), $confirm));
    }

    public function testInternalTypeDropsPartyFieldsAndNonOneOffDropsParent(): void
    {
        $doc = $this->doc();
        $data = $this->project(['number_series' => 2, 'customer' => 50, 'currency' => 'eur', 'payment_reference' => '123', 'parent' => null]);
        $this->assertSame([], $this->codes($doc, $data));

        $doc->beforeSave($data, null);
        $this->assertNull($data['customer']);
        $this->assertNull($data['currency']);
        $this->assertNull($data['payment_reference']);
        $this->assertNull($data['parent']);
    }

    public function testExternalTypeGetsHomeCurrencyByDefault(): void
    {
        $doc = $this->doc(homeCurrency: 'eur');
        $data = $this->project(['currency' => '']);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertSame('eur', $data['currency']);

        $data = $this->project(['currency' => 'usd']);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertSame('usd', $data['currency']);
    }

    // --- nadřazená zakázka (D15, P4) ------------------------------------------

    public function testParentRules(): void
    {
        $rows = [
            20 => ['id' => 20, 'number_series' => 1, 'kind' => 11, 'type' => 'project', 'parent' => null, 'docState' => 40],
            21 => ['id' => 21, 'number_series' => 3, 'kind' => 13, 'type' => 'periodic', 'parent' => null, 'docState' => 40],
            22 => ['id' => 22, 'number_series' => 1, 'kind' => 11, 'type' => 'project', 'parent' => null, 'docState' => 90],
            // 30 → 31 → 32: řetězec předků pro test cyklu
            30 => ['id' => 30, 'number_series' => 4, 'kind' => 14, 'type' => 'internal', 'parent' => 31, 'docState' => 40],
            31 => ['id' => 31, 'number_series' => 4, 'kind' => 14, 'type' => 'internal', 'parent' => 32, 'docState' => 40],
            32 => ['id' => 32, 'number_series' => 4, 'kind' => 14, 'type' => 'internal', 'parent' => null, 'docState' => 40],
        ];
        $doc = $this->doc($rows);

        // Průběžná (ne jednorázová) zakázka nadřazenou mít nesmí.
        $data = $this->project(['number_series' => 2, 'parent' => 20]);
        $this->assertSame(['parent:not_allowed'], $this->codes($doc, $data));

        $data = $this->project(['parent' => 20]);
        $this->assertSame([], $this->codes($doc, $data));

        $data = $this->project(['parent' => 21]);
        $this->assertSame(['parent:parent_type'], $this->codes($doc, $data));

        $data = $this->project(['parent' => 22]);
        $this->assertSame(['parent:invalid_state'], $this->codes($doc, $data));

        $data = $this->project(['parent' => 99]);
        $this->assertSame(['parent:not_found'], $this->codes($doc, $data));

        $data = $this->project(['id' => 30, 'number_series' => 4, 'parent' => 30]);
        $this->assertSame(['parent:cycle'], $this->codes($doc, $data));

        // 32 pod 30 by uzavřelo kruh 30 → 31 → 32 → 30.
        $data = $this->project(['id' => 32, 'number_series' => 4, 'parent' => 30]);
        $this->assertSame(['parent:cycle'], $this->codes($doc, $data));

        // Interní jednorázová pod externí jednorázovou je v pořádku.
        $data = $this->project(['id' => 32, 'number_series' => 4, 'parent' => 20]);
        $this->assertSame([], $this->codes($doc, $data));
    }

    // --- data ------------------------------------------------------------------

    public function testStartDateRequiredAtConfirmationAndEndNotBeforeStart(): void
    {
        $data = $this->project(['date_start' => null, 'docState' => 40]);
        $this->assertSame(['date_start:required'], $this->codes($this->doc(), $data));

        $data = $this->project(['date_end' => '2026-02-01']);
        $this->assertSame(['date_end:invalid_range'], $this->codes($this->doc(), $data));
    }

    public function testMissingFiscalYearBlocksConfirmationOfYearlySeries(): void
    {
        $data = $this->project(['docState' => 40]);
        $this->assertSame(['date_start:fiscalYearMissing'], $this->codes($this->doc(fiscalYearId: null), $data));

        // Průběžná řada fiskální rok nepotřebuje.
        $data = $this->project(['number_series' => 2, 'docState' => 40]);
        $this->assertSame([], $this->codes($this->doc(fiscalYearId: null), $data));

        // Importované číslo rok také nepotřebuje.
        $data = $this->project(['number' => 'Z260099', 'docState' => 40]);
        $this->assertSame([], $this->codes($this->doc(fiscalYearId: null), $data));
    }

    public function testDuplicateNumberIsRejected(): void
    {
        $doc = $this->doc();
        $doc->numberOwners = ['Z260001' => 3];
        $data = $this->project(['number' => 'Z260001']);
        $this->assertSame(['number:duplicate'], $this->codes($doc, $data));
    }

    // --- číslo při potvrzení (D17) --------------------------------------------

    public function testConfirmationAssignsNumberFromCounterWithinFiscalYear(): void
    {
        $doc = $this->doc();
        $doc->nextSequences = [7];
        $data = $this->project(['id' => 5, 'docState' => 40]);
        $original = $this->project(['id' => 5, 'docState' => 10]);

        $doc->validate($data);
        $doc->beforeSave($data, $original);

        $this->assertSame([[1, 26]], $doc->sequenceCalls, 'řada 1, rozsah = fiskální rok zahájení');
        $this->assertSame(7, $data['sequence_number']);
        $this->assertSame(26, $data['fiscal_year']);
        $this->assertSame('Z260007', $data['number']);
        $this->assertSame(['old' => 10, 'new' => 40], $doc->getStateTransition());
    }

    public function testContinuousSeriesUsesNullScopeAndYearFromStartDate(): void
    {
        $doc = $this->doc(fiscalYearId: null);
        $doc->nextSequences = [12];
        $data = $this->project(['number_series' => 2, 'docState' => 40]);

        $doc->validate($data);
        $doc->beforeSave($data, null);

        $this->assertSame([[2, null]], $doc->sequenceCalls);
        $this->assertNull($data['fiscal_year']);
        $this->assertSame('R-00012', $data['number']);
        $this->assertSame(['old' => 0, 'new' => 40], $doc->getStateTransition());
    }

    // --- převzaté číslo s pořadím (import, I4) ----------------------------------

    public function testImportSequenceSyncsCounterAndLeavesData(): void
    {
        $doc = $this->doc();
        $data = $this->project(['number' => 'Z260007', WorkOrderDocument::IMPORT_SEQUENCE_KEY => 7]);

        $doc->validate($data);
        $doc->beforeSave($data, null);

        $this->assertArrayNotHasKey(WorkOrderDocument::IMPORT_SEQUENCE_KEY, $data, 'marker nesmí dojít do SQL');
        $this->assertSame(7, $data['sequence_number']);
        $this->assertSame(26, $data['fiscal_year'], 'rozsah = fiskální rok zahájení');
        $this->assertSame([[1, 26, 7]], $doc->syncCalls);
        $this->assertSame([], $doc->sequenceCalls, 'číslo se nepřiděluje');
        $this->assertSame('Z260007', $data['number']);

        // Průběžná řada: rozsah NULL.
        $doc = $this->doc(fiscalYearId: null);
        $data = $this->project(['number_series' => 2, 'number' => 'R-00012', WorkOrderDocument::IMPORT_SEQUENCE_KEY => 12, 'docState' => 40]);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertSame(12, $data['sequence_number']);
        $this->assertNull($data['fiscal_year']);
        $this->assertSame([[2, null, 12]], $doc->syncCalls);
        $this->assertSame([], $doc->sequenceCalls);
    }

    public function testImportSequenceWithoutNumberOrYearlySeriesWithoutYear(): void
    {
        // Bez čísla se marker jen vyhodí.
        $doc = $this->doc();
        $data = $this->project([WorkOrderDocument::IMPORT_SEQUENCE_KEY => 7]);
        $doc->beforeSave($data, null);
        $this->assertArrayNotHasKey(WorkOrderDocument::IMPORT_SEQUENCE_KEY, $data);
        $this->assertArrayNotHasKey('sequence_number', $data);
        $this->assertSame([], $doc->syncCalls);

        // Roční řada bez fiskálního roku: pojistka za verifierem importu.
        $doc = $this->doc(fiscalYearId: null);
        $data = $this->project(['number' => 'Z260007', WorkOrderDocument::IMPORT_SEQUENCE_KEY => 7]);
        $this->expectException(\DomainException::class);
        $doc->beforeSave($data, null);
    }

    public function testImportedNumberIsKeptAndRepairDoesNotRenumber(): void
    {
        $doc = $this->doc();
        $data = $this->project(['number' => 'Z250123', 'docState' => 40]);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertSame([], $doc->sequenceCalls);
        $this->assertSame('Z250123', $data['number']);

        // V opravě → V pořádku číslo nemění.
        $doc = $this->doc();
        $data = $this->project(['id' => 5, 'number' => 'Z260007', 'docState' => 40]);
        $doc->beforeSave($data, $this->project(['id' => 5, 'number' => 'Z260007', 'docState' => 80]));
        $this->assertSame([], $doc->sequenceCalls);
        $this->assertSame('Z260007', $data['number']);
    }

    // --- koncové stavy (D18) ---------------------------------------------------

    public function testFinishingOrCancellingFillsEmptyEndDate(): void
    {
        foreach ([WorkOrderDocument::STATE_FINISHED, WorkOrderDocument::STATE_CANCELLED] as $state) {
            $doc = $this->doc();
            $doc->today = '2026-09-30';
            $data = $this->project(['id' => 5, 'number' => 'Z260007', 'docState' => $state, 'date_end' => null]);
            $doc->beforeSave($data, $this->project(['id' => 5, 'number' => 'Z260007', 'docState' => 40]));
            $this->assertSame('2026-09-30', $data['date_end'], "stav {$state}");
        }

        // Vyplněné datum zůstává.
        $doc = $this->doc();
        $doc->today = '2026-09-30';
        $data = $this->project(['id' => 5, 'number' => 'Z260007', 'docState' => 70, 'date_end' => '2026-08-15']);
        $doc->beforeSave($data, $this->project(['id' => 5, 'number' => 'Z260007', 'docState' => 40]));
        $this->assertSame('2026-08-15', $data['date_end']);
    }

    public function testOnlyDraftCanBeDeleted(): void
    {
        $doc = $this->doc();
        $doc->beforeDelete(['id' => 5, 'docState' => 10]);

        $this->expectException(\DomainException::class);
        $doc->beforeDelete(['id' => 5, 'docState' => 40]);
    }

    public function testDeletingDraftRemovesItsRows(): void
    {
        $doc = $this->doc();
        $doc->afterDelete(['id' => 5, 'docState' => 10]);
        $this->assertSame([5], $doc->deletedRows);
    }

    // --- fakturační předpis periodické zakázky (fáze 2, D3) --------------------

    /** @return array<string, mixed> periodická zakázka (řada 3, druh 13) */
    private function periodic(array $overrides = []): array
    {
        return array_merge([
            'number_series'   => 3,
            'title'           => 'Nájem kanceláře',
            'customer'        => 50,
            'date_start'      => '2026-08-01',
            'inv_periodicity' => 'month',
            'docState'        => 10,
        ], $overrides);
    }

    public function testPeriodicConfirmationNeedsPeriodicitySettingsAndRows(): void
    {
        $doc = $this->doc();
        $doc->kinds = [13 => ['id' => 13, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 5]];
        $doc->rowCounts = [5 => 2];

        // Koncept nic z toho nepotřebuje.
        $data = $this->periodic(['inv_periodicity' => null]);
        $this->assertSame([], $this->codes($doc, $data));

        $data = $this->periodic(['id' => 5, 'inv_periodicity' => null, 'docState' => 40]);
        $this->assertSame(['inv_periodicity:required'], $this->codes($doc, $data));

        $data = $this->periodic(['id' => 5, 'docState' => 40]);
        $this->assertSame([], $this->codes($doc, $data));

        // Bez řádků předpisu nejde potvrdit — chyba formuláře, ne pole.
        $data = $this->periodic(['id' => 6, 'docState' => 40]);
        $this->assertSame(['_form:rows_required'], $this->codes($doc, $data));

        // Druh bez typu dokladu a řady: potvrzení vyžaduje přepis na zakázce.
        $doc->kinds = [13 => ['id' => 13, 'type' => 'periodic']];
        $data = $this->periodic(['id' => 5, 'docState' => 40]);
        $this->assertSame(['inv_doc_type:required', 'inv_number_series:required'], $this->codes($doc, $data));
        $data = $this->periodic(['id' => 5, 'docState' => 40, 'inv_doc_type' => 'invpo', 'inv_number_series' => 6]);
        $this->assertSame([], $this->codes($doc, $data));
    }

    public function testPeriodicValuesAreCheckedForShapeAndSeriesTypeMatchesEffectiveDocType(): void
    {
        $doc = $this->doc();
        $doc->kinds = [13 => ['id' => 13, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 5]];

        $data = $this->periodic(['inv_periodicity' => 'week', 'inv_doc_type' => 'invni', 'inv_timing' => 'middle', 'inv_due_days' => -3]);
        $this->assertSame(
            ['inv_periodicity:invalid', 'inv_doc_type:invalid', 'inv_timing:invalid', 'inv_due_days:invalid'],
            $this->codes($doc, $data),
        );

        // Řada zálohových faktur k typu z druhu (FV) nesedí …
        $data = $this->periodic(['inv_number_series' => 6]);
        $this->assertSame(['inv_number_series:series_type_mismatch'], $this->codes($doc, $data));
        // … s přepisem typu na zakázce sedí.
        $data = $this->periodic(['inv_doc_type' => 'invpo', 'inv_number_series' => 6]);
        $this->assertSame([], $this->codes($doc, $data));
        $data = $this->periodic(['inv_number_series' => 77]);
        $this->assertSame(['inv_number_series:not_found'], $this->codes($doc, $data));
    }

    public function testInvoiceFromDefaultsToStartAndNonPeriodicDropsInvoicingFields(): void
    {
        $doc = $this->doc();
        $data = $this->periodic(['inv_from' => null]);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertSame('2026-08-01', $data['inv_from']);

        $data = $this->periodic(['inv_from' => '2026-09-01']);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertSame('2026-09-01', $data['inv_from']);

        $data = $this->project(['inv_periodicity' => 'month', 'inv_from' => '2026-09-01', 'inv_doc_type' => 'invno', 'inv_due_days' => 14]);
        $doc->validate($data);
        $doc->beforeSave($data, null);
        $this->assertNull($data['inv_periodicity']);
        $this->assertNull($data['inv_from']);
        $this->assertNull($data['inv_doc_type']);
        $this->assertNull($data['inv_due_days']);
    }
}

class TestableWorkOrderDocument extends WorkOrderDocument
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    /** @var array<int, array<string, mixed>> */
    public array $series = [];
    public ?int $fiscalYearId = null;
    /** @var array<string, int> číslo → id */
    public array $numberOwners = [];
    /** @var list<int> */
    public array $nextSequences = [];
    /** @var list<array{int, ?int}> */
    public array $sequenceCalls = [];
    /** @var list<array{int, ?int, int}> syncImported(řada, rozsah, pořadí) */
    public array $syncCalls = [];
    public string $today = '2026-10-08';
    /** @var array<int, array<string, mixed>> druhy podle id (sloupce inv_*) */
    public array $kinds = [];
    /** @var array<int, array<string, mixed>> řady dokladů: 5 = FV, 6 = zálohová FV */
    public array $docSeries = [
        5 => ['id' => 5, 'doc_type' => 'invno', 'docState' => 40],
        6 => ['id' => 6, 'doc_type' => 'invpo', 'docState' => 40],
    ];
    /** @var array<int, int> zakázka → počet řádků předpisu */
    public array $rowCounts = [];
    /** @var list<int> */
    public array $deletedRows = [];

    protected function loadRow(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    protected function loadKind(int $kindId): ?array
    {
        return $this->kinds[$kindId] ?? null;
    }

    protected function loadDocSeries(int $seriesId): ?array
    {
        return $this->docSeries[$seriesId] ?? null;
    }

    protected function countRows(int $workOrderId): int
    {
        return $this->rowCounts[$workOrderId] ?? 0;
    }

    protected function deleteRows(int $workOrderId): void
    {
        $this->deletedRows[] = $workOrderId;
    }

    protected function loadSeries(int $seriesId): ?array
    {
        return $this->series[$seriesId] ?? null;
    }

    protected function fiscalYearIdForDate(string $date): ?int
    {
        return $this->fiscalYearId;
    }

    protected function yearLabel(int $fiscalYearId): string
    {
        return '2026';
    }

    protected function findNumberOwner(string $number, ?int $excludeId): ?int
    {
        $owner = $this->numberOwners[$number] ?? null;
        return $owner !== null && $owner !== $excludeId ? $owner : null;
    }

    protected function nextSequence(int $seriesId, ?int $fiscalYearId): int
    {
        $this->sequenceCalls[] = [$seriesId, $fiscalYearId];
        return array_shift($this->nextSequences) ?? 1;
    }

    protected function syncSequence(int $seriesId, ?int $fiscalYearId, int $sequence): void
    {
        $this->syncCalls[] = [$seriesId, $fiscalYearId, $sequence];
    }

    protected function today(): string
    {
        return $this->today;
    }
}
