<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ViewerController;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Tests\Unit\Core\Module\UiExtensionFixtures;

/**
 * Akce záznamu z modulů v detailu (`recordActions`, #172 D12, D15, D19):
 * builder je přidá za akce vieweru v pořadí registru, akci `admin` jen
 * správci, akci, která spadne, vynechá — a bez uživatele nevypíše žádnou.
 */
class TableViewerRecordActionsTest extends TestCase
{
    use UiExtensionFixtures;

    private const ROW = ['id' => 7, 'name' => 'Záznam', 'kind' => 'main', 'docState' => 10, 'contact_payment' => 1];

    private const PING = 'test.uiExtensions.ping';
    private const CONFIRM = 'test.uiExtensions.confirmPayment';
    private const UPLOAD = 'test.uiExtensions.uploadCertificate';
    private const PURGE = 'test.uiExtensions.purge';

    protected function setUp(): void
    {
        $this->setUpUiExtensionFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownUiExtensionFixtures();
    }

    /** @param array<string, mixed>|null $row holý řádek tabulky */
    private function db(?array $row = self::ROW): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($row);
        $db->method('fetchAll')->willReturn([]);
        return $db;
    }

    /** @param array<string, mixed>|null $row */
    private function viewer(?ModuleUiExtensions $extensions, ?array $row = self::ROW): RecordActionsTestViewer
    {
        $viewer = new RecordActionsTestViewer($this->db($row), self::UI_TABLE);
        $viewer->setLanguage('cs');
        $viewer->setTables($this->uiTables());
        if ($extensions !== null) {
            $viewer->setUiExtensions($extensions);
        }
        return $viewer;
    }

    /** @param list<array{0: string, 1: string, 2?: int}> $actions [id, krátké jméno třídy, pořadí] */
    private function actions(array $actions, string $moduleId = 'test.uiExtensions'): ModuleDefinition
    {
        $registrations = [];
        foreach ($actions as $action) {
            $registrations[] = ['id' => $action[0], 'table' => self::UI_TABLE, 'class' => self::UI_NAMESPACE . $action[1]]
                + (isset($action[2]) ? ['order' => $action[2]] : []);
        }
        return ModuleDefinition::fromArray(['id' => $moduleId, 'name' => $moduleId, 'recordActions' => $registrations]);
    }

    /**
     * @param array<string, mixed> $detail
     * @return list<string>
     */
    private static function ids(array $detail): array
    {
        return array_column($detail['actions'] ?? [], 'id');
    }

    public function testWithoutUserNoModuleActionsAreListed(): void
    {
        $detail = $this->viewer($this->uiExtensionsOf())->renderDetail(7);

        $this->assertSame(['viewerAction'], self::ids($detail));
        $this->assertSame([], $this->uiExtensionFailures());
    }

    public function testModuleActionsFollowViewerActionsInRegistryOrder(): void
    {
        $viewer = $this->viewer($this->uiExtensionsOf());
        $viewer->setRecordActionUser(1, true);

        $this->assertSame(
            ['viewerAction', self::PURGE, self::PING, self::CONFIRM, self::UPLOAD],
            self::ids($viewer->renderDetail(7)),
            'akce vieweru, pak moduly podle order',
        );
    }

    public function testOrderIsOrderThenModuleIdThenDeclaration(): void
    {
        $extensions = ModuleUiExtensions::fromModules([
            $this->actions([['late', 'FixturePingAction', 900], ['zFirst', 'FixtureConfirmAction']], 'test.zeta'),
            $this->actions([['b', 'FixturePingAction'], ['a', 'FixtureConfirmAction'], ['early', 'FixtureAdminAction', 100]], 'test.alpha'),
        ]);
        $viewer = $this->viewer($extensions);
        $viewer->setRecordActionUser(1, false);

        $this->assertSame(
            ['viewerAction', 'test.alpha.early', 'test.alpha.b', 'test.alpha.a', 'test.zeta.zFirst', 'test.zeta.late'],
            self::ids($viewer->renderDetail(7)),
        );
    }

    public function testAdminActionIsHiddenFromOtherUsers(): void
    {
        $viewer = $this->viewer($this->uiExtensionsOf());
        $viewer->setRecordActionUser(5, false);

        $this->assertSame(['viewerAction', self::PING, self::CONFIRM, self::UPLOAD], self::ids($viewer->renderDetail(7)));
    }

    public function testActionShapes(): void
    {
        $viewer = $this->viewer($this->uiExtensionsOf());
        $viewer->setRecordActionUser(5, false);
        $actions = array_column($viewer->renderDetail(7)['actions'], null, 'id');

        $this->assertSame([
            'id' => self::PING,
            'kind' => 'record_action',
            'label' => 'Fixture ping',
            'variant' => 'secondary',
            'icon' => 'check',
            'confirm' => null,
            'dialog' => null,
            'target' => ['table' => self::UI_TABLE, 'recordId' => 7],
        ], $actions[self::PING]);

        $this->assertSame('danger', $actions[self::CONFIRM]['variant']);
        $this->assertSame('Really confirm the payment?', $actions[self::CONFIRM]['confirm']);
        $this->assertArrayNotHasKey('icon', $actions[self::CONFIRM]);

        $dialog = $actions[self::UPLOAD]['dialog'];
        $this->assertNull($actions[self::UPLOAD]['confirm']);
        $this->assertSame('Upload certificate', $dialog['title']);
        $this->assertSame('Choose the file and enter its password.', $dialog['intro']);
        $this->assertSame('Upload', $dialog['submitLabel']);
        $this->assertSame(
            ['notice', 'text', 'textarea', 'password', 'number', 'date', 'datetime', 'checkbox', 'select', 'file'],
            array_column($dialog['fields'], 'type'),
            'fixtura pokrývá všechny typy polí',
        );
        $this->assertSame(
            ['id' => 'certificate', 'type' => 'file', 'label' => 'Certificate', 'required' => true, 'accept' => '.p12,.pfx', 'maxBytes' => 2048],
            $dialog['fields'][9],
        );
        $this->assertSame('now', $dialog['fields'][6]['default']);
    }

    public function testActionGetsPlainTableRowNotDetailRecordOfTheViewer(): void
    {
        // Záznam háčků vieweru (JOINy, aliasy) má `kind: skip`, řádek tabulky
        // `main` — akce se rozhoduje podle řádku, stejně jako endpoint.
        $viewer = $this->viewer($this->uiExtensionsOf());
        $viewer->detailRecord = ['kind' => 'skip'] + self::ROW;
        $viewer->setRecordActionUser(5, false);

        $this->assertContains(self::PING, self::ids($viewer->renderDetail(7)));

        $skipped = $this->viewer($this->uiExtensionsOf(), ['kind' => 'skip'] + self::ROW);
        $skipped->setRecordActionUser(5, false);
        $this->assertNotContains(self::PING, self::ids($skipped->renderDetail(7)), 'nedostupná akce se nevypíše');
        $this->assertSame([], $this->uiExtensionFailures(), 'nedostupná akce není chyba');
    }

    public function testFailingActionIsLoggedAndSkipped(): void
    {
        $extensions = ModuleUiExtensions::fromModules([$this->actions([
            ['first', 'FixturePingAction', 100],
            ['availableFails', 'ThrowingAvailableAction', 200],
            ['describeFails', 'ThrowingDescribeAction', 300],
            ['missing', 'NoSuchAction', 400],
            ['never', 'UnavailableAction', 500],
            ['last', 'FixtureConfirmAction', 600],
        ])]);
        $viewer = $this->viewer($extensions);
        $viewer->setRecordActionUser(5, false);

        $detail = $viewer->renderDetail(7);

        $this->assertSame(['viewerAction', 'test.uiExtensions.first', 'test.uiExtensions.last'], self::ids($detail));
        $this->assertSame(['overview'], array_column($detail['tabs'], 'id'), 'detail se vykreslí');

        $failures = $this->uiExtensionFailures();
        $this->assertCount(3, $failures);
        $this->assertSame(ModuleUiExtensions::RECORD_ACTIONS, $failures[0]['kind']);
        $this->assertSame('available failed', $failures[0]['message']);
        $this->assertStringContainsString("variant 'success'", $failures[1]['message']);
        $this->assertStringContainsString('does not exist', $failures[2]['message']);
    }

    public function testDetailWithoutViewerActionsHasOnlyModuleActions(): void
    {
        $viewer = $this->viewer($this->uiExtensionsOf());
        $viewer->viewerActions = [];
        $viewer->setRecordActionUser(5, false);
        $this->assertSame([self::PING, self::CONFIRM, self::UPLOAD], self::ids($viewer->renderDetail(7)));

        // Žádná akce vieweru ani modulu = klíč `actions` chybí.
        $plain = $this->viewer(ModuleUiExtensions::none());
        $plain->viewerActions = [];
        $plain->setRecordActionUser(5, false);
        $this->assertArrayNotHasKey('actions', $plain->renderDetail(7));
    }

    public function testTableWithoutActionsCostsNoExtraQuery(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())->method('fetchRow')->willReturn(self::ROW);
        $db->method('fetchAll')->willReturn([]);

        $viewer = new RecordActionsTestViewer($db, self::UI_TABLE);
        $viewer->setUiExtensions($this->uiExtensionsOf(['test.uiHost']));
        $viewer->setRecordActionUser(5, false);

        $viewer->renderDetail(7);
    }

    public function testViewerControllerPassesTheUser(): void
    {
        $registry = new ViewerRegistry();
        $registry->register(new ViewerDefinition('test.uiHost.records', 'Records', self::UI_TABLE, RecordActionsTestViewer::class, 'test.uiHost'));
        $registry->setTables($this->uiTables());
        $registry->setUiExtensions($this->uiExtensionsOf());

        $detail = fn (AuthContext $auth): array => (new ViewerController())
            ->detail('test.uiHost.records', 7, $auth, $registry, $this->uiTables(), $this->db())
            ->getPayload()['data']['detail'];

        $admin = $detail(new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true));
        $this->assertSame(['viewerAction', self::PURGE, self::PING, self::CONFIRM, self::UPLOAD], self::ids($admin));

        $user = $detail(new AuthContext(true, 5, 'session', 'shpd_st_test'));
        $this->assertSame(['viewerAction', self::PING, self::CONFIRM, self::UPLOAD], self::ids($user));
    }
}

/** Viewer nad fixturovou tabulkou s jednou vlastní akcí detailu. */
class RecordActionsTestViewer extends TableViewer
{
    /** @var list<array<string, mixed>> */
    public array $viewerActions = [['id' => 'viewerAction', 'label' => 'Viewer action', 'kind' => 'button']];

    /** @var array<string, mixed>|null záznam háčků, když se má lišit od řádku tabulky */
    public ?array $detailRecord = null;

    public function selectRows(?string $search, array $filters, int $pageNumber): array
    {
        return [];
    }

    public function renderRow(array $rowData): array
    {
        return [];
    }

    protected function loadDetailRecord(int $recordId): ?array
    {
        return $this->detailRecord ?? parent::loadDetailRecord($recordId);
    }

    protected function detailTabs(array $record): array
    {
        return [['id' => 'overview', 'label' => 'Přehled', 'content' => ['type' => 'text', 'text' => 'viewer']]];
    }

    protected function detailActions(array $record): array
    {
        return $this->viewerActions;
    }
}
