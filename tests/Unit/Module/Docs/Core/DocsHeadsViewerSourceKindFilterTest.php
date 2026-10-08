<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocsHeadsViewer;

/**
 * Filtr zdroje dokladu (`source_kind`, #110): nabídka z cfgItem
 * docs.core.sourceKinds, podmínka v dotazu; bez konfigurace žádný filtr.
 */
class DocsHeadsViewerSourceKindFilterTest extends TestCase
{
    public function testFilterOffersSourceKindsFromConfig(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['docs.core.sourceKinds', ['manual' => ['name' => 'Ručně pořízeno'], 'workOrder' => ['name' => 'Periodická fakturace']]],
            ['docs.core.viewerLabels', ['filter.sourceKind' => ['name' => 'Zdroj']]],
        ]);
        $viewer = new DocsHeadsViewer($this->createMock(DataSourceConnection::class), 'docs_core_heads');
        $viewer->setConfig($config);

        $filters = $viewer->getFilters();
        $this->assertSame([DocsHeadsViewer::FILTER_SOURCE_KIND], array_column($filters, 'id'));
        $this->assertSame('Zdroj', $filters[0]['label']);
        $this->assertSame('select', $filters[0]['type']);
        $this->assertSame(
            [['value' => 'manual', 'label' => 'Ručně pořízeno'], ['value' => 'workOrder', 'label' => 'Periodická fakturace']],
            $filters[0]['options'],
        );

        $this->assertSame([], (new DocsHeadsViewer($this->createMock(DataSourceConnection::class), 'docs_core_heads'))->getFilters());
    }

    public function testSelectRowsAppliesSourceKindCondition(): void
    {
        $captured = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$captured): array {
            $captured = $args;
            return [];
        });
        $viewer = new DocsHeadsViewer($db, 'docs_core_heads');

        $viewer->selectRows(null, [['id' => 'source_kind', 'value' => 'workOrder']], 0);
        $this->assertStringContainsString('h.`source_kind` = %s', (string) $captured[0]);
        $this->assertContains('workOrder', $captured);

        $viewer->selectRows(null, [['id' => 'source_kind', 'value' => '']], 0);
        $this->assertStringNotContainsString('source_kind', (string) $captured[0]);
    }
}
