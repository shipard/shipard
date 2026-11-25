<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Places;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Module\Base\Places\PlaceKindDocument;
use Shipard\Module\Base\Places\PlaceKindsViewer;
use Shipard\Module\Base\Places\PlacesViewer;
use Shipard\Module\Base\Places\PlaceTree;
use Shipard\Module\Base\Places\PlaceTypes;

/**
 * Viewery Nastavení → Místa (#169 D11, D14, D16): řádek s cestou a ikonou
 * podle druhu, hledání ve třech sloupcích, detail s hlavičkou cesta · druh,
 * adresou podle typu a tabulkou podřízených míst; sekce v Nastavení.
 */
class PlacesViewersTest extends TestCase
{
    private const STATES = [
        '10' => ['stateName' => 'Koncept', 'stateStyle' => 'concept', 'mainState' => 1, 'viewGroup' => 'active', 'goto' => [40]],
        '40' => ['stateName' => 'V pořádku', 'stateStyle' => 'done', 'mainState' => 3, 'viewGroup' => 'active', 'goto' => [70], 'detailBadge' => false],
        '70' => ['stateName' => 'V archívu', 'stateStyle' => 'archive', 'mainState' => 4, 'viewGroup' => 'archive', 'goto' => []],
    ];

    /**
     * Živé druhy v pořadí podle názvu (tak je vrací dotaz): seed má
     * `system_code` = typ, vlastní druhy ho nemají.
     */
    private const KINDS = [
        ['id' => 11, 'type' => 'site', 'system_code' => 'site'],
        ['id' => 12, 'type' => 'building', 'system_code' => 'building'],
        ['id' => 21, 'type' => 'room', 'system_code' => null],      // Kancelář
        ['id' => 15, 'type' => 'room', 'system_code' => 'room'],    // Místnost
        ['id' => 14, 'type' => 'floor', 'system_code' => 'floor'],
        ['id' => 22, 'type' => 'spot', 'system_code' => null],      // Pracovní místo
        ['id' => 23, 'type' => 'spot', 'system_code' => null],      // Stání
        ['id' => 18, 'type' => 'other', 'system_code' => 'other'],
    ];

    private const MODULES = __DIR__ . '/../../../../../modules';
    private const MODULE = self::MODULES . '/base/places';

    /**
     * Areál(1) › Budova A(2) › 1. NP(3), Sklep(4, archiv)
     *
     * @return list<array<string, mixed>>
     */
    private static function treeRows(): array
    {
        $row = static fn (int $id, ?int $parent, string $name, ?string $short, ?string $code, int $order, string $kind, string $type, int $state = 40): array => [
            'id' => $id, 'parent' => $parent, 'name' => $name, 'short_name' => $short, 'code' => $code,
            'order_pos' => $order, 'docState' => $state, 'kind' => 1, 'kind_name' => $kind, 'kind_type' => $type, 'kind_icon' => null,
        ];
        return [
            $row(1, null, 'Areál firmy', 'Areál', null, 1, 'Areál', 'site'),
            $row(2, 1, 'Budova A', 'A', 'A', 1, 'Budova', 'building'),
            $row(3, 2, '1. NP', null, '1', 2, 'Podlaží', 'floor'),
            $row(4, 2, 'Sklep', null, '0', 1, 'Podlaží', 'floor', 70),
        ];
    }

    /**
     * Deklarace vieweru z module.jsonc a reálná definice jeho tabulky.
     * Definice i katalog textů jsou tu nelokalizované — popisky jsou proto
     * anglické (holé `name`), stejně jako fallbacky v PHP.
     */
    private function declare(TableViewer $viewer, string $viewerId, string $table): void
    {
        $registry = new ViewerRegistry();
        $registry->loadFromModules([ModuleLoader::loadModule(self::MODULE)], 'cs');
        $viewer->setDefinition($registry->get($viewerId) ?? throw new \RuntimeException($viewerId));
        $viewer->setTables([$table => TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/' . $table . '.jsonc'))]);
    }

    private function config(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            [PlaceTypes::CFG_ITEM, JsoncParser::parseFile(self::MODULE . '/config/types.jsonc')],
            [PlaceKindDocument::ICONS_CFG_ITEM, JsoncParser::parseFile(self::MODULE . '/config/icons.jsonc')],
            ['base.places.viewerLabels', JsoncParser::parseFile(self::MODULE . '/config/viewerLabels.jsonc')],
            ['core.system.docStatesArchive', self::STATES],
            ['world.base.countries', ['cz' => ['name' => 'Czechia']]],
            ['core.system.viewerDetailLabels', null],
        ]);
        return $config;
    }

    /** @param list<mixed>|null $captured */
    private function db(?array &$captured = null, ?array $row = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$captured): array {
            $captured = $args;
            return [];
        });
        $db->method('fetchRow')->willReturn($row);
        return $db;
    }

    /** @param array<string, mixed>|null $record */
    private function placesViewer(?array &$captured = null, ?array $record = null): PlacesViewer
    {
        $viewer = new class($this->db($captured, $record), 'base_places_places') extends PlacesViewer {
            /** @var list<array<string, mixed>> */
            public array $treeRows = [];

            /** @var list<array<string, mixed>> živé druhy podle názvu */
            public array $kinds = [];

            public int $kindLoads = 0;

            protected function loadLiveKinds(): array
            {
                $this->kindLoads++;
                return $this->kinds;
            }

            protected function tree(): PlaceTree
            {
                $rows = $this->treeRows;
                return new class($rows) extends PlaceTree {
                    /** @param list<array<string, mixed>> $rows */
                    public function __construct(private readonly array $rows)
                    {
                        parent::__construct(null);
                    }

                    protected function loadAll(): array
                    {
                        return $this->rows;
                    }
                };
            }
        };
        $viewer->treeRows = self::treeRows();
        $viewer->kinds = self::KINDS;
        $viewer->setConfig($this->config());
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getCountry')->willReturn('cz');
        $viewer->setDsConfig($dsConfig);
        $this->declare($viewer, 'base.places.places', 'base_places_places');
        return $viewer;
    }

    // --- seznam míst ---

    public function testPlacesViewerSearchesNameShortNameAndCodeAndSortsByName(): void
    {
        $viewer = $this->placesViewer($captured);

        $viewer->selectRows('101', [['id' => 'viewGroup', 'value' => 'archive']], 1);

        $sql = $captured[0];
        foreach (['p.`name`', 'p.`short_name`', 'p.`code`'] as $column) {
            $this->assertStringContainsString($column . ' LIKE', $sql);
        }
        $this->assertStringContainsString('p.`docState` IN (%i)', $sql);
        $this->assertStringContainsString('LEFT JOIN `base_places_kinds` k', $sql);
        $this->assertStringContainsString('ORDER BY p.`docStateMain` ASC, p.`name` ASC, p.`id` ASC', $sql);
        $this->assertSame([70, '101', '101', '101'], array_slice($captured, 1));
    }

    public function testPlaceRowShowsPathKindCodeAndKindIcon(): void
    {
        $viewer = $this->placesViewer();

        $row = $viewer->renderRow(['id' => 3, 'name' => '1. NP', 'code' => '1', 'docState' => 40, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null]);
        $this->assertSame('1. NP', $row['t1']);
        $this->assertSame('1', $row['i1']);
        $this->assertSame([['text' => 'Areál › A · Podlaží']], $row['t2']);
        $this->assertSame('place-floor', $row['icon'], 'bez ikony druhu ikona typu');
        $this->assertSame('done', $row['stateStyle']);

        $top = $viewer->renderRow(['id' => 1, 'name' => 'Areál firmy', 'code' => null, 'docState' => 40, 'kind_name' => 'Areál', 'kind_type' => 'site', 'kind_icon' => 'place-office']);
        $this->assertNull($top['i1']);
        $this->assertSame([['text' => 'Areál']], $top['t2'], 'místo nejvyšší úrovně jen druh');
        $this->assertSame('place-office', $top['icon'], 'ikona druhu má přednost');

        $archived = $viewer->renderRow(['id' => 4, 'name' => 'Sklep', 'code' => '0', 'docState' => 70, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null]);
        $this->assertSame([['text' => 'Areál › A · Podlaží'], ['text' => 'V archívu', 'class' => 'muted']], $archived['t2']);
    }

    // --- strom míst (docs/viewer-tree.md) ---

    /** @return array<string, mixed> raw řádek stromu */
    private static function raw(int $id, ?int $parent, string $name, string $type, int $state = 40, ?string $code = null, int $order = 0): array
    {
        return [
            'id' => $id, 'parent' => $parent, 'name' => $name, 'short_name' => null, 'code' => $code, 'order_pos' => $order,
            'kind' => 1, 'docState' => $state, 'kind_name' => ucfirst($type), 'kind_type' => $type, 'kind_icon' => null,
        ];
    }

    public function testTreeIsTheDefaultLayoutWithNameCodeAndKindColumns(): void
    {
        $viewer = $this->placesViewer();
        $options = $viewer->getTreeOptions();

        $this->assertSame('tree', $viewer->getDefaultLayout());
        $this->assertSame('parent', $options['parentColumn']);
        $this->assertSame(['name', 'code', 'kind'], array_column($options['columns'], 'id'));
        $this->assertSame(['Name', 'Code', 'Kind'], array_column($options['columns'], 'label'));
        $this->assertTrue($options['columns'][0]['grow'], 'stromový sloupec bere zbylou šířku');
    }

    public function testTreeSelectSharesTheListFilterWithoutPagingOrSorting(): void
    {
        $viewer = $this->placesViewer($captured);

        $viewer->selectTreeRows('101', [['id' => 'viewGroup', 'value' => 'archive']]);

        $sql = $captured[0];
        foreach (['p.`name`', 'p.`short_name`', 'p.`code`'] as $column) {
            $this->assertStringContainsString($column . ' LIKE', $sql);
        }
        $this->assertStringContainsString('p.`docState` IN (%i)', $sql);
        foreach (['p.`parent`', 'p.`order_pos`', 'p.`kind`', 'k.`type` AS `kind_type`'] as $column) {
            $this->assertStringContainsString($column, $sql);
        }
        $this->assertStringNotContainsString('ORDER BY', $sql, 'sourozence řadí komparátor, ne dotaz');
        $this->assertStringEndsWith(' LIMIT ' . (TableViewer::TREE_MAX_NODES + 1), $sql, 'řádek nad strop = detekce D25');
        $this->assertSame([70, '101', '101', '101'], array_slice($captured, 1));
    }

    public function testTreeAncestorsAreSelectedByIdRegardlessOfFilters(): void
    {
        $viewer = $this->placesViewer($captured);

        $viewer->selectTreeRowsByIds([20, '10', 20]);

        $this->assertStringEndsWith(' WHERE p.`id` IN %in', $captured[0]);
        $this->assertStringNotContainsString('docState` IN', $captured[0], 'předek je kontext v jakémkoli stavu');
        $this->assertSame([[20, 10]], array_slice($captured, 1));

        $captured = null;
        $this->assertSame([], $viewer->selectTreeRowsByIds([]));
        $this->assertNull($captured, 'bez id žádný dotaz');
    }

    public function testTreeSiblingsUseTheOrderOfPlaceTree(): void
    {
        $viewer = $this->placesViewer();
        $rows = [
            self::raw(1, null, 'Sklad', 'room', code: '10', order: 5),
            self::raw(2, null, 'Archiv', 'room', code: '9', order: 5),
            self::raw(3, null, 'Dílna', 'room', order: 1),
        ];
        usort($rows, $viewer->compareTreeRows(...));

        $this->assertSame([3, 2, 1], array_column($rows, 'id'));
    }

    public function testTreeRowHasCellsStateTagKindIconAndAddChildAction(): void
    {
        $viewer = $this->placesViewer();

        $row = $viewer->renderTreeRow(self::raw(3, 2, '1. NP', 'floor', code: '1'));
        $this->assertSame(3, $row['id']);
        $this->assertSame(
            ['name' => [['text' => '1. NP']], 'code' => '1', 'kind' => 'Floor'],
            $row['cells'],
        );
        $this->assertSame('place-floor', $row['icon']);
        $this->assertSame('done', $row['stateStyle']);
        $this->assertSame('addChild', $row['rowAction']['id']);
        $this->assertSame('Add child place', $row['rowAction']['label']);
        $this->assertSame('add', $row['rowAction']['icon']);
        $this->assertSame('open_form', $row['rowAction']['kind']);
        $this->assertSame('base_places_places', $row['rowAction']['target']['table']);
        $this->assertSame(3, $row['rowAction']['target']['preset']['parent']);

        // Archivované místo: štítek stavu u názvu, prázdný kód, žádné „+“.
        $archived = $viewer->renderTreeRow(self::raw(4, 2, 'Sklep', 'floor', 70));
        $this->assertSame([['text' => 'Sklep'], ['text' => 'V archívu', 'class' => 'muted']], $archived['cells']['name']);
        $this->assertNull($archived['cells']['code']);
        $this->assertSame('archive', $archived['stateStyle']);
        $this->assertArrayNotHasKey('rowAction', $archived);
    }

    // --- Přidat podřízené: navržený druh (D23) ---

    /**
     * Předvyplnění akce Přidat podřízené pro místo `$id` typu `$type`.
     *
     * @param list<array<string, mixed>> $treeRows
     * @param list<array<string, mixed>>|null $kinds
     * @return array<string, mixed>|null
     */
    private function presetOf(int $id, string $type, array $treeRows, ?array $kinds = null): ?array
    {
        $viewer = $this->placesViewer();
        $viewer->treeRows = $treeRows;
        if ($kinds !== null) {
            $viewer->kinds = $kinds;
        }
        return $viewer->renderTreeRow(self::raw($id, null, 'x', $type))['rowAction']['target']['preset'] ?? null;
    }

    /** @return array<string, mixed> uzel stromu s druhem */
    private static function node(int $id, ?int $parent, int $kind, int $order, int $state = 40): array
    {
        return [
            'id' => $id, 'parent' => $parent, 'name' => 'n' . $id, 'short_name' => null, 'code' => null,
            'order_pos' => $order, 'docState' => $state, 'kind' => $kind, 'kind_name' => 'k', 'kind_type' => 'room', 'kind_icon' => null,
        ];
    }

    public function testChildPresetTakesTheKindOfTheLastChild(): void
    {
        // Podlaží 3 má kanceláře; poslední podle pořadí (ne podle id) je druh 21.
        $tree = [self::node(3, null, 14, 1), self::node(31, 3, 21, 9), self::node(32, 3, 15, 2)];

        $this->assertSame(['parent' => 3, 'kind' => 21], $this->presetOf(3, 'floor', $tree));
    }

    public function testChildPresetCountsArchivedChildButNeedsALiveKind(): void
    {
        // Poslední podřízené je v archivu — pořád platí (mimo koš je).
        $tree = [self::node(3, null, 14, 1), self::node(31, 3, 21, 1), self::node(32, 3, 15, 2, 70)];
        $this->assertSame(['parent' => 3, 'kind' => 15], $this->presetOf(3, 'floor', $tree));

        // Druh posledního podřízeného už živý není → výchozí podřízený typ.
        $tree = [self::node(3, null, 14, 1), self::node(31, 3, 99, 1)];
        $this->assertSame(['parent' => 3, 'kind' => 15], $this->presetOf(3, 'floor', $tree));
    }

    public function testChildPresetWithoutChildrenUsesTheSeedKindOfTheDefaultChildType(): void
    {
        // Podlaží → místnost: seedový druh (15), i když je Kancelář (21) dřív podle názvu.
        $this->assertSame(['parent' => 3, 'kind' => 15], $this->presetOf(3, 'floor', [self::node(3, null, 14, 1)]));
        // Areál → budova.
        $this->assertSame(['parent' => 1, 'kind' => 12], $this->presetOf(1, 'site', [self::node(1, null, 11, 1)]));
    }

    public function testChildPresetWithoutSeedKindUsesTheFirstLiveKindOfTheType(): void
    {
        // Místnost → místo: seed typu Místo je vyřazený, zbývají dva vlastní druhy.
        $this->assertSame(['parent' => 5, 'kind' => 22], $this->presetOf(5, 'room', [self::node(5, null, 15, 1)]));
    }

    public function testChildPresetWithoutSuitableKindFillsOnlyTheParent(): void
    {
        $kinds = [['id' => 15, 'type' => 'room', 'system_code' => 'room']];

        $this->assertSame(['parent' => 5], $this->presetOf(5, 'room', [self::node(5, null, 15, 1)], $kinds));
    }

    public function testSpotHasNoAddChildActionAndOtherAsParentHas(): void
    {
        $viewer = $this->placesViewer();
        $viewer->treeRows = [self::node(7, null, 22, 1), self::node(8, null, 18, 1)];

        $this->assertArrayNotHasKey('rowAction', $viewer->renderTreeRow(self::raw(7, null, 'Stání 1', 'spot')));

        // Ostatní pravidlo úrovní nemá: akce je, druh se nenavrhuje.
        $other = $viewer->renderTreeRow(self::raw(8, null, 'Sklad', 'other'));
        $this->assertSame(['parent' => 8], $other['rowAction']['target']['preset']);
    }

    public function testKindsAreLoadedOncePerRequest(): void
    {
        $viewer = $this->placesViewer();
        foreach ([1, 2, 3] as $id) {
            $viewer->renderTreeRow(self::raw($id, null, 'x', 'floor'));
        }

        $this->assertSame(1, $viewer->kindLoads);
    }

    public function testDetailOffersTheSameAddChildAction(): void
    {
        $record = [
            'id' => 3, 'name' => '1. NP', 'short_name' => null, 'code' => '1', 'order_pos' => 2, 'note' => null,
            'kind' => 4, 'parent' => 2, 'docState' => 40, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null,
        ];
        $viewer = $this->placesViewer(record: $record);
        $detail = $viewer->renderDetail(3);

        $this->assertSame(['addChild'], array_column($detail['actions'], 'id'));
        $this->assertSame(
            $viewer->renderTreeRow(self::raw(3, 2, '1. NP', 'floor'))['rowAction'],
            $detail['actions'][0],
            'řádek stromu a detail nesou tutéž akci',
        );

        // Místo nejnižší úrovně a archivované místo akci nemají — klíč chybí.
        $spot = $this->placesViewer(record: ['kind_type' => 'spot'] + $record)->renderDetail(3);
        $this->assertArrayNotHasKey('actions', $spot);
        $archived = $this->placesViewer(record: ['docState' => 70] + $record)->renderDetail(3);
        $this->assertArrayNotHasKey('actions', $archived);
    }

    // --- detail místa ---

    public function testPlaceDetailHeaderIsNamePathKindAndKindIcon(): void
    {
        $record = [
            'id' => 3, 'name' => '1. NP', 'short_name' => null, 'code' => '1', 'order_pos' => 2, 'note' => null,
            'kind' => 4, 'parent' => 2, 'docState' => 40, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null,
        ];
        $detail = $this->placesViewer(record: $record)->renderDetail(3);

        $this->assertSame('1. NP', $detail['title']);
        $this->assertSame('Areál › A · Podlaží', $detail['subtitle']);
        $this->assertSame('place-floor', $detail['icon']);
        $this->assertSame([], $detail['badges']);
        $this->assertSame(['overview'], array_column($detail['tabs'], 'id'));

        // List stromu: jen vlastnosti; druh ani cesta se neopakují (#140 D26).
        $content = $detail['tabs'][0]['content'];
        $this->assertSame('properties', $content['type']);
        $this->assertSame(
            [['label' => 'Code', 'value' => '1'], ['label' => 'Order', 'value' => '2']],
            $content['groups'][0]['items'],
        );
        $this->assertCount(1, $content['groups'], 'podlaží nemá adresu ani poznámku');
    }

    public function testTopLevelPlaceHasOnlyKindInSubtitleAndShortNameWhenItDiffers(): void
    {
        $record = [
            'id' => 1, 'name' => 'Areál firmy', 'short_name' => 'Areál', 'code' => null, 'order_pos' => 1, 'note' => 'Hlavní sídlo',
            'kind' => 1, 'parent' => null, 'docState' => 40, 'kind_name' => 'Areál', 'kind_type' => 'site', 'kind_icon' => null,
            'street' => 'Dlouhá', 'house_number' => '12', 'orientation_number' => '3', 'city' => 'Praha', 'zip' => '11000',
            'country' => 'cz', 'latitude' => '50.087500', 'longitude' => '14.421300',
        ];
        $detail = $this->placesViewer(record: $record)->renderDetail(1);

        $this->assertSame('Areál', $detail['subtitle']);

        $content = $detail['tabs'][0]['content'];
        $this->assertSame('composite', $content['type'], 'areál má podřízená místa');
        $groups = $content['blocks'][0]['groups'];
        $this->assertSame(['Identity', 'Address', 'Note'], array_column($groups, 'title'));
        $this->assertSame(
            [['label' => 'Short name', 'value' => 'Areál'], ['label' => 'Order', 'value' => '1']],
            $groups[0]['items'],
        );
        $this->assertSame(
            [
                ['label' => 'Address', 'value' => 'Dlouhá 12/3, 110 00 Praha'],
                ['label' => 'Country', 'value' => 'Czechia'],
                ['label' => 'GPS', 'value' => '50.087500, 14.421300'],
            ],
            $groups[1]['items'],
        );
        $this->assertSame([['label' => 'Note', 'value' => 'Hlavní sídlo']], $groups[2]['items']);
        $this->assertTrue($groups[2]['wide']);
    }

    public function testShortNameSameAsNameIsNotRepeated(): void
    {
        $record = [
            'id' => 3, 'name' => '1. NP', 'short_name' => '1. NP', 'code' => null, 'order_pos' => 0, 'note' => null,
            'kind' => 4, 'parent' => 2, 'docState' => 40, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null,
        ];
        $content = $this->placesViewer(record: $record)->renderDetail(3)['tabs'][0]['content'];

        $this->assertSame([['label' => 'Order', 'value' => '0']], $content['groups'][0]['items']);
    }

    public function testAddressIsShownOnlyForTypeWithAddress(): void
    {
        // Podlaží s adresou v datech (starý záznam) ji neukáže.
        $record = [
            'id' => 3, 'name' => '1. NP', 'short_name' => null, 'code' => '1', 'order_pos' => 2, 'note' => null,
            'kind' => 4, 'parent' => 2, 'docState' => 40, 'kind_name' => 'Podlaží', 'kind_type' => 'floor', 'kind_icon' => null,
            'street' => 'Dlouhá', 'city' => 'Praha',
        ];
        $content = $this->placesViewer(record: $record)->renderDetail(3)['tabs'][0]['content'];

        $this->assertNotContains('Address', array_column($content['groups'], 'title'));
    }

    public function testChildPlacesTableOpensTheChildDetail(): void
    {
        $record = [
            'id' => 2, 'name' => 'Budova A', 'short_name' => 'A', 'code' => 'A', 'order_pos' => 1, 'note' => null,
            'kind' => 2, 'parent' => 1, 'docState' => 40, 'kind_name' => 'Budova', 'kind_type' => 'building', 'kind_icon' => null,
        ];
        $content = $this->placesViewer(record: $record)->renderDetail(2)['tabs'][0]['content'];

        $this->assertSame(['properties', 'heading', 'table'], array_column($content['blocks'], 'type'));
        $this->assertSame('Child places (2)', $content['blocks'][1]['text']);

        $table = $content['blocks'][2];
        $this->assertSame(
            [
                ['id' => 'name', 'label' => 'Name', 'link' => true],
                ['id' => 'code', 'label' => 'Code'],
                ['id' => 'kind', 'label' => 'Kind'],
            ],
            $table['columns'],
        );
        // Pořadí stromu: Sklep (pořadí 1) před 1. NP (pořadí 2).
        $this->assertSame(['Sklep', '1. NP'], array_column($table['rows'], 'name'));
        $this->assertSame(
            ['kind' => 'open_detail', 'target' => ['viewerId' => 'base.places.places', 'recordId' => 3]],
            $table['rows'][1]['_action'],
        );
        $this->assertSame('muted', $table['rows'][0]['_class'], 'archivované podřízené místo tlumeně');
        $this->assertArrayNotHasKey('_class', $table['rows'][1]);
    }

    // --- druhy míst ---

    public function testKindsViewerRowAndDetail(): void
    {
        $record = ['id' => 5, 'name' => 'Kancelář', 'type' => 'room', 'icon' => 'place-office', 'docState' => 70];
        $viewer = new PlaceKindsViewer($this->db($captured, $record), 'base_places_kinds');
        $viewer->setConfig($this->config());
        $this->declare($viewer, 'base.places.kinds', 'base_places_kinds');

        $viewer->selectRows('kanc', [], 1);
        $this->assertStringContainsString('k.`name`', $captured[0]);
        $this->assertStringContainsString('ORDER BY k.`docStateMain` ASC, k.`name` ASC, k.`id` ASC', $captured[0]);

        $row = $viewer->renderRow($record);
        $this->assertSame('Kancelář', $row['t1']);
        $this->assertSame([['text' => 'Room', 'class' => 'primary'], ['text' => 'V archívu', 'class' => 'muted']], $row['t2']);
        $this->assertSame('place-office', $row['icon']);
        $this->assertSame('place-room', $viewer->renderRow(['icon' => null] + $record)['icon'], 'bez ikony ikona typu');

        $detail = $viewer->renderDetail(5);
        $this->assertSame('Kancelář', $detail['title']);
        $this->assertNull($detail['subtitle']);
        $this->assertSame(
            [['label' => 'Place type', 'value' => 'Room'], ['label' => 'Icon', 'value' => 'Office']],
            $detail['tabs'][0]['content']['groups'][0]['items'],
        );
    }

    public function testKindWithoutIconSaysByType(): void
    {
        $record = ['id' => 5, 'name' => 'Místnost', 'type' => 'room', 'icon' => null, 'docState' => 40];
        $viewer = new PlaceKindsViewer($this->db($captured, $record), 'base_places_kinds');
        $viewer->setConfig($this->config());
        $this->declare($viewer, 'base.places.kinds', 'base_places_kinds');

        $items = $viewer->renderDetail(5)['tabs'][0]['content']['groups'][0]['items'];
        $this->assertSame(['label' => 'Icon', 'value' => 'By type'], $items[1]);
    }

    // --- Nastavení ---

    public function testPlacesSectionSitsBetweenAssetsAndWarehouses(): void
    {
        $module = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $this->assertSame(
            ['base.places.places' => 10, 'base.places.kinds' => 20],
            array_column($module['settingsItems'], 'order', 'viewer'),
        );
        $this->assertSame(['places'], array_values(array_unique(array_column($module['settingsItems'], 'section'))));
        foreach ($module['viewers'] as $viewer) {
            $this->assertArrayNotHasKey('navSection', $viewer, $viewer['id']);
        }

        $sections = array_column(
            JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc')['sections'],
            null,
            'id',
        );
        $this->assertSame('Místa', $sections['places']['name:cs']);
        $this->assertSame('Places', $sections['places']['name:en']);
        $this->assertSame('place-building', $sections['places']['icon']);
        $this->assertSame(16, $sections['places']['order']);
        $this->assertGreaterThan($sections['assets']['order'], $sections['places']['order']);
        $this->assertLessThan($sections['warehouses']['order'], $sections['places']['order']);
    }
}
