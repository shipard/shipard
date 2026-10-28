<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\NavigationController;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Skupina Reporty v hlavní navigaci (Fáze 3, docs/reports.md D10) — report
 * deklarace s navSection se seskupí do `{id: 'reports', children: [...]}`
 * v cílové sekci; child je panel leaf s panelParams.reportId. Reálné moduly
 * z modules/, cfgItem mockovaný (vzor NavigationControllerTest).
 */
class NavigationReportsTest extends TestCase
{
    private string $dsDir;
    private ModulePathResolver $resolver;
    private NavigationController $ctrl;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd_navrep_' . uniqid('', true);
        mkdir($this->dsDir . '/config', 0755, true);
        $this->resolver = new ModulePathResolver([dirname(__DIR__, 4) . '/modules']);
        $this->ctrl     = new NavigationController();
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath($this->dsDir . '/test.log');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink($this->dsDir . '/test.log');
        @unlink($this->dsDir . '/config/main.json');
        @rmdir($this->dsDir . '/config');
        @rmdir($this->dsDir);
    }

    // --- helpers ---

    private function config(): DataSourceConfig
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'            => 'test-test-test-test',
            'name'          => 'Testovací firma',
            'database_name' => 'x',
            'database_user' => 'x',
            'created'       => '2026-01-01T00:00:00+00:00',
            'modules'       => ['install.base'],
        ]));
        return new DataSourceConfig($this->dsDir);
    }

    private function configRuntime(string $language): ConfigRuntime
    {
        $raw       = JsoncParser::parseFile(dirname(__DIR__, 4) . '/modules/install/base/config/navSections.jsonc');
        $localized = ConfigLocalizer::localize($raw, $language);

        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(
            fn(string $id) => $id === 'global.navSections' ? $localized : null,
        );
        return $cfg;
    }

    /** @return array<int, array> the navigation tree */
    private function tree(string $language = 'cs', ?AuthContext $auth = null): array
    {
        $resp = $this->ctrl->navigation(
            $this->config(),
            $this->resolver,
            $language,
            $this->configRuntime($language),
            null,
            $auth ?? new AuthContext(isAuthenticated: true, userId: 1, isAdmin: true),
            [],
        );
        return $resp->getPayload()['data'];
    }

    private function reportsGroup(array $tree): ?array
    {
        foreach ($tree as $node) {
            if (($node['id'] ?? null) !== 'accounting') {
                continue;
            }
            foreach ($node['children'] as $child) {
                if (($child['id'] ?? null) === 'reports') {
                    return $child;
                }
            }
        }
        return null;
    }

    // --- tests ---

    public function testAccountingSectionContainsReportsGroup(): void
    {
        $group = $this->reportsGroup($this->tree());

        $this->assertNotNull($group, 'accounting section must contain the reports group');
        $this->assertSame('Reporty', $group['label']);
        $this->assertArrayNotHasKey('_section', $group);
        $this->assertArrayNotHasKey('_order', $group);

        // Reporty v pořadí dle navOrder (účetní 60–62, DPH 70–72).
        $this->assertSame(
            [
                'report:economy.accounting.generalLedger',
                'report:economy.accounting.profitLoss',
                'report:economy.accounting.balanceSheet',
                'report:economy.vat.returnLive',
                'report:economy.vat.controlStatementLive',
                'report:economy.vat.recapitulativeStatementLive',
            ],
            array_column($group['children'], 'id'),
        );
        $this->assertSame(
            [
                'Hlavní kniha', 'Výsledovka', 'Rozvaha',
                'Přiznání k DPH — živě', 'Kontrolní hlášení — živě', 'Souhrnné hlášení — živě',
            ],
            array_column($group['children'], 'label'),
        );

        foreach ($group['children'] as $child) {
            $this->assertSame('panel', $child['type']);
            $this->assertSame('reports', $child['panelId']);
            $this->assertSame('chart', $child['icon']);
            $this->assertSame(
                str_replace('report:', '', $child['id']),
                $child['panelParams']['reportId'],
            );
        }
    }

    public function testReportsGroupLocalizedEn(): void
    {
        $group = $this->reportsGroup($this->tree('en'));

        $this->assertNotNull($group);
        $this->assertSame('Reports', $group['label']);
        $this->assertSame(
            [
                'General ledger', 'Profit and loss', 'Balance sheet',
                'VAT return (live)', 'VAT control statement (live)', 'Recapitulative statement (live)',
            ],
            array_column($group['children'], 'label'),
        );
    }

    public function testReportsGroupVisibleToNonAdmin(): void
    {
        // Reporty čtou deník — žádné adminOnly, ne-admin je vidět musí.
        $group = $this->reportsGroup($this->tree('cs', new AuthContext(isAuthenticated: true, userId: 2, isAdmin: false)));

        $this->assertNotNull($group);
        $this->assertCount(6, $group['children']);
    }

    public function testReportsGroupOrderedAfterAccountingViewers(): void
    {
        $tree = $this->tree();
        foreach ($tree as $node) {
            if (($node['id'] ?? null) !== 'accounting') {
                continue;
            }
            $ids = array_column($node['children'], 'id');
            // navOrder 60 řadí skupinu za deník (10) a účtový rozvrh (20).
            $this->assertSame('reports', end($ids));
            return;
        }
        $this->fail('accounting section not found');
    }
}
