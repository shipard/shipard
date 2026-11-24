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
