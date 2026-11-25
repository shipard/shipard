<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Request;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Module\Test\UiExtensions\FixtureRowDecorator;
use Shipard\Tests\Unit\Core\Module\UiExtensionFixtures;

/**
 * Stub vieweru nad fixturovou tabulkou — stránka má pět řádků, každý
 * s jiným tvarem `t2` (pole, objekt, řetězec, chybí, řádek bez štítku);
 * stránka je menší než počet vybraných řádků (`hasMore`).
 */
class RowDecoratorStubViewer extends TableViewer
{
    protected int $pageSize = 5;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        return [
            ['id' => 1, 'contact_payment' => 1, 't2' => [['text' => 'IČO: 1'], ['text' => 'V archívu', 'class' => 'muted']]],
            ['id' => 2, 'contact_payment' => 1, 't2' => ['text' => 'Objekt', 'class' => 'bold']],
            ['id' => 3, 'contact_payment' => 1, 't2' => 'Řetězec'],
            ['id' => 4, 'contact_payment' => 1],
            ['id' => 5, 'contact_payment' => 0, 't2' => 'Bez štítku'],
            // šestý řádek = jen příznak další stránky, controller ho odřízne
            ['id' => 6, 'contact_payment' => 1, 't2' => 'Další stránka'],
        ];
    }

    public function renderRow(array $rowData): array
    {
        $row = ['id' => (int) $rowData['id'], 't1' => 'row ' . $rowData['id']];
        if (array_key_exists('t2', $rowData)) {
            $row['t2'] = $rowData['t2'];
        }
        return $row;
    }

    public function getGridColumns(): ?array
    {
        return [['id' => 'name', 'label' => 'Name', 'grow' => true]];
    }

    public function renderGridRow(array $rowData): array
    {
        return ['id' => (int) $rowData['id'], 'cells' => ['name' => 'row ' . $rowData['id']]];
    }
}

/** Stromová varianta — strom má buňky, ne `t2`. */
class RowDecoratorTreeStubViewer extends RowDecoratorStubViewer
{
    public function getTreeOptions(): ?array
    {
        return ['parentColumn' => 'parent'];
    }

    public function selectTreeRows(?string $search, array $filters): array
    {
        return [['id' => 1, 'parent' => null, 'contact_payment' => 1]];
    }

    public function renderTreeRow(array $rowData): array
    {
        return ['id' => (int) $rowData['id'], 'cells' => ['name' => 'node ' . $rowData['id']]];
    }
}

/**
 * Štítky řádku seznamu z modulů (`rowDecorators`, #172 D18, D19): spany na
 * konci `t2` pro všechny tvary pole, jedno volání dekorátoru na stránku,
 * jen layout `list`, izolace dekorátoru, který spadne nebo vrátí span mimo
 * kontrakt.
 */
final class ViewerControllerRowDecoratorsTest extends TestCase
{
    use UiExtensionFixtures;

    private const TAG = ['text' => 'Fixture tag', 'class' => 'success'];

    private ViewerController $ctrl;

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
        $this->ctrl = new ViewerController();
        FixtureRowDecorator::$calls = 0;
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    private function registry(?ModuleUiExtensions $extensions): ViewerRegistry
    {
        $registry = new ViewerRegistry();
        $registry->register(new ViewerDefinition('test.records', 'Records', self::UI_TABLE, RowDecoratorStubViewer::class, 'test.uiHost', 'table'));
        $registry->register(new ViewerDefinition('test.tree', 'Tree', self::UI_TABLE, RowDecoratorTreeStubViewer::class, 'test.uiHost', 'table'));
        $registry->register(new ViewerDefinition('test.config', 'Config', null, RowDecoratorStubViewer::class, 'test.uiHost', 'table'));
        if ($extensions !== null) {
            $registry->setUiExtensions($extensions);
        }
        return $registry;
    }

    /** @param list<array{0: string, 1?: int}> $classes krátké jméno fixturové třídy + volitelné pořadí */
    private function rowDecorators(array $classes): ModuleUiExtensions
    {
        $registrations = [];
        foreach ($classes as $entry) {
            $reg = ['table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . $entry[0]];
            if (isset($entry[1])) {
                $reg['order'] = $entry[1];
            }
            $registrations[] = $reg;
        }
        return ModuleUiExtensions::fromModules([ModuleDefinition::fromArray([
            'id' => 'test.uiExtensions',
            'name' => 'addon',
            'rowDecorators' => $registrations,
        ])]);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function rows(?ModuleUiExtensions $extensions, array $query = [], string $viewerId = 'test.records'): array
    {
        $db = (new \ReflectionClass(DataSourceConnection::class))->newInstanceWithoutConstructor();
        $response = $this->ctrl->rows(
            $viewerId,
            Request::fromArray('GET', '/_ui/viewer/x/rows', $query, '', []),
            new AuthContext(true, 2, 'session', 'shpd_st_x'),
            $this->registry($extensions),
            $this->uiTables(),
            $db,
            null,
            'en',
        );
        $this->assertSame(200, $response->getStatus(), json_encode($response->getPayload()));
        return $response->getPayload()['data'];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, mixed> id řádku → `t2` (null = klíč chybí)
     */
    private function t2ById(array $data): array
    {
        $out = [];
        foreach ($data['rows'] as $row) {
            $out[$row['id']] = $row['t2'] ?? null;
        }
        return $out;
    }

    public function testRowsWithoutDecoratorsAreUnchanged(): void
    {
        $plain = $this->rows(null);

        $this->assertSame('Řetězec', $this->t2ById($plain)[3], 't2 zůstává, jak ho vrátil viewer');
        $this->assertArrayNotHasKey('t2', $plain['rows'][3]);
        $this->assertSame($plain, $this->rows($this->uiExtensionsOf(['test.uiHost'])), 'doplněk není ve zdroji dat zapnutý');
    }

    public function testSpansAreAppendedToEveryShapeOfT2(): void
    {
        $t2 = $this->t2ById($this->rows($this->uiExtensionsOf()));

        $this->assertSame(
            [['text' => 'IČO: 1'], ['text' => 'V archívu', 'class' => 'muted'], self::TAG],
            $t2[1],
            'pole spanů: pořadí původních zůstává, štítek modulu je poslední',
        );
        $this->assertSame([['text' => 'Objekt', 'class' => 'bold'], self::TAG], $t2[2], 'objekt se převede na pole');
        $this->assertSame([['text' => 'Řetězec'], self::TAG], $t2[3], 'řetězec se převede na pole');
        $this->assertSame([self::TAG], $t2[4], 'řádek bez t2 dostane jen štítek modulu');
        $this->assertSame('Bez štítku', $t2[5], 'řádek bez štítku se nemění');
        $this->assertSame([], $this->uiExtensionFailures());
    }

    public function testDecoratorIsCalledOncePerPageNotPerRow(): void
    {
        $data = $this->rows($this->uiExtensionsOf());

        $this->assertCount(5, $data['rows']);
        $this->assertTrue($data['hasMore']);
        $this->assertSame(1, FixtureRowDecorator::$calls);
        $this->assertArrayNotHasKey(6, $this->t2ById($data), 'řádek další stránky dekorátor nedostane');
    }

    public function testDecoratorsApplyOnlyToListLayout(): void
    {
        $grid = $this->rows($this->uiExtensionsOf(), ['layout' => 'grid']);
        $this->assertSame(['id' => 1, 'cells' => ['name' => 'row 1']], $grid['rows'][0]);

        $tree = $this->rows($this->uiExtensionsOf(), ['layout' => 'tree'], 'test.tree');
        $this->assertSame(['name' => 'node 1'], $tree['rows'][0]['cells']);
        $this->assertArrayNotHasKey('t2', $tree['rows'][0]);

        $this->assertSame(0, FixtureRowDecorator::$calls);
    }

    public function testViewerWithoutTableHasNoDecorators(): void
    {
        $data = $this->rows($this->uiExtensionsOf(), [], 'test.config');

        $this->assertSame('Řetězec', $this->t2ById($data)[3]);
        $this->assertSame(0, FixtureRowDecorator::$calls);
    }

    public function testDecoratorsAreOrderedByOrder(): void
    {
        $t2 = $this->t2ById($this->rows($this->rowDecorators([['FixtureRowDecorator'], ['SecondRowDecorator', 100]])));

        $second = ['text' => 'Second tag', 'badge' => 'info'];
        $this->assertSame([['text' => 'Řetězec'], $second, self::TAG], $t2[3]);
        $this->assertSame([['text' => 'Bez štítku'], $second], $t2[5]);
    }

    /** @return array<string, array{string, string}> */
    public static function failingDecorators(): array
    {
        return [
            'výjimka dekorátoru' => ['ThrowingRowDecorator', 'row decorator broke'],
            'span bez textu'     => ['BadSpanRowDecorator', 'decorate()[1][0].text must be a non-empty string'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failingDecorators')]
    public function testFailingDecoratorIsLoggedAndListIsRenderedWithoutIt(string $class, string $message): void
    {
        $data = $this->rows($this->rowDecorators([[$class, 100], ['FixtureRowDecorator']]));

        $this->assertCount(5, $data['rows']);
        $this->assertSame([['text' => 'Řetězec'], self::TAG], $this->t2ById($data)[3]);

        $failures = $this->uiExtensionFailures();
        $this->assertCount(1, $failures);
        $this->assertSame('test.uiExtensions', $failures[0]['module']);
        $this->assertSame(self::UI_NAMESPACE . $class, $failures[0]['class']);
        $this->assertSame(self::UI_TABLE, $failures[0]['table']);
        $this->assertSame(ModuleUiExtensions::ROW_DECORATORS, $failures[0]['kind']);
        $this->assertStringContainsString($message, $failures[0]['message']);
    }
}
