<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentLockProvider;
use Shipard\Core\Document\ValidationResult;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Facility\Buildings\ContractDocument;
use Shipard\Module\Facility\Buildings\ContractKindDocument;
use Shipard\Module\Facility\Buildings\ContractKindRowDocument;
use Shipard\Module\Facility\Buildings\ContractKindRowLockProvider;
use Shipard\Module\Facility\Buildings\ContractRowDocument;
use Shipard\Module\Facility\Buildings\ContractRowLockProvider;

/** Spy smlouvy pro fakturační model: druhy, řady dokladů a řádky předpisu z polí; kopii šablony zaznamenává. */
final class InvoicingContractDocument extends ContractDocument
{
    /** @var array<int, array<string, mixed>> uložené smlouvy */
    public array $stored = [];
    /** @var array<int, array<string, mixed>> druhy */
    public array $kinds = [];
    /** @var array<int, array<string, mixed>> řady dokladů */
    public array $series = [];
    /** @var array<int, list<array<string, mixed>>> řádky předpisu smlouvy */
    public array $prescription = [];
    /** @var list<array{0: int, 1: int}> kopie šablony (smlouva, druh) */
    public array $copies = [];
    /** @var list<int> smlouvy, kterým se smazaly řádky předpisu */
    public array $deletedPrescription = [];
    public string $homeCurrency = 'czk';

    protected function loadRow(int $id): ?array
    {
        return $this->stored[$id] ?? null;
    }

    protected function loadKind(int $kindId): ?array
    {
        return $this->kinds[$kindId] ?? null;
    }

    protected function loadDocSeries(int $seriesId): ?array
    {
        return $this->series[$seriesId] ?? null;
    }

    protected function loadPrescriptionRows(int $contractId): array
    {
        return $this->prescription[$contractId] ?? [];
    }

    protected function countPrescriptionRows(int $contractId): int
    {
        return count($this->prescription[$contractId] ?? []);
    }

    protected function copyTemplateRows(int $contractId, int $kindId): void
    {
        $this->copies[] = [$contractId, $kindId];
    }

    protected function deletePrescriptionRows(int $contractId): void
    {
        $this->deletedPrescription[] = $contractId;
    }

    protected function deletePlaceRows(int $contractId): void
    {
    }

    protected function homeCurrency(): string
    {
        return $this->homeCurrency;
    }

    protected function nextSequence(int $kindId, ?int $year): int
    {
        return 1;
    }
}

final class TestableContractRowDocument extends ContractRowDocument
{
    /** @var array<int, array{date_start: ?string, date_end: ?string}> */
    public array $contracts = [];

    protected function loadContractValidity(int $contractId): ?array
    {
        return $this->contracts[$contractId] ?? null;
    }
}

final class TestableContractRowLockProvider extends ContractRowLockProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $parents = [];

    public function withDb(): self
    {
        $this->db = (new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor();
        return $this;
    }

    protected function loadParents(array $ids): array
    {
        return array_intersect_key($this->parents, array_flip($ids));
    }
}

final class TestableContractKindRowLockProvider extends ContractKindRowLockProvider
{
    /** @var array<int, array<string, mixed>> */
    public array $parents = [];

    public function withDb(): self
    {
        $this->db = (new \ReflectionClass(\Dibi\Connection::class))->newInstanceWithoutConstructor();
        return $this;
    }

    protected function loadParents(array $ids): array
    {
        return array_intersect_key($this->parents, array_flip($ids));
    }
}

/**
 * Fakturační model Budov (#174 D29, D30, D32, D36): šablona řádků druhu se
 * kopíruje jen při vložení nebo změně druhu konceptu bez řádků, „fakturovat
 * od“ se řídí zahájením, potvrzení smlouvy s periodicitou vyžaduje úplný
 * předpis a řádky uvnitř platnosti smlouvy, řádky a šablona se zamykají
 * stavem rodiče a tabulky drží tvar, který engine předepisuje.
 */
final class ContractInvoicingModelTest extends TestCase
{
    use BuildingsConfig;

    private const MODULES = __DIR__ . '/../../../../../modules';
    private const KIND_PLAIN = 2;
    private const KIND_INVOICED = 5;
    private const KIND_OTHER = 6;

    private function document(): InvoicingContractDocument
    {
        $document = new InvoicingContractDocument();
        $document->setConfig($this->buildingsConfig());
        $kind = static fn (array $inv = []): array => $inv + [
            'type' => 'other', 'number_code' => 'OS', 'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 40,
        ];
        $document->kinds = [
            self::KIND_PLAIN => $kind(),
            self::KIND_INVOICED => $kind(['inv_doc_type' => 'invno', 'inv_number_series' => 11]),
            self::KIND_OTHER => $kind(),
        ];
        $document->series = [
            11 => ['id' => 11, 'doc_type' => 'invno', 'docState' => 40],
            12 => ['id' => 12, 'doc_type' => 'invpo', 'docState' => 40],
        ];
        return $document;
    }

    /** @return array<string, mixed> */
    private static function contract(array $overrides = []): array
    {
        return $overrides + [
            'kind' => self::KIND_INVOICED, 'title' => 'Pronájem', 'customer' => 7,
            'date_start' => '2027-01-01', 'date_end' => null, 'docState' => 10,
        ];
    }

    /** @return list<string> */
    private static function errors(ValidationResult $result): array
    {
        return array_map(static fn ($e): string => $e->column . ':' . $e->code, $result->getErrors());
    }

    /** Uložení jako v gateway: beforeSave s originálem, po zápisu afterPersist. */
    private static function save(InvoicingContractDocument $document, array $data, ?array $original, int $id): void
    {
        $document->beforeSave($data, $original);
        $document->afterPersist(['id' => $id] + $data);
    }

    // ── Šablona řádků (D32) ─────────────────────────────────────────────────

    public function testNewDraftGetsTheTemplateOfItsKind(): void
    {
        $document = $this->document();
        self::save($document, self::contract(), null, 31);
        $this->assertSame([[31, self::KIND_INVOICED]], $document->copies);
    }

    public function testChangingTheKindOfADraftWithoutRowsCopiesTheNewTemplate(): void
    {
        $document = $this->document();
        $original = ['id' => 31] + self::contract(['kind' => self::KIND_PLAIN]);
        self::save($document, ['id' => 31] + self::contract(['kind' => self::KIND_INVOICED]), $original, 31);
        $this->assertSame([[31, self::KIND_INVOICED]], $document->copies);
    }

    public function testDraftThatAlreadyHasRowsKeepsThemOnKindChange(): void
    {
        $document = $this->document();
        $document->prescription[31] = [['id' => 1, 'description' => 'Nájem']];
        $original = ['id' => 31] + self::contract(['kind' => self::KIND_PLAIN]);
        self::save($document, ['id' => 31] + self::contract(), $original, 31);
        $this->assertSame([], $document->copies);
    }

    public function testSavingADraftWhoseRowsWereDeletedDoesNotBringTheTemplateBack(): void
    {
        // Past zadání: „smlouva nemá řádky“ nestačí — bez vložení nebo změny
        // druhu se šablona nekopíruje.
        $document = $this->document();
        $original = ['id' => 31] + self::contract();
        self::save($document, ['id' => 31] + self::contract(['title' => 'Jiný název']), $original, 31);
        $this->assertSame([], $document->copies);
    }

    public function testOnlyDraftsGetTheTemplateAndTheMarkDoesNotLeakToTheNextSave(): void
    {
        $document = $this->document();
        self::save($document, self::contract(['docState' => 40, 'number' => 'OS-2027-0001']), null, 32);
        $this->assertSame([], $document->copies, 'vložení rovnou V pořádku šablonu nedostane');

        // Poznámka z vložení nesmí přežít do dalšího uložení téže instance.
        $data = self::contract();
        $document->beforeSave($data, null);
        $original = ['id' => 33] + self::contract();
        self::save($document, ['id' => 33] + self::contract(), $original, 33);
        $this->assertSame([], $document->copies);
    }

    // ── Fakturovat od, měna (D29, D36) ──────────────────────────────────────

    public function testEmptyInvoiceFromIsFilledWithTheStartAndMayNotPrecedeIt(): void
    {
        $data = self::contract();
        $this->assertSame([], self::errors($this->document()->validate($data)));
        $this->assertSame('2027-01-01', $data['inv_from']);

        $data = self::contract(['inv_from' => '2027-03-01']);
        $this->assertSame([], self::errors($this->document()->validate($data)));
        $this->assertSame('2027-03-01', $data['inv_from'], 'pozdější datum zůstává');

        $data = self::contract(['inv_from' => '2026-12-31']);
        $this->assertSame(['inv_from:before_start'], self::errors($this->document()->validate($data)));

        $data = self::contract(['date_start' => null]);
        $this->assertSame([], self::errors($this->document()->validate($data)));
        $this->assertArrayNotHasKey('inv_from', $data, 'bez zahájení není čím doplnit');
    }

    public function testShapeOfThePrescriptionIsCheckedAlways(): void
    {
        $data = self::contract([
            'inv_periodicity' => 'weekly', 'inv_doc_type' => 'invni', 'inv_timing' => 'middle', 'inv_due_days' => -1,
        ]);
        $this->assertSame(
            ['inv_periodicity:invalid', 'inv_doc_type:invalid', 'inv_timing:invalid', 'inv_due_days:invalid'],
            self::errors($this->document()->validate($data)),
        );

        // Řada jiného typu, než je efektivní typ dokladu (z druhu), a řada, která není.
        $data = self::contract(['inv_number_series' => 12]);
        $this->assertSame(['inv_number_series:series_type_mismatch'], self::errors($this->document()->validate($data)));
        $data = self::contract(['inv_doc_type' => 'invpo', 'inv_number_series' => 12]);
        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testEmptyCurrencyBecomesTheHomeCurrency(): void
    {
        $document = $this->document();
        $document->homeCurrency = 'eur';

        $data = self::contract();
        $document->beforeSave($data, null);
        $this->assertSame('eur', $data['currency']);

        $data = self::contract(['currency' => 'usd', 'payment_reference' => ' 2027001 ', 'inv_doc_text' => '  ']);
        $document->beforeSave($data, null);
        $this->assertSame('usd', $data['currency']);
        $this->assertSame('2027001', $data['payment_reference']);
        $this->assertNull($data['inv_doc_text']);

        // Úprava bez klíče měny na uloženou hodnotu nesahá.
        $data = ['id' => 31, 'title' => 'Pronájem', 'docState' => 10];
        $document->beforeSave($data, ['id' => 31, 'kind' => self::KIND_INVOICED, 'currency' => 'usd', 'docState' => 10]);
        $this->assertArrayNotHasKey('currency', $data);
    }

    // ── Potvrzení (D36) ─────────────────────────────────────────────────────

    public function testContractWithoutPeriodicityConfirmsWithoutPrescription(): void
    {
        $document = $this->document();
        $document->stored[31] = ['id' => 31, 'kind' => self::KIND_PLAIN, 'type' => 'other', 'docState' => 10];
        $data = ['id' => 31] + self::contract(['kind' => self::KIND_PLAIN, 'docState' => 40]);
        $this->assertSame([], self::errors($document->validate($data)));
    }

    public function testConfirmationWithPeriodicityNeedsDocTypeSeriesAndARow(): void
    {
        $document = $this->document();
        $document->stored[31] = ['id' => 31, 'kind' => self::KIND_PLAIN, 'type' => 'other', 'docState' => 10];
        $data = ['id' => 31] + self::contract(['kind' => self::KIND_PLAIN, 'docState' => 40, 'inv_periodicity' => 'month']);
        $this->assertSame(
            ['inv_doc_type:required', 'inv_number_series:required', '_form:rows_required'],
            self::errors($document->validate($data)),
        );

        // V Konceptu tatáž smlouva projde — povinné je to až při potvrzení.
        $draft = ['id' => 31] + self::contract(['kind' => self::KIND_PLAIN, 'inv_periodicity' => 'month']);
        $this->assertSame([], self::errors($document->validate($draft)));
    }

    public function testDocTypeAndSeriesMayComeFromTheKindOrTheContract(): void
    {
        $document = $this->document();
        $document->prescription[31] = [['id' => 1, 'description' => 'Nájem', 'valid_from' => null, 'valid_to' => null]];

        $document->stored[31] = ['id' => 31, 'kind' => self::KIND_INVOICED, 'type' => 'other', 'docState' => 10];
        $data = ['id' => 31] + self::contract(['docState' => 40, 'inv_periodicity' => 'quarter']);
        $this->assertSame([], self::errors($document->validate($data)), 'typ dokladu a řada z druhu');

        $document->stored[31]['kind'] = self::KIND_PLAIN;
        $data = ['id' => 31] + self::contract([
            'kind' => self::KIND_PLAIN, 'docState' => 40, 'inv_periodicity' => 'quarter', 'inv_doc_type' => 'invpo', 'inv_number_series' => 12,
        ]);
        $this->assertSame([], self::errors($document->validate($data)), 'přepis na smlouvě');
    }

    public function testRowsMustLieWithinTheContractValidityOnConfirmation(): void
    {
        $document = $this->document();
        $document->stored[31] = ['id' => 31, 'kind' => self::KIND_INVOICED, 'type' => 'other', 'docState' => 80];
        $document->prescription[31] = [
            ['id' => 1, 'description' => 'Nájem 2027', 'valid_from' => '2027-01-01', 'valid_to' => '2027-12-31'],
            ['id' => 2, 'description' => 'Nájem od 2028', 'valid_from' => '2028-01-01', 'valid_to' => null],
            ['id' => 3, 'description' => '', 'valid_from' => '2026-06-01', 'valid_to' => null],
        ];
        $data = ['id' => 31] + self::contract(['docState' => 40, 'inv_periodicity' => 'month', 'date_end' => '2027-12-31']);
        $result = $document->validate($data);

        $this->assertSame(['_form:row_outside_contract', '_form:row_outside_contract'], self::errors($result));
        $messages = array_map(static fn ($e): string => $e->message, $result->getErrors());
        $this->assertSame('Řádek „Nájem od 2028“: platnost od 01.01.2028 je po ukončení smlouvy (31.12.2027).', $messages[0]);
        $this->assertSame('Řádek „#3“: platnost od 01.06.2026 je před zahájením smlouvy (01.01.2027).', $messages[1]);

        // Bez periodicity se řádky při potvrzení nehlídají.
        $plain = ['id' => 31] + self::contract(['docState' => 40, 'date_end' => '2027-12-31']);
        $this->assertSame([], self::errors($document->validate($plain)));
    }

    public function testDeletingADraftRemovesItsPrescriptionRows(): void
    {
        $document = $this->document();
        $document->afterDelete(['id' => 31]);
        $this->assertSame([31], $document->deletedPrescription);
    }

    // ── Řádky předpisu a šablony ────────────────────────────────────────────

    public function testRowValidityAgainstTheContract(): void
    {
        $inside = ['valid_from' => '2027-02-01', 'valid_to' => '2027-06-30'];
        $this->assertSame([], ContractRowDocument::validityProblems($inside, '2027-01-01', '2027-12-31'));
        $this->assertSame([], ContractRowDocument::validityProblems([], '2027-01-01', '2027-12-31'), 'prázdná platnost řádku neomezuje');
        $this->assertSame([], ContractRowDocument::validityProblems(['valid_from' => '2020-01-01', 'valid_to' => '2099-01-01'], null, null));

        $this->assertSame(
            ['valid_from', 'valid_to'],
            array_keys(ContractRowDocument::validityProblems(['valid_from' => '2026-12-01', 'valid_to' => '2028-01-31'], '2027-01-01', '2027-12-31')),
        );
        $this->assertSame(
            ['valid_to' => 'platnost do 30.11.2026 je před zahájením smlouvy (01.01.2027).'],
            ContractRowDocument::validityProblems(['valid_to' => '2026-11-30'], '2027-01-01', null),
        );
    }

    public function testRowDocumentReportsValidityOutsideItsContract(): void
    {
        $document = new TestableContractRowDocument();
        $document->contracts[31] = ['date_start' => '2027-01-01', 'date_end' => '2027-12-31'];

        $data = ['contract' => 31, 'description' => 'Nájem', 'valid_from' => '2026-12-01', 'valid_to' => '2027-06-30'];
        $this->assertSame(['valid_from:outside_contract'], self::errors($document->validate($data)));

        $data = ['contract' => 31, 'description' => 'Nájem', 'valid_from' => '2027-01-01'];
        $this->assertSame([], self::errors($document->validate($data)));

        $data = ['contract' => 99, 'valid_from' => '1999-01-01'];
        $this->assertSame([], self::errors($document->validate($data)), 'neznámá smlouva pravidlo nespustí');

        $data = ['description' => 'Nájem'];
        $result = $document->validate($data);
        $this->assertSame(['contract:required'], self::errors($result));
        $this->assertSame('Řádek musí patřit smlouvě.', $result->getErrors()[0]->message);
    }

    public function testTemplateRowBelongsToAKindAndHasNoValidity(): void
    {
        $document = new ContractKindRowDocument();
        $data = ['kind' => 5, 'description' => 'Nájem', 'valid_from' => '2027-02-01', 'valid_to' => '2027-01-01'];
        $this->assertSame([], self::errors($document->validate($data)));

        $data = ['description' => 'Nájem'];
        $result = $document->validate($data);
        $this->assertSame(['kind:required'], self::errors($result));
        $this->assertSame('Řádek šablony musí patřit druhu smlouvy.', $result->getErrors()[0]->message);
    }

    // ── Zámky ───────────────────────────────────────────────────────────────

    private function statesConfig(string $cfgItem, string $file): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === $cfgItem ? self::czech($file) : null,
        );
        return $config;
    }

    public function testRowsFollowTheStateOfTheContract(): void
    {
        $provider = (new TestableContractRowLockProvider())->withDb();
        $provider->setConfig($this->statesConfig(ContractDocument::DOC_STATES_CFG_ITEM, 'facility/buildings/config/docStates.jsonc'));
        $provider->parents = [
            1 => ['number' => null, 'title' => 'Koncept', 'docState' => 10],
            2 => ['number' => 'KA-2027-0002', 'title' => 'V opravě', 'docState' => 80],
            3 => ['number' => 'KA-2027-0003', 'title' => 'Běží', 'docState' => 40],
            4 => ['number' => 'KA-2026-0004', 'title' => 'Ukončená', 'docState' => 70],
            5 => ['number' => 'KA-2026-0005', 'title' => 'Zrušená', 'docState' => 30],
        ];

        $this->assertSame([], $provider->lockReasons(ContractRowDocument::TABLE, ['contract' => 1], null));
        $this->assertSame([], $provider->lockReasons(ContractRowDocument::TABLE, ['contract' => 2], ['contract' => 2]));
        foreach ([3, 4, 5] as $id) {
            $this->assertCount(1, $provider->lockReasons(ContractRowDocument::TABLE, ['contract' => $id], null), "smlouva {$id}");
        }

        $reason = $provider->lockReasons(ContractRowDocument::TABLE, ['contract' => 3], null)[0];
        $this->assertSame(ContractRowLockProvider::SOURCE, $reason->source);
        $this->assertSame('Smlouva KA-2027-0003 je jen ke čtení — řádky uprav přes V opravě.', $reason->title);
        $this->assertStringContainsString('(V pořádku)', $reason->message);
        $this->assertSame(ContractRowLockProvider::SUBJECT_TABLE_ID, $reason->subjectTableId);
        $this->assertSame(3, $reason->subjectRowId);
    }

    public function testTemplateFollowsTheStateOfTheKind(): void
    {
        $provider = (new TestableContractKindRowLockProvider())->withDb();
        $provider->setConfig($this->statesConfig(ContractKindRowLockProvider::DOC_STATES_CFG_ITEM, 'core/system/config/docStatesArchive.jsonc'));
        $provider->parents = [
            1 => ['name' => 'Nový druh', 'docState' => 10],
            2 => ['name' => 'Druh v opravě', 'docState' => 80],
            3 => ['name' => 'Kancelář', 'docState' => 40],
            4 => ['name' => 'Starý druh', 'docState' => 70],
        ];

        $this->assertSame([], $provider->lockReasons(ContractKindRowDocument::TABLE, ['kind' => 1], null));
        $this->assertSame([], $provider->lockReasons(ContractKindRowDocument::TABLE, ['kind' => 2], null));
        $this->assertCount(1, $provider->lockReasons(ContractKindRowDocument::TABLE, ['kind' => 4], null));

        $reason = $provider->lockReasons(ContractKindRowDocument::TABLE, ['kind' => 3], null)[0];
        $this->assertSame(ContractKindRowLockProvider::SOURCE, $reason->source);
        $this->assertSame('Druh smlouvy Kancelář je jen ke čtení — šablonu řádků uprav přes V opravě.', $reason->title);
        $this->assertSame(ContractKindRowLockProvider::SUBJECT_TABLE_ID, $reason->subjectTableId);
    }

    // ── Druh: výchozí hodnoty fakturace ─────────────────────────────────────

    public function testKindChecksOnlyTheShapeOfItsInvoicingDefaults(): void
    {
        $kind = static fn (array $inv): array => $inv + [
            'name' => 'Kancelář', 'type' => 'office', 'number_code' => 'KA',
            'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 40,
        ];
        $document = new class extends ContractKindDocument {
            protected function loadDocSeries(int $seriesId): ?array
            {
                return $seriesId === 11 ? ['id' => 11, 'doc_type' => 'invno', 'docState' => 40] : null;
            }

            protected function findCodeOwner(string $code, ?int $excludeId): ?int
            {
                return null;
            }
        };
        $document->setConfig($this->buildingsConfig());

        $data = $kind([]);
        $this->assertSame([], self::errors($document->validate($data)), 'výchozí hodnoty nejsou povinné ani u potvrzeného druhu');

        $data = $kind(['inv_doc_type' => 'invno', 'inv_number_series' => 11, 'inv_due_days' => 30, 'inv_timing' => 'end']);
        $this->assertSame([], self::errors($document->validate($data)));

        $data = $kind(['inv_doc_type' => 'invpo', 'inv_number_series' => 11, 'inv_due_days' => -5, 'inv_timing' => 'later']);
        $this->assertSame(
            ['inv_number_series:series_type_mismatch', 'inv_due_days:invalid', 'inv_timing:invalid'],
            self::errors($document->validate($data)),
        );

        $data = $kind(['inv_doc_type' => 'invni']);
        $this->assertSame(['inv_doc_type:invalid'], self::errors($document->validate($data)));
    }

    // ── Deklarace a tvar tabulek ────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> sloupec → definice bez popisků */
    private static function shape(string $file, array $rename = []): array
    {
        $out = [];
        foreach (JsoncParser::parseFile(self::MODULES . '/' . $file)['columns'] as $column) {
            $id = $rename[$column['id']] ?? $column['id'];
            unset($column['id'], $column['name'], $column['name:cs'], $column['name:en'], $column['reference'], $column['group']);
            $out[$id] = $column;
        }
        return $out;
    }

    public function testRowsTemplateAndPeriodsKeepTheShapeTheEnginePrescribes(): void
    {
        $workOrderRows = self::shape('economy/workOrders/tables/economy_work_orders_rows.jsonc', ['work_order' => 'contract']);
        $this->assertSame($workOrderRows, self::shape('facility/buildings/tables/facility_buildings_contract_rows.jsonc'));

        $template = $workOrderRows;
        unset($template['valid_from'], $template['valid_to']);
        $this->assertSame(
            $template,
            self::shape('facility/buildings/tables/facility_buildings_contract_kind_rows.jsonc', ['kind' => 'contract']),
        );

        $this->assertSame(
            self::shape('economy/workOrders/tables/economy_work_orders_periods.jsonc', ['work_order' => 'contract']),
            self::shape('facility/buildings/tables/facility_buildings_contract_periods.jsonc'),
        );
        $periods = TableDefinition::fromArray(JsoncParser::parseFile(self::MODULES . '/facility/buildings/tables/facility_buildings_contract_periods.jsonc'));
        $unique = array_values(array_filter($periods->indexes, static fn ($index): bool => $index->type === 'unique'));
        $this->assertCount(1, $unique);
        $this->assertSame(['contract', 'period_from'], array_map(static fn ($c): string => $c['column'], $unique[0]->columns));
    }

    public function testPrescriptionColumnsOfKindAndContractMatchWorkOrders(): void
    {
        $columns = [
            'inv_doc_type', 'inv_number_series', 'inv_due_days', 'inv_timing', 'inv_vat_mode', 'inv_payment_method', 'inv_bank_account',
        ];
        $workOrderKinds = self::shape('economy/workOrders/tables/economy_work_orders_kinds.jsonc');
        $workOrders = self::shape('economy/workOrders/tables/economy_work_orders_heads.jsonc');
        $kinds = self::shape('facility/buildings/tables/facility_buildings_contract_kinds.jsonc');
        $contracts = self::shape('facility/buildings/tables/facility_buildings_contracts.jsonc');

        foreach ($columns as $column) {
            $this->assertSame($workOrderKinds[$column], $kinds[$column], "druh.{$column}");
            $this->assertSame($workOrders[$column], $contracts[$column], "smlouva.{$column}");
        }
        foreach (['inv_periodicity', 'inv_from', 'inv_doc_text', 'currency', 'payment_reference', 'cost_center'] as $column) {
            $this->assertSame($workOrders[$column], $contracts[$column], "smlouva.{$column}");
        }
        // Řádky jsou sub-tabulka, ne child tabulka — uložení hlavičky na ně nesahá.
        $definition = JsoncParser::parseFile(self::MODULES . '/facility/buildings/tables/facility_buildings_contracts.jsonc');
        $this->assertSame(['facility_buildings_contract_places'], array_column($definition['childTables'], 'table'));
    }

    public function testModuleDeclaresRowDocumentsAndLockProviders(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(self::MODULES . '/facility/buildings/module.jsonc'));

        $documents = array_column($module->documentClasses, 'class', 'table');
        $this->assertSame(ContractRowDocument::class, $documents[ContractRowDocument::TABLE]);
        $this->assertSame(ContractKindRowDocument::class, $documents[ContractKindRowDocument::TABLE]);

        $this->assertSame(
            [
                ['table' => ContractRowDocument::TABLE, 'class' => ContractRowLockProvider::class],
                ['table' => ContractKindRowDocument::TABLE, 'class' => ContractKindRowLockProvider::class],
            ],
            $module->documentLockProviders,
        );
        $this->assertInstanceOf(DocumentLockProvider::class, new ContractRowLockProvider());
        $this->assertInstanceOf(DocumentLockProvider::class, new ContractKindRowLockProvider());

        $tableId = static fn (string $table): int
            => (int) JsoncParser::parseFile(self::MODULES . "/facility/buildings/tables/{$table}.jsonc")['tableId'];
        $this->assertSame($tableId(ContractDocument::TABLE), ContractRowLockProvider::SUBJECT_TABLE_ID);
        $this->assertSame($tableId(ContractKindDocument::TABLE), ContractKindRowLockProvider::SUBJECT_TABLE_ID);
    }
}
