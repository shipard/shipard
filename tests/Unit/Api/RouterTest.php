<?php
declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;

class RouterTest extends TestCase
{
	private Router $router;

	protected function setUp(): void
	{
		$this->router = new Router();
	}

	private function assertRoute(Route $route, string $controller, string $action, ?string $table = null, ?int $id = null): void
	{
		$this->assertSame($controller, $route->controller);
		$this->assertSame($action, $route->action);
		$this->assertSame($table, $route->table);
		$this->assertSame($id, $route->id);
	}

	// Meta endpoints
	public function testMetaTables(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'meta', 'tables');
	}

	public function testMetaTablesSingle(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables/core_system_users', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'meta', 'table', 'core_system_users');
	}

	// Section badges (UI shells Fáze 3)
	public function testSectionBadgesGet(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/section-badges', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'dashboard', 'sectionBadges');
	}

	public function testSectionBadgesPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/section-badges', 'POST');
		$this->assertInstanceOf(Response::class, $result);
	}

	// Panel „O zdroji dat" (Issue #41)
	public function testDsAboutGet(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/ds-about', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'dsAbout', 'about');
	}

	public function testDsAboutPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/ds-about', 'POST');
		$this->assertInstanceOf(Response::class, $result);
	}

	// Settings pages
	public function testSettingsPageGet(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/settings/page/appSettings', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'settings', 'page', 'appSettings');
	}

	public function testSettingsPageSave(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/settings/page/appSettings', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'settings', 'savePage', 'appSettings');
	}

	public function testSettingsPageDeleteNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/settings/page/appSettings', 'DELETE');
		$this->assertInstanceOf(Response::class, $result);
	}

	public function testSettingsPageEmptyIdNotFound(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/settings/page/', 'GET');
		$this->assertInstanceOf(Response::class, $result);
	}

	// App info + branding
	public function testAppInfo(): void
	{
		$result = $this->router->resolve('/api/v1/_app/info', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'app', 'info');
	}

	public function testAppInfoPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_app/info', 'POST');
		$this->assertInstanceOf(Response::class, $result);
	}

	public function testAppManifest(): void
	{
		$result = $this->router->resolve('/api/v1/_app/manifest', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'app', 'manifest');
	}

	public function testAppManifestPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_app/manifest', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAppBrandingActionsPerMethod(): void
	{
		$get = $this->router->resolve('/api/v1/_app/branding/icon', 'GET');
		$this->assertInstanceOf(Route::class, $get);
		$this->assertRoute($get, 'app', 'brandingGet', 'icon');

		$post = $this->router->resolve('/api/v1/_app/branding/companyLogo', 'POST');
		$this->assertInstanceOf(Route::class, $post);
		$this->assertRoute($post, 'app', 'brandingUpload', 'companyLogo');

		$delete = $this->router->resolve('/api/v1/_app/branding/icon', 'DELETE');
		$this->assertInstanceOf(Route::class, $delete);
		$this->assertRoute($delete, 'app', 'brandingDelete', 'icon');
	}

	public function testAppBrandingNestedPathNotFound(): void
	{
		$result = $this->router->resolve('/api/v1/_app/branding/icon/extra', 'GET');
		$this->assertInstanceOf(Response::class, $result);
	}

	// OpenAPI
	public function testOpenApiSpec(): void
	{
		$result = $this->router->resolve('/api/v1/_openapi.json', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'openapi', 'spec');
	}

	// Auth endpoints
	public function testAuthLogin(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/login', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'login');
	}

	public function testAuthRefresh(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/refresh', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'refresh');
	}

	public function testAuthLogout(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/logout', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'logout');
	}

	public function testOidcStart(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/oidc/start', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'oidcStart');
	}

	public function testOidcCallback(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/oidc/callback', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'oidcCallback');
	}

	public function testOidcExchange(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/oidc/exchange', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'auth', 'oidcExchange');
	}

	public function testMethodNotAllowedOnOidcRoutes(): void
	{
		foreach ([['/_auth/oidc/start', 'POST'], ['/_auth/oidc/callback', 'POST'], ['/_auth/oidc/exchange', 'GET']] as [$path, $method]) {
			$result = $this->router->resolve('/api/v1' . $path, $method);
			$this->assertInstanceOf(Response::class, $result);
			$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
		}
	}

	// CRUD list & create
	public function testCrudList(): void
	{
		$result = $this->router->resolve('/api/v1/economy_docs_heads', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'list', 'economy_docs_heads');
	}

	public function testCrudCreate(): void
	{
		$result = $this->router->resolve('/api/v1/economy_docs_heads', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'create', 'economy_docs_heads');
	}

	// CRUD with id
	public function testCrudShow(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/42', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'show', 'core_system_users', 42);
	}

	public function testCrudUpdate(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'PUT');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'update', 'core_system_users', 5);
	}

	public function testCrudPatch(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'PATCH');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'patch', 'core_system_users', 5);
	}

	public function testCrudDelete(): void
	{
		$result = $this->router->resolve('/api/v1/core_system_users/5', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'delete', 'core_system_users', 5);
	}

	// Special endpoints not treated as generic table
	public function testMetaNotTreatedAsTable(): void
	{
		$result = $this->router->resolve('/api/v1/_meta/tables', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('meta', $result->controller);
	}

	// Error cases
	public function testUnknownPath(): void
	{
		$result = $this->router->resolve('/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertFalse($payload['success']);
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
	}

	public function testMethodNotAllowedOnAuthLogin(): void
	{
		$result = $this->router->resolve('/api/v1/_auth/login', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertSame('METHOD_NOT_ALLOWED', $payload['error']['code']);
	}

	public function testNonNumericIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/users/abc', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$payload = $result->getPayload();
		$this->assertSame('NOT_FOUND', $payload['error']['code']);
	}

	public function testZeroIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/users/0', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testMethodNotAllowedOnCrudList(): void
	{
		$result = $this->router->resolve('/api/v1/some_table', 'DELETE');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testMethodNotAllowedOnOpenApi(): void
	{
		$result = $this->router->resolve('/api/v1/_openapi.json', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testTableNamePreserved(): void
	{
		$result = $this->router->resolve('/api/v1/economy_codebooks_warehouses', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('economy_codebooks_warehouses', $result->table);
	}

	public function testDocStateOptions(): void
	{
		$result = $this->router->resolve('/api/v1/base_persons_persons/42/doc-state-options', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'crud', 'docStateOptions', 'base_persons_persons', 42);
	}

	public function testDocStateOptionsMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/base_persons_persons/42/doc-state-options', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testUnknownSubResource(): void
	{
		$result = $this->router->resolve('/api/v1/users/5/unknown-action', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Form routes

	public function testFormMetaNew(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/meta', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'meta', 'core_system_users');
	}

	public function testFormMetaWithId(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/base_persons_persons/meta/42', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'meta', 'base_persons_persons', 42);
	}

	public function testFormSubtable(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows/42', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'subtable', 'docs_core_heads', 42);
		$this->assertSame('rows', $result->key);
	}

	public function testFormSubtableWrongMethod(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows/42', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormSubtableRejectsInvalidParentId(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows/0', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testFormSubtableRejectsInvalidTabId(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/ro-ws/1', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testFormSubtableMove(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows/42/move', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'subtableMove', 'docs_core_heads', 42);
		$this->assertSame('rows', $result->key);
	}

	public function testFormSubtableMoveRequiresPost(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows/42/move', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormSubtableUnknownTailIsNotFound(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows/42/swap', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testFormSubtableWithoutParentIdIsNotFound(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/docs_core_heads/subtable/rows', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testFormSaveCreate(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'save', 'core_system_users');
	}

	public function testFormSaveUpdate(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save/5', 'PUT');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'save', 'core_system_users', 5);
	}

	public function testFormRecalculate(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/recalculate', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'form', 'recalculate', 'core_system_users');
	}

	public function testFormMetaMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/meta', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormSaveGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormSaveUpdatePostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/save/5', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormRecalculateGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/recalculate', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testFormMetaInvalidId(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/meta/0', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testFormUnknownAction(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/form/core_system_users/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Attachment routes

	public function testAttachmentUpload(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/upload', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'upload');
	}

	public function testAttachmentUploadMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/upload', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentList(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'list');
	}

	public function testAttachmentListWithTrailingSlash(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'list');
	}

	public function testAttachmentListMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentDownload(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/download', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'download', null, 42);
	}

	public function testAttachmentDownloadMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/download', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentThumbnail(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/thumbnail', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'thumbnail', null, 42);
	}

	public function testAttachmentPatch(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42', 'PATCH');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'patch', null, 42);
	}

	public function testAttachmentDelete(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42', 'DELETE');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'delete', null, 42);
	}

	public function testAttachmentRestore(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/restore', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'attachment', 'restore', null, 42);
	}

	public function testAttachmentRestoreMethodNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/restore', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAttachmentUnknownAction(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/42/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testAttachmentZeroIdReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_attachments/0/download', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Mail routes

	public function testSentMessageResend(): void
	{
		$result = $this->router->resolve('/api/v1/_sent-messages/42/resend', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'sentMessages', 'resend', null, 42);

		foreach ([
			['/api/v1/_sent-messages/42/resend', 'GET', 'METHOD_NOT_ALLOWED'],
			['/api/v1/_sent-messages/0/resend', 'POST', 'NOT_FOUND'],
			['/api/v1/_sent-messages/42', 'POST', 'NOT_FOUND'],
		] as [$path, $method, $code]) {
			$result = $this->router->resolve($path, $method);
			$this->assertInstanceOf(Response::class, $result);
			$this->assertSame($code, $result->getPayload()['error']['code']);
		}
	}

	public function testMailSenderAddresses(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/sender-addresses', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'mail', 'senderAddresses');

		$this->assertInstanceOf(Response::class, $this->router->resolve('/api/v1/_mail/sender-addresses', 'POST'));
	}

	public function testMailIncomingPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/incoming', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'mail', 'receiveIncoming');
	}

	public function testMailIncomingGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/incoming', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Hosting portal routes (D10)

	public function testHostingPortalMyDatasourcesGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/portal/my-datasources', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingPortal', 'myDatasources');
	}

	public function testHostingPortalMyDatasourcesPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/portal/my-datasources', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Hosting portal self-service routes (hosting-08 D4)

	public function testHostingPortalCreateMetaGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/portal/create-meta', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingPortal', 'createMeta');
	}

	public function testHostingPortalCheckWebIdGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/portal/check-web-id', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingPortal', 'checkWebId');
	}

	public function testHostingPortalCreateDatasourcePost(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/portal/create-datasource', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingPortal', 'createDatasource');
	}

	public function testHostingPortalCreateDatasourceGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/portal/create-datasource', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Hosting OIDC OP routes (D2)

	public function testHostingOidcDiscoveryGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/.well-known/openid-configuration', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingOidc', 'discovery');
	}

	public function testHostingOidcJwksGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/jwks', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingOidc', 'jwks');
	}

	public function testHostingOidcAuthorizeGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/authorize', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingOidc', 'authorize');
	}

	public function testHostingOidcApprovePost(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/approve', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingOidc', 'approve');
	}

	public function testHostingOidcTokenPost(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/token', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingOidc', 'token');
	}

	public function testHostingOidcTokenGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/token', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testHostingOidcUnknownPathIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/oidc/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Hosting server provisioning routes (D3)

	public function testHostingServerReconcilePost(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/server/reconcile', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingServer', 'reconcile');
	}

	public function testHostingServerQueueGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/server/queue', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingServer', 'queue');
	}

	public function testHostingServerConfirmPost(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/server/confirm', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingServer', 'confirm');
	}

	public function testHostingServerQueuePostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/server/queue', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testHostingServerUnknownPathIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/server/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Hosting mail lookup route (D4)

	public function testHostingMailLookupGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/mail/lookup', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingMail', 'lookup');
	}

	public function testHostingMailLookupPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/mail/lookup', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testHostingMailUnknownPathIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/mail/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Hosting AI analyzer lookup route (hosting-10 D4)

	public function testHostingAiAnalyzerLookupGet(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/ai-analyzer/lookup', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingAiAnalyzer', 'lookup');
	}

	public function testHostingAiAnalyzerLookupPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/ai-analyzer/lookup', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testHostingAiAnalyzerUnknownPathIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/ai-analyzer/unknown', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// AI gateway route (D5)

	public function testHostingAiGatewayMessagesPost(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/ai-gw/v1/messages', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'hostingAiGateway', 'messages');
	}

	public function testHostingAiGatewayMessagesGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_hosting/ai-gw/v1/messages', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testHostingAiGatewayUnknownPathIs404(): void
	{
		foreach (['/api/v1/_hosting/ai-gw/v1/complete', '/api/v1/_hosting/ai-gw/admin'] as $path) {
			$result = $this->router->resolve($path, 'POST');
			$this->assertInstanceOf(Response::class, $result);
			$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
		}
	}

	// Analysis routes (Fáze 3a)

	public function testAnalysisQueueGet(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/queue', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'analysis', 'queue');
	}

	public function testAnalysisQueuePostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/queue', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAnalysisClaimPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/12345/claim', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('claim', $result->action);
		$this->assertSame(12345, $result->id);
	}

	public function testAnalysisClaimZeroNdxReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/0/claim', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testAnalysisPayloadGet(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/payload', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('payload', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testAnalysisAttachmentContentGet(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/attachments/7/content', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('attachmentContent', $result->action);
		$this->assertSame(42, $result->id);
		$this->assertSame(7, $result->secondaryId);
	}

	public function testAnalysisResultPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/result', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('result', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testAnalysisFailedPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/failed', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('failed', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testAnalysisUnknownActionReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/analysis/42/whatever', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testMailMessagesReanalyzePost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/42/reanalyze', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('reanalyze', $result->action);
		$this->assertSame(42, $result->id);
	}

	public function testMailMessagesReanalyzeGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/42/reanalyze', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Message-centrické akce (tasks/mail-message-centric.md) — apply/unapply/
	// reject/preview žijí nad zprávou; /_mail/extracted-documents/* zaniklo.

	public function testMailMessagesApplyPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/apply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('applyMessage', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testMailMessagesUnapplyPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/unapply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('unapplyMessage', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testMailMessagesRejectPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/reject', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('rejectMessage', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testMailMessagesDecisionsPost(): void
	{
		// Průběžné ukládání rozhodnutí z review modalu (#76).
		$result = $this->router->resolve('/api/v1/_mail/messages/55/decisions', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('saveDecisions', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testMailMessagesDecisionsGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/decisions', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testMailMessagesPreviewGet(): void
	{
		// Preview je read-only → GET (na rozdíl od ostatních akcí).
		$result = $this->router->resolve('/api/v1/_mail/messages/55/preview', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('analysis', $result->controller);
		$this->assertSame('previewMessage', $result->action);
		$this->assertSame(55, $result->id);
	}

	public function testMailMessagesPreviewPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/preview', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testMailMessagesApplyGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/apply', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testMailMessagesUnknownActionReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/55/whatever', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testMailMessagesZeroNdxReturns404(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/0/apply', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Ruční nahrání z dashboardu (tasks/mail-dashboard-upload.md) — literál
	// 'upload' před parsováním {ndx}.

	public function testMailMessagesUploadPost(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/upload', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertSame('mail', $result->controller);
		$this->assertSame('uploadMessages', $result->action);
	}

	public function testMailMessagesUploadGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_mail/messages/upload', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testExtractedDocumentsRoutesAreGone(): void
	{
		// Tabulka core_mail_extracted_documents zanikla — celý podstrom je 404.
		foreach ([
			['/api/v1/_mail/extracted-documents/55/apply', 'POST'],
			['/api/v1/_mail/extracted-documents/55/reject', 'POST'],
			['/api/v1/_mail/extracted-documents/55/preview', 'POST'],
			['/api/v1/_mail/extracted-documents/55/preview', 'GET'],
		] as [$path, $method]) {
			$result = $this->router->resolve($path, $method);
			$this->assertInstanceOf(Response::class, $result);
			$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code'], "expected 404 for {$method} {$path}");
		}
	}

	// Exchange endpoints
	public function testExchangeValidate(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/validate', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'validate');
	}

	public function testExchangePreview(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/preview', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'preview');
	}

	public function testExchangeApply(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/apply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'apply');
	}

	public function testExchangeGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/apply', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testExchangeUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/docs/document/explode', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testPersonExchangeValidate(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/persons/person/validate', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'person:validate');
	}

	public function testPersonExchangePreview(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/persons/person/preview', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'person:preview');
	}

	public function testPersonExchangeApply(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/persons/person/apply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'person:apply');
	}

	public function testPersonExchangeGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/persons/person/apply', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testPersonExchangeUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/persons/person/explode', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testItemExchangeValidate(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/items/item/validate', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'item:validate');
	}

	public function testItemExchangePreview(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/items/item/preview', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'item:preview');
	}

	public function testItemExchangeApply(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/items/item/apply', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'exchange', 'item:apply');
	}

	public function testItemExchangeGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/items/item/apply', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testItemExchangeUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_exchange/items/item/explode', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Lookup routes

	public function testLookupSearch(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/search', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'lookup', 'search', 'base_persons_persons');
	}

	public function testLookupResolve(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/resolve', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'lookup', 'resolve', 'base_persons_persons');
	}

	public function testLookupCreateDefaults(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/economy_assets_assets/create-defaults', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'lookup', 'createDefaults', 'economy_assets_assets');
	}

	public function testLookupCreateDefaultsGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/economy_assets_assets/create-defaults', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testLookupSearchPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/search', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testLookupUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons/explode', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testLookupMissingActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/base_persons_persons', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testLookupExtraSegmentIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_ui/lookup/foo/search/extra', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Alerts endpoints

	public function testAlertsRegistry(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/registry', 'GET');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'alerts', 'registry');
	}

	public function testAlertsRegistryPostNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/registry', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAlertsRunDue(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/run-due', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'alerts', 'runDue');
	}

	public function testAlertsRunDueGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/run-due', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAlertsRunCheck(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/checks/base.persons.missing_own_person/run', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'alerts', 'runCheck', 'base.persons.missing_own_person');
	}

	public function testAlertsRunCheckBadIdIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/checks/BadCAPS/run', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testAlertsSnooze(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/alerts/42/snooze', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'alerts', 'snooze', null, 42);
	}

	public function testAlertsDismiss(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/alerts/7/dismiss', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'alerts', 'dismiss', null, 7);
	}

	public function testAlertsUnsnooze(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/alerts/3/unsnooze', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'alerts', 'unsnooze', null, 3);
	}

	public function testAlertsUnknownActionIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/alerts/3/explode', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	public function testAlertsRunCheckGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_alerts/checks/x.y/run', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Accbal endpoints

	public function testAccbalMatch(): void
	{
		$result = $this->router->resolve('/api/v1/_accbal/match', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'accbal', 'match');
	}

	public function testAccbalMatchGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_accbal/match', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testAccbalUnknownSubpathIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_accbal/unmatch', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Bank
	public function testBankImportStatementRoute(): void
	{
		$result = $this->router->resolve('/api/v1/_bank/import-statement', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'bank', 'importStatement');
	}

	public function testBankImportStatementGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_bank/import-statement', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	// Registry (Spisovna)
	public function testRegistryFromMessageRoute(): void
	{
		$result = $this->router->resolve('/api/v1/_registry/from-message/42', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'registry', 'fromMessage', null, 42);
	}

	public function testRegistryExtractTextRoute(): void
	{
		$result = $this->router->resolve('/api/v1/_registry/documents/17/extract-text', 'POST');
		$this->assertInstanceOf(Route::class, $result);
		$this->assertRoute($result, 'registry', 'extractText', null, 17);
	}

	public function testRegistryExtractTextGetNotAllowed(): void
	{
		$result = $this->router->resolve('/api/v1/_registry/documents/17/extract-text', 'GET');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('METHOD_NOT_ALLOWED', $result->getPayload()['error']['code']);
	}

	public function testRegistryUnknownSubpathIs404(): void
	{
		$result = $this->router->resolve('/api/v1/_registry/documents/17/reindex', 'POST');
		$this->assertInstanceOf(Response::class, $result);
		$this->assertSame('NOT_FOUND', $result->getPayload()['error']['code']);
	}

	// Majetek — odpisy za období (docs/assets.md D33)
	public function testAssetsDepreciationRunRoutes(): void
	{
		$options = $this->router->resolve('/api/v1/_assets/depreciation-run/options', 'GET');
		$this->assertInstanceOf(Route::class, $options);
		$this->assertRoute($options, 'assets', 'depreciationRunOptions');

		$preview = $this->router->resolve('/api/v1/_assets/depreciation-run/preview', 'GET');
		$this->assertInstanceOf(Route::class, $preview);
		$this->assertRoute($preview, 'assets', 'depreciationRunPreview');

		$run = $this->router->resolve('/api/v1/_assets/depreciation-run', 'POST');
		$this->assertInstanceOf(Route::class, $run);
		$this->assertRoute($run, 'assets', 'depreciationRun');

		$this->assertInstanceOf(Response::class, $this->router->resolve('/api/v1/_assets/depreciation-run', 'GET'));
		$this->assertInstanceOf(Response::class, $this->router->resolve('/api/v1/_assets/depreciation-run/preview', 'POST'));
	}

	// Majetek — odpisy a zaúčtování za období (docs/assets.md D50–D55)
	public function testAssetsPostingRoutes(): void
	{
		$preview = $this->router->resolve('/api/v1/_assets/posting/preview', 'GET');
		$this->assertInstanceOf(Route::class, $preview);
		$this->assertRoute($preview, 'assets', 'postingPreview');

		$post = $this->router->resolve('/api/v1/_assets/posting', 'POST');
		$this->assertInstanceOf(Route::class, $post);
		$this->assertRoute($post, 'assets', 'posting');

		$cancel = $this->router->resolve('/api/v1/_assets/posting/cancel', 'POST');
		$this->assertInstanceOf(Route::class, $cancel);
		$this->assertRoute($cancel, 'assets', 'postingCancel');

		$this->assertInstanceOf(Response::class, $this->router->resolve('/api/v1/_assets/posting', 'GET'));
		$this->assertInstanceOf(Response::class, $this->router->resolve('/api/v1/_assets/posting/cancel', 'GET'));
		$this->assertInstanceOf(Response::class, $this->router->resolve('/api/v1/_assets/posting/preview', 'POST'));
	}
}
