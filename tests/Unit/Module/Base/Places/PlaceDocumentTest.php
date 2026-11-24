<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Places\PlaceDocument;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * Pravidla místa (#169 D3, D4, D7, D13, D15): povinná pole, cyklus, úrovně
 * oběma směry, kód mezi sourozenci, koš a archiv s podřízenými, adresa
 * podle typu a pořadí mezi sourozenci.
 */
class PlaceDocumentTest extends TestCase
{
    private const TYPES_FILE = __DIR__ . '/../../../../../modules/base/places/config/types.jsonc';

    // Druhy: id → typ (jeden na typ jako seed).
    private const KIND_SITE = 1;
    private const KIND_BUILDING = 2;
    private const KIND_FLOOR = 4;
    private const KIND_ROOM = 5;
    private const KIND_SPOT = 7;
    private const KIND_OTHER = 8;

    private const KINDS = [
        1 => ['id' => 1, 'type' => 'site', 'docState' => 40],
        2 => ['id' => 2, 'type' => 'building', 'docState' => 40],
        4 => ['id' => 4, 'type' => 'floor', 'docState' => 40],
        5 => ['id' => 5, 'type' => 'room', 'docState' => 40],
        7 => ['id' => 7, 'type' => 'spot', 'docState' => 40],
        8 => ['id' => 8, 'type' => 'other', 'docState' => 40],
        9 => ['id' => 9, 'type' => 'room', 'docState' => 70],
        10 => ['id' => 10, 'type' => 'room', 'docState' => 90],
    ];

    /**
     * Areál(1) › Budova A(2) › 1. NP(3) › Kancelář 101(4)
     *          › Budova B(5) › Sklad 101(6)
     *
     * @return array<int, array<string, mixed>>
     */
    private static function tree(): array
    {
        return [
            1 => self::place(1, 'Areál', self::KIND_SITE, null, 1),
            2 => self::place(2, 'Budova A', self::KIND_BUILDING, 1, 1, 'A'),
            3 => self::place(3, '1. NP', self::KIND_FLOOR, 2, 1, '1'),
            4 => self::place(4, 'Kancelář 101', self::KIND_ROOM, 3, 1, '101'),
            5 => self::place(5, 'Budova B', self::KIND_BUILDING, 1, 2, 'B'),
            6 => self::place(6, 'Sklad 101', self::KIND_ROOM, 5, 1, '101'),
        ];
    }

    /** @return array<string, mixed> */
    private static function place(int $id, string $name, int $kind, ?int $parent, int $order, ?string $code = null, int $state = 40): array
    {
        return [
            'id' => $id, 'name' => $name, 'kind' => $kind, 'parent' => $parent,
            'order_pos' => $order, 'code' => $code, 'docState' => $state, 'country' => null,
        ];
    }

    /** @param array<int, array<string, mixed>>|null $places */
    private function doc(?array $places = null, string $country = 'cz'): TestablePlaceDocument
    {
        $doc = new TestablePlaceDocument();
        $doc->places = $places ?? self::tree();
        $doc->kinds = self::KINDS;
        $doc->country = $country;

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [PlaceTypes::CFG_ITEM, JsoncParser::parseFile(self::TYPES_FILE)],
        ]);
        $doc->setConfig($config);
        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string> `sloupec:kód`
     */
    private function codes(PlaceDocument $doc, array $data): array
    {
        return array_map(
            static fn (array $e): string => $e['column'] . ':' . $e['code'],
            $doc->validate($data)->toArray(),
        );
    }

    /**
     * Úplný payload uloženého místa se změnami (jako formulář).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function edit(int $id, array $changes): array
    {
        return $changes + self::tree()[$id];
    }

    // --- povinná pole ---

    public function testNameAndKindAreRequired(): void
    {
        $this->assertSame(
            ['name:required', 'kind:required'],
            $this->codes($this->doc(), ['name' => '  ', 'kind' => null]),
        );
    }

    public function testKindMustExistAndNotBeInTrash(): void
    {
        $doc = $this->doc();

        $this->assertSame(['kind:not_found'], $this->codes($doc, ['name' => 'X', 'kind' => 99]));
        $this->assertSame(['kind:invalid_state'], $this->codes($doc, ['name' => 'X', 'kind' => 10]));
        // Druh v archivu si místo smí ponechat (D18).
        $this->assertSame([], $this->codes($doc, ['name' => 'X', 'kind' => 9]));
    }

    public function testStateOnlyPayloadValidatesAgainstTheStoredRow(): void
    {
        // Částečný payload (jen změna stavu) se nesmí tvářit jako místo bez
        // názvu a druhu.
        $this->assertSame([], $this->codes($this->doc(), ['id' => 4, 'docState' => 80]));
    }

    // --- nadřazené místo a cyklus ---

    public function testParentMustExistAndBeLive(): void
    {
        $places = self::tree();
        $places[5]['docState'] = 70;
        $places[2]['docState'] = 90;
        $doc = $this->doc($places);

        $this->assertSame(['parent:not_found'], $this->codes($doc, ['name' => 'X', 'kind' => self::KIND_ROOM, 'parent' => 99]));
        $this->assertSame(['parent:invalid_state'], $this->codes($doc, ['name' => 'X', 'kind' => self::KIND_ROOM, 'parent' => 5]));
        $this->assertSame(['parent:invalid_state'], $this->codes($doc, ['name' => 'X', 'kind' => self::KIND_ROOM, 'parent' => 2]));
    }

    public function testPlaceCannotBeItsOwnParentNorSitUnderItsDescendant(): void
    {
        $doc = $this->doc();

        $this->assertSame(['parent:cycle'], $this->codes($doc, self::edit(2, ['parent' => 2])));
        // Budova A pod vlastní kancelář (potomek jako rodič).
        $this->assertSame(['parent:cycle'], $this->codes($doc, self::edit(2, ['parent' => 4])));
    }

    public function testCycleIsCaughtEvenAmongOtherTypedPlaces(): void
    {
        // Ostatní vypíná úrovně, ne kontrolu cyklu: 1 › 2 › 3, přesun 1 pod 3.
        $doc = $this->doc([
            1 => self::place(1, 'A', self::KIND_OTHER, null, 1),
            2 => self::place(2, 'B', self::KIND_OTHER, 1, 1),
            3 => self::place(3, 'C', self::KIND_OTHER, 2, 1),
        ]);

        $this->assertSame(['parent:cycle'], $this->codes($doc, ['id' => 1, 'name' => 'A', 'kind' => self::KIND_OTHER, 'parent' => 3]));
    }

    // --- úrovně ---

    public function testLevelsTowardsTheParent(): void
    {
        $doc = $this->doc();
        $room = static fn (int $parent): array => ['name' => 'Nová místnost', 'kind' => self::KIND_ROOM, 'parent' => $parent];

        $this->assertSame([], $this->codes($doc, $room(3)), 'místnost pod podlažím');
        $this->assertSame([], $this->codes($doc, $room(2)), 'místnost rovnou pod budovou');
        $this->assertSame(['parent:level'], $this->codes($doc, $room(4)), 'místnost pod místností');
        $this->assertSame(
            ['parent:level'],
            $this->codes($doc, ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 3]),
            'budova pod podlažím',
        );
    }

    public function testLevelMessageNamesBothTypes(): void
    {
        $data = ['name' => 'X', 'kind' => self::KIND_ROOM, 'parent' => 4];
        $errors = $this->doc()->validate($data)->toArray();

        $this->assertStringContainsString('„Room“', $errors[0]['message']);
    }

    public function testKindChangeIsCheckedAgainstDirectChildren(): void
    {
        $doc = $this->doc();

        // Budova A má pod sebou podlaží — místností být nemůže (D15).
        $this->assertSame(['kind:level'], $this->codes($doc, self::edit(2, ['kind' => self::KIND_ROOM])));
        // Na areálu úroveň vůči podlaží sedí, vůči nadřazenému areálu ne.
        $this->assertSame(['parent:level'], $this->codes($doc, self::edit(2, ['kind' => self::KIND_SITE])));
        // Podlaží → místnost: pod ním je místnost.
        $this->assertSame(['kind:level'], $this->codes($doc, self::edit(3, ['kind' => self::KIND_ROOM])));
        // Kancelář → místo: nic pod ní není.
        $this->assertSame([], $this->codes($doc, self::edit(4, ['kind' => self::KIND_SPOT])));
    }

    public function testTrashedChildDoesNotBlockTheKindChange(): void
    {
        $places = self::tree();
        $places[4]['docState'] = 90;

        $this->assertSame([], $this->codes($this->doc($places), self::edit(3, ['kind' => self::KIND_ROOM])));
    }

    public function testOtherTypeFitsAnywhere(): void
    {
        $doc = $this->doc();

        $this->assertSame([], $this->codes($doc, ['name' => 'Kout', 'kind' => self::KIND_OTHER, 'parent' => 4]));
        $this->assertSame([], $this->codes($doc, ['name' => 'Nad vším', 'kind' => self::KIND_OTHER, 'parent' => null]));
        // Budova s podlažím se smí stát Ostatním, i pod areálem.
        $this->assertSame([], $this->codes($doc, self::edit(2, ['kind' => self::KIND_OTHER])));
    }

    // --- kód mezi sourozenci ---

    public function testCodeMustBeUniqueAmongSiblingsOnly(): void
    {
        $doc = $this->doc();
        $room = static fn (int $parent, string $code): array
            => ['name' => 'Nová', 'kind' => self::KIND_ROOM, 'parent' => $parent, 'code' => $code];

        $this->assertSame(['code:code_duplicate'], $this->codes($doc, $room(3, '101')), 'stejný kód na témže podlaží');
        $this->assertSame([], $this->codes($doc, $room(3, '102')));
        // Kód 101 je v obou budovách — jiný rodič, žádná kolize.
        $this->assertSame([], $this->codes($doc, $room(2, '101')));
        // Uložení beze změny nekoliduje samo se sebou.
        $this->assertSame([], $this->codes($doc, self::edit(4, [])));
    }

    public function testTopLevelIsOneSiblingGroup(): void
    {
        $places = self::tree();
        $places[1]['code'] = 'HQ';
        $doc = $this->doc($places);

        $this->assertSame(
            ['code:code_duplicate'],
            $this->codes($doc, ['name' => 'Druhý areál', 'kind' => self::KIND_SITE, 'parent' => null, 'code' => 'HQ']),
        );
        // Stejný kód o úroveň níž nevadí.
        $this->assertSame(
            [],
            $this->codes($doc, ['name' => 'Budova HQ', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'code' => 'HQ']),
        );
    }

    public function testCodeComparisonIgnoresCaseAndTrashedSiblings(): void
    {
        $doc = $this->doc();
        $building = static fn (string $code): array
            => ['name' => 'Budova', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'code' => $code];

        $this->assertSame(['code:code_duplicate'], $this->codes($doc, $building('a')));
        $this->assertSame(['code:code_duplicate'], $this->codes($doc, $building(' A ')));

        $places = self::tree();
        $places[2]['docState'] = 90;
        $this->assertSame([], $this->codes($this->doc($places), $building('A')), 'sourozenec v koši se nepočítá');
    }

    // --- koš a archiv (D7) ---

    public function testArchiveAndTrashWithChildren(): void
    {
        $doc = $this->doc();

        $this->assertSame(['_form:has_children'], $this->codes($doc, self::edit(3, ['docState' => 70])), 'archiv s aktivním podřízeným');
        $this->assertSame(['_form:has_children'], $this->codes($doc, self::edit(3, ['docState' => 90])), 'koš s aktivním podřízeným');
        $this->assertSame([], $this->codes($doc, self::edit(4, ['docState' => 70])), 'list stromu do archivu');
        $this->assertSame([], $this->codes($doc, self::edit(4, ['docState' => 90])), 'list stromu do koše');

        $places = self::tree();
        $places[4]['docState'] = 70;
        $doc = $this->doc($places);
        $this->assertSame([], $this->codes($doc, self::edit(3, ['docState' => 70])), 'archiv s archivovaným podřízeným');
        $this->assertSame(['_form:has_children'], $this->codes($doc, self::edit(3, ['docState' => 90])), 'koš s archivovaným podřízeným');

        $places[4]['docState'] = 90;
        $this->assertSame([], $this->codes($this->doc($places), self::edit(3, ['docState' => 90])), 'koš s podřízeným v koši');
    }

    public function testHasChildrenMessage(): void
    {
        $data = self::edit(3, ['docState' => 90]);
        $errors = $this->doc()->validate($data)->toArray();

        $this->assertSame('Místo má podřízená místa — nejdřív je přesuňte nebo vyřaďte.', $errors[0]['message']);
    }

    public function testEditingAPlaceWithChildrenWithoutStateChangeIsAllowed(): void
    {
        $this->assertSame([], $this->codes($this->doc(), self::edit(3, ['name' => 'První nadzemní'])));
    }

    // --- adresa (D13) ---

    public function testAddressIsWipedForTypeWithoutAddressEvenFromPayload(): void
    {
        $doc = $this->doc();
        $data = [
            'name' => '2. NP', 'kind' => self::KIND_FLOOR, 'parent' => 2,
            'street' => 'Dlouhá', 'house_number' => '12', 'city' => 'Praha', 'zip' => '110 00',
            'country' => 'cz', 'latitude' => '50.1', 'longitude' => '14.4',
        ];

        $doc->beforeSave($data, null);

        foreach (PlaceDocument::ADDRESS_COLUMNS as $column) {
            $this->assertArrayHasKey($column, $data);
            $this->assertNull($data[$column], $column);
        }
    }

    public function testChangingBuildingToFloorWipesTheStoredAddress(): void
    {
        $doc = $this->doc();
        $original = self::tree()[2] + ['street' => 'Dlouhá', 'zip' => '11000'];
        $data = ['id' => 2, 'kind' => self::KIND_FLOOR];

        $doc->beforeSave($data, $original);

        $this->assertNull($data['street']);
        $this->assertNull($data['zip']);
    }

    public function testBuildingAddressIsNormalizedByDataSourceCountry(): void
    {
        $data = ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'street' => 'Dlouhá', 'zip' => '110 00'];
        $this->doc(country: 'cz')->beforeSave($data, null);

        $this->assertSame('11000', $data['zip']);
        $this->assertSame('Dlouhá', $data['street']);
        // Země zdroje dat slouží jen pravidlům PSČ, do sloupce se nezapisuje.
        $this->assertArrayNotHasKey('country', $data);

        $data = ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'zip' => '110 00', 'country' => 'CZ'];
        $this->doc(country: 'us')->beforeSave($data, null);
        $this->assertSame('cz', $data['country']);
        $this->assertSame('11000', $data['zip']);
    }

    public function testInvalidZipIsAnErrorOnlyForTypeWithAddress(): void
    {
        $doc = $this->doc();

        $this->assertSame(
            ['zip:zip_invalid'],
            $this->codes($doc, ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'zip' => '1234']),
        );
        $data = ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'zip' => '1234'];
        $this->assertSame([], $doc->validate($data)->warningsToArray(), 'vlastní údaj = chyba, ne varování');
        // U podlaží se adresa stejně smaže — nehlídá se.
        $this->assertSame(
            [],
            $this->codes($doc, ['name' => '2. NP', 'kind' => self::KIND_FLOOR, 'parent' => 2, 'zip' => '1234']),
        );
    }

    // --- pořadí ---

    public function testNewPlaceGoesToTheEndOfItsSiblings(): void
    {
        $doc = $this->doc();

        // Formulář posílá výchozí hodnotu sloupce 0.
        $data = ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'order_pos' => 0];
        $doc->beforeSave($data, null);
        $this->assertSame(3, $data['order_pos']);

        $data = ['name' => 'Druhý areál', 'kind' => self::KIND_SITE, 'parent' => null];
        $doc->beforeSave($data, null);
        $this->assertSame(2, $data['order_pos']);
        $this->assertNull($data['parent']);

        $data = ['name' => 'První pod kanceláří', 'kind' => self::KIND_SPOT, 'parent' => 4];
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['order_pos']);
    }

    public function testMovedPlaceGoesToTheEndOfNewSiblings(): void
    {
        $doc = $this->doc();
        $original = self::tree()[4];

        // Kancelář 101 z 1. NP rovnou pod Budovu A (ta má jedno dítě s pořadím 1).
        $data = self::edit(4, ['parent' => 2]);
        $doc->beforeSave($data, $original);
        $this->assertSame(2, $data['order_pos']);

        // Na nejvyšší úroveň vedle Areálu.
        $data = self::edit(4, ['parent' => 0]);
        $doc->beforeSave($data, $original);
        $this->assertNull($data['parent']);
        $this->assertSame(2, $data['order_pos']);
    }

    public function testManualOrderIsNeverOverwritten(): void
    {
        $doc = $this->doc();
        $original = self::tree()[4];

        $data = ['name' => 'Budova C', 'kind' => self::KIND_BUILDING, 'parent' => 1, 'order_pos' => 7];
        $doc->beforeSave($data, null);
        $this->assertSame(7, $data['order_pos'], 'nový záznam s pořadím');

        $data = self::edit(4, ['parent' => 2, 'order_pos' => 9]);
        $doc->beforeSave($data, $original);
        $this->assertSame(9, $data['order_pos'], 'přesun s ručně změněným pořadím');

        $data = self::edit(4, ['order_pos' => 5]);
        $doc->beforeSave($data, $original);
        $this->assertSame(5, $data['order_pos'], 'změna pořadí bez přesunu');

        $data = self::edit(4, ['name' => 'Kancelář 101a']);
        $doc->beforeSave($data, $original);
        $this->assertSame(1, $data['order_pos'], 'úprava bez přesunu pořadí nemění');
    }

    public function testTextColumnsAreTrimmed(): void
    {
        $data = ['name' => '  Budova C ', 'short_name' => '  ', 'code' => ' C ', 'note' => '', 'kind' => self::KIND_BUILDING, 'parent' => 1];
        $this->doc()->beforeSave($data, null);

        $this->assertSame('Budova C', $data['name']);
        $this->assertNull($data['short_name']);
        $this->assertSame('C', $data['code']);
        $this->assertNull($data['note']);
    }
}

/**
 * PlaceDocument nad poli místo databáze: podřízená místa, kódy sourozenců
 * a pořadí se odvozují z `$places` stejně, jako je čtou dotazy dokumentu.
 */
class TestablePlaceDocument extends PlaceDocument
{
    /** @var array<int, array<string, mixed>> */
    public array $places = [];

    /** @var array<int, array<string, mixed>> */
    public array $kinds = [];

    public string $country = 'cz';

    protected function loadPlaceRow(int $id): ?array
    {
        $row = $this->places[$id] ?? null;
        if ($row === null) {
            return null;
        }
        return $row + ['kind_type' => $this->kinds[(int) $row['kind']]['type'] ?? null];
    }

    protected function loadKindRow(int $kindId): ?array
    {
        return $this->kinds[$kindId] ?? null;
    }

    protected function loadChildren(int $id): array
    {
        $out = [];
        foreach ($this->places as $row) {
            if ((int) ($row['parent'] ?? 0) === $id && (int) $row['docState'] !== self::STATE_DELETED) {
                $out[] = $this->loadPlaceRow((int) $row['id']);
            }
        }
        return $out;
    }

    protected function loadSiblingCodes(?int $parentId, ?int $excludeId): array
    {
        return array_values(array_filter(array_map(
            static fn (array $row): ?string => $row['code'],
            $this->siblings($parentId, $excludeId),
        ), static fn (?string $code): bool => $code !== null));
    }

    protected function maxSiblingOrder(?int $parentId, ?int $excludeId): int
    {
        $orders = array_map(static fn (array $row): int => (int) $row['order_pos'], $this->siblings($parentId, $excludeId));
        return $orders === [] ? 0 : max($orders);
    }

    protected function dataSourceCountry(): string
    {
        return $this->country;
    }

    /** @return list<array<string, mixed>> */
    private function siblings(?int $parentId, ?int $excludeId): array
    {
        return array_values(array_filter(
            $this->places,
            static fn (array $row): bool => ($row['parent'] ?? null) === $parentId
                && (int) $row['id'] !== (int) $excludeId
                && (int) $row['docState'] !== self::STATE_DELETED,
        ));
    }
}
