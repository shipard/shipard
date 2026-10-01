<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\SettingsController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;

/**
 * Testy settings pages (page/savePage) nad reálnou definicí appSettings
 * z modules/core/system/module.jsonc — DB je mockovaná.
 */
class SettingsControllerTest extends TestCase
{
    private string $dsDir;
    private ModulePathResolver $resolver;
    private SettingsController $ctrl;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_settingsctrl_' . uniqid('', true);
        mkdir($this->dsDir . '/config', 0755, true);
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'                => 'test-test-test-test',
            'name'              => 'Testovací firma',
            'database_name'     => 'x',
            'database_user'     => 'x',
            'database_password' => 'x',
            'created'           => '2026-01-01T00:00:00+00:00',
            'modules'           => ['core.system'],
        ]));

        $this->resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $this->ctrl     = new SettingsController();
    }

    protected function tearDown(): void
    {
        @unlink($this->dsDir . '/config/main.json');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    private function config(): DataSourceConfig
    {
        return new DataSourceConfig($this->dsDir);
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_test');
    }

    private function mockDb(array $fetchAllRows = []): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($fetchAllRows);
        return $db;
    }

    private function saveRequest(array $body): Request
    {
        return Request::fromArray('POST', '/api/v1/_ui/settings/page/appSettings', [], json_encode($body), []);
    }

    private function getStatus(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return $ref->getProperty('status')->getValue($response);
    }

    // --- page() ---

    public function testPageRequiresAuth(): void
    {
        $resp = $this->ctrl->page('appSettings', $this->config(), $this->resolver, 'cs', AuthContext::anonymous(), $this->mockDb());

        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testPageUnknownIdReturns404(): void
    {
        $resp = $this->ctrl->page('noSuchPage', $this->config(), $this->resolver, 'cs', $this->auth(), $this->mockDb());

        $this->assertSame(404, $this->getStatus($resp));
    }

    public function testPageReturnsLocalizedDefinitionAndValues(): void
    {
        $db = $this->mockDb([
            ['key' => 'app.name', 'value' => json_encode('Moje firma s.r.o.')],
        ]);

        $resp = $this->ctrl->page('appSettings', $this->config(), $this->resolver, 'cs', $this->auth(), $db);
        $data = $resp->getPayload()['data'];

        $this->assertSame('appSettings', $data['definition']['id']);
        $this->assertSame('Aplikace', $data['definition']['label']);
        $this->assertSame('ds', $data['definition']['scope']);
        $this->assertCount(6, $data['definition']['fields']);

        $byId = array_column($data['definition']['fields'], null, 'id');
        $this->assertSame('Název zdroje dat', $byId['app.name']['label']);
        $this->assertSame(120, $byId['app.name']['maxLength']);
        $this->assertSame('image', $byId['app.icon']['type']);
        $this->assertSame('icon', $byId['app.icon']['slot']);
        // DS default vzhledu — pole typu theme, scope ds (přes Uložit).
        $this->assertSame('theme', $byId['app.theme']['type']);
        $this->assertSame('Výchozí vzhled', $byId['app.theme']['label']);
        // DS default shellu — pole typu shell, scope ds (přes Uložit).
        $this->assertSame('shell', $byId['app.shell']['type']);
        $this->assertSame('Výchozí rozložení', $byId['app.shell']['label']);

        $this->assertSame('Moje firma s.r.o.', $data['values']['app.name']);
        $this->assertNull($data['values']['app.shortName']);
        $this->assertNull($data['values']['app.icon']);
    }

    public function testPageResolvesImageSlotState(): void
    {
        $db = $this->mockDb([
            ['key' => 'app.icon', 'value' => json_encode([
                'filename' => 'logo.png',
                'storedAs' => 'icon.png',
                'mime'     => 'image/png',
                'size'     => 1234,
                'hash'     => 'abcd1234abcd1234',
            ])],
        ]);

        $resp = $this->ctrl->page('appSettings', $this->config(), $this->resolver, 'en', $this->auth(), $db);
        $icon = $resp->getPayload()['data']['values']['app.icon'];

        $this->assertSame('/_app/branding/icon?h=abcd1234abcd1234', $icon['url']);
        $this->assertSame('logo.png', $icon['filename']);
        $this->assertSame('image/png', $icon['mime']);
        $this->assertSame(1234, $icon['size']);
    }

    // --- savePage() ---

    public function testSavePageRequiresAuth(): void
    {
        $resp = $this->ctrl->savePage(
            'appSettings', $this->saveRequest(['values' => []]),
            $this->config(), $this->resolver, AuthContext::anonymous(), $this->mockDb(),
        );

        $this->assertSame(401, $this->getStatus($resp));
    }

    public function testSavePageWithoutValuesReturns400(): void
    {
        $resp = $this->ctrl->savePage(
            'appSettings', $this->saveRequest(['nonsense' => true]),
            $this->config(), $this->resolver, $this->auth(), $this->mockDb(),
        );

        $this->assertSame(400, $this->getStatus($resp));
    }

    public function testSavePageStoresOnlyWhitelistedTextFields(): void
    {
        $db = $this->mockDb();
        // Jediný upsert — app.name. Klíč mimo definici a image klíč se ignorují.
        $db->expects($this->once())->method('execute');
        $db->expects($this->never())->method('deleteWhere');

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => [
                'app.name'     => '  Nová firma  ',
                'evil.key'     => 'hack',
                'app.icon'     => ['hash' => 'spoofed'],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        // Trim + cache store: uložená hodnota se vrací bez mezer.
        $this->assertSame('Nová firma', $resp->getPayload()['data']['values']['app.name']);
    }

    public function testSavePageEmptyStringDeletesKey(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');
        $db->expects($this->once())->method('deleteWhere');

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => ['app.shortName' => '   ']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertNull($resp->getPayload()['data']['values']['app.shortName']);
    }

    public function testSavePageMaxLengthValidation(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => ['app.shortName' => str_repeat('x', 31)]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
        $error = $resp->getPayload()['error'];
        $this->assertSame('VALIDATION_ERROR', $error['code']);
        $this->assertSame('app.shortName', $error['details'][0]['field']);
        $this->assertSame('MAX_LENGTH', $error['details'][0]['code']);
    }

    public function testSavePageUnknownPageReturns404(): void
    {
        $resp = $this->ctrl->savePage(
            'noSuchPage', $this->saveRequest(['values' => []]),
            $this->config(), $this->resolver, $this->auth(), $this->mockDb(),
        );

        $this->assertSame(404, $this->getStatus($resp));
    }

    // --- adminOnly stránky (hostingOidc) ---

    public function testPageAdminOnlyReturns403ForNonAdmin(): void
    {
        $this->useHostingModules();
        $resp = $this->ctrl->page('hostingOidc', $this->config(), $this->resolver, 'cs', $this->auth(), $this->mockDb());

        $this->assertSame(403, $this->getStatus($resp));
    }

    public function testPageAdminOnlyAllowsAdmin(): void
    {
        $this->useHostingModules();
        $admin = new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true);
        $resp = $this->ctrl->page('hostingOidc', $this->config(), $this->resolver, 'cs', $admin, $this->mockDb());

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('hostingOidc', $resp->getPayload()['data']['definition']['id']);
    }

    public function testSavePageAdminOnlyReturns403ForNonAdmin(): void
    {
        $this->useHostingModules();
        $resp = $this->ctrl->savePage(
            'hostingOidc', $this->saveRequest(['values' => ['hosting.oidc.issuer' => 'https://x/api/v1/_hosting/oidc']]),
            $this->config(), $this->resolver, $this->auth(), $this->mockDb(),
        );

        $this->assertSame(403, $this->getStatus($resp));
    }

    /** Přepne DS na moduly s hosting.core (stránka hostingOidc). */
    private function useHostingModules(): void
    {
        $main = json_decode((string) file_get_contents($this->dsDir . '/config/main.json'), true);
        $main['modules'] = ['core.system', 'hosting.core'];
        file_put_contents($this->dsDir . '/config/main.json', json_encode($main));
    }

    // --- account page (scope user, theme/language pole) ---

    public function testPageUserScopeRequiresUserId(): void
    {
        // Autentizovaný, ale bez userId (např. API klíč) → user scope nelze obsloužit.
        $auth = new AuthContext(true, null, 'api_key', 'k');
        $resp = $this->ctrl->page('accountBasic', $this->config(), $this->resolver, 'cs', $auth, $this->mockDb());

        $this->assertSame(401, $this->getStatus($resp));
    }

    /** Zdroj dat s modulem majetku — stránka Odpisy má pole typu select. */
    private function withAssetsModule(): void
    {
        $main = json_decode((string) file_get_contents($this->dsDir . '/config/main.json'), true);
        $main['modules'] = ['core.system', 'economy.assets'];
        file_put_contents($this->dsDir . '/config/main.json', json_encode($main));
    }

    /**
     * DB pro stránku Odpisy: nastavení + řady účetních dokladů pro
     * dynamickou nabídku pole (optionsProvider).
     *
     * @param list<array<string, mixed>> $settings
     */
    private function assetsDb(array $settings = []): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static fn(mixed ...$args): array => str_contains((string) $args[0], 'docs_core_number_series')
                ? [
                    ['id' => 3, 'name' => 'Účetní doklad', 'doc_number_code' => null],
                    ['id' => 77, 'name' => 'Majetek', 'doc_number_code' => 'MA'],
                ]
                : $settings,
        );
        return $db;
    }

    public function testPageSelectFieldCarriesLocalizedOptions(): void
    {
        $this->withAssetsModule();
        $db = $this->assetsDb([
            ['key' => 'economy.assets.accPeriodicity', 'value' => json_encode('month')],
        ]);

        $resp = $this->ctrl->page('assetsDepreciation', $this->config(), $this->resolver, 'cs', $this->auth(), $db);
        $data = $resp->getPayload()['data'];

        $field = array_column($data['definition']['fields'], null, 'id')['economy.assets.accPeriodicity'];
        $this->assertSame('select', $field['type']);
        $this->assertSame('Četnost účetních odpisů', $field['label']);
        $this->assertSame(
            [['value' => 'year', 'label' => 'Ročně'], ['value' => 'month', 'label' => 'Měsíčně']],
            $field['options'],
        );
        $this->assertSame('month', $data['values']['economy.assets.accPeriodicity']);
    }

    public function testPageSelectOptionsComeFromProvider(): void
    {
        // Řada účetních dokladů majetku (assets D54): nabídka z dat zdroje.
        $this->withAssetsModule();
        $db = $this->assetsDb([
            ['key' => 'economy.assets.accountingSeries', 'value' => json_encode('77')],
        ]);

        $resp = $this->ctrl->page('assetsDepreciation', $this->config(), $this->resolver, 'cs', $this->auth(), $db);
        $data = $resp->getPayload()['data'];

        $field = array_column($data['definition']['fields'], null, 'id')['economy.assets.accountingSeries'];
        $this->assertSame('select', $field['type']);
        $this->assertSame('Řada účetních dokladů majetku', $field['label']);
        $this->assertSame(
            [['value' => '3', 'label' => 'Účetní doklad'], ['value' => '77', 'label' => 'Majetek (MA)']],
            $field['options'],
        );
        $this->assertArrayNotHasKey('optionsProvider', $field, 'třída provideru na klienta nejde');
        $this->assertSame('77', $data['values']['economy.assets.accountingSeries']);
    }

    public function testSavePageProviderSelectAcceptsOnlyOfferedValues(): void
    {
        $this->withAssetsModule();
        $db = $this->assetsDb();
        $db->expects($this->once())->method('execute');

        $resp = $this->ctrl->savePage(
            'assetsDepreciation',
            $this->saveRequest(['values' => ['economy.assets.accountingSeries' => '77']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );
        $this->assertSame(200, $this->getStatus($resp));

        // Řada jiného typu / neexistující id v nabídce není.
        $resp = $this->ctrl->savePage(
            'assetsDepreciation',
            $this->saveRequest(['values' => ['economy.assets.accountingSeries' => '1']]),
            $this->config(), $this->resolver, $this->auth(), $this->assetsDb(),
        );
        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('INVALID_VALUE', $resp->getPayload()['error']['details'][0]['code']);
    }

    public function testSavePageSelectAcceptsOnlyOptionValues(): void
    {
        $this->withAssetsModule();
        $db = $this->assetsDb();
        $db->expects($this->once())->method('execute');

        $resp = $this->ctrl->savePage(
            'assetsDepreciation',
            $this->saveRequest(['values' => ['economy.assets.accPeriodicity' => 'month']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );
        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('month', $resp->getPayload()['data']['values']['economy.assets.accPeriodicity']);

        $resp = $this->ctrl->savePage(
            'assetsDepreciation',
            $this->saveRequest(['values' => ['economy.assets.accPeriodicity' => 'weekly']]),
            $this->config(), $this->resolver, $this->auth(), $this->assetsDb(),
        );
        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('INVALID_VALUE', $resp->getPayload()['error']['details'][0]['code']);
    }

    public function testSavePageSelectEmptyDeletesKey(): void
    {
        $this->withAssetsModule();
        $db = $this->assetsDb();
        $db->expects($this->never())->method('execute');
        $db->expects($this->once())->method('deleteWhere');

        $resp = $this->ctrl->savePage(
            'assetsDepreciation',
            $this->saveRequest(['values' => ['economy.assets.accPeriodicity' => '']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );
        $this->assertSame(200, $this->getStatus($resp));
        $this->assertNull($resp->getPayload()['data']['values']['economy.assets.accPeriodicity']);
    }

    public function testPageAccountBasicReturnsThemeAndLanguageFields(): void
    {
        $db = $this->mockDb([
            ['key' => 'account.language', 'value' => json_encode('cs')],
        ]);

        $resp = $this->ctrl->page('accountBasic', $this->config(), $this->resolver, 'cs', $this->auth(), $db);
        $data = $resp->getPayload()['data'];

        $byId = array_column($data['definition']['fields'], null, 'id');
        $this->assertSame('theme', $byId['account.theme']['type']);
        $this->assertSame('language', $byId['account.language']['type']);
        $this->assertSame('Vzhled', $byId['account.theme']['label']);
        $this->assertSame('shell', $byId['account.shell']['type']);
        $this->assertSame('Rozložení', $byId['account.shell']['label']);
        $this->assertSame('cs', $data['values']['account.language']);
        $this->assertNull($data['values']['account.theme']);
        $this->assertNull($data['values']['account.shell']);
    }

    public function testSavePageThemeStoresStructuredValue(): void
    {
        $db = $this->mockDb();
        $db->expects($this->once())->method('execute');

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => [
                'account.theme' => ['mode' => 'custom', 'custom' => ['base' => 'light', 'sidebar' => ['type' => 'solid', 'color' => '#00345C']]],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
    }

    public function testSavePageInvalidThemeReturns422(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.theme' => ['mode' => 'bogus']]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('account.theme', $resp->getPayload()['error']['details'][0]['field']);
    }

    public function testSavePageLanguageStored(): void
    {
        $db = $this->mockDb();
        $db->expects($this->once())->method('execute');

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.language' => 'en']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
    }

    public function testSavePageInvalidLanguageReturns422(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.language' => 'de']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
    }

    // --- account.theme follow tvary (Fáze 4) ---

    public function testSavePageAccountThemeFollowTrue(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.theme' => ['follow' => true]]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        // UserSettingsStore::set váže (userId, key, json, json) — JSON je index 3.
        $this->assertSame(['follow' => true], json_decode($captured[3], true));
    }

    public function testSavePageAccountThemeOverrideKeepsFollowFalse(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => [
                'account.theme' => ['follow' => false, 'mode' => 'dark', 'custom' => null],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        $stored = json_decode($captured[3], true);
        $this->assertFalse($stored['follow']);
        $this->assertSame('dark', $stored['mode']);
    }

    public function testSavePageAccountThemeLegacyShapeBecomesOverride(): void
    {
        // Legacy {mode, custom} bez follow → uloží se jako override (follow:false).
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => [
                'account.theme' => ['mode' => 'custom', 'custom' => ['base' => 'light', 'sidebar' => ['type' => 'solid', 'color' => '#6D1F2C']]],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        $stored = json_decode($captured[3], true);
        $this->assertFalse($stored['follow']);
        $this->assertSame('custom', $stored['mode']);
    }

    // --- app.theme (DS default, scope ds, bez follow) ---

    public function testSavePageAppThemeStoresWithoutFollow(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => [
                'app.theme' => ['follow' => true, 'mode' => 'custom', 'custom' => ['base' => 'light', 'sidebar' => ['type' => 'solid', 'color' => '#00345C']]],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        // DS scope follow ignoruje — uloží jen {mode, custom}.
        $stored = json_decode($captured[2], true);
        $this->assertArrayNotHasKey('follow', $stored);
        $this->assertSame('custom', $stored['mode']);
        $this->assertSame('#00345C', $stored['custom']['sidebar']['color']);
    }

    public function testSavePageAppThemeInvalidReturns422(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => ['app.theme' => ['mode' => 'bogus']]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('app.theme', $resp->getPayload()['error']['details'][0]['field']);
    }

    // --- account.shell / app.shell (typ shell, UI shells Fáze 4) ---

    public function testSavePageAccountShellFollowTrue(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.shell' => ['follow' => true]]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        // UserSettingsStore::set váže (userId, key, json, json) — JSON je index 3.
        $this->assertSame(['follow' => true], json_decode($captured[3], true));
    }

    public function testSavePageAccountShellOverrideKeepsFollowFalseAndParams(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => [
                'account.shell' => ['follow' => false, 'shell' => 'classic', 'params' => ['density' => 'compact']],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        $stored = json_decode($captured[3], true);
        $this->assertFalse($stored['follow']);
        $this->assertSame('classic', $stored['shell']);
        // params jsou v1 passthrough — bez interpretace, ale zachované.
        $this->assertSame(['density' => 'compact'], $stored['params']);
    }

    public function testSavePageAccountShellWildIsAllowed(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.shell' => ['follow' => false, 'shell' => 'wild']]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        $this->assertSame('wild', json_decode($captured[3], true)['shell']);
    }

    public function testSavePageAccountShellUnknownNameReturns422(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.shell' => ['follow' => false, 'shell' => 'nope']]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('account.shell', $resp->getPayload()['error']['details'][0]['field']);
        $this->assertSame('INVALID_VALUE', $resp->getPayload()['error']['details'][0]['code']);
    }

    public function testSavePageAppShellStoresWithoutFollow(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->once())->method('execute')
            ->willReturnCallback(function (...$args) use (&$captured) { $captured = $args; });

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => [
                'app.shell' => ['follow' => true, 'shell' => 'classic'],
            ]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(200, $this->getStatus($resp));
        // DS scope follow ignoruje — uloží jen {shell, params}.
        $stored = json_decode($captured[2], true);
        $this->assertArrayNotHasKey('follow', $stored);
        $this->assertSame('classic', $stored['shell']);
    }

    public function testSavePageAppShellInvalidParamsReturns422(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'appSettings',
            $this->saveRequest(['values' => ['app.shell' => ['shell' => 'classic', 'params' => 'compact']]]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
        $this->assertSame('app.shell', $resp->getPayload()['error']['details'][0]['field']);
    }

    public function testSavePageShellNonArrayReturns422(): void
    {
        $db = $this->mockDb();
        $db->expects($this->never())->method('execute');

        $resp = $this->ctrl->savePage(
            'accountBasic',
            $this->saveRequest(['values' => ['account.shell' => 'classic']]),
            $this->config(), $this->resolver, $this->auth(), $db,
        );

        $this->assertSame(422, $this->getStatus($resp));
    }

    // --- account navigation ---

    public function testAccountNavigationBuildsBasicSectionTree(): void
    {
        $configRuntime = $this->createMock(\Shipard\Core\Config\ConfigRuntime::class);
        $configRuntime->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'global.accountSections'
                ? ['sections' => [['id' => 'basic', 'name' => 'Basic', 'name:cs' => 'Základní', 'icon' => 'settings', 'order' => 10]]]
                : null,
        );

        $resp = $this->ctrl->navigation($this->config(), $this->resolver, 'cs', $configRuntime, 'account');
        $tree = $resp->getPayload()['data'];

        $this->assertCount(1, $tree);
        $this->assertSame('basic', $tree[0]['id']);
        $this->assertSame('Základní', $tree[0]['label']);
        $this->assertSame('page:accountBasic', $tree[0]['children'][0]['id']);
        $this->assertSame('page', $tree[0]['children'][0]['type']);
    }

    // --- settings navigation: filtr systémových tabulek dle is_admin ---

    private function settingsRuntime(): \Shipard\Core\Config\ConfigRuntime
    {
        $configRuntime = $this->createMock(\Shipard\Core\Config\ConfigRuntime::class);
        $configRuntime->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'global.settingsSections'
                ? ['sections' => [[
                    'id' => 'other', 'name' => 'Other', 'order' => 10,
                    'subsections' => [['id' => 'other.system', 'name' => 'System', 'order' => 10]],
                ]]]
                : null,
        );
        return $configRuntime;
    }

    /** @return string[] Item ids across the whole tree (sections + subsections). */
    private function collectNavItemIds(array $tree): array
    {
        $ids = [];
        $walk = function (array $nodes) use (&$walk, &$ids): void {
            foreach ($nodes as $node) {
                if (isset($node['children'])) {
                    $walk($node['children']);
                } else {
                    $ids[] = $node['id'];
                }
            }
        };
        $walk($tree);
        return $ids;
    }

    public function testNavigationHidesSystemTablesFromNonAdmin(): void
    {
        $resp = $this->ctrl->navigation(
            $this->config(), $this->resolver, 'en', $this->settingsRuntime(), 'settings', $this->auth(),
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        // Users jsou viewer položka — guard je skrývá přes target tabulku.
        $this->assertNotContains('viewer:core.system.users', $ids);
        $this->assertNotContains('core_system_users', $ids);
        $this->assertNotContains('core_system_api_keys', $ids);
    }

    public function testNavigationHidesSystemTablesWithoutAuthContext(): void
    {
        // Bez AuthContextu (starý wiring) = ne-admin — fail closed.
        $resp = $this->ctrl->navigation(
            $this->config(), $this->resolver, 'en', $this->settingsRuntime(), 'settings',
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertNotContains('viewer:core.system.users', $ids);
        $this->assertNotContains('core_system_users', $ids);
    }

    public function testNavigationShowsSystemTablesToAdmin(): void
    {
        $admin = new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true);
        $resp  = $this->ctrl->navigation(
            $this->config(), $this->resolver, 'en', $this->settingsRuntime(), 'settings', $admin,
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertContains('viewer:core.system.users', $ids);
    }

    // --- settings navigation: filtr adminOnly tabulek (hosting D9) ---

    /** Config s fixture modulem test.hosting (adminOnly tabulka v settings). */
    private function hostingConfig(): DataSourceConfig
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'                => 'test-test-test-test',
            'name'              => 'Testovací firma',
            'database_name'     => 'x',
            'database_user'     => 'x',
            'database_password' => 'x',
            'created'           => '2026-01-01T00:00:00+00:00',
            'modules'           => ['core.system', 'test.hosting'],
        ]));
        return new DataSourceConfig($this->dsDir);
    }

    private function hostingResolver(): ModulePathResolver
    {
        return new ModulePathResolver([
            dirname(__DIR__, 4) . '/modules',
            dirname(__DIR__, 3) . '/Fixtures/modules',
        ]);
    }

    /** @return array<string, \Shipard\Core\Database\TableDefinition> */
    private function hostingTables(): array
    {
        return [
            'test_hosting_servers' => \Shipard\Core\Database\TableDefinition::fromArray([
                'tableId'   => 9002,
                'name'      => 'test_hosting_servers',
                'adminOnly' => true,
                'columns'   => [
                    ['id' => 'id',   'name' => 'ID',   'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
                    ['id' => 'name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
                ],
            ]),
        ];
    }

    public function testNavigationHidesAdminOnlyTablesFromNonAdmin(): void
    {
        $resp = $this->ctrl->navigation(
            $this->hostingConfig(), $this->hostingResolver(), 'en', $this->settingsRuntime(),
            'settings', $this->auth(), $this->hostingTables(),
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertNotContains('test_hosting_servers', $ids);
    }

    public function testNavigationShowsAdminOnlyTablesToAdmin(): void
    {
        $admin = new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true);
        $resp  = $this->ctrl->navigation(
            $this->hostingConfig(), $this->hostingResolver(), 'en', $this->settingsRuntime(),
            'settings', $admin, $this->hostingTables(),
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertContains('test_hosting_servers', $ids);
    }

    // --- settings navigation: runtime visibility gate (NavItemVisibilityGate) ---

    /** Config s reálným modulem economy.codebooks (nese VatAgendaNavGate). */
    private function codebooksConfig(): DataSourceConfig
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'                => 'test-test-test-test',
            'name'              => 'Testovací firma',
            'database_name'     => 'x',
            'database_user'     => 'x',
            'database_password' => 'x',
            'created'           => '2026-01-01T00:00:00+00:00',
            'modules'           => ['economy.codebooks'],
        ]));
        return new DataSourceConfig($this->dsDir);
    }

    private function accountingRuntime(): \Shipard\Core\Config\ConfigRuntime
    {
        $configRuntime = $this->createMock(\Shipard\Core\Config\ConfigRuntime::class);
        $configRuntime->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'global.settingsSections'
                ? ['sections' => [
                    ['id' => 'accounting', 'name' => 'Accounting', 'order' => 10],
                    ['id' => 'warehouses', 'name' => 'Warehouses', 'order' => 20],
                ]]
                : null,
        );
        return $configRuntime;
    }

    /**
     * @param mixed $vatAgenda hodnota klíče economy.vatAgenda (null = klíč není)
     */
    private function gateDb(mixed $vatAgenda, int $registrationCount): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($vatAgenda, $registrationCount): mixed {
                if (($args[1] ?? null) === 'economy.vatAgenda') {
                    return $vatAgenda === null ? null : json_encode($vatAgenda);
                }
                if (str_contains((string) $args[0], 'COUNT(*)')) {
                    return $registrationCount;
                }
                return null;
            },
        );
        return $db;
    }

    public function testNavigationHidesVatAgendaForNonPayerWithoutRegistrations(): void
    {
        $resp = $this->ctrl->navigation(
            $this->codebooksConfig(), $this->resolver, 'en', $this->accountingRuntime(),
            'settings', $this->auth(), [], $this->gateDb(false, 0),
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertNotContains('viewer:economy.codebooks.vatRegistrations', $ids);
        // Ostatní položky modulu gate nefiltruje.
        $this->assertContains('viewer:economy.codebooks.fiscalYears', $ids);
    }

    public function testNavigationShowsVatAgendaWhenRegistrationEverExisted(): void
    {
        $resp = $this->ctrl->navigation(
            $this->codebooksConfig(), $this->resolver, 'en', $this->accountingRuntime(),
            'settings', $this->auth(), [], $this->gateDb(false, 1),
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertContains('viewer:economy.codebooks.vatRegistrations', $ids);
    }

    public function testNavigationShowsVatAgendaWhenUndecided(): void
    {
        $resp = $this->ctrl->navigation(
            $this->codebooksConfig(), $this->resolver, 'en', $this->accountingRuntime(),
            'settings', $this->auth(), [], $this->gateDb(null, 0),
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertContains('viewer:economy.codebooks.vatRegistrations', $ids);
    }

    public function testNavigationShowsVatAgendaWithoutDb(): void
    {
        // Fail-open: degradovaný kontext bez DB nesmí schovávat funkčnost.
        $resp = $this->ctrl->navigation(
            $this->codebooksConfig(), $this->resolver, 'en', $this->accountingRuntime(),
            'settings', $this->auth(), [],
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertContains('viewer:economy.codebooks.vatRegistrations', $ids);
    }

    public function testNavigationShowsVatAgendaWhenGateThrows(): void
    {
        // Fail-open: výjimka z gate se loguje a položka se zobrazí.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willThrowException(new \RuntimeException('db down'));

        $resp = $this->ctrl->navigation(
            $this->codebooksConfig(), $this->resolver, 'en', $this->accountingRuntime(),
            'settings', $this->auth(), [], $db,
        );
        $ids = $this->collectNavItemIds($resp->getPayload()['data']);

        $this->assertContains('viewer:economy.codebooks.vatRegistrations', $ids);
    }
}
