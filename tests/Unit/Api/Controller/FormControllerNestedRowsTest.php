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
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Form\FormRegistry;
use Shipard\Core\Form\Lookup\LookupRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Fixtures\Core\Form\StubSubtableParentForm;

/** Rodičovský form, který při vzniku záznamu pouští vnořené řádky `items`. */
class NestedRowsParentForm extends StubSubtableParentForm
{
	public function getNestedRowKeys(): array
	{
		return ['items'];
	}
}

/** Testovací subclass — podstrčí fake gateway (Dibi\Connection je final). */
class TestableNestedRowsFormController extends FormController
{
	public ?TableGateway $gateway = null;
	public ?string $builtFor = null;

	protected function buildGateway(
		string $table,
		TableDefinition $def,
		DataSourceConnection $db,
		DocumentRegistry $documentRegistry,
		?ConfigRuntime $config,
		?DataSourceConfig $dsConfig,
		?DocumentEventDispatcher $eventDispatcher,
	): TableGateway {
		$this->builtFor = $table;
		return $this->gateway ?? throw new \LogicException('gateway not set');
	}
}

/** Fake gateway — zaznamená ukládaná data a vrátí předpřipravený výsledek. */
class FakeSaveGateway extends TableGateway
{
	/** @var list<array<string, mixed>> */
	public array $saved = [];
	private DocumentResult $result;

	public static function create(DocumentResult $result): self
	{
		$instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
		$instance->result = $result;
		return $instance;
	}

	public function saveDocument(array $data): DocumentResult
	{
		$this->saved[] = $data;
		return $this->result;
	}
}

/**
 * Vnořené řádky sub-tabulek v těle `save` při vzniku záznamu (#103 D99,
 * docs/edit-forms.md kap. 15.7): jen klíče z `TableForm::getNestedRowKeys()`,
 * jen seznam objektů (jinak 400), jen pro nový záznam (PUT je ignoruje);
 * form bez whitelistu je nedostane. Zápis je věc Document třídy rodiče —
 * tady se ověřuje jen, co gateway dostane.
 */
final class FormControllerNestedRowsTest extends TestCase
{
	private TestableNestedRowsFormController $ctrl;

	protected function setUp(): void
	{
		$this->ctrl = new TestableNestedRowsFormController();
	}

	/** @return array<string, TableDefinition> */
	private function tables(): array
	{
		return [
			'parent_tbl' => TableDefinition::fromArray([
				'tableId' => 500,
				'name'    => 'Parent',
				'columns' => [
					['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
				],
			]),
			'child_tbl' => TableDefinition::fromArray([
				'tableId' => 501,
				'name'    => 'Child',
				'columns' => [
					['id' => 'id',     'name' => 'ID',     'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
					['id' => 'parent', 'name' => 'Parent', 'type' => 'int', 'reference' => 'parent_tbl'],
					['id' => 'name',   'name' => 'Name',   'type' => 'varchar', 'length' => 100],
				],
			]),
		];
	}

	private function registry(string $class = NestedRowsParentForm::class): FormRegistry
	{
		return new FormRegistry([['table' => 'parent_tbl', 'class' => $class]]);
	}

	private function db(): DataSourceConnection
	{
		$db = $this->createMock(DataSourceConnection::class);
		$db->method('fetchRow')->willReturn(['id' => 7, 'name' => 'Parent 7']);
		return $db;
	}

	private function save(?int $id, array $body, ?FormRegistry $registry = null): Response
	{
		$method = $id === null ? 'POST' : 'PUT';
		$path = '/_ui/form/parent_tbl/save' . ($id === null ? '' : '/' . $id);
		$request = Request::fromArray($method, $path, [], json_encode($body), ['Content-Type' => 'application/json']);
		return $this->ctrl->save(
			'parent_tbl', $id, $request, $this->tables(), $this->db(), null, $registry ?? $this->registry(),
			new ModulePathResolver([]), new LookupRegistry(), 'cs', null, null, new AuthContext(true, 1, 'session', null, true), null,
		);
	}

	private function httpStatus(Response $response): int
	{
		return (new \ReflectionClass($response))->getProperty('status')->getValue($response);
	}

	public function testNewRecordPassesWhitelistedRowsToTheGateway(): void
	{
		$gateway = FakeSaveGateway::create(DocumentResult::ok(['id' => 7]));
		$this->ctrl->gateway = $gateway;
		$rows = [['name' => 'Pilařská 7', 'country' => 'cz'], ['name' => 'Brno']];

		$res = $this->save(null, ['name' => 'Acme', 'items' => $rows, 'ordered' => [['name' => 'x']], 'bogus' => [['a' => 1]]]);

		$this->assertSame(201, $this->httpStatus($res), json_encode($res->getPayload()));
		$this->assertSame('parent_tbl', $this->ctrl->builtFor);
		$this->assertCount(1, $gateway->saved);
		$this->assertSame(['name' => 'Acme', 'items' => $rows], $gateway->saved[0], 'řádky whitelistu celé a nezměněné; ostatní klíče mimo sloupce pryč');
		$this->assertSame(7, $res->getPayload()['data']['id']);
	}

	public function testFormWithoutWhitelistAndUpdateDoNotGetRows(): void
	{
		$gateway = FakeSaveGateway::create(DocumentResult::ok(['id' => 7]));
		$this->ctrl->gateway = $gateway;

		$res = $this->save(null, ['name' => 'Acme', 'items' => [['name' => 'x']]], $this->registry(StubSubtableParentForm::class));
		$this->assertSame(201, $this->httpStatus($res));
		$this->assertSame(['name' => 'Acme'], $gateway->saved[0], 'bez getNestedRowKeys() se řádky zahodí jako každý neznámý klíč');

		$res = $this->save(7, ['name' => 'Acme 2', 'items' => [['name' => 'x']]]);
		$this->assertSame(200, $this->httpStatus($res));
		$this->assertSame(['name' => 'Acme 2', 'id' => 7], $gateway->saved[1], 'úprava záznamu vnořené řádky ignoruje');
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidRows(): iterable
	{
		yield 'řetězec' => ['x'];
		yield 'objekt místo seznamu' => [['name' => 'x']];
		yield 'seznam skalárů' => [[1, 2]];
		yield 'seznam s neobjektem' => [[['name' => 'x'], 'y']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('invalidRows')]
	public function testRowsMustBeAListOfObjects(mixed $items): void
	{
		$gateway = FakeSaveGateway::create(DocumentResult::ok(['id' => 7]));
		$this->ctrl->gateway = $gateway;

		$res = $this->save(null, ['name' => 'Acme', 'items' => $items]);

		$this->assertSame(400, $this->httpStatus($res));
		$this->assertSame('BAD_REQUEST', $res->getPayload()['error']['code']);
		$this->assertStringContainsString("'items'", $res->getPayload()['error']['message']);
		$this->assertSame([], $gateway->saved, 'nic se neukládá');
	}

	public function testNullRowsMeanNoRows(): void
	{
		$gateway = FakeSaveGateway::create(DocumentResult::ok(['id' => 7]));
		$this->ctrl->gateway = $gateway;

		$res = $this->save(null, ['name' => 'Acme', 'items' => null]);

		$this->assertSame(201, $this->httpStatus($res));
		$this->assertSame(['name' => 'Acme'], $gateway->saved[0]);
	}
}
