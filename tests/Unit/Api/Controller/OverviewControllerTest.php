<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\OverviewController;
use Shipard\Api\Response;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Overview\OverviewBlockRegistry;
use Shipard\Core\Overview\OverviewCollector;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Tests\Fixtures\Core\Overview\StaticBlock;

/** `GET /_ui/overview` (#120 D10): 401 anonym, 403 ne-admin, 200 admin; kontext se staví až po kontrole práv. */
final class OverviewControllerTest extends TestCase
{
    private int $contextBuilds = 0;

    public function testAnonymousGets401WithoutBuildingContext(): void
    {
        $resp = $this->controller()->index(AuthContext::anonymous());

        $this->assertSame(401, $this->getStatus($resp));
        $this->assertSame(0, $this->contextBuilds);
    }

    public function testNonAdminGets403WithoutBuildingContext(): void
    {
        $resp = $this->controller()->index(new AuthContext(true, 1, 'session', 'shpd_st_test'));

        $this->assertSame(403, $this->getStatus($resp));
        $this->assertSame('FORBIDDEN', $resp->getPayload()['error']['code']);
        $this->assertSame(0, $this->contextBuilds);
    }

    public function testAdminGetsSections(): void
    {
        $resp = $this->controller()->index(new AuthContext(true, 1, 'session', 'shpd_st_test', isAdmin: true));

        $this->assertSame(200, $this->getStatus($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame('2026-10-16', $data['asOf']);
        $this->assertSame(['money'], array_column($data['sections'], 'id'));
        $this->assertSame(['fixture:1'], array_column($data['sections'][0]['items'], 'id'));
        $this->assertSame(1, $this->contextBuilds);
    }

    private function controller(): OverviewController
    {
        $registry = new OverviewBlockRegistry();
        $registry->add('fixture', 'money', 10, new StaticBlock());
        $db = $this->createMock(DataSourceConnection::class);

        return new OverviewController($registry, new OverviewCollector(), function () use ($db): OverviewContext {
            $this->contextBuilds++;
            return new OverviewContext(
                $db, null, 'cs', new \DateTimeImmutable('2026-10-16'),
                new ReportRunner(new ReportRegistry(), $db, null, 'test-ds'),
            );
        });
    }

    private function getStatus(Response $response): int
    {
        return (new \ReflectionClass($response))->getProperty('status')->getValue($response);
    }
}
