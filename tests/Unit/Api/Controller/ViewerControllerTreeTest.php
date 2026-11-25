<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;

/**
 * Stub stromového vieweru: budova 1 s podlažími 10 a 20, pod nimi místnosti.
 * Hledání vybere jen místnosti s kódem; předci se dotahují přes
 * selectTreeRowsByIds(). Volání zachytává do static polí (controller
 * instanci vieweru neexponuje).
 */
class TreeStubViewer extends TableViewer
{
	/** @var array<int, array<string, mixed>> */
	public const ROWS = [
		1   => ['id' => 1, 'parent' => null, 'pos' => 1, 'name' => 'Budova A', 'code' => 'A'],
		10  => ['id' => 10, 'parent' => 1, 'pos' => 1, 'name' => '1. NP', 'code' => '1'],
		20  => ['id' => 20, 'parent' => 1, 'pos' => 2, 'name' => '2. NP', 'code' => '2'],
		101 => ['id' => 101, 'parent' => 10, 'pos' => 1, 'name' => 'Kancelář', 'code' => '101'],
		201 => ['id' => 201, 'parent' => 20, 'pos' => 1, 'name' => 'Zasedačka', 'code' => '201'],
	];

	public static bool $selectRowsCalled = false;
	/** @var array{0: ?string, 1: array}|null */
	public static ?array $lastTreeSelect = null;
	/** @var list<list<int>> */
	public static array $idRequests = [];
	public static ?int $maxNodes = null;

	public function selectRows(?string $search, array $filters, int $pageNumber): array
	{
		self::$selectRowsCalled = true;
		return array_values(self::ROWS);
	}

	public function renderRow(array $rowData): array
	{
		return ['id' => (int) $rowData['id'], 't1' => (string) $rowData['name']];
	}

	public function getTreeOptions(): ?array
	{
		$options = [
			'parentColumn' => 'parent',
			'columns'      => [
				['id' => 'name', 'label' => 'Name', 'grow' => true],
				['id' => 'code', 'label' => 'Code', 'width' => 96],
			],
		];
		if (self::$maxNodes !== null) {
			$options['maxNodes'] = self::$maxNodes;
		}
		return $options;
	}

	public function getDefaultLayout(): string
	{
		return 'tree';
	}

	public function selectTreeRows(?string $search, array $filters): array
	{
		self::$lastTreeSelect = [$search, $filters];
		$rows = array_values(self::ROWS);
		if ($search === null || $search === '') {
			return $rows;
		}
		return array_values(array_filter($rows, static fn (array $r): bool => str_contains((string) $r['code'], $search)));
	}

	public function selectTreeRowsByIds(array $ids): array
	{
		self::$idRequests[] = $ids;
		return array_values(array_intersect_key(self::ROWS, array_flip($ids)));
	}

	public function compareTreeRows(array $a, array $b): int
	{
		return $a['pos'] <=> $b['pos'];
	}

	public function renderTreeRow(array $rowData): array
	{
		$row = [
			'id'    => (int) $rowData['id'],
			'cells' => ['name' => (string) $rowData['name'], 'code' => (string) $rowData['code']],
		];
		if ((int) $rowData['id'] === 1) {
			$row['icon'] = 'place-building';
			$row['rowAction'] = ['id' => 'addChild', 'label' => 'Add child', 'kind' => 'open_form'];
		}
		return $row;
	}
}

/**
 * Stromová větev ViewerControlleru (#169 D20, D21, D25; docs/viewer-tree.md):
 * meta klíče layouts / defaultLayout / tree, rows s layout=tree (tvar řádku,
 * předci jako kontext, výchozí ikona), guard LAYOUT_NOT_SUPPORTED, strop
 * tooMany a ignorované stránkování.
 */
class ViewerControllerTreeTest extends TestCase
{
	private DataSourceConnection $db;
	private ViewerRegistry $registry;
	private ViewerController $ctrl;

	protected function setUp(): void
	{
		$ref = new \ReflectionClass(DataSourceConnection::class);
		$this->db = $ref->newInstanceWithoutConstructor();

		$this->registry = new ViewerRegistry();
		$this->registry->register(new ViewerDefinition(
			id: 'test.tree',
			name: 'Tree',
			table: 'test_tree_rows',
			class: TreeStubViewer::class,
			moduleId: 'test',
			icon: 'table',
		));
		$this->registry->register(new ViewerDefinition(
			id: 'test.listOnly',
			name: 'List only',
			table: 'test_list_rows',
			class: ListOnlyStubViewer::class,
			moduleId: 'test',
			icon: 'table',
		));

		$this->ctrl = new ViewerController();
		TreeStubViewer::$selectRowsCalled = false;
		TreeStubViewer::$lastTreeSelect = null;
		TreeStubViewer::$idRequests = [];
		TreeStubViewer::$maxNodes = null;
	}

	private function auth(): AuthContext
	{
		return new AuthContext(true, 2, 'session', 'shpd_st_x');
	}

	/** @return array<string, mixed> */
	private function rows(string $viewerId, array $query): array
	{
		return $this->rowsResponse($viewerId, $query)->getPayload()['data'];
	}

	private function rowsResponse(string $viewerId, array $query): Response
	{
		return $this->ctrl->rows(
			$viewerId,
			Request::fromArray('GET', '/_ui/viewer/x/rows', $query, '', []),
			$this->auth(),
			$this->registry,
			[],
			$this->db,
		);
	}

	private function getStatus(Response $response): int
	{
		$ref  = new \ReflectionClass($response);
		$prop = $ref->getProperty('status');
		return $prop->getValue($response);
	}

	// ── meta ────────────────────────────────────────────────────────────────

	public function testMetaTreeViewerOffersTreeLayoutWithColumnsAndParentColumn(): void
	{
		$data = $this->ctrl->meta('test.tree', $this->auth(), $this->registry, [], $this->db)->getPayload()['data'];

		$this->assertSame(['list', 'tree'], $data['layouts']);
		$this->assertSame('tree', $data['defaultLayout']);
		$this->assertSame(['name', 'code'], array_column($data['tree']['columns'], 'id'));
		$this->assertSame('parent', $data['tree']['parentColumn']);
		$this->assertArrayNotHasKey('maxNodes', $data['tree']);
		$this->assertArrayNotHasKey('grid', $data);
	}

	public function testMetaViewerWithoutTreeHasNoTreeKey(): void
	{
		$data = $this->ctrl->meta('test.listOnly', $this->auth(), $this->registry, [], $this->db)->getPayload()['data'];

		$this->assertSame(['list'], $data['layouts']);
		$this->assertArrayNotHasKey('tree', $data);
	}

	// ── rows ────────────────────────────────────────────────────────────────

	public function testRowsTreeLayoutReturnsWholeTreeDepthFirst(): void
	{
		$data = $this->rows('test.tree', ['layout' => 'tree']);

		$this->assertSame([1, 10, 101, 20, 201], array_column($data['rows'], 'id'));
		$this->assertSame([null, 1, 10, 1, 20], array_column($data['rows'], 'parent'));
		$this->assertSame([0, 1, 2, 1, 2], array_column($data['rows'], 'depth'));
		$this->assertSame([2, 1, 0, 1, 0], array_column($data['rows'], 'childCount'));
		$this->assertSame([true, true, true, true, true], array_column($data['rows'], 'match'));
		$this->assertFalse($data['hasMore']);
		$this->assertArrayNotHasKey('tooMany', $data);
		$this->assertArrayNotHasKey('footer', $data);
		$this->assertFalse(TreeStubViewer::$selectRowsCalled, 'strom nejde přes stránkované selectRows()');
	}

	public function testTreeRowKeepsCellsRowActionAndGetsDefaultIcon(): void
	{
		$rows = $this->rows('test.tree', ['layout' => 'tree'])['rows'];

		$this->assertSame(['name' => 'Budova A', 'code' => 'A'], $rows[0]['cells']);
		$this->assertSame('place-building', $rows[0]['icon'], 'ikona vieweru nepřepisuje ikonu řádku');
		$this->assertSame('addChild', $rows[0]['rowAction']['id']);
		$this->assertSame('table', $rows[1]['icon'], 'řádek bez ikony dostane ikonu vieweru');
		$this->assertArrayNotHasKey('rowAction', $rows[1]);
	}

	public function testSearchKeepsAncestorsAsContext(): void
	{
		$data = $this->rows('test.tree', ['layout' => 'tree', 'search' => '01', 'filter' => ['viewGroup' => 'archive']]);

		$this->assertSame([1, 10, 101, 20, 201], array_column($data['rows'], 'id'));
		$this->assertSame([false, false, true, false, true], array_column($data['rows'], 'match'));
		$this->assertSame(['01', [['id' => 'viewGroup', 'value' => 'archive']]], TreeStubViewer::$lastTreeSelect);
		$this->assertSame([[10, 20], [1]], TreeStubViewer::$idRequests);
	}

	public function testPageAndSortAreIgnoredInTreeLayout(): void
	{
		$data = $this->rows('test.tree', ['layout' => 'tree', 'page' => '3', 'sort' => 'name:desc']);

		$this->assertSame([1, 10, 101, 20, 201], array_column($data['rows'], 'id'));
		$this->assertFalse($data['hasMore']);
	}

	public function testMoreSelectedNodesThanTheCapReturnsTooManyAndNoRows(): void
	{
		TreeStubViewer::$maxNodes = 4;
		$data = $this->rows('test.tree', ['layout' => 'tree']);

		$this->assertSame([], $data['rows']);
		$this->assertFalse($data['hasMore']);
		$this->assertTrue($data['tooMany']);
		$this->assertSame([], TreeStubViewer::$idRequests, 'přes strop se strom neskládá');
	}

	public function testExactlyTheCapStillRendersTheTree(): void
	{
		TreeStubViewer::$maxNodes = 5;
		$data = $this->rows('test.tree', ['layout' => 'tree']);

		$this->assertCount(5, $data['rows']);
		$this->assertArrayNotHasKey('tooMany', $data);
	}

	public function testContextAncestorsDoNotCountTowardsTheCap(): void
	{
		// Hledání vybere 2 místnosti, kontext přidá 3 předky — strop 2 stačí.
		TreeStubViewer::$maxNodes = 2;
		$data = $this->rows('test.tree', ['layout' => 'tree', 'search' => '01']);

		$this->assertCount(5, $data['rows']);
		$this->assertArrayNotHasKey('tooMany', $data);
	}

	public function testListLayoutOfTreeViewerIsUnchanged(): void
	{
		$data = $this->rows('test.tree', []);

		$this->assertSame('Budova A', $data['rows'][0]['t1']);
		$this->assertArrayNotHasKey('depth', $data['rows'][0]);
		$this->assertTrue(TreeStubViewer::$selectRowsCalled);
	}

	public function testRowsTreeLayoutOnViewerWithoutTreeIsRejected(): void
	{
		$response = $this->rowsResponse('test.listOnly', ['layout' => 'tree']);

		$this->assertSame(400, $this->getStatus($response));
		$this->assertSame('LAYOUT_NOT_SUPPORTED', $response->getPayload()['error']['code']);
	}
}
