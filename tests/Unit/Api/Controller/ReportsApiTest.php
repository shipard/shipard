<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Controller\ReportsController;
use Shipard\Api\Response;
use Shipard\Api\Route;
use Shipard\Api\Router;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Reports\FiscalPeriodProvider;
use Shipard\Core\Reports\ReportBuilder;
use Shipard\Core\Reports\ReportDefinition;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRequest;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Core\Reports\ReportPeriodProvider;
use Shipard\Core\Utils\JsoncParser;

/** Testovací builder pro ReportRunner — echo reportId + parametrů. */
final class ReportsApiTestBuilder implements ReportBuilder
{
    public function build(ReportRequest $request): ReportResult
    {
        return new ReportResult(
            reportId: $request->reportId,
            params: $request->params,
            dataSource: $request->dataSource,
            messages: [],
            columns: [],
            rows: [],
        );
    }
}

class ReportsApiTest extends TestCase
{
    // ── Routing ─────────────────────────────────────────────────────────────

    public function testRouterResolvesCatalogAndRun(): void
    {
        $router = new Router();

        $route = $router->resolve('/api/v1/_reports', 'GET');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame(['reports', 'catalog'], [$route->controller, $route->action]);

        $route = $router->resolve('/api/v1/_reports/economy.accounting.generalLedger', 'GET');
        $this->assertInstanceOf(Route::class, $route);
        $this->assertSame('reports', $route->controller);
        $this->assertSame('run', $route->action);
        $this->assertSame('economy.accounting.generalLedger', $route->table);
    }

    public function testRouterRejectsBadMethodAndBadId(): void
    {
        $router = new Router();

        $response = $router->resolve('/api/v1/_reports', 'POST');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(405, $this->statusOf($response));

        $response = $router->resolve('/api/v1/_reports/economy.accounting.generalLedger', 'DELETE');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(405, $this->statusOf($response));

        // Nevalidní znaky v id (path traversal apod.) → 404 už na routeru.
        $response = $router->resolve('/api/v1/_reports/../etc', 'GET');
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $this->statusOf($response));
    }

    // ── Katalog ─────────────────────────────────────────────────────────────

    public function testCatalogContainsGeneralLedgerLocalized(): void
    {
        // Reálná deklarace z modulu — katalog musí obsahovat hlavní knihu
        // s lokalizovaným názvem (cs).
        $declarations = JsoncParser::parseFile(
            dirname(__DIR__, 4) . '/modules/economy/accounting/config/reports.jsonc',
        );
        $registry = new ReportRegistry();
        foreach ($declarations as $raw) {
            $registry->add(ReportDefinition::fromArray(
                ConfigLocalizer::localize($raw, 'cs'),
                'economy.accounting',
            ));
        }

        $controller = new ReportsController($registry, $this->makeRunner($registry), $this->makePeriods());
        $payload    = $controller->catalog()->getPayload();

        $this->assertTrue($payload['success']);
        $items = array_column($payload['data']['items'], null, 'id');
        $this->assertArrayHasKey('economy.accounting.generalLedger', $items);

        // Fiskální období pro picker — jednou pro celou odpověď, ne per report.
        $this->assertSame(
            ['fiscalYears' => [['name' => '2026', 'months' => 12]]],
            $payload['data']['periods'],
        );

        $ledger = $items['economy.accounting.generalLedger'];
        $this->assertSame('Hlavní kniha', $ledger['name']);
        $this->assertSame(['month', 'quarter', 'halfYear', 'year'], $ledger['periodGranularities']);
        $this->assertSame(
            [['id' => 'detail', 'type' => 'enum', 'options' => ['analytic', 'synthetic'], 'default' => 'analytic']],
            $ledger['params'],
        );
    }

    // ── Spuštění ────────────────────────────────────────────────────────────

    public function testRunReturnsReportResultShape(): void
    {
        $controller = $this->makeControllerWithFakeReport();

        $response = $controller->run('test.fake', [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '3',
        ]);
        $payload = $response->getPayload();

        $this->assertSame(200, $this->statusOf($response));
        $this->assertTrue($payload['success']);

        $data = $payload['data'];
        $this->assertSame(
            ['reportId', 'params', 'generatedAt', 'dataSource', 'status', 'messages', 'columns', 'rows'],
            array_keys($data),
        );
        $this->assertSame('test.fake', $data['reportId']);
        $this->assertSame('test-ds-id', $data['dataSource']);
        $this->assertSame('ok', $data['status']);
        $this->assertSame(
            ['fiscalYear' => '2026', 'monthFrom' => 1, 'monthTo' => 3],
            $data['params']['period'],
        );
    }

    public function testRunInvalidParamsReturns400(): void
    {
        $controller = $this->makeControllerWithFakeReport();

        $response = $controller->run('test.fake', [
            'fiscalYear' => '2026', 'monthFrom' => '3', 'monthTo' => '1',
        ]);

        $this->assertSame(400, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('BAD_REQUEST', $payload['error']['code']);
    }

    public function testRunUnknownReportReturns404(): void
    {
        $controller = $this->makeControllerWithFakeReport();

        $response = $controller->run('no.such.report', [
            'fiscalYear' => '2026', 'monthFrom' => '1', 'monthTo' => '1',
        ]);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('REPORT_NOT_FOUND', $response->getPayload()['error']['code']);
    }

    // ── VatPeriod reporty ───────────────────────────────────────────────────

    public function testCatalogWithVatPeriodReportExposesRegistrations(): void
    {
        $controller = $this->makeVatPeriodControllerWithFakeReport();
        $payload    = $controller->catalog()->getPayload();

        $this->assertTrue($payload['success']);
        $items = array_column($payload['data']['items'], null, 'id');
        $this->assertSame('vatPeriod', $items['test.vatFake']['periodSource']);
        $this->assertSame('cs', $items['test.vatFake']['vatReportType']);
        $this->assertSame([], $items['test.vatFake']['periodGranularities']);

        $periods = $payload['data']['periods'];
        $this->assertSame([['name' => '2026', 'months' => 12]], $periods['fiscalYears']);
        $this->assertCount(1, $periods['vatRegistrations']);
        $registration = $periods['vatRegistrations'][0];
        $this->assertSame(5, $registration['id']);
        $this->assertSame('CZ plátce', $registration['name']);
        $this->assertSame('CZ12345678', $registration['vatId']);
        $this->assertCount(3, $registration['periods']);
        $this->assertSame(
            ['id' => 1, 'type' => 'return', 'name' => 'Q1/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-03-31', 'locked' => false, 'docState' => 40],
            $registration['periods'][0],
        );
    }

    public function testRunVatPeriodReport(): void
    {
        $controller = $this->makeVatPeriodControllerWithFakeReport();

        $response = $controller->run('test.vatFake', ['period' => '12']);
        $payload = $response->getPayload();

        $this->assertSame(200, $this->statusOf($response));
        $this->assertTrue($payload['success']);
        $this->assertSame(
            ['period' => 12, 'reportType' => 'cs', 'name' => '02/2026', 'vatRegistration' => 5,
                'dateFrom' => '2026-02-01', 'dateTo' => '2026-02-28'],
            $payload['data']['params']['period'],
        );
    }

    public function testRunVatPeriodReportRejectsWrongType(): void
    {
        $controller = $this->makeVatPeriodControllerWithFakeReport();

        // instance 1 je přiznání, report je KH
        $response = $controller->run('test.vatFake', ['period' => '1']);

        $this->assertSame(400, $this->statusOf($response));
        $this->assertSame('BAD_REQUEST', $response->getPayload()['error']['code']);
    }

    public function testRunVatPeriodReportRejectsLegacyIntervalParams(): void
    {
        $controller = $this->makeVatPeriodControllerWithFakeReport();

        $response = $controller->run('test.vatFake', [
            'vatRegistration' => '5', 'dateFrom' => '2026-01-01', 'dateTo' => '2026-03-31',
        ]);

        $this->assertSame(400, $this->statusOf($response));
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function makeVatPeriodControllerWithFakeReport(): ReportsController
    {
        $registry = new ReportRegistry();
        $registry->add(new ReportDefinition(
            id: 'test.vatFake',
            name: 'Fake VAT report',
            builderClass: ReportsApiTestBuilder::class,
            periodGranularities: [],
            params: [],
            moduleId: 'test.module',
            periodSource: 'vatPeriod',
            vatReportType: 'cs',
        ));
        $vatPeriods = $this->makeVatPeriods();
        return new ReportsController(
            $registry,
            $this->makeRunner($registry, $vatPeriods),
            $this->makePeriods(),
            $vatPeriods,
        );
    }

    private function makeVatPeriods(): ReportPeriodProvider
    {
        return new class implements ReportPeriodProvider {
            private const PERIODS = [
                1  => ['type' => 'return', 'name' => 'Q1/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-03-31', 'locked' => false, 'docState' => 40],
                11 => ['type' => 'cs', 'name' => '01/2026', 'dateBegin' => '2026-01-01', 'dateEnd' => '2026-01-31', 'locked' => false, 'docState' => 40],
                12 => ['type' => 'cs', 'name' => '02/2026', 'dateBegin' => '2026-02-01', 'dateEnd' => '2026-02-28', 'locked' => false, 'docState' => 40],
            ];

            public function findPeriod(int $id): ?array
            {
                if (!isset(self::PERIODS[$id])) {
                    return null;
                }
                return self::PERIODS[$id] + ['id' => $id, 'registrationId' => 5, 'registrationName' => 'CZ plátce'];
            }

            public function registrationsWithPeriods(): array
            {
                $periods = [];
                foreach (self::PERIODS as $id => $p) {
                    $periods[] = ['id' => $id] + $p;
                }
                return [[
                    'id'      => 5,
                    'name'    => 'CZ plátce',
                    'vatId'   => 'CZ12345678',
                    'periods' => $periods,
                ]];
            }
        };
    }

    private function makeControllerWithFakeReport(): ReportsController
    {
        $registry = new ReportRegistry();
        $registry->add(new ReportDefinition(
            id: 'test.fake',
            name: 'Fake report',
            builderClass: ReportsApiTestBuilder::class,
            periodGranularities: ['month', 'quarter', 'year'],
            params: [],
            moduleId: 'test.module',
        ));
        return new ReportsController($registry, $this->makeRunner($registry));
    }

    private function makePeriods(): FiscalPeriodProvider
    {
        return new class implements FiscalPeriodProvider {
            public function findYearByName(string $name): ?array
            {
                return $name === '2026' ? ['id' => 1, 'name' => '2026'] : null;
            }

            public function monthsOfYear(int $fiscalYearId): array
            {
                $months = [];
                for ($i = 1; $i <= 12; $i++) {
                    $months[] = ['id' => $i, 'periodType' => 1];
                }
                return $months;
            }

            public function regularYears(): array
            {
                return [['name' => '2026', 'months' => 12]];
            }

            public function yearForDate(string $date): ?array
            {
                return null;
            }

            public function years(): array
            {
                return [];
            }
        };
    }

    private function makeRunner(ReportRegistry $registry, ?ReportPeriodProvider $vatPeriods = null): ReportRunner
    {
        $periods = $this->makePeriods();

        return new ReportRunner(
            $registry,
            $this->createMock(DataSourceConnection::class),
            null,
            'test-ds-id',
            'cs',
            $periods,
            $vatPeriods,
        );
    }

    private function statusOf(Response $response): int
    {
        $ref  = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }
}
