<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\WorkOrders\Invoicing\InvoicingRunService;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunLine;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunOptions;
use Shipard\Module\Economy\WorkOrders\Invoicing\RunReport;
use Shipard\Module\Economy\WorkOrders\WorkOrdersInvoicingController;

/**
 * HTTP obal akcí periodické fakturace (D24): vstup, mapování
 * \DomainException na 404 / 409 / 422, výstup.
 */
class WorkOrdersInvoicingControllerTest extends TestCase
{
    /** @var list<array{string, mixed}> */
    private array $calls = [];

    private function controller(?\Throwable $throw = null): WorkOrdersInvoicingController
    {
        $calls = &$this->calls;
        $service = new class($calls, $throw) extends InvoicingRunService {
            public function __construct(private array &$calls, private readonly ?\Throwable $throw)
            {
            }

            public function run(RunOptions $options): RunReport
            {
                $this->calls[] = ['run', $options];
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                $report = new RunReport($options);
                $report->add(new RunLine(6, 'S260001', 'Nájem', '2026-10-01', '2026-10-31', RunReport::OUTCOME_ISSUED, 605, null, 3));
                return $report;
            }

            public function regenerate(int $periodId, RunOptions $options): RunLine
            {
                $this->calls[] = ['regenerate', $periodId];
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                return new RunLine(6, 'S260001', 'Nájem', '2026-10-01', '2026-10-31', RunReport::OUTCOME_ISSUED, 605, null, $periodId);
            }

            public function restore(int $periodId, RunOptions $options): RunLine
            {
                $this->calls[] = ['restore', $periodId];
                if ($this->throw !== null) {
                    throw $this->throw;
                }
                return new RunLine(6, 'S260001', 'Nájem', '2026-09-01', '2026-09-30', RunReport::OUTCOME_ISSUED, 606, null, $periodId);
            }
        };

        return new class($this->createMock(DataSourceConnection::class), $this->createMock(DataSourceConfig::class), $service) extends WorkOrdersInvoicingController {
            public function __construct(DataSourceConnection $db, DataSourceConfig $dsConfig, private readonly InvoicingRunService $fake)
            {
                parent::__construct($db, null, $dsConfig);
            }

            protected function service(): InvoicingRunService
            {
                return $this->fake;
            }
        };
    }

    private static function statusOf(Response $response): int
    {
        return (int) (new \ReflectionClass($response))->getProperty('status')->getValue($response);
    }

    private function request(array $body): Request
    {
        return Request::fromArray('POST', '/api/v1/_work-orders/x', [], (string) json_encode($body), ['CONTENT_TYPE' => 'application/json']);
    }

    public function testIssueDueRunsForcedRunOfOneWorkOrder(): void
    {
        $response = $this->controller()->issueDue($this->request(['workOrder' => 6]));
        $payload = $response->getPayload();

        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['data']['counts']['issued']);
        $this->assertSame('run', $this->calls[0][0]);
        $options = $this->calls[0][1];
        $this->assertSame(6, $options->workOrderId);
        $this->assertTrue($options->force);
        $this->assertFalse($options->dryRun);
    }

    public function testMissingIdsAreBadRequests(): void
    {
        $this->assertSame(400, self::statusOf($this->controller()->issueDue($this->request([]))));
        $this->assertSame(400, self::statusOf($this->controller()->regenerate($this->request(['period' => 'abc']))));
        $this->assertSame(400, self::statusOf($this->controller()->restore($this->request(['period' => 0]))));
        $this->assertSame([], $this->calls);
    }

    public function testRegenerateAndRestoreReturnTheLine(): void
    {
        $payload = $this->controller()->regenerate($this->request(['period' => '3']))->getPayload();
        $this->assertSame(605, $payload['data']['docId']);
        $this->assertSame(['regenerate', 3], $this->calls[0]);

        $payload = $this->controller()->restore($this->request(['period' => 2]))->getPayload();
        $this->assertSame('issued', $payload['data']['outcome']);
        $this->assertSame(['restore', 2], $this->calls[1]);
    }

    public function testDomainExceptionsMapToStatusAndCode(): void
    {
        $cases = [
            [new \DomainException('Období neexistuje.', 404), 404, 'NOT_FOUND'],
            [new \DomainException('Jen koncept.', 409), 409, 'PERIOD_STATE_CONFLICT'],
            [new \DomainException('Bez řádků.', 422), 422, 'PERIOD_NOT_BUILDABLE'],
            [new \DomainException('Jiné.'), 409, 'PERIOD_STATE_CONFLICT'],
            [new \RuntimeException('Pád.'), 500, 'INTERNAL_ERROR'],
        ];
        foreach ($cases as [$exception, $status, $code]) {
            $response = $this->controller($exception)->regenerate($this->request(['period' => 3]));
            $this->assertSame($status, self::statusOf($response), $exception->getMessage());
            $this->assertSame($code, $response->getPayload()['error']['code']);
        }
    }
}
