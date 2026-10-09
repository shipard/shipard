<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\FormController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentEventDispatcher;
use Shipard\Core\Document\DocumentLockRegistry;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Form\FormRegistry;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;
use Shipard\Tests\Fixtures\Core\Form\StubSubtableParentForm;

/**
 * POST /_ui/form/{table}/subtable/{tabId}/{parentId}/delete — mazání řádku
 * sub-tabulky přes TableGateway dětské tabulky (#113 bod 2): happy path
 * volá gateway, 422 read-only rodič, 405 systemManaged, 404 cizí řádek,
 * 422 zámek z gateway, 500 jiná chyba gateway, 400 špatné tělo.
 */
class FormControllerSubtableDeleteTest extends TestCase
{
	private TestableSubtableDeleteFormController $ctrl;

	protected function setUp(): void
	{
		$this->ctrl = new TestableSubtableDeleteFormController();
	}

	private function tables(bool $parentDocStates = false, bool $childSystemManaged = false): array
	{
		$parent = [
			'tableId' => 500,
			'name'    => 'Parent',
			'columns' => [
				['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
				['id' => 'docState', 'name' => 'State', 'type' => 'tinyint', 'default' => 10, 'system' => true],
				['id' => 'docStateMain', 'name' => 'State main', 'type' => 'tinyint', 'default' => 1, 'system' => true],
			],
		];
		if ($parentDocStates) {
			$parent['docStates'] = ['stateColumn' => 'docState', 'mainColumn' => 'docStateMain', 'cfgItem' => 'test.states'];
		}
		$child = [
			'tableId' => 501,
			'name'    => 'Child',
			'columns' => [
				['id' => 'id',        'name' => 'ID',     'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
				['id' => 'parent',    'name' => 'Parent', 'type' => 'int', 'reference' => 'parent_tbl'],
				['id' => 'name',      'name' => 'Name',   'type' => 'varchar', 'length' => 100],
				['id' => 'order_pos', 'name' => 'Order',  'type' => 'smallint', 'default' => 0],
			],
		];
		if ($childSystemManaged) {
			$child['systemManaged'] = true;
		}
		return [
			'parent_tbl' => TableDefinition::fromArray($parent),
			'child_tbl'  => TableDefinition::fromArray($child),
		];
	}

	private function registry(): FormRegistry
	{
		return new FormRegistry([
			['table' => 'parent_tbl', 'class' => StubSubtableParentForm::class],
		]);
	}

	/**
	 * `fetchRow` obsluhuje dva dotazy: rodiče (`SELECT * FROM parent_tbl`)
	 * a příslušnost řádku (`SELECT id FROM child_tbl WHERE id … AND parent …`).
	 *
	 * @param list<array{0: string, 1: array}> $log zachycené dotazy na dítě
	 */
	private function db(?array $parentRow, ?array $childRow, array &$log): DataSourceConnection
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchRow')->willReturnCallback(
			static function (mixed ...$args) use ($parentRow, $childRow, &$log): ?array {
				$sql = (string) $args[0];
				if (str_starts_with($sql, 'SELECT * FROM `parent_tbl`')) {
					return $parentRow;
				}
				$log[] = ['child', $args];
				return $childRow;
			},
		);
		return $db;
	}

	private function req(array $body): Request
	{
		return Request::fromArray('POST', '/_ui/form/parent_tbl/subtable/items/5/delete', [], json_encode($body), ['Content-Type' => 'application/json']);
	}

	private function httpStatus(Response $response): int
	{
		$ref = new \ReflectionClass($response);
		return $ref->getProperty('status')->getValue($response);
	}

	private function admin(): AuthContext
	{
		return new AuthContext(true, 1, 'session', null, true);
	}

	private function readOnlyConfig(): ConfigRuntime
	{
		return ConfigRuntimeFactory::fromItems([
			'test.states' => ['10' => ['stateStyle' => 'concept'], '40' => ['stateStyle' => 'done', 'readOnly' => 1]],
		]);
	}

	// ── Happy path ───────────────────────────────────────────────────────────

	public function testDeletesRowThroughChildGateway(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], ['id' => 10], $log);
		$gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10, 'parent' => 5]));
		$this->ctrl->gateway = $gateway;

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 10]), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(200, $this->httpStatus($res));
		$this->assertSame(['success' => true, 'data' => null], $res->getPayload());
		$this->assertSame([10], $gateway->deleted);
		$this->assertSame('child_tbl', $this->ctrl->builtFor);
		$this->assertCount(1, $log);
		$this->assertStringContainsString('SELECT `id` FROM `child_tbl` WHERE `id` = %i AND `parent` = %i', $log[0][1][0]);
		$this->assertSame([10, 5], [$log[0][1][1], $log[0][1][2]]);
	}

	public function testEditableParentWithDocStatesPasses(): void
	{
		$log = [];
		$db = $this->db(['id' => 5, 'docState' => 10], ['id' => 10], $log);
		$gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10]));
		$this->ctrl->gateway = $gateway;

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 10]), $this->tables(parentDocStates: true), $db, $this->registry(), $this->readOnlyConfig(), null, null, null, $this->admin());

		$this->assertSame(200, $this->httpStatus($res));
		$this->assertSame([10], $gateway->deleted);
	}

	// ── Errors ───────────────────────────────────────────────────────────────

	public function testReadOnlyParentIs422WithoutTouchingChild(): void
	{
		$log = [];
		$db = $this->db(['id' => 5, 'docState' => 40], ['id' => 10], $log);
		$gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10]));
		$this->ctrl->gateway = $gateway;

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 10]), $this->tables(parentDocStates: true), $db, $this->registry(), $this->readOnlyConfig(), null, null, null, $this->admin());

		$this->assertSame(422, $this->httpStatus($res));
		$this->assertSame('DOCUMENT_READONLY', $res->getPayload()['error']['code']);
		$this->assertSame('Document is read-only in state 40.', $res->getPayload()['error']['message']);
		$this->assertSame([], $log);
		$this->assertSame([], $gateway->deleted);
	}

	public function testSystemManagedChildIs405(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], ['id' => 10], $log);
		$gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10]));
		$this->ctrl->gateway = $gateway;

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 10]), $this->tables(childSystemManaged: true), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(405, $this->httpStatus($res));
		$this->assertSame('TABLE_SYSTEM_MANAGED', $res->getPayload()['error']['code']);
		$this->assertSame([], $log);
		$this->assertSame([], $gateway->deleted);
	}

	public function testForeignRowIs404WithoutGateway(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], null, $log);
		$gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 99]));
		$this->ctrl->gateway = $gateway;

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 99]), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('RECORD_NOT_FOUND', $res->getPayload()['error']['code']);
		$this->assertCount(1, $log);
		$this->assertSame([], $gateway->deleted);
	}

	public function testLockedRowFromGatewayIs422DocumentLocked(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], ['id' => 10], $log);
		$this->ctrl->gateway = FakeChildGateway::create(
			DocumentResult::domainError('Work order is confirmed', DocumentLockRegistry::DOMAIN_CODE),
		);

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 10]), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(422, $this->httpStatus($res));
		$this->assertSame('DOCUMENT_LOCKED', $res->getPayload()['error']['code']);
		$this->assertSame('Work order is confirmed', $res->getPayload()['error']['message']);
	}

	public function testGatewayFailureIs500(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], ['id' => 10], $log);
		$this->ctrl->gateway = FakeChildGateway::create(DocumentResult::error('boom'));

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req(['id' => 10]), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(500, $this->httpStatus($res));
		$this->assertSame('INTERNAL_ERROR', $res->getPayload()['error']['code']);
		$this->assertSame('boom', $res->getPayload()['error']['message']);
	}

	/** @return list<array{array}> */
	public static function invalidBodies(): array
	{
		return [[[]], [['id' => 0]], [['id' => -3]], [['id' => 'abc']], [['id' => null]]];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidBodies')]
	public function testInvalidBodyIs400(array $body): void
	{
		$log = [];
		$db = $this->db(['id' => 5], ['id' => 10], $log);
		$gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10]));
		$this->ctrl->gateway = $gateway;

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 5, $this->req($body), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(400, $this->httpStatus($res));
		$this->assertSame('BAD_REQUEST', $res->getPayload()['error']['code']);
		$this->assertSame([], $log);
		$this->assertSame([], $gateway->deleted);
	}

	public function testMissingParentIs404(): void
	{
		$log = [];
		$db = $this->db(null, ['id' => 10], $log);
		$this->ctrl->gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10]));

		$res = $this->ctrl->subtableDelete('parent_tbl', 'items', 99, $this->req(['id' => 10]), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('RECORD_NOT_FOUND', $res->getPayload()['error']['code']);
		$this->assertSame([], $log);
	}

	public function testUnknownTabIs404(): void
	{
		$log = [];
		$db = $this->db(['id' => 5], ['id' => 10], $log);
		$this->ctrl->gateway = FakeChildGateway::create(DocumentResult::ok(['id' => 10]));

		$res = $this->ctrl->subtableDelete('parent_tbl', 'nope', 5, $this->req(['id' => 10]), $this->tables(), $db, $this->registry(), null, null, null, null, $this->admin());

		$this->assertSame(404, $this->httpStatus($res));
		$this->assertSame('SUBTABLE_NOT_FOUND', $res->getPayload()['error']['code']);
	}
}

/**
 * Testovací subclass — podstrčí fake gateway (Dibi\Connection je final,
 * reálný TableGateway nelze v unit testu postavit) a zapamatuje si, pro
 * kterou tabulku byla gateway žádána.
 */
class TestableSubtableDeleteFormController extends FormController
{
	public ?TableGateway $gateway = null;
	public ?string $builtFor = null;

	protected function buildChildGateway(
		string $childTable,
		TableDefinition $childDef,
		DataSourceConnection $db,
		DocumentRegistry $documentRegistry,
		?ConfigRuntime $config,
		?DataSourceConfig $dsConfig,
		?DocumentEventDispatcher $eventDispatcher,
	): TableGateway {
		$this->builtFor = $childTable;
		return $this->gateway ?? throw new \LogicException('gateway not set');
	}
}

/** Fake gateway — zaznamená mazaná id a vrátí předpřipravený výsledek. */
class FakeChildGateway extends TableGateway
{
	/** @var list<int> */
	public array $deleted = [];
	private DocumentResult $result;

	public static function create(DocumentResult $result): self
	{
		$instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$instance->result = $result;
		return $instance;
	}

	public function deleteDocument(int $id): DocumentResult
	{
		$this->deleted[] = $id;
		return $this->result;
	}
}
