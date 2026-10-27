<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Alerts;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Alerts\AlertReconciler;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Alerts\AlertsViewer;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Tab Kontext v detailu upozornění (#137 D30): akce a kontext jako nadpis
 * + výpis JSON v bloku `code` (nadpisy z katalogu popisků), tab chybí bez
 * obou sloupců, nevalidní JSON se přeskočí.
 */
class AlertsViewerDetailTest extends TestCase
{
    /** @param array<string, mixed> $record */
    private function viewer(array $record): AlertsViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);
        $db->method('fetchAll')->willReturn([]);

        $viewer = new AlertsViewer($db, 'core_alerts_alerts');
        $viewer->setConfig(ConfigRuntimeFactory::fromItems([
            'core.alerts.viewerDetailLabels' => [
                'tabs'  => ['overview' => ['name' => 'Přehled'], 'context' => ['name' => 'Kontext']],
                'texts' => ['actions' => ['name' => 'Akce'], 'context' => ['name' => 'Kontext']],
            ],
        ]));
        $viewer->setLanguage('cs');
        return $viewer;
    }

    /** @param array<string, mixed> $overrides */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'id'               => 5,
            'check_id'         => 'core.secrets.health',
            'finding_key'      => 'key',
            'title'            => 'Klíč chybí',
            'message'          => 'Soubor klíče nebyl nalezen.',
            'severity'         => 30,
            'alert_state'      => AlertReconciler::STATE_ACTIVE,
            'snoozed_until'    => null,
            'first_seen_at'    => '2026-10-01 08:00:00',
            'last_seen_at'     => '2026-10-02 08:00:00',
            'seen_count'       => 2,
            'actions'          => null,
            'context'          => null,
            'subject_table_id' => null,
            'subject_row_id'   => null,
        ], $overrides);
    }

    /** @return array<string, mixed>|null */
    private function contextTab(AlertsViewer $viewer): ?array
    {
        foreach ($viewer->renderDetail(5)['tabs'] as $tab) {
            if ($tab['id'] === 'context') {
                return $tab;
            }
        }
        return null;
    }

    public function testContextTabRendersHeadingsAndCodeBlocks(): void
    {
        $actions = [['id' => 'fix', 'label' => 'Opravit', 'kind' => 'open_viewer']];
        $context = ['path' => '/opt/shipard/data-sources/x/secrets.key', 'count' => 1];
        $tab = $this->contextTab($this->viewer($this->record([
            'actions' => json_encode($actions),
            'context' => json_encode($context),
        ])));

        $this->assertNotNull($tab);
        $this->assertSame('Kontext', $tab['label']);
        $this->assertSame('composite', $tab['content']['type']);
        $blocks = $tab['content']['blocks'];
        $this->assertSame(['heading', 'code', 'heading', 'code'], array_column($blocks, 'type'));
        $this->assertSame('Akce', $blocks[0]['text']);
        $this->assertSame('Kontext', $blocks[2]['text']);
        // Čistý text výpisu JSON — bez escapování, čitelné znaky.
        $this->assertSame(json_encode($actions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $blocks[1]['text']);
        $this->assertSame(json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $blocks[3]['text']);
        $this->assertStringContainsString('"count": 1', $blocks[3]['text']);
    }

    public function testContextOnlyWhenActionsMissingOrInvalid(): void
    {
        $tab = $this->contextTab($this->viewer($this->record([
            'actions' => '{not json',
            'context' => json_encode(['a' => 1]),
        ])));

        $this->assertNotNull($tab);
        $this->assertSame(['heading', 'code'], array_column($tab['content']['blocks'], 'type'));
        $this->assertSame('Kontext', $tab['content']['blocks'][0]['text']);
    }

    public function testNoContextTabWithoutActionsAndContext(): void
    {
        $this->assertNull($this->contextTab($this->viewer($this->record())));
    }
}
