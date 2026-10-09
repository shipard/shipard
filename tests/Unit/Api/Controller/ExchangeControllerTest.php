<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ExchangeController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\User\UserApplier;
use Shipard\Module\Economy\Assets\Import\AssetDocLinkService;
use Shipard\Module\Economy\Assets\Import\AssetImportApplier;
use Shipard\Module\Economy\Assets\Import\AssetImportResult;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportResult;

class ExchangeControllerTest extends TestCase
{
    private function buildRequest(string $method, string $path, ?array $body = null): Request
    {
        return Request::fromArray(
            $method,
            $path,
            [],
            $body !== null ? (string) json_encode($body) : '',
            ['HTTP_HOST' => 'localhost'],
        );
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return $prop->getValue($response);
    }

    public function testValidateRejectsMissingJsonBody(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('validate');
        $controller = new ExchangeController($applier);

        $response = $controller->validate($this->buildRequest('POST', '/api/v1/_exchange/docs/document/validate'));

        $this->assertSame(400, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('schema_invalid', $payload['error']['code']);
    }

    public function testValidateDelegatesToApplierAndReturnsSuccess(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('validate')
            ->willReturn(ApplyResult::ok(['format' => 'shpd.docs.document', 'enriched' => true]));
        $controller = new ExchangeController($applier);

        $response = $controller->validate($this->buildRequest('POST', '/api/v1/_exchange/docs/document/validate', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['data']['canonical']['enriched']);
    }

    public function testValidatePropagatesErrorStatus(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('validate')->willReturn(
            ApplyResult::error('validation_failed', 'Validace selhala', ['_resolve' => ['issues' => []]], statusCode: 422),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->validate($this->buildRequest('POST', '/api/v1/_exchange/docs/document/validate', [
            'docType' => 'invoiceReceived',
        ]));

        $this->assertSame(422, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertSame('validation_failed', $payload['error']['code']);
        $this->assertArrayHasKey('details', $payload['error']);
    }

    public function testPreviewDelegatesToApplier(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('preview')
            ->willReturn(ApplyResult::ok(['_resolve' => ['summary' => ['status' => 'ok']]]));
        $controller = new ExchangeController($applier);

        $response = $controller->preview($this->buildRequest('POST', '/api/v1/_exchange/docs/document/preview', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(200, $this->getStatus($response));
    }

    public function testApplyReturnsSavedDocId(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::ok(['savedDocId' => 123], savedId: 123),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->apply($this->buildRequest('POST', '/api/v1/_exchange/docs/document/apply', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertSame(123, $payload['data']['savedDocId']);
    }

    public function testApplyConflictReturns409(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('conflict', 'Target gone', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->apply($this->buildRequest('POST', '/api/v1/_exchange/docs/document/apply', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('conflict', $response->getPayload()['error']['code']);
    }

    public function testApplyUnresolvedReturns422(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturn(
            ApplyResult::error('unresolved_required', 'Doplň userAction', ['_resolve' => []], statusCode: 422),
        );
        $controller = new ExchangeController($applier);

        $response = $controller->apply($this->buildRequest('POST', '/api/v1/_exchange/docs/document/apply', [
            'format' => 'shpd.docs.document',
        ]));

        $this->assertSame(422, $this->getStatus($response));
        $this->assertSame('unresolved_required', $response->getPayload()['error']['code']);
    }

    // ── Person flow ────────────────────────────────────────────────────

    public function testValidatePersonReturns500WhenPersonApplierUnconfigured(): void
    {
        $applier = $this->createMock(DocumentApplier::class);
        // No PersonApplier injected — call must fail with 500.
        $controller = new ExchangeController($applier);

        $response = $controller->validatePerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/validate', ['format' => 'shpd.persons.person'],
        ));

        $this->assertSame(500, $this->getStatus($response));
        $this->assertSame('INTERNAL_ERROR', $response->getPayload()['error']['code']);
    }

    public function testValidatePersonDelegatesToPersonApplier(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $docApplier->expects($this->never())->method('validate');

        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->expects($this->once())
            ->method('validate')
            ->willReturn(ApplyResult::ok(['format' => 'shpd.persons.person', 'enriched' => true]));

        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->validatePerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/validate',
            ['format' => 'shpd.persons.person', 'personType' => 'company'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('canonical', $payload['data']);
    }

    public function testApplyPersonResponseUsesSavedPersonIdKey(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->method('apply')->willReturn(
            ApplyResult::ok(['savedPersonId' => 42], savedId: 42),
        );
        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->applyPerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/apply',
            ['format' => 'shpd.persons.person', 'personType' => 'company', 'country' => 'cz'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(42, $data['savedPersonId']);
        $this->assertArrayNotHasKey('savedDocId', $data, 'Person flow must not leak doc-flow key name');
    }

    public function testApplyPersonExistsReturns409(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->method('apply')->willReturn(
            ApplyResult::error('person_exists', 'Osoba existuje', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->applyPerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/apply',
            ['format' => 'shpd.persons.person'],
        ));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('person_exists', $response->getPayload()['error']['code']);
    }

    public function testApplyPersonRejectsMissingJsonBody(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $personApplier = $this->createMock(PersonApplier::class);
        $personApplier->expects($this->never())->method('apply');
        $controller = new ExchangeController($docApplier, $personApplier);

        $response = $controller->applyPerson($this->buildRequest(
            'POST', '/api/v1/_exchange/persons/person/apply',
        ));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('schema_invalid', $response->getPayload()['error']['code']);
    }

    // ── Item flow ──────────────────────────────────────────────────────

    public function testValidateItemReturns500WhenItemApplierUnconfigured(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        // No ItemApplier injected — call must fail with 500.
        $controller = new ExchangeController($docApplier);

        $response = $controller->validateItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/validate', ['format' => 'shpd.items.item'],
        ));

        $this->assertSame(500, $this->getStatus($response));
        $this->assertSame('INTERNAL_ERROR', $response->getPayload()['error']['code']);
    }

    public function testValidateItemDelegatesToItemApplier(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $docApplier->expects($this->never())->method('validate');

        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->expects($this->once())
            ->method('validate')
            ->willReturn(ApplyResult::ok(['format' => 'shpd.items.item', 'enriched' => true]));

        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->validateItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/validate',
            ['format' => 'shpd.items.item', 'name' => 'Konzultace IT', 'unit' => 'h'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertArrayHasKey('canonical', $payload['data']);
    }

    public function testPreviewItemDelegatesToItemApplier(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->expects($this->once())
            ->method('preview')
            ->willReturn(ApplyResult::ok(['_resolve' => ['summary' => ['status' => 'ok']]]));
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->previewItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/preview',
            ['format' => 'shpd.items.item', 'name' => 'X', 'unit' => 'h'],
        ));

        $this->assertSame(200, $this->getStatus($response));
    }

    public function testApplyItemResponseUsesSavedItemIdKey(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->method('apply')->willReturn(
            ApplyResult::ok(['savedItemId' => 42], savedId: 42),
        );
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
            ['format' => 'shpd.items.item', 'name' => 'Konzultace IT', 'unit' => 'h'],
        ));

        $this->assertSame(200, $this->getStatus($response));
        $data = $response->getPayload()['data'];
        $this->assertSame(42, $data['savedItemId']);
        $this->assertArrayNotHasKey('savedDocId', $data, 'Item flow must not leak doc-flow key name');
        $this->assertArrayNotHasKey('savedPersonId', $data, 'Item flow must not leak person-flow key name');
    }

    public function testApplyItemItemExistsReturns409(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->method('apply')->willReturn(
            ApplyResult::error('item_exists', 'Položka existuje', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
            ['format' => 'shpd.items.item'],
        ));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('item_exists', $response->getPayload()['error']['code']);
    }

    public function testApplyItemCodeConflictReturns409(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->method('apply')->willReturn(
            ApplyResult::error('code_conflict', 'Kód kolikuje', ['_resolve' => []], statusCode: 409),
        );
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
            ['format' => 'shpd.items.item'],
        ));

        $this->assertSame(409, $this->getStatus($response));
        $this->assertSame('code_conflict', $response->getPayload()['error']['code']);
    }

    public function testApplyItemRejectsMissingJsonBody(): void
    {
        $docApplier = $this->createMock(DocumentApplier::class);
        $itemApplier = $this->createMock(ItemApplier::class);
        $itemApplier->expects($this->never())->method('apply');
        $controller = new ExchangeController($docApplier, null, $itemApplier);

        $response = $controller->applyItem($this->buildRequest(
            'POST', '/api/v1/_exchange/items/item/apply',
        ));

        $this->assertSame(400, $this->getStatus($response));
        $this->assertSame('schema_invalid', $response->getPayload()['error']['code']);
    }

    // ── Import uživatelů (#93 D13) ──────────────────────────────────────

    private const USER_PAYLOAD = ['format' => 'shpd.system.user.v1', 'login' => 'jana@example.test', 'fullName' => 'Jana Příkladová'];

    private function userController(?UserApplier $applier): ExchangeController
    {
        return new ExchangeController($this->createMock(DocumentApplier::class), userApplier: $applier);
    }

    private function userRequest(?array $body = self::USER_PAYLOAD): Request
    {
        return $this->buildRequest('POST', '/api/v1/_exchange/users/user/apply', $body);
    }

    public function testUserImportRequiresAdminOrApiKey(): void
    {
        $applier = $this->createMock(UserApplier::class);
        $applier->expects($this->never())->method('apply');
        $applier->expects($this->never())->method('validate');
        $controller = $this->userController($applier);

        $anonymous = $controller->applyUser($this->userRequest(), AuthContext::anonymous());
        $this->assertSame(401, $this->getStatus($anonymous));

        $plainUser = new AuthContext(true, 5, 'session', 'shpd_st_x', isAdmin: false);
        $this->assertSame(403, $this->getStatus($controller->applyUser($this->userRequest(), $plainUser)));
        $this->assertSame(403, $this->getStatus($controller->validateUser($this->userRequest(), $plainUser)));
    }

    public function testUserImportAnswersUserIdAndCreatedFlag(): void
    {
        $applier = $this->createMock(UserApplier::class);
        $applier->method('apply')->willReturnOnConsecutiveCalls(
            ApplyResult::ok(self::USER_PAYLOAD, 7, 201),
            ApplyResult::ok(self::USER_PAYLOAD, 7, 200),
        );
        $controller = $this->userController($applier);

        // API klíč (není admin) i admin v session smí.
        $created = $controller->applyUser($this->userRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));
        $this->assertSame(201, $this->getStatus($created));
        $this->assertSame(['userId' => 7, 'created' => true], $created->getPayload()['data']);

        $existing = $controller->applyUser($this->userRequest(), new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true));
        $this->assertSame(200, $this->getStatus($existing));
        $this->assertSame(['userId' => 7, 'created' => false], $existing->getPayload()['data']);
    }

    public function testUserValidateReportsMatchWithoutCreating(): void
    {
        $applier = $this->createMock(UserApplier::class);
        $applier->expects($this->never())->method('apply');
        $applier->method('validate')->willReturn(ApplyResult::ok(self::USER_PAYLOAD, null));

        $response = $this->userController($applier)
            ->validateUser($this->userRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));

        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame(['userId' => null, 'created' => false], $response->getPayload()['data']);
    }

    public function testUserImportErrorKeepsSharedErrorShape(): void
    {
        $applier = $this->createMock(UserApplier::class);
        $applier->method('apply')->willReturn(ApplyResult::error(
            'validation_failed', 'Validace uživatele selhala.', ['_resolve' => ['issues' => []]], statusCode: 422,
        ));

        $response = $this->userController($applier)
            ->applyUser($this->userRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));

        $this->assertSame(422, $this->getStatus($response));
        $this->assertSame('validation_failed', $response->getPayload()['error']['code']);
    }

    // ── Asset import flow (#83 fáze 6) ─────────────────────────────────

    private const ASSET_PAYLOAD = ['format' => 'shpd.assets.asset.v1', 'asset' => ['assetNumber' => 'MA0001'], 'events' => []];

    private function assetController(?AssetImportApplier $applier): ExchangeController
    {
        return new ExchangeController($this->createMock(DocumentApplier::class), assetApplier: $applier);
    }

    private function assetRequest(?array $body = self::ASSET_PAYLOAD): Request
    {
        return $this->buildRequest('POST', '/api/v1/_exchange/assets/asset/apply', $body);
    }

    public function testAssetImportRequiresAdminOrApiKey(): void
    {
        $applier = $this->createMock(AssetImportApplier::class);
        $applier->expects($this->never())->method('apply');
        $applier->expects($this->never())->method('validate');
        $controller = $this->assetController($applier);

        $this->assertSame(401, $this->getStatus($controller->applyAsset($this->assetRequest(), AuthContext::anonymous())));
        $plainUser = new AuthContext(true, 5, 'session', 'shpd_st_x', isAdmin: false);
        $this->assertSame(403, $this->getStatus($controller->applyAsset($this->assetRequest(), $plainUser)));
        $this->assertSame(403, $this->getStatus($controller->validateAsset($this->assetRequest(), $plainUser)));
    }

    public function testAssetImportAnswersStatusIdAndWarnings(): void
    {
        $applier = $this->createMock(AssetImportApplier::class);
        $applier->method('apply')->willReturn(AssetImportResult::ok('created', 31, [['code' => 'plan_error', 'message' => 'x']], 201));
        $applier->method('validate')->willReturn(AssetImportResult::ok('updated', 31, [], 200));
        $controller = $this->assetController($applier);

        $created = $controller->applyAsset($this->assetRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));
        $this->assertSame(201, $this->getStatus($created));
        $this->assertSame(
            ['status' => 'created', 'assetId' => 31, 'warnings' => [['code' => 'plan_error', 'message' => 'x']]],
            $created->getPayload()['data'],
        );

        $validated = $controller->validateAsset($this->assetRequest(), new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true));
        $this->assertSame(200, $this->getStatus($validated));
        $this->assertSame('updated', $validated->getPayload()['data']['status']);
    }

    public function testAssetImportErrorCarriesIssues(): void
    {
        $issues = [['severity' => 'error', 'path' => 'events.2.amount', 'code' => 'aboveResidual', 'message' => 'm', 'sourceRef' => 'deps:9']];
        $applier = $this->createMock(AssetImportApplier::class);
        $applier->method('apply')->willReturn(AssetImportResult::error('validation_failed', 'Validace události selhala.', $issues, 422));

        $response = $this->assetController($applier)
            ->applyAsset($this->assetRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));

        $this->assertSame(422, $this->getStatus($response));
        $this->assertSame('validation_failed', $response->getPayload()['error']['code']);
        $this->assertSame($issues, $response->getPayload()['error']['details']['issues']);
    }

    public function testAssetImportRejectsMissingBodyAndUnwiredFlow(): void
    {
        $apiKey = new AuthContext(true, 2, 'api_key', 'shpd_ak_x');
        $applier = $this->createMock(AssetImportApplier::class);
        $applier->expects($this->never())->method('apply');

        $this->assertSame(400, $this->getStatus($this->assetController($applier)->applyAsset($this->assetRequest(null), $apiKey)));
        $this->assertSame(500, $this->getStatus($this->assetController(null)->applyAsset($this->assetRequest(), $apiKey)));
    }

    // ── Work order import flow (#110 D25, D26) ─────────────────────────

    private const WORK_ORDER_PAYLOAD = ['format' => 'shpd.workOrders.workOrder.v1', 'workOrder' => ['numberSeries' => 3, 'title' => 'x', 'state' => 'confirmed']];

    private function workOrderController(?WorkOrderImportApplier $applier): ExchangeController
    {
        return new ExchangeController($this->createMock(DocumentApplier::class), workOrderApplier: $applier);
    }

    private function workOrderRequest(?array $body = self::WORK_ORDER_PAYLOAD): Request
    {
        return $this->buildRequest('POST', '/api/v1/_exchange/workOrders/workOrder/apply', $body);
    }

    public function testWorkOrderImportRequiresAdminOrApiKey(): void
    {
        $applier = $this->createMock(WorkOrderImportApplier::class);
        $applier->expects($this->never())->method('apply');
        $applier->expects($this->never())->method('validate');
        $controller = $this->workOrderController($applier);

        $this->assertSame(401, $this->getStatus($controller->applyWorkOrder($this->workOrderRequest(), AuthContext::anonymous())));
        $plainUser = new AuthContext(true, 5, 'session', 'shpd_st_x', isAdmin: false);
        $this->assertSame(403, $this->getStatus($controller->applyWorkOrder($this->workOrderRequest(), $plainUser)));
        $this->assertSame(403, $this->getStatus($controller->validateWorkOrder($this->workOrderRequest(), $plainUser)));
    }

    public function testWorkOrderImportAnswersStatusIdNumberAndWarnings(): void
    {
        $applier = $this->createMock(WorkOrderImportApplier::class);
        $applier->method('apply')->willReturn(WorkOrderImportResult::ok('created', 31, 'S260001', [['code' => 'counter_not_synced', 'message' => 'x', 'path' => 'workOrder.sequenceNumber']], 201));
        $applier->method('validate')->willReturn(WorkOrderImportResult::ok('skipped', 31, 'S260001', [], 200));
        $controller = $this->workOrderController($applier);

        $created = $controller->applyWorkOrder($this->workOrderRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));
        $this->assertSame(201, $this->getStatus($created));
        $this->assertSame(
            ['status' => 'created', 'workOrderId' => 31, 'number' => 'S260001', 'warnings' => [['code' => 'counter_not_synced', 'message' => 'x', 'path' => 'workOrder.sequenceNumber']]],
            $created->getPayload()['data'],
        );

        $validated = $controller->validateWorkOrder($this->workOrderRequest(), new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true));
        $this->assertSame(200, $this->getStatus($validated));
        $this->assertSame('skipped', $validated->getPayload()['data']['status']);
    }

    public function testWorkOrderImportErrorCarriesIssues(): void
    {
        $issues = [['severity' => 'error', 'path' => 'rows.2.vatCode', 'code' => 'vat_code_unknown', 'message' => 'm']];
        $applier = $this->createMock(WorkOrderImportApplier::class);
        $applier->method('apply')->willReturn(WorkOrderImportResult::error('validation_failed', 'Validace zakázky selhala.', $issues, 422));

        $response = $this->workOrderController($applier)
            ->applyWorkOrder($this->workOrderRequest(), new AuthContext(true, 2, 'api_key', 'shpd_ak_x'));

        $this->assertSame(422, $this->getStatus($response));
        $this->assertSame('validation_failed', $response->getPayload()['error']['code']);
        $this->assertSame($issues, $response->getPayload()['error']['details']['issues']);
    }

    public function testWorkOrderImportRejectsMissingBodyAndUnwiredFlow(): void
    {
        $apiKey = new AuthContext(true, 2, 'api_key', 'shpd_ak_x');
        $applier = $this->createMock(WorkOrderImportApplier::class);
        $applier->expects($this->never())->method('apply');

        $this->assertSame(400, $this->getStatus($this->workOrderController($applier)->applyWorkOrder($this->workOrderRequest(null), $apiKey)));
        $this->assertSame(500, $this->getStatus($this->workOrderController(null)->applyWorkOrder($this->workOrderRequest(), $apiKey)));
    }

    public function testAssetDocLinksAnswerMatchingStatusAndShapeErrors(): void
    {
        $apiKey = new AuthContext(true, 2, 'api_key', 'shpd_ak_x');
        $service = $this->createMock(AssetDocLinkService::class);
        $service->method('apply')->willReturnOnConsecutiveCalls(
            ['status' => 'ambiguous', 'docId' => 4711, 'rows' => [['index' => 0, 'sourceRef' => 'row:1', 'rowId' => null, 'status' => 'ambiguous']]],
            ['status' => 'invalid', 'docId' => 0, 'rows' => [], 'issues' => [['severity' => 'error', 'path' => 'docId', 'code' => 'required', 'message' => 'm']]],
        );
        $controller = new ExchangeController($this->createMock(DocumentApplier::class), assetDocLinks: $service);
        $request = $this->buildRequest('POST', '/api/v1/_exchange/assets/doc-links/apply', ['docId' => 4711, 'rows' => []]);

        // Stav párování je obsah odpovědi (200), ne HTTP chyba.
        $response = $controller->applyAssetDocLinks($request, $apiKey);
        $this->assertSame(200, $this->getStatus($response));
        $this->assertSame('ambiguous', $response->getPayload()['data']['status']);

        $invalid = $controller->applyAssetDocLinks($request, $apiKey);
        $this->assertSame(400, $this->getStatus($invalid));
        $this->assertSame('docId', $invalid->getPayload()['error']['details']['issues'][0]['path']);

        // Oprávnění a nezapojená služba.
        $this->assertSame(403, $this->getStatus($controller->applyAssetDocLinks($request, new AuthContext(true, 5, 'session', 'x'))));
        $this->assertSame(500, $this->getStatus(
            (new ExchangeController($this->createMock(DocumentApplier::class)))->applyAssetDocLinks($request, $apiKey),
        ));
    }

    public function testUserImportRejectsMissingBodyAndUnwiredFlow(): void
    {
        $apiKey = new AuthContext(true, 2, 'api_key', 'shpd_ak_x');

        $noBody = $this->userController($this->createMock(UserApplier::class))->applyUser($this->userRequest(null), $apiKey);
        $this->assertSame(400, $this->getStatus($noBody));

        $unwired = $this->userController(null)->applyUser($this->userRequest(), $apiKey);
        $this->assertSame(500, $this->getStatus($unwired));
    }
}
