<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\PrintsController;
use Shipard\Api\Controller\ViewerController;
use Shipard\Api\Middleware\CorsMiddleware;
use Shipard\Api\ReadOnlyPolicy;
use Shipard\Api\ReadOnlyVerdict;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintLanguageNotCompiledException;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;
use Shipard\Core\Viewer\ViewerRegistry;
use Shipard\Tests\Unit\Core\Prints\PrintRunnerTest;

/**
 * REST tisků (#90 D20, D21) a generická akce Tisk v detailu vieweru (D19):
 * routa, klasifikace pro read-only DS, `PrintsController` (práva, formáty,
 * mapování chyb, hlavičky PDF) a háček `ViewerController::detail()`.
 *
 * Runner je skutečný nad stub DB, fake builderem a dočasnou šablonou;
 * render službu zastupuje mock engine.
 */
class PrintsApiTest extends TestCase
{
    private const RECORD = ['id' => 5, 'kind' => 'a', 'docState' => 40, 'name' => 'Záznam'];

    /** `target.languages` akce Tisk bez konfigurace — popiskem je kód jazyka. */
    private const LANGUAGE_CODES = [
        ['id' => 'cs', 'label' => 'cs'],
        ['id' => 'en', 'label' => 'en'],
        ['id' => 'sk', 'label' => 'sk'],
        ['id' => 'de', 'label' => 'de'],
    ];

    private string $root;

    protected function setUp(): void
    {
        RenderClient::resetWarningForTesting();
        PrintsApiFakeBuilder::$fail = false;
        PrintsApiFakeBuilder::$messages = true;

        $this->root = sys_get_temp_dir() . '/shpd_printsapi_' . uniqid('', true);
        mkdir($this->root . '/test/prints/prints/sample', 0755, true);
        file_put_contents($this->root . '/test/prints/module.jsonc', '{"id": "test.prints", "name": "Prints"}');
        file_put_contents($this->root . '/test/prints/prints/sample/page.html.twig', '<p>{{ data.name }}</p>');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param array<string, mixed> $overrides */
    private static function definition(array $overrides = []): PrintDefinition
    {
        return PrintDefinition::fromArray($overrides + [
            'id'        => 'test.prints.card',
            'name'      => 'Karta',
            'table'     => 'test_prints_records',
            'filter'    => ['kind' => ['a']],
            'docStates' => [40],
            'builder'   => PrintsApiFakeBuilder::class,
            'template'  => '@test.prints/sample',
            'order'     => 10,
        ], 'test.prints');
    }

    private static function registry(PrintDefinition ...$definitions): PrintRegistry
    {
        $registry = new PrintRegistry();
        foreach ($definitions as $definition) {
            $registry->add($definition);
        }
        return $registry;
    }

    /**
     * @param array<string, mixed>|null $record
     * @param ?RenderResult $render null = render služba není nakonfigurovaná.
     * @param ?\Closure(string): ?ConfigRuntime $config Konfigurace v jazyce tisku; null = žádná.
     */
    private function controller(
        ?array $record = self::RECORD,
        ?RenderResult $render = null,
        ?PrintDefinition $definition = null,
        ?\Closure $config = null,
    ): PrintsController {
        $registry = self::registry($definition ?? self::definition());

        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);

        if ($render === null) {
            $client = new RenderClient(null);
        } else {
            $engine = $this->createStub(RenderEngineInterface::class);
            $engine->method('renderHtml')->willReturn($render);
            $client = new RenderClient(new RenderConfig('http://127.0.0.1:3000'), $engine);
        }

        $paths  = new PrintTemplatePaths(new ModulePathResolver([$this->root]));
        $runner = new PrintRunner(
            $registry,
            $db,
            $config ?? static fn (string $language) => null,
            PrintRunnerTest::languages(),
            renderer: new PrintRenderer($paths, new PrintTwigFactory($paths), $client),
        );

        return new PrintsController($registry, $runner);
    }

    private static function user(): AuthContext
    {
        return new AuthContext(true, 2, 'session', 'shpd_st_y');
    }

    private static function admin(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_x', isAdmin: true);
    }

    private static function statusOf(Response $response): int
    {
        return (new \ReflectionClass($response))->getProperty('status')->getValue($response);
    }

    private static function assertError(Response $response, int $status, string $code): void
    {
        self::assertSame($status, self::statusOf($response));
        self::assertSame($code, $response->getPayload()['error']['code']);
    }

    // ── routa + read-only ───────────────────────────────────────────────────

    public function testRouterResolvesPrintRun(): void
    {
        $route = (new Router())->resolve('/api/v1/_prints/docs.invoicesOut.invoice/123', 'GET');

        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['prints', 'run'], [$route->controller, $route->action]);
        $this->assertSame('docs.invoicesOut.invoice', $route->table);
        $this->assertSame(123, $route->id);
    }

    public function testRouterRejectsBadMethodAndBadPath(): void
    {
        $router = new Router();

        $response = $router->resolve('/api/v1/_prints/docs.invoicesOut.invoice/123', 'POST');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(405, self::statusOf($response));

        foreach ([
            '/api/v1/_prints/docs.invoicesOut.invoice',
            '/api/v1/_prints/docs.invoicesOut.invoice/abc',
            '/api/v1/_prints/Docs-invoice/1',
            '/api/v1/_prints/docs.invoicesOut.invoice/1/extra',
        ] as $path) {
            $response = $router->resolve($path, 'GET');
            $this->assertInstanceOf(Response::class, $response, $path);
            $this->assertSame(404, self::statusOf($response), $path);
        }
    }

    public function testPrintIsAllowedOnReadOnlyDataSource(): void
    {
        $this->assertSame(ReadOnlyVerdict::Allow, (new ReadOnlyPolicy())->verdict(new Route('prints', 'run')));
    }

    // ── PrintsController ────────────────────────────────────────────────────

    public function testPdfResponseHeaders(): void
    {
        $response = $this->controller(render: RenderResult::success('%PDF-1.7 fake'))
            ->run('test.prints.card', 5, [], self::user(), []);

        $this->assertSame(200, self::statusOf($response));
        $this->assertSame('%PDF-1.7 fake', $response->getPayload());
        $headers = $response->getHeaders();
        $this->assertSame('application/pdf', $headers['Content-Type']);
        $this->assertSame('inline; filename="karta-5.pdf"', $headers['Content-Disposition']);
        $this->assertSame('no-store', $headers['Cache-Control']);
        $this->assertSame((string) strlen('%PDF-1.7 fake'), $headers['Content-Length']);
        $this->assertSame('cs', $headers['Content-Language'], 'jazyk tisku, i když ho klient nevyžádal');

        // Hlášení builderu jdou hlavičkou — ASCII, po dekódování JSON.
        $this->assertMatchesRegularExpression('/^[\x21-\x7E]+$/', $headers['X-Print-Messages']);
        $this->assertSame(
            [['severity' => 'warning', 'code' => 'builder.note', 'text' => 'Poznámka builderu']],
            json_decode(rawurldecode($headers['X-Print-Messages']), true),
        );
    }

    public function testPdfContentLanguageIsTheRequestedPrintLanguage(): void
    {
        foreach (['en', 'sk', 'de'] as $language) {
            $response = $this->controller(render: RenderResult::success('%PDF-1.7 fake'))
                ->run('test.prints.card', 5, ['language' => $language], self::user(), []);

            $this->assertSame(200, self::statusOf($response));
            $this->assertSame($language, $response->getHeaders()['Content-Language']);
        }
    }

    public function testContentLanguageIsExposedToTheBrowser(): void
    {
        $exposed = (new CorsMiddleware())->applyTo(Response::success(null))->getHeaders()['Access-Control-Expose-Headers'];

        $this->assertStringContainsString('Content-Language', $exposed);
        $this->assertStringContainsString(PrintsController::MESSAGES_HEADER, $exposed);
    }

    public function testPdfWithoutBuilderMessagesHasNoMessagesHeader(): void
    {
        PrintsApiFakeBuilder::$messages = false;

        $response = $this->controller(render: RenderResult::success('%PDF-1.7 fake'))
            ->run('test.prints.card', 5, [], self::user(), []);

        $this->assertArrayNotHasKey('X-Print-Messages', $response->getHeaders());
    }

    public function testJsonIsForAdministratorsOnly(): void
    {
        $controller = $this->controller();

        self::assertError(
            $controller->run('test.prints.card', 5, ['format' => 'json'], self::user(), []),
            403,
            'FORBIDDEN_ADMIN_ONLY',
        );

        $response = $controller->run('test.prints.card', 5, ['format' => 'json', 'language' => 'en'], self::admin(), []);
        $this->assertSame(200, self::statusOf($response));
        $payload = $response->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame('test.prints.card', $payload['data']['printId']);
        $this->assertSame('en', $payload['data']['language']);
        $this->assertSame(['name' => 'Záznam'], $payload['data']['data']);
        $this->assertSame('{}', json_encode($payload['data']['texts']));
        $this->assertSame('builder.note', $payload['data']['messages'][0]['code']);
    }

    public function testUnknownPrintAndRecord(): void
    {
        self::assertError(
            $this->controller()->run('test.prints.missing', 5, [], self::user(), []),
            404,
            'PRINT_NOT_FOUND',
        );
        self::assertError(
            $this->controller(record: null)->run('test.prints.card', 99, [], self::user(), []),
            404,
            'RECORD_NOT_FOUND',
        );
    }

    public function testDraftAndOtherRecordKindAreConflicts(): void
    {
        self::assertError(
            $this->controller(record: ['docState' => 10] + self::RECORD)->run('test.prints.card', 5, [], self::user(), []),
            409,
            'PRINT_NOT_AVAILABLE',
        );
        self::assertError(
            $this->controller(record: ['kind' => 'b'] + self::RECORD)->run('test.prints.card', 5, [], self::user(), []),
            409,
            'PRINT_NOT_AVAILABLE',
        );
    }

    public function testRecordWithoutPrintableDataIsConflict(): void
    {
        PrintsApiFakeBuilder::$fail = true;

        self::assertError(
            $this->controller()->run('test.prints.card', 5, [], self::user(), []),
            409,
            'PRINT_DATA_MISSING',
        );
    }

    public function testPrintLanguageWithoutCompiledConfigIsConflict(): void
    {
        // Zdroj dat před `ds-upgrade` — konfigurace v jazyce tisku chybí.
        $controller = $this->controller(config: static fn (string $language) => $language === 'en'
            ? throw new PrintLanguageNotCompiledException($language)
            : null);

        $response = $controller->run('test.prints.card', 5, ['language' => 'en'], self::user(), []);

        self::assertError($response, 409, 'PRINT_LANGUAGE_NOT_COMPILED');
        $this->assertStringContainsString("'en'", $response->getPayload()['error']['message']);
        $this->assertStringContainsString('ds-upgrade', $response->getPayload()['error']['message']);
    }

    public function testInvalidFormatAndLanguage(): void
    {
        $controller = $this->controller();

        self::assertError($controller->run('test.prints.card', 5, ['format' => 'xml'], self::user(), []), 400, 'BAD_REQUEST');
        // HTML je nástroj CLI, REST ho nenabízí — ani administrátorovi.
        self::assertError($controller->run('test.prints.card', 5, ['format' => 'html'], self::admin(), []), 400, 'BAD_REQUEST');
        self::assertError($controller->run('test.prints.card', 5, ['format' => ['pdf']], self::user(), []), 400, 'BAD_REQUEST');
        self::assertError($controller->run('test.prints.card', 5, ['language' => 'fr'], self::user(), []), 400, 'BAD_REQUEST');
        self::assertError($controller->run('test.prints.card', 5, ['language' => ['cs']], self::user(), []), 400, 'BAD_REQUEST');
    }

    public function testRenderServiceStatesMapTo503And500(): void
    {
        $response = $this->controller()->run('test.prints.card', 5, [], self::user(), []);
        self::assertError($response, 503, 'RENDER_UNAVAILABLE');
        $this->assertSame('unconfigured', $response->getPayload()['error']['details'][0]['code']);

        $response = $this->controller(render: RenderResult::failure(RenderErrorKind::Timeout, 'slow'))
            ->run('test.prints.card', 5, [], self::user(), []);
        self::assertError($response, 503, 'RENDER_UNAVAILABLE');
        $this->assertSame('timeout', $response->getPayload()['error']['details'][0]['code']);

        $response = $this->controller(render: RenderResult::failure(RenderErrorKind::EngineError, 'HTTP 500'))
            ->run('test.prints.card', 5, [], self::user(), []);
        self::assertError($response, 500, 'RENDER_FAILED');
        $this->assertSame(
            [['field' => '_render', 'code' => 'engineError', 'message' => 'HTTP 500']],
            $response->getPayload()['error']['details'],
        );
    }

    public function testPrintFollowsTableAccessRights(): void
    {
        $system = self::definition(['table' => 'core_system_users', 'filter' => []]);
        self::assertError(
            $this->controller(definition: $system)->run('test.prints.card', 5, [], self::user(), []),
            403,
            'FORBIDDEN_SYSTEM_TABLE',
        );

        $tables = ['test_prints_records' => TableDefinition::fromArray([
            'tableId'   => 10,
            'name'      => 'test_prints_records',
            'adminOnly' => true,
            'columns'   => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true]],
        ])];
        self::assertError(
            $this->controller()->run('test.prints.card', 5, [], self::user(), $tables),
            403,
            'FORBIDDEN_ADMIN_ONLY',
        );
        $this->assertSame(
            200,
            self::statusOf($this->controller(render: RenderResult::success('%PDF'))
                ->run('test.prints.card', 5, [], self::admin(), $tables)),
        );
    }

    // ── akce Tisk v detailu vieweru ─────────────────────────────────────────

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed> `detail` z odpovědi
     */
    private function detail(array $record, ?PrintRegistry $prints, ?ConfigRuntime $config = null, string $viewer = PrintsApiPlainViewer::class): array
    {
        $viewers = new ViewerRegistry();
        $viewers->register(new ViewerDefinition(
            id: 'test.prints.records',
            name: 'Records',
            table: 'test_prints_records',
            class: $viewer,
            moduleId: 'test.prints',
            icon: null,
        ));
        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);

        $response = (new ViewerController())->detail(
            'test.prints.records', 5, self::user(), $viewers, [], $db, $config, 'cs', prints: $prints,
        );
        $this->assertSame(200, self::statusOf($response));
        return $response->getPayload()['data']['detail'];
    }

    public function testDetailOffersSinglePrintAsButton(): void
    {
        $detail = $this->detail(self::RECORD, self::registry(self::definition()));

        $this->assertSame(
            [[
                'id'      => 'print',
                'label'   => 'Print',
                'variant' => 'secondary',
                'kind'    => 'button',
                'target'  => ['printId' => 'test.prints.card', 'languages' => self::LANGUAGE_CODES],
            ]],
            $detail['actions'],
        );
    }

    public function testDetailOffersMorePrintsAsDropdownInDeclaredOrder(): void
    {
        $detail = $this->detail(self::RECORD, self::registry(
            self::definition(['id' => 'test.prints.label', 'name' => 'Štítek', 'order' => 20]),
            self::definition(),
        ));

        $this->assertCount(1, $detail['actions']);
        $this->assertSame('dropdown', $detail['actions'][0]['kind']);
        $this->assertSame('print', $detail['actions'][0]['id']);
        $this->assertSame(
            [['label' => 'Karta', 'value' => 'test.prints.card'], ['label' => 'Štítek', 'value' => 'test.prints.label']],
            $detail['actions'][0]['items'],
        );
        // Dropdown nemá `printId` (nese ho položka), jazyky ano.
        $this->assertSame(['languages' => self::LANGUAGE_CODES], $detail['actions'][0]['target']);
    }

    public function testDetailHasNoPrintActionForDraftOtherKindOrWithoutRegistry(): void
    {
        $registry = self::registry(self::definition());

        $this->assertArrayNotHasKey('actions', $this->detail(['docState' => 10] + self::RECORD, $registry));
        $this->assertArrayNotHasKey('actions', $this->detail(['kind' => 'b'] + self::RECORD, $registry));
        $this->assertArrayNotHasKey('actions', $this->detail(self::RECORD, null));
        $this->assertArrayNotHasKey('actions', $this->detail(self::RECORD, new PrintRegistry()));
    }

    public function testPrintActionIsAppendedAfterViewerActionsWithLocalizedLabel(): void
    {
        $configDir = $this->root . '/ds';
        mkdir($configDir . '/config/configuration', 0755, true);
        file_put_contents($configDir . '/config/configuration/compiled.cs.json', json_encode(['items' => [
            'core.system.viewerDefaults' => ['detailActions' => ['print' => ['name' => 'Tisk', 'variant' => 'secondary']]],
            // Popisek jazyka dokumentů bez záznamu (de) spadne na kód.
            'world.base.documentLanguages' => [
                'cs' => ['name' => 'čeština'],
                'en' => ['name' => 'angličtina'],
                'sk' => ['name' => 'slovenština'],
            ],
        ]]));

        $detail = $this->detail(
            self::RECORD,
            self::registry(self::definition()),
            ConfigRuntime::load($configDir, 'cs'),
            PrintsApiActionViewer::class,
        );

        $this->assertSame(['reaccount', 'print'], array_column($detail['actions'], 'id'));
        $this->assertSame('Tisk', $detail['actions'][1]['label']);
        $this->assertSame(
            [
                ['id' => 'cs', 'label' => 'čeština'],
                ['id' => 'en', 'label' => 'angličtina'],
                ['id' => 'sk', 'label' => 'slovenština'],
                ['id' => 'de', 'label' => 'de'],
            ],
            $detail['actions'][1]['target']['languages'],
            'jazyky tisku s popisky jazyků dokumentů v jazyce rozhraní',
        );
    }
}

class PrintsApiFakeBuilder implements PrintBuilder
{
    public static bool $fail = false;
    public static bool $messages = true;

    public function build(PrintRequest $request): PrintBuildResult
    {
        if (self::$fail) {
            throw new PrintBuildException('Record has no snapshot');
        }
        return new PrintBuildResult(
            data: ['name' => (string) $request->record['name']],
            title: 'Karta ' . $request->recordId,
            fileName: 'karta-' . $request->recordId . '.pdf',
            messages: self::$messages ? [PrintMessage::warning('builder.note', 'Poznámka builderu')] : [],
        );
    }

    public function version(): int
    {
        return 1;
    }
}

class PrintsApiPlainViewer extends TableViewer
{
    public function selectRows(?string $search, array $filters, int $page): array
    {
        return [];
    }

    public function renderRow(array $row): array
    {
        return [];
    }
}

class PrintsApiActionViewer extends PrintsApiPlainViewer
{
    public function renderDetail(int $recordId): array
    {
        return ['tabs' => [], 'actions' => [['id' => 'reaccount', 'label' => 'Přeúčtovat', 'kind' => 'button']]];
    }
}
