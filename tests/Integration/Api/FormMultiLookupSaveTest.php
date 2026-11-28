<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Api;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Request;
use Shipard\Core\Database\SqlGenerator;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Test\MultiLookup\ParentsForm;
use Shipard\Module\Test\MultiLookup\TargetsLookup;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Prvek `multilookup` proti skutečné databázi (#176 D3, D9): uložení
 * formuláře přes `FormController::save` → `TableGateway` → child tabulka.
 * Vložení, změna pořadí, odebrání a smazání všeho; id nedotčených řádků
 * i sloupec navíc existujícího řádku zůstávají, nový řádek dostane výchozí
 * hodnotu sloupce.
 *
 * Tabulky fixturového modulu `test.multiLookup` ve zdroji dat nejsou —
 * test si je založí jako dočasné (`CREATE TEMPORARY TABLE`) z definic
 * fixtury, žijí jen ve spojení testu a ve zdroji dat po nich nic nezůstane.
 */
class FormMultiLookupSaveTest extends IntegrationTestCase
{
    private const PARENTS = 'test_multilookup_parents';
    private const LINKS = 'test_multilookup_links';
    private const TARGETS = 'test_multilookup_targets';

    /** @var array<string, TableDefinition> */
    private array $fixtureTables = [];

    private ModulePathResolver $fixtureResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $mainRoot = dirname(__DIR__, 3) . '/modules';
        $fixtureRoot = dirname(__DIR__, 2) . '/Fixtures/modules';
        $this->fixtureResolver = new ModulePathResolver([$fixtureRoot]);
        ModuleClassLoader::register(new ModulePathResolver([$mainRoot, $fixtureRoot]));

        foreach ([self::PARENTS, self::LINKS, self::TARGETS] as $table) {
            $definition = TableDefinition::fromArray(
                JsoncParser::parseFile("{$fixtureRoot}/test/multiLookup/tables/{$table}.jsonc"),
            );
            $this->fixtureTables[$table] = $definition;
            $create = SqlGenerator::generateCreateTable($table, $definition);
            $this->db->execute('%sql', 'CREATE TEMPORARY TABLE ' . substr($create, strlen('CREATE TABLE ')));
        }

        foreach ([1 => 'Alpha', 2 => 'Beta', 3 => 'Gamma', 4 => 'Delta'] as $id => $name) {
            $this->db->execute('INSERT INTO %n ([id], [name]) VALUES (%i, %s)', self::TARGETS, $id, $name);
        }
    }

    protected function onTearDown(): void
    {
        foreach ([self::PARENTS, self::LINKS, self::TARGETS] as $table) {
            $this->db->execute('DROP TEMPORARY TABLE IF EXISTS %n', $table);
        }
        ModuleClassLoader::register(new ModulePathResolver([dirname(__DIR__, 3) . '/modules']));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> `data` odpovědi
     */
    private function save(array $body, ?int $id): array
    {
        $forms = new FormRegistry([['table' => self::PARENTS, 'class' => ParentsForm::class]]);
        $lookups = new LookupRegistry();
        $lookups->register(self::TARGETS, TargetsLookup::class);

        $request = Request::fromArray(
            $id === null ? 'POST' : 'PUT', '/_ui/form/' . self::PARENTS . '/save', [],
            (string) json_encode($body), ['Content-Type' => 'application/json'],
        );
        $response = (new FormController())->save(
            self::PARENTS, $id, $request, $this->fixtureTables, $this->db, null, $forms,
            $this->fixtureResolver, $lookups, 'en', null, $this->dsConfig, new AuthContext(true, 1, 'session', null, true), null,
        );
        $this->assertContains($response->getStatus(), [200, 201], (string) json_encode($response->getPayload()));
        return $response->getPayload()['data'];
    }

    /** @return list<array{id: int, target: int, order_pos: int, note: string}> řádky vazby podle pořadí */
    private function links(int $parentId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'target' => (int) $row['target'],
                'order_pos' => (int) $row['order_pos'],
                'note' => (string) $row['note'],
            ],
            $this->db->fetchAll(
                'SELECT [id], [target], [order_pos], [note] FROM %n WHERE [parent] = %i ORDER BY [order_pos], [id]',
                self::LINKS, $parentId,
            ),
        );
    }

    public function testInsertReorderRemoveKeepsRowIdsAndExtraColumn(): void
    {
        // Vložení s novým záznamem: řádky v pořadí výběru, sloupec navíc má výchozí hodnotu.
        $created = $this->save(['name' => 'Parent', 'targets' => [3, 1, 2]], null);
        $parentId = (int) $created['id'];
        $this->assertSame([3, 1, 2], $created['data']['targets']);
        $this->assertSame(['Gamma', 'Alpha', 'Beta'], array_column($created['dataResolved']['targets'], 'primary'));

        $links = $this->links($parentId);
        $this->assertSame([3, 1, 2], array_column($links, 'target'));
        $this->assertSame([1, 2, 3], array_column($links, 'order_pos'));
        $this->assertSame(['new', 'new', 'new'], array_column($links, 'note'));
        $rowIdOf = array_column($links, 'id', 'target');

        // Sloupec navíc existujícího řádku změněný mimo prvek (sub-tabulka, D9).
        $this->db->execute('UPDATE %n SET [note] = %s WHERE [id] = %i', self::LINKS, 'edited', $rowIdOf[1]);

        // Změna pořadí + nový záznam + odebrání (2): id ostatních řádků zůstávají.
        $updated = $this->save(['name' => 'Parent', 'targets' => [1, 4, 3]], $parentId);
        $this->assertSame([1, 4, 3], $updated['data']['targets']);

        $links = $this->links($parentId);
        $this->assertSame([1, 4, 3], array_column($links, 'target'));
        $this->assertSame([1, 2, 3], array_column($links, 'order_pos'));
        $this->assertSame($rowIdOf[1], $links[0]['id'], 'řádek se stejným odkazem si nechá id');
        $this->assertSame($rowIdOf[3], $links[2]['id']);
        $this->assertNotContains($links[1]['id'], $rowIdOf, 'nový odkaz = nový řádek');
        $this->assertSame('edited', $links[0]['note'], 'sloupec navíc existujícího řádku se nemění');
        $this->assertSame('new', $links[1]['note'], 'nový řádek má výchozí hodnotu sloupce');

        // Uložení hlavičky bez klíče se vazeb nedotkne.
        $this->save(['name' => 'Renamed'], $parentId);
        $this->assertSame([1, 4, 3], array_column($this->links($parentId), 'target'));

        // Prázdný seznam = smazat vše.
        $cleared = $this->save(['name' => 'Renamed', 'targets' => []], $parentId);
        $this->assertSame([], $cleared['data']['targets']);
        $this->assertSame([], $this->links($parentId));
    }

    public function testRowsOfOtherParentAreNotTouched(): void
    {
        $first = (int) $this->save(['name' => 'First', 'targets' => [1, 2]], null)['id'];
        $second = (int) $this->save(['name' => 'Second', 'targets' => [2, 3]], null)['id'];

        $this->save(['name' => 'First', 'targets' => [2]], $first);

        $this->assertSame([2], array_column($this->links($first), 'target'));
        $this->assertSame([2, 3], array_column($this->links($second), 'target'));
    }
}
