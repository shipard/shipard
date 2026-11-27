<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Facility\Buildings\ContractDocument;

/** Spy: uložené smlouvy, druhy, místa a překryvy z polí; čítač a číslo zaznamenává. */
class TestableContractDocument extends ContractDocument
{
    /** @var array<int, array<string, mixed>> uložené smlouvy */
    public array $rows = [];
    /** @var array<int, array<string, mixed>> druhy */
    public array $kinds = [];
    /** @var array<int, array<string, mixed>> místa (id → name, docState, kind_type) */
    public array $places = [];
    /** @var array<int, list<array<string, mixed>>> uložené vazby míst smlouvy */
    public array $placeRows = [];
    /** @var list<array<string, mixed>> platné smlouvy s místy pro kontrolu překryvu */
    public array $others = [];
    /** @var array<string, int> číslo → id smlouvy */
    public array $numbers = [];
    /** @var list<int> pořadí, která čítač vydá */
    public array $nextSequences = [1];
    /** @var list<array{0: int, 1: ?int}> volání čítače (druh, rok) */
    public array $sequenceCalls = [];
    /** @var list<array{0: list<int>, 1: string, 2: ?string, 3: ?int}> dotazy na překryv */
    public array $overlapCalls = [];
    /** @var list<int> smlouvy, kterým se smazaly vazby míst */
    public array $deletedPlaceRows = [];
    public string $today = '2027-06-15';

    protected function loadRow(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    protected function loadKind(int $kindId): ?array
    {
        return $this->kinds[$kindId] ?? null;
    }

    protected function loadPlaceRows(int $contractId): array
    {
        return $this->placeRows[$contractId] ?? [];
    }

    protected function loadPlaces(array $ids): array
    {
        return array_intersect_key($this->places, array_flip($ids));
    }

    /**
     * Stejné pravidlo jako SQL: jiná smlouva, společné místo, doby se
     * překrývají (prázdný konec = neomezeno), řazeno podle zahájení.
     */
    protected function findOverlaps(array $placeIds, string $start, ?string $end, ?int $excludeId): array
    {
        $this->overlapCalls[] = [$placeIds, $start, $end, $excludeId];
        $found = [];
        foreach ($this->others as $other) {
            if ((int) $other['id'] === $excludeId || !in_array($other['docState'], ContractDocument::VALID_STATES, true)) {
                continue;
            }
            if ($other['date_end'] !== null && $other['date_end'] < $start) {
                continue;
            }
            if ($end !== null && $other['date_start'] !== null && $other['date_start'] > $end) {
                continue;
            }
            foreach ($other['places'] as $placeId) {
                if (in_array($placeId, $placeIds, true)) {
                    $found[] = [
                        'place' => $placeId, 'place_name' => $this->places[$placeId]['name'] ?? null,
                        'number' => $other['number'], 'title' => $other['title'] ?? '',
                        'date_start' => $other['date_start'], 'date_end' => $other['date_end'],
                    ];
                }
            }
        }
        usort($found, static fn (array $a, array $b): int => [$a['date_start'], $a['number']] <=> [$b['date_start'], $b['number']]);
        return $found;
    }

    protected function findNumberOwner(string $number, ?int $excludeId): ?int
    {
        $owner = $this->numbers[$number] ?? null;
        return $owner !== null && $owner !== $excludeId ? $owner : null;
    }

    protected function nextSequence(int $kindId, ?int $year): int
    {
        $this->sequenceCalls[] = [$kindId, $year];
        return array_shift($this->nextSequences) ?? 1;
    }

    protected function deletePlaceRows(int $contractId): void
    {
        $this->deletedPlaceRows[] = $contractId;
    }

    protected function today(): string
    {
        return $this->today;
    }

    /** @return array{old: int, new: int}|null */
    public function transition(): ?array
    {
        return $this->stateTransition;
    }
}

/**
 * Smlouva (#174 D20–D22): validace v Konceptu a při potvrzení, místa podle
 * typu, překryv jako varování, číslo per druh a rok zahájení, ukončení.
 */
class ContractDocumentTest extends TestCase
{
    use BuildingsConfig;

    private const KIND_OFFICE = 1;
    private const KIND_OTHER = 2;
    private const KIND_CONTINUOUS = 3;
    private const KIND_PARKING = 4;

    private function document(): TestableContractDocument
    {
        $document = new TestableContractDocument();
        $document->setConfig($this->buildingsConfig());
        $kind = static fn (string $type, string $code, string $scope = 'yearly', string $pattern = '%C-%Y-%4'): array
            => ['type' => $type, 'number_code' => $code, 'number_pattern' => $pattern, 'reset_scope' => $scope, 'docState' => 40];
        $document->kinds = [
            self::KIND_OFFICE => $kind('office', 'KA'),
            self::KIND_OTHER => $kind('other', 'OS'),
            self::KIND_CONTINUOUS => $kind('storage', 'SK', 'continuous', '%C%5'),
            self::KIND_PARKING => $kind('parking', 'PA'),
        ];
        $place = static fn (string $name, string $type, int $state = 40): array
            => ['name' => $name, 'kind_type' => $type, 'docState' => $state];
        $document->places = [
            201 => $place('Kancelář 201', 'room'),
            202 => $place('Kancelář 202', 'room'),
            30 => $place('Křídlo B', 'section'),
            40 => $place('2. NP', 'floor'),
            50 => $place('Stání 5', 'spot'),
            60 => $place('Stará kancelář', 'room', 90),
        ];
        return $document;
    }

    /**
     * @param list<int> $places
     * @return array<string, mixed>
     */
    private static function contract(array $overrides = [], array $places = [201]): array
    {
        $rows = [];
        foreach ($places as $pos => $placeId) {
            $rows[] = ['place' => $placeId, 'order_pos' => $pos];
        }
        return $overrides + [
            'kind' => self::KIND_OFFICE, 'title' => 'Pronájem kanceláře', 'customer' => 7,
            'date_start' => '2027-01-01', 'date_end' => null, 'docState' => 10, 'places' => $rows,
        ];
    }

    /** @return list<string> */
    private static function errors(ValidationResult $result): array
    {
        return array_map(static fn ($e): string => $e->column . ':' . $e->code, $result->getErrors());
    }

    /** @return list<string> */
    private static function warnings(ValidationResult $result): array
    {
        return array_map(static fn ($e): string => $e->column . ':' . $e->message, $result->getWarnings());
    }

    // ── Koncept ─────────────────────────────────────────────────────────────

    public function testDraftNeedsOnlyTitleAndKindAndTakesTheTypeFromTheKind(): void
    {
        $data = ['kind' => self::KIND_OFFICE, 'title' => 'Rozpracovaná', 'type' => 'flat', 'docState' => 10];
        $result = $this->document()->validate($data);

        $this->assertSame([], self::errors($result));
        $this->assertSame('office', $data['type'], 'typ jde vždy z druhu, ne od klienta');
    }

    public function testTitleAndKindAreRequired(): void
    {
        $data = ['title' => ' ', 'docState' => 10];
        $this->assertSame(['title:required', 'kind:required'], self::errors($this->document()->validate($data)));
    }

    public function testMissingOrDeletedKindIsAnError(): void
    {
        $document = $this->document();
        $document->setDb($this->createMock(\Dibi\Connection::class));

        $data = self::contract(['kind' => 99]);
        $this->assertSame(['kind:not_found'], self::errors($document->validate($data)));

        $document->kinds[self::KIND_OFFICE]['docState'] = 90;
        $data = self::contract();
        $this->assertSame(['kind:invalid_state'], self::errors($document->validate($data)));
    }

    public function testEndBeforeStartIsAnError(): void
    {
        $data = self::contract(['date_start' => '2027-05-01', 'date_end' => '2027-04-30']);
        $this->assertSame(['date_end:invalid_range'], self::errors($this->document()->validate($data)));

        $data = self::contract(['date_start' => '2027-05-01', 'date_end' => '2027-05-01']);
        $this->assertSame([], self::errors($this->document()->validate($data)), 'jednodenní smlouva je v pořádku');
    }

    // ── Místa podle typu ────────────────────────────────────────────────────

    public function testPlaceOfATypeTheContractTypeDoesNotAllowIsAnErrorWithItsName(): void
    {
        $data = self::contract(places: [201, 40, 30]);
        $result = $this->document()->validate($data);

        $this->assertSame(['places.1.place:place_type'], self::errors($result));
        $this->assertSame(
            'Místo 2. NP (Podlaží) nejde dát do smlouvy typu Kancelář — povolené typy míst: Místnost, Část budovy.',
            $result->getErrors()[0]->message,
        );
    }

    public function testContractTypeWithAnyPlaceTypeTakesEverything(): void
    {
        $data = self::contract(['kind' => self::KIND_OTHER], [201, 40, 50]);
        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testDuplicateAndTrashedAndMissingPlacesAreErrors(): void
    {
        $document = $this->document();
        $document->setDb($this->createMock(\Dibi\Connection::class));

        $data = self::contract(places: [201, 201, 60, 999]);
        $result = $document->validate($data);

        $this->assertSame(
            ['places.1.place:duplicate', 'places.2.place:invalid_state', 'places.3.place:not_found'],
            self::errors($result),
        );
        $this->assertSame('Místo Kancelář 201 je ve smlouvě dvakrát.', $result->getErrors()[0]->message);
        $this->assertSame('Místo Stará kancelář je v koši.', $result->getErrors()[1]->message);
    }

    public function testContractTypeWithoutPlacesRejectsAnyPlace(): void
    {
        $document = new TestableContractDocument();
        $document->setConfig($this->buildingsConfig([
            \Shipard\Module\Facility\Buildings\ContractTypes::CFG_ITEM => [
                'service' => ['name' => 'Služba', 'places' => 'none'],
            ],
        ]));
        $document->kinds = [9 => ['type' => 'service', 'number_code' => 'SL', 'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 40]];
        $document->places = [201 => ['name' => 'Kancelář 201', 'kind_type' => 'room', 'docState' => 40]];

        $data = self::contract(['kind' => 9, 'docState' => 40]);
        $result = $document->validate($data);
        $this->assertSame(['places.0.place:not_allowed'], self::errors($result));
        $this->assertSame('Smlouva typu Služba místa nemá — odeber místo Kancelář 201.', $result->getErrors()[0]->message);

        $data = self::contract(['kind' => 9, 'docState' => 40], []);
        $this->assertSame([], self::errors($document->validate($data)), 'bez míst jde potvrdit');
    }

    // ── Potvrzení ───────────────────────────────────────────────────────────

    public function testConfirmationNeedsTenantStartAndPlacesOfARequiredType(): void
    {
        $data = self::contract(['customer' => null, 'date_start' => null, 'docState' => 40], []);
        $this->assertSame(
            ['customer:required', 'date_start:required', 'places:required'],
            self::errors($this->document()->validate($data)),
        );

        $data = self::contract(['customer' => null, 'date_start' => null], []);
        $this->assertSame([], self::errors($this->document()->validate($data)), 'koncept tohle mít nemusí');
    }

    public function testConfirmationOfATypeWithOptionalPlacesNeedsNoPlace(): void
    {
        $data = self::contract(['kind' => self::KIND_OTHER, 'docState' => 40], []);
        $this->assertSame([], self::errors($this->document()->validate($data)));
    }

    public function testStateOnlyTransitionChecksTheStoredPlaces(): void
    {
        // Uložení bez klíče `places` (vazby se nemění) — pravidla čtou uložené.
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'kind' => self::KIND_OFFICE, 'type' => 'office', 'docState' => 10];

        $data = self::contract(['id' => 5, 'docState' => 40]);
        unset($data['places']);
        $this->assertSame(['places:required'], self::errors($document->validate($data)));

        $document->placeRows[5] = [['id' => 1, 'place' => 201, 'order_pos' => 0]];
        $data = self::contract(['id' => 5, 'docState' => 40]);
        unset($data['places']);
        $this->assertSame([], self::errors($document->validate($data)));
        $this->assertArrayNotHasKey('places', $data, 'validace klíč do dat nepřidává — gateway by vazby přepsala');
    }

    // ── Druh jen v Konceptu ─────────────────────────────────────────────────

    public function testKindOfAConfirmedContractCannotChange(): void
    {
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'kind' => self::KIND_OFFICE, 'type' => 'office', 'docState' => 80];

        $data = self::contract(['id' => 5, 'kind' => self::KIND_OTHER, 'docState' => 80]);
        $this->assertSame(['kind:kindLocked'], self::errors($document->validate($data)));

        $data = self::contract(['id' => 5, 'docState' => 80, 'type' => 'other']);
        $this->assertSame([], self::errors($document->validate($data)));
        $this->assertSame('office', $data['type'], 'typ potvrzené smlouvy zůstává uložený');
    }

    public function testKindOfADraftMayChangeAndTheTypeFollows(): void
    {
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'kind' => self::KIND_OFFICE, 'type' => 'office', 'docState' => 10];

        $data = self::contract(['id' => 5, 'kind' => self::KIND_PARKING], [50]);
        $this->assertSame([], self::errors($document->validate($data)));
        $this->assertSame('parking', $data['type']);

        // Místa, která novému typu nevyhovují, nikdo nemaže — ohlásí je validace.
        $data = self::contract(['id' => 5, 'kind' => self::KIND_PARKING], [201]);
        $this->assertSame(['places.0.place:place_type'], self::errors($document->validate($data)));
    }

    // ── Překryv (D7, D22) ───────────────────────────────────────────────────

    /** @param list<int> $places */
    private static function other(int $id, string $number, array $places, ?string $start, ?string $end, int $state = 40): array
    {
        return ['id' => $id, 'number' => $number, 'places' => $places, 'date_start' => $start, 'date_end' => $end, 'docState' => $state];
    }

    public function testOverlapIsAWarningThatDoesNotBlockConfirmation(): void
    {
        $document = $this->document();
        $document->others = [self::other(11, 'KA-2027-0003', [201], '2027-01-01', null)];

        $data = self::contract(['date_start' => '2027-03-01', 'docState' => 40], [202, 201]);
        $result = $document->validate($data);

        $this->assertTrue($result->isValid(), 'překryv není chyba');
        $this->assertSame(
            ['places.1.place:Kancelář 201 je i ve smlouvě KA-2027-0003 (od 01.01.2027)'],
            self::warnings($result),
        );
        $this->assertSame('overlap', $result->getWarnings()[0]->code);
    }

    public function testOpenEndedContractsOverlapOnBothSides(): void
    {
        $document = $this->document();
        // Cizí smlouva bez konce začíná až po našem zahájení — my konec nemáme.
        $document->others = [self::other(11, 'KA-2030-0001', [201], '2030-01-01', null)];

        $data = self::contract(['date_start' => '2027-01-01', 'date_end' => null]);
        $this->assertCount(1, self::warnings($document->validate($data)));

        // S naším koncem před jejím zahájením se neprotnou.
        $data = self::contract(['date_start' => '2027-01-01', 'date_end' => '2029-12-31']);
        $this->assertSame([], self::warnings($document->validate($data)));

        // Hraniční den se počítá na obou stranách.
        $data = self::contract(['date_start' => '2027-01-01', 'date_end' => '2030-01-01']);
        $this->assertCount(1, self::warnings($document->validate($data)));
    }

    public function testEndedOtherContractOverlapsOnlyUntilItsEnd(): void
    {
        $document = $this->document();
        $document->others = [self::other(11, 'KA-2026-0002', [201], '2026-01-01', '2026-12-31')];

        $data = self::contract(['date_start' => '2027-01-01']);
        $this->assertSame([], self::warnings($document->validate($data)));

        $data = self::contract(['date_start' => '2026-12-31']);
        $this->assertSame(
            ['places.0.place:Kancelář 201 je i ve smlouvě KA-2026-0002 (01.01.2026 – 31.12.2026)'],
            self::warnings($document->validate($data)),
        );
    }

    public function testOnlyValidContractsCountAndTheContractSkipsItself(): void
    {
        $document = $this->document();
        $document->rows[5] = ['id' => 5, 'kind' => self::KIND_OFFICE, 'type' => 'office', 'docState' => 80];
        $document->others = [
            self::other(5, 'KA-2027-0001', [201], '2027-01-01', null, 80),       // tato smlouva
            self::other(11, 'KA-2027-0002', [201], '2027-01-01', null, 10),      // koncept
            self::other(12, 'KA-2027-0003', [201], '2027-01-01', '2027-06-30', 70), // ukončená
            self::other(13, 'KA-2027-0004', [201], '2027-01-01', null, 30),      // zrušená
            self::other(14, 'KA-2027-0005', [201], '2027-01-01', null, 90),      // smazaná
        ];

        $data = self::contract(['id' => 5, 'docState' => 80]);
        $this->assertSame([], self::warnings($document->validate($data)));
        $this->assertSame(5, $document->overlapCalls[0][3], 'dotaz vynechává smlouvu samu');

        $document->others[] = self::other(15, 'KA-2027-0006', [201], '2027-02-01', null, 80); // v opravě
        $data = self::contract(['id' => 5, 'docState' => 80]);
        $this->assertSame(
            ['places.0.place:Kancelář 201 je i ve smlouvě KA-2027-0006 (od 01.02.2027)'],
            self::warnings($document->validate($data)),
        );
    }

    public function testOneFindingPerPlaceEvenWithMoreOverlappingContracts(): void
    {
        $document = $this->document();
        $document->others = [
            self::other(12, 'KA-2027-0009', [201, 202], '2027-04-01', null),
            self::other(11, 'KA-2027-0003', [201], '2027-01-01', null),
        ];

        $data = self::contract(places: [201, 202]);
        $this->assertSame(
            [
                'places.0.place:Kancelář 201 je i ve smlouvě KA-2027-0003 (od 01.01.2027)',
                'places.1.place:Kancelář 202 je i ve smlouvě KA-2027-0009 (od 01.04.2027)',
            ],
            self::warnings($document->validate($data)),
        );
    }

    public function testNoOverlapCheckWithoutStartOrForAnEndedContract(): void
    {
        $document = $this->document();
        $document->others = [self::other(11, 'KA-2027-0003', [201], '2027-01-01', null)];

        $data = self::contract(['date_start' => null]);
        $this->assertSame([], self::warnings($document->validate($data)));

        $data = self::contract(['docState' => 70, 'date_end' => '2027-12-31']);
        $this->assertSame([], self::warnings($document->validate($data)));
        $this->assertSame([], $document->overlapCalls);
    }

    public function testPlaceThatFailedValidationIsNotCheckedForOverlap(): void
    {
        $document = $this->document();
        $document->others = [self::other(11, 'KA-2027-0003', [40], '2027-01-01', null)];

        $data = self::contract(places: [40]);
        $result = $document->validate($data);

        $this->assertSame(['places.0.place:place_type'], self::errors($result));
        $this->assertSame([], self::warnings($result));
    }

    // ── Číslo (D15, D18) ────────────────────────────────────────────────────

    public function testConfirmationAssignsTheNumberPerKindAndStartYear(): void
    {
        $document = $this->document();
        $document->nextSequences = [3];

        $data = self::contract(['id' => 5, 'date_start' => '2027-11-20', 'docState' => 40]);
        $document->beforeSave($data, ['id' => 5, 'docState' => 10]);

        $this->assertSame([[self::KIND_OFFICE, 2027]], $document->sequenceCalls);
        $this->assertSame('KA-2027-0003', $data['number']);
        $this->assertSame(3, $data['sequence_number']);
        $this->assertSame(2027, $data['number_year']);
        $this->assertSame(['old' => 10, 'new' => 40], $document->transition());
    }

    public function testYearOfTheNumberIsTheStartYearNotToday(): void
    {
        $document = $this->document();
        $document->today = '2026-12-10';

        $data = self::contract(['date_start' => '2028-01-01', 'docState' => 40]);
        $document->beforeSave($data, null);

        $this->assertSame([[self::KIND_OFFICE, 2028]], $document->sequenceCalls);
        $this->assertSame('KA-2028-0001', $data['number']);
        $this->assertSame(['old' => 0, 'new' => 40], $document->transition(), 'nový záznam rovnou V pořádku');
    }

    public function testContinuousKindCountsWithoutAYear(): void
    {
        $document = $this->document();
        $document->nextSequences = [42];

        $data = self::contract(['kind' => self::KIND_CONTINUOUS, 'docState' => 40], [201]);
        $document->beforeSave($data, ['docState' => 10]);

        $this->assertSame([[self::KIND_CONTINUOUS, null]], $document->sequenceCalls);
        $this->assertSame('SK00042', $data['number']);
        $this->assertNull($data['number_year']);
    }

    public function testEachKindHasItsOwnCounter(): void
    {
        $document = $this->document();
        $document->nextSequences = [1, 1];

        $office = self::contract(['docState' => 40]);
        $document->beforeSave($office, ['docState' => 10]);
        $parking = self::contract(['kind' => self::KIND_PARKING, 'docState' => 40], [50]);
        $document->beforeSave($parking, ['docState' => 10]);

        $this->assertSame([[self::KIND_OFFICE, 2027], [self::KIND_PARKING, 2027]], $document->sequenceCalls);
        $this->assertSame(['KA-2027-0001', 'PA-2027-0001'], [$office['number'], $parking['number']]);
    }

    public function testImportedNumberStaysAndTheCounterIsNotTouched(): void
    {
        $document = $this->document();

        $data = self::contract(['number' => ' N-2019/17 ', 'docState' => 40]);
        $document->beforeSave($data, ['docState' => 10]);

        $this->assertSame('N-2019/17', $data['number']);
        $this->assertSame([], $document->sequenceCalls);
    }

    public function testNumberIsAssignedOnlyOnConfirmationFromDraft(): void
    {
        $document = $this->document();

        $draft = self::contract();
        $document->beforeSave($draft, ['docState' => 10]);
        $this->assertNull($document->transition());
        $this->assertArrayNotHasKey('number', $draft);

        // Návrat z opravy číslo nemění.
        $edited = self::contract(['number' => 'KA-2027-0001', 'docState' => 40]);
        $document->beforeSave($edited, ['docState' => 80, 'number' => 'KA-2027-0001']);
        $this->assertSame('KA-2027-0001', $edited['number']);
        $this->assertSame([], $document->sequenceCalls);
    }

    public function testGeneratedNumberThatAlreadyExistsStopsTheConfirmation(): void
    {
        $document = $this->document();
        $document->numbers = ['KA-2027-0001' => 77];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Číslo KA-2027-0001 už má jiná smlouva');

        $data = self::contract(['docState' => 40]);
        $document->beforeSave($data, ['docState' => 10]);
    }

    public function testDuplicateNumberIsAValidationError(): void
    {
        $document = $this->document();
        $document->numbers = ['N-1' => 77];

        $data = self::contract(['number' => 'N-1']);
        $this->assertSame(['number:duplicate'], self::errors($document->validate($data)));
    }

    // ── Ukončení a mazání (D21) ─────────────────────────────────────────────

    public function testFinishingAndCancellingFillTheMissingEndDate(): void
    {
        foreach ([70, 30] as $endState) {
            $document = $this->document();
            $data = self::contract(['number' => 'KA-2027-0001', 'docState' => $endState]);
            $document->beforeSave($data, ['docState' => 40]);
            $this->assertSame('2027-06-15', $data['date_end'], (string) $endState);

            $data = self::contract(['number' => 'KA-2027-0001', 'docState' => $endState, 'date_end' => '2027-03-31']);
            $document->beforeSave($data, ['docState' => 40]);
            $this->assertSame('2027-03-31', $data['date_end'], 'vyplněné ukončení zůstává');
        }
    }

    public function testOnlyADraftCanBeDeleted(): void
    {
        $document = $this->document();

        $document->beforeDelete(['id' => 5, 'docState' => 10]);
        $document->beforeDelete(['id' => 5, 'docState' => 90]);
        $document->afterDelete(['id' => 5]);
        $this->assertSame([5], $document->deletedPlaceRows, 'se smlouvou odejdou i vazby míst');

        foreach ([40, 80, 70, 30] as $state) {
            try {
                $document->beforeDelete(['id' => 5, 'docState' => $state]);
                $this->fail("stav {$state} smazat nejde");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('Smazat jde jen koncept smlouvy', $e->getMessage());
            }
        }
    }

    public function testBeforeSaveTrimsTexts(): void
    {
        $document = $this->document();
        $data = self::contract(['title' => '  Pronájem  ', 'internal_note' => '  ']);
        $document->beforeSave($data, ['docState' => 10]);

        $this->assertSame('Pronájem', $data['title']);
        $this->assertNull($data['internal_note']);
    }
}
