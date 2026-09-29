<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\RegistryDocumentsViewer;

/**
 * Spodní taby Spisovny: Vše / živé šanony / Nezařazené. Id záložky je pro
 * frontend neprůhledné — `all` a `unfiled` interpretuje jen viewer, číselné
 * id je šanon; záložka šanonu nese šanon jako výchozí hodnotu nového
 * dokumentu.
 */
class RegistryDocumentsViewerTabsTest extends TestCase
{
    /** @return array{0: string, 1: array<int, mixed>} zachycené (sql, params) */
    private function selectWithBottomTab(?string $bottomTab): array
    {
        $capturedSql = '';
        $capturedParams = [];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, ...$params) use (&$capturedSql, &$capturedParams) {
                $capturedSql = $sql;
                $capturedParams = $params;
                return [];
            },
        );

        $filters = [['id' => 'viewGroup', 'value' => 'all']];
        if ($bottomTab !== null) {
            $filters[] = ['id' => 'bottomTab', 'value' => $bottomTab];
        }

        $viewer = new RegistryDocumentsViewer($db, 'base_registry_documents');
        $viewer->selectRows(null, $filters, 0);

        return [$capturedSql, $capturedParams];
    }

    public function testBottomTabsListAllThenLiveBindersThenUnfiled(): void
    {
        $capturedSql = '';
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;
                return [
                    ['id' => 5, 'name' => 'Smlouvy'],
                    ['id' => 9, 'name' => 'Pojistky'],
                ];
            },
        );

        $viewer = new RegistryDocumentsViewer($db, 'base_registry_documents');
        $tabs = $viewer->getBottomTabs();

        $this->assertStringContainsString('`docState` IN (10, 40, 80)', $capturedSql);
        $this->assertStringContainsString('ORDER BY `order_pos` ASC, `name` ASC', $capturedSql);
        // Bez configu padají popisky Vše / Nezařazené na anglický fallback.
        $this->assertSame(
            [
                ['id' => 'all', 'label' => 'All'],
                ['id' => 5, 'label' => 'Smlouvy', 'newRecordDefaults' => ['binder' => 5]],
                ['id' => 9, 'label' => 'Pojistky', 'newRecordDefaults' => ['binder' => 9]],
                ['id' => 'unfiled', 'label' => 'Unfiled'],
            ],
            $tabs,
        );
        $this->assertNull($viewer->getDefaultBottomTab());
    }

    public function testAllTabAndMissingTabApplyNoBinderCondition(): void
    {
        foreach (['all', null] as $tab) {
            [$sql, $params] = $this->selectWithBottomTab($tab);
            // viewGroup=all + Vše → dotaz nemá vůbec žádnou podmínku.
            $this->assertStringNotContainsString('WHERE', $sql);
            $this->assertSame([], $params);
        }
    }

    public function testUnfiledTabFiltersNullBinder(): void
    {
        [$sql, $params] = $this->selectWithBottomTab('unfiled');

        $this->assertStringContainsString('d.`binder` IS NULL', $sql);
        $this->assertSame([], $params);
    }

    public function testBinderTabFiltersByBinderId(): void
    {
        // Hodnota z query stringu je string — viewer ji převede na id šanonu.
        [$sql, $params] = $this->selectWithBottomTab('5');

        $this->assertStringContainsString('d.`binder` = %i', $sql);
        $this->assertSame([5], $params);
    }
}
