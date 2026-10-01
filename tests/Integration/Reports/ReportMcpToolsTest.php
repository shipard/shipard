<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Api\AuthContext;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accounting\Mcp\ReportListTool;
use Shipard\Module\Economy\Accounting\Mcp\ReportRunTool;
use Shipard\Module\Economy\Accounting\Mcp\ReportToolSupport;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * MCP tooly report_list + report_run nad reálným dev DS — vzor builder testů.
 * Ověřuje obálky toolů (summary + data), MCP default detailu (synthetic)
 * a propadnutí InvalidArgumentException pro nevalidní vstup (→ -32602).
 */
class ReportMcpToolsTest extends IntegrationTestCase
{
    private ReportToolSupport $support;
    private McpInvocationContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->support = new ReportToolSupport(
            $this->dsConfig,
            new ModulePathResolver([dirname(__DIR__, 3) . '/modules']),
            'cs',
        );

        try {
            $configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        } catch (\Throwable) {
            $configRuntime = null;
        }
        $this->ctx = new McpInvocationContext(
            new AuthContext(true, 1, 'api_key'),
            $this->db,
            $this->tables,
            $configRuntime,
        );
    }

    /** @return array<string, mixed> */
    private function listReports(): array
    {
        return (new ReportListTool($this->support))->call([], $this->ctx);
    }

    public function testReportListReturnsCatalogWithFiscalYears(): void
    {
        $result = $this->listReports();

        $this->assertIsString($result['summary']);
        $this->assertGreaterThanOrEqual(3, count($result['items']));

        $ids = array_column($result['items'], 'reportId');
        $this->assertContains('economy.accounting.generalLedger', $ids);
        $this->assertContains('economy.accounting.profitLoss', $ids);
        $this->assertContains('economy.accounting.balanceSheet', $ids);

        foreach ($result['items'] as $item) {
            $this->assertNotSame('', $item['name']);
            $this->assertArrayHasKey('params', $item);
            if (($item['periodSource'] ?? 'fiscal') === 'vatPeriod') {
                // vatPeriod reporty granularity nemají a roky neneseou —
                // období jsou v top-level vatRegistrations.
                $this->assertSame([], $item['periodGranularities']);
                $this->assertArrayNotHasKey('fiscalYears', $item);
                continue;
            }
            $this->assertNotSame([], $item['periodGranularities']);
            $this->assertNotSame([], $item['fiscalYears'], 'dev DS musí mít fiskální roky');
            $this->assertArrayHasKey('name', $item['fiscalYears'][0]);
            $this->assertArrayHasKey('months', $item['fiscalYears'][0]);
        }
    }

    public function testReportRunReturnsResultWithStatusInSummary(): void
    {
        $year = $this->listReports()['items'][0]['fiscalYears'][0];

        $result = (new ReportRunTool($this->support))->call([
            'reportId'   => 'economy.accounting.generalLedger',
            'fiscalYear' => (int) $year['name'],
            'monthFrom'  => 1,
            'monthTo'    => 1,
        ], $this->ctx);

        $report = $result['report'];
        $this->assertSame('economy.accounting.generalLedger', $report['reportId']);
        $this->assertContains($report['status'], ['ok', 'warnings', 'errors']);
        $this->assertArrayHasKey('columns', $report);
        $this->assertArrayHasKey('rows', $report);

        $this->assertStringContainsString('status: ' . $report['status'], $result['summary']);
        $this->assertStringContainsString((string) $year['name'], $result['summary']);

        // Default detailu pro MCP je synthetic (menší výstup pro LLM).
        $this->assertSame('synthetic', $report['params']['detail']);
    }

    public function testReportRunExplicitDetailAnalytic(): void
    {
        $year = $this->listReports()['items'][0]['fiscalYears'][0];

        $result = (new ReportRunTool($this->support))->call([
            'reportId'   => 'economy.accounting.generalLedger',
            'fiscalYear' => (int) $year['name'],
            'monthFrom'  => 1,
            'monthTo'    => 1,
            'detail'     => 'analytic',
        ], $this->ctx);

        $this->assertSame('analytic', $result['report']['params']['detail']);
    }

    public function testReportRunGenericParamsObject(): void
    {
        $year = $this->listReports()['items'][0]['fiscalYears'][0];
        $args = [
            'reportId'   => 'economy.accounting.generalLedger',
            'fiscalYear' => (int) $year['name'],
            'monthFrom'  => 1,
            'monthTo'    => 1,
        ];

        // `params` má přednost před výchozím detailem MCP (synthetic).
        $result = (new ReportRunTool($this->support))->call($args + ['params' => ['detail' => 'analytic']], $this->ctx);
        $this->assertSame('analytic', $result['report']['params']['detail']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown parameter 'groupBy'");
        (new ReportRunTool($this->support))->call($args + ['params' => ['groupBy' => 'type']], $this->ctx);
    }

    public function testReportRunMissingParameterThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fiscalYear');
        (new ReportRunTool($this->support))->call([
            'reportId'  => 'economy.accounting.generalLedger',
            'monthFrom' => 1,
            'monthTo'   => 1,
        ], $this->ctx);
    }

    public function testReportRunUnknownReportThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('report_list');
        (new ReportRunTool($this->support))->call([
            'reportId'   => 'economy.accounting.nonsense',
            'fiscalYear' => 2026,
            'monthFrom'  => 1,
            'monthTo'    => 1,
        ], $this->ctx);
    }

    public function testReportRunInvalidRangeThrows(): void
    {
        $year = $this->listReports()['items'][0]['fiscalYears'][0];

        $this->expectException(\InvalidArgumentException::class);
        (new ReportRunTool($this->support))->call([
            'reportId'   => 'economy.accounting.generalLedger',
            'fiscalYear' => (int) $year['name'],
            'monthFrom'  => 3,
            'monthTo'    => 1,
        ], $this->ctx);
    }
}
