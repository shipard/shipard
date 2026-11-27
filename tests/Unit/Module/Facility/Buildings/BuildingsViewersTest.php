<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Module\Base\Places\PlaceTree;
use Shipard\Module\Facility\Buildings\ContractKindsViewer;
use Shipard\Module\Facility\Buildings\ContractsViewer;

class TestableContractsViewer extends ContractsViewer
{
    /** @var list<array<string, mixed>> uzly stromu míst */
    public array $placeNodes = [];

    protected function placeTree(): PlaceTree
    {
        return new class($this->placeNodes) extends PlaceTree {
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
}

class TestableContractKindsViewer extends ContractKindsViewer
{
    protected function currentYear(): string
    {
        return '2027';
    }
}

/**
 * Viewery doplňku Budovy (#174 D18, D25): řádek a detail smlouvy (místa,
 * platnost, ikona typu, tabulka míst s proklikem, Přehled neopakuje
 * hlavičku), filtry druh / typ bez dolních tabů, hledání i v názvech míst;
 * druhy smluv s ukázkou čísla.
 */
class BuildingsViewersTest extends TestCase
{
    use BuildingsConfig;

    private static function definition(string $viewerId): ViewerDefinition
    {
        $registry = new ViewerRegistry();
        $registry->loadFromModules([ModuleLoader::loadModule(self::modulesDir() . '/facility/buildings')], 'cs');
        return $registry->get($viewerId) ?? throw new \RuntimeException($viewerId);
    }

    /**
     * Definice tabulek doplňku s českými popisky (jako `TableLoader` pro `cs`).
     *
     * @return array<string, TableDefinition>
     */
    private static function tables(): array
    {
        $tables = [];
        foreach (['facility_buildings_contracts', 'facility_buildings_contract_kinds'] as $table) {
            $tables[$table] = TableDefinition::fromArray(self::czech('facility/buildings/tables/' . $table . '.jsonc'));
        }
        return $tables;
    }

    /** @return array<string, mixed> cfgItemy viewerů v češtině */
    private static function catalogs(): array
    {
        return [
            'facility.buildings.viewerLabels' => self::czech('facility/buildings/config/viewerLabels.jsonc'),
            'facility.buildings.docStates' => self::czech('facility/buildings/config/docStates.jsonc')
                + [],
            'facility.buildings.resetScopes' => self::czech('facility/buildings/config/resetScopes.jsonc'),
            'core.system.docStatesArchive' => self::czech('core/system/config/docStatesArchive.jsonc'),
            'core.system.viewerDetailLabels' => self::czech('core/system/config/viewerDetailLabels.jsonc'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows řádky seznamu
     * @param list<array<string, mixed>> $places vazby míst (contract, place, name, short_name)
     * @param list<list<mixed>>|null $queries
     */
    private function contracts(?array &$queries = null, array $rows = [], array $places = [], ?array $detail = null): TestableContractsViewer
    {
        $queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (...$args) use (&$queries, $rows, $places): array {
            $queries[] = $args;
            $sql = (string) $args[0];
            if (str_contains($sql, 'FROM `facility_buildings_contract_places` cp') && !str_contains($sql, 'EXISTS')) {
                return $places;
            }
            if (str_contains($sql, 'FROM `facility_buildings_contract_kinds`')) {
                return [['id' => 1, 'name' => 'Kancelář'], ['id' => 3, 'name' => 'Parkování']];
            }
            return $rows;
        });
        $db->method('fetchRow')->willReturn($detail);

        $viewer = new TestableContractsViewer($db, 'facility_buildings_contracts');
        $viewer->setConfig($this->buildingsConfig(self::catalogs()));
        $viewer->setLanguage('cs');
        $viewer->setTables(self::tables());
        $viewer->setDefinition(self::definition('facility.buildings.contracts'));
        return $viewer;
    }

    private function kinds(?array &$queries = null, ?array $detail = null): TestableContractKindsViewer
    {
        $queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(static function (...$args) use (&$queries): array {
            $queries[] = $args;
            return [];
        });
        $db->method('fetchRow')->willReturn($detail);

        $viewer = new TestableContractKindsViewer($db, 'facility_buildings_contract_kinds');
        $viewer->setConfig($this->buildingsConfig(self::catalogs()));
        $viewer->setLanguage('cs');
        $viewer->setTables(self::tables());
        $viewer->setDefinition(self::definition('facility.buildings.contractKinds'));
        return $viewer;
    }

    // ── Smlouvy: seznam ─────────────────────────────────────────────────────

    public function testViewGroupsComeFromOwnStateSetAndThereAreNoBottomTabs(): void
    {
        $viewer = $this->contracts();

        $this->assertSame(['active', 'archive', 'trash'], $viewer->getViewGroups());
        $this->assertSame([], $viewer->getBottomTabs(), 'smlouvy dolní taby nemají (D5)');

        $module = JsoncParser::parseFile(self::modulesDir() . '/facility/buildings/module.jsonc');
        $viewers = array_column($module['viewers'], null, 'id');
        $this->assertSame('buildings', $viewers['facility.buildings.contracts']['navSection']);
        $this->assertArrayNotHasKey('navSection', $viewers['facility.buildings.contractKinds'], 'druhy žijí jen v Nastavení');
        $this->assertSame(
            [['viewer' => 'facility.buildings.contractKinds', 'section' => 'buildings', 'order' => 10]],
            $module['settingsItems'],
        );
    }

    public function testFiltersAreKindAndType(): void
    {
        $filters = $this->contracts()->getFilters();

        $this->assertSame(['kind', 'type'], array_column($filters, 'id'));
        $this->assertSame(['Druh', 'Typ smlouvy'], array_column($filters, 'label'));
        $this->assertSame([['value' => 1, 'label' => 'Kancelář'], ['value' => 3, 'label' => 'Parkování']], $filters[0]['options']);
        $this->assertCount(7, $filters[1]['options']);
    }

    public function testSelectRowsAppliesKindTypeAndSearchesHeadTenantAndPlaceNames(): void
    {
        $viewer = $this->contracts($queries, [['id' => 5, 'title' => 'Pronájem']], [
            ['contract' => 5, 'name' => 'Kancelář 201', 'short_name' => '201'],
            ['contract' => 5, 'name' => 'Kancelář 202', 'short_name' => null],
        ]);

        $rows = $viewer->selectRows('201', [
            ['id' => 'viewGroup', 'value' => 'archive'],
            ['id' => 'kind', 'value' => '1'],
            ['id' => 'type', 'value' => 'office'],
            ['id' => 'type', 'value' => 'bogus'],
        ], 1);

        [$sql] = $queries[0];
        $params = array_slice($queries[0], 1);
        $this->assertStringContainsString('c.`kind` = %i', $sql);
        $this->assertSame(1, substr_count($sql, 'c.`type` = %s'), 'neznámý typ se nefiltruje');
        foreach (['c.`number`', 'c.`title`', 'p.`full_name`', 'pl.`name`', 'pl.`short_name`'] as $column) {
            $this->assertStringContainsString($column, $sql);
        }
        $this->assertStringContainsString('EXISTS (SELECT 1 FROM `facility_buildings_contract_places` cp', $sql);
        $this->assertStringContainsString('COLLATE utf8mb4_uca1400_ai_ci', $sql, 'hledání přes SearchCondition');
        $this->assertSame(5, count(array_keys($params, '201', true)), 'surový text bez % u všech pěti sloupců');
        $this->assertContains(1, $params);
        $this->assertContains('office', $params);

        // Místa stránky jedním dotazem, v pořadí vazby, zkráceným názvem.
        $this->assertSame([5], $queries[1][1]);
        $this->assertSame(['201', 'Kancelář 202'], $rows[0]['place_labels']);
    }

    public function testRowShowsTenantFirstTwoPlacesValidityAndTypeIcon(): void
    {
        $row = $this->contracts()->renderRow([
            'id' => 5, 'number' => 'KA-2027-0003', 'title' => 'Pronájem 2. patra', 'type' => 'office',
            'customer_name' => 'Alfa s.r.o.', 'date_start' => '2027-01-01', 'date_end' => null,
            'docState' => 40, 'place_labels' => ['201', '202', '203'],
        ]);

        $this->assertSame('Pronájem 2. patra', $row['t1'], 'titulek je název, ne nájemce');
        $this->assertSame('KA-2027-0003', $row['i1']);
        $this->assertSame(
            [
                ['text' => 'Alfa s.r.o.', 'class' => 'primary'],
                ['text' => '201, 202 +1'],
                ['text' => 'od 01.01.2027', 'class' => 'muted'],
            ],
            $row['t2'],
            'V pořádku nemá štítek stavu',
        );
        $this->assertSame('contract-office', $row['icon']);
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testRowWithBothDatesFewPlacesAndAnEndedState(): void
    {
        $row = $this->contracts()->renderRow([
            'id' => 6, 'number' => 'PA-2027-0001', 'title' => 'Stání', 'type' => 'parking',
            'customer_name' => null, 'date_start' => '2027-01-01', 'date_end' => '2028-12-31',
            'docState' => 70, 'place_labels' => ['P5'],
        ]);

        $texts = array_column($row['t2'], 'text');
        $this->assertSame(['P5', '01.01.2027 – 31.12.2028', 'Ukončená'], $texts);
        $this->assertSame('contract-parking', $row['icon']);
    }

    public function testDraftRowWithoutNumberPlacesOrDates(): void
    {
        $row = $this->contracts()->renderRow(['id' => 7, 'title' => 'Rozpracovaná', 'type' => '', 'docState' => 10]);

        $this->assertNull($row['i1']);
        $this->assertNull($row['t2'], 'koncept nemá štítek, místa ani platnost');
        $this->assertArrayNotHasKey('icon', $row, 'bez typu ikona vieweru');
    }

    // ── Smlouvy: detail ─────────────────────────────────────────────────────

    public function testDetailShowsOverviewAndPlacesTableWithLinks(): void
    {
        $record = [
            'id' => 5, 'number' => 'KA-2027-0003', 'title' => 'Pronájem 2. patra', 'kind' => 1, 'type' => 'office',
            'kind_name' => 'Kancelář', 'customer' => 7, 'customer_name' => 'Alfa s.r.o.',
            'date_start' => '2027-01-01', 'date_end' => null, 'internal_note' => 'Klíče u správce.',
            'docState' => 40, 'docStateMain' => 3,
        ];
        $viewer = $this->contracts(
            $queries,
            places: [['place' => 201, 'name' => 'Kancelář 201'], ['place' => 30, 'name' => 'Křídlo B']],
            detail: $record,
        );
        $node = static fn (int $id, ?int $parent, string $name, ?string $short = null): array => [
            'id' => $id, 'parent' => $parent, 'name' => $name, 'short_name' => $short, 'code' => null, 'order_pos' => $id,
            'docState' => 40, 'kind' => 1, 'kind_name' => '', 'kind_type' => 'room', 'kind_icon' => null,
        ];
        $viewer->placeNodes = [
            $node(1, null, 'Areál firmy', 'Areál'), $node(2, 1, 'Budova A', 'A'),
            $node(30, 2, 'Křídlo B'), $node(201, 30, 'Kancelář 201'),
        ];

        $detail = $viewer->renderDetail(5);

        // Hlavička z deklarace; V pořádku bez štítku.
        $this->assertSame('Pronájem 2. patra', $detail['title']);
        $this->assertSame('KA-2027-0003', $detail['subtitle']);
        $this->assertSame('contract', $detail['icon']);
        $this->assertSame([], $detail['badges']);
        $this->assertSame(['overview'], array_column($detail['tabs'], 'id'), 'jeden tab — přílohy jsou na konci Přehledu');

        $blocks = $detail['tabs'][0]['content']['blocks'];
        $this->assertSame(['properties', 'heading', 'table'], array_column($blocks, 'type'));
        $groups = array_column($blocks[0]['groups'], null, 'title');
        $this->assertSame(['Identifikace', 'Platnost', 'Poznámka'], array_keys($groups));
        $this->assertSame(
            [
                ['label' => 'Druh', 'value' => 'Kancelář'],
                ['label' => 'Typ smlouvy', 'value' => 'Kancelář'],
                ['label' => 'Nájemce', 'value' => 'Alfa s.r.o.'],
            ],
            $groups['Identifikace']['items'],
        );
        $this->assertSame(
            [['label' => 'Zahájení', 'value' => '01.01.2027'], ['label' => 'Ukončení', 'value' => 'bez konce']],
            $groups['Platnost']['items'],
        );
        $this->assertSame([['label' => 'Interní poznámka', 'value' => 'Klíče u správce.']], $groups['Poznámka']['items']);
        $this->assertTrue($groups['Poznámka']['wide']);

        // Přehled neopakuje hlavičku (#140 D26).
        $values = array_merge(...array_map(static fn (array $g): array => array_column($g['items'], 'value'), array_values($groups)));
        $this->assertNotContains('Pronájem 2. patra', $values);
        $this->assertNotContains('KA-2027-0003', $values);

        $this->assertSame('Místa', $blocks[1]['text']);
        $table = $blocks[2];
        $this->assertSame(
            [['id' => 'place', 'label' => 'Místo', 'link' => true], ['id' => 'location', 'label' => 'Umístění']],
            $table['columns'],
        );
        $this->assertSame(
            [
                [
                    'place' => 'Kancelář 201', 'location' => 'Areál › A › Křídlo B',
                    '_action' => ['id' => 'openPlace', 'kind' => 'open_detail', 'target' => ['viewerId' => 'base.places.places', 'recordId' => 201]],
                ],
                [
                    'place' => 'Křídlo B', 'location' => 'Areál › A',
                    '_action' => ['id' => 'openPlace', 'kind' => 'open_detail', 'target' => ['viewerId' => 'base.places.places', 'recordId' => 30]],
                ],
            ],
            $table['rows'],
        );
    }

    public function testDetailWithoutPlacesHasJustTheOverviewAndTheStateBadge(): void
    {
        $viewer = $this->contracts($queries, detail: [
            'id' => 8, 'number' => null, 'title' => 'Zrušená smlouva', 'kind' => 2, 'type' => 'other', 'kind_name' => 'Ostatní',
            'customer_name' => null, 'date_start' => '2027-01-01', 'date_end' => '2027-02-01', 'internal_note' => null,
            'docState' => 30, 'docStateMain' => 4,
        ]);

        $detail = $viewer->renderDetail(8);

        $this->assertSame('properties', $detail['tabs'][0]['content']['type']);
        $this->assertSame(['Zrušená'], array_column($detail['badges'], 'label'));
        $this->assertSame(
            ['Identifikace', 'Platnost'],
            array_column($detail['tabs'][0]['content']['groups'], 'title'),
            'prázdná skupina Poznámka mizí',
        );
    }

    // ── Druhy smluv ─────────────────────────────────────────────────────────

    public function testKindRowShowsCodeTypeAndSampleNumber(): void
    {
        $row = $this->kinds()->renderRow([
            'id' => 1, 'name' => 'Kancelář', 'type' => 'office', 'number_code' => 'KA',
            'number_pattern' => '%C-%Y-%4', 'notice' => 'Nájmy kanceláří', 'docState' => 40,
        ]);

        $this->assertSame('Kancelář', $row['t1']);
        $this->assertSame('KA', $row['i1']);
        $this->assertSame(
            [['text' => 'Kancelář', 'class' => 'primary'], ['text' => 'KA-2027-0001', 'class' => 'muted']],
            $row['t2'],
        );
        $this->assertSame('Nájmy kanceláří', $row['t3']);
        $this->assertSame('contract-office', $row['icon']);
    }

    public function testKindRowWithAnInvalidPatternHasNoSample(): void
    {
        $row = $this->kinds()->renderRow([
            'id' => 2, 'name' => 'Rozpracovaný', 'type' => 'other', 'number_code' => null,
            'number_pattern' => '%C-%Y-%4', 'docState' => 70,
        ]);

        $texts = array_column($row['t2'], 'text');
        $this->assertSame('Ostatní', $texts[0]);
        $this->assertCount(2, $texts, 'vzorec s %C bez kódu ukázku nemá; zůstává typ a štítek archivu');
    }

    public function testKindSearchLooksInNameAndCode(): void
    {
        $viewer = $this->kinds($queries);
        $viewer->selectRows('ka', [], 1);

        $this->assertStringContainsString('k.`name`', $queries[0][0]);
        $this->assertStringContainsString('k.`number_code`', $queries[0][0]);
    }

    public function testKindDetailShowsTypeAndNumbering(): void
    {
        $viewer = $this->kinds(detail: [
            'id' => 1, 'name' => 'Kancelář', 'type' => 'office', 'notice' => null, 'number_code' => 'KA',
            'number_pattern' => '%C-%Y-%4', 'reset_scope' => 'yearly', 'docState' => 40, 'docStateMain' => 3,
        ]);

        $detail = $viewer->renderDetail(1);

        $this->assertSame('Kancelář', $detail['title']);
        $groups = array_column($detail['tabs'][0]['content']['groups'], null, 'title');
        $this->assertSame(['Identifikace', 'Číslování'], array_keys($groups));
        $this->assertSame([['label' => 'Typ smlouvy', 'value' => 'Kancelář']], $groups['Identifikace']['items']);
        $this->assertSame(
            [
                ['label' => 'Kód druhu (%C)', 'value' => 'KA'],
                ['label' => 'Vzorec čísla smlouvy', 'value' => '%C-%Y-%4'],
                ['label' => 'Restart počítadla', 'value' => 'Ročně'],
                ['label' => 'Ukázka čísla', 'value' => 'KA-2027-0001'],
            ],
            $groups['Číslování']['items'],
        );
    }
}
