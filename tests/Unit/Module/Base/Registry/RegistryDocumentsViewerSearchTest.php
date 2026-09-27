<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Registry;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Base\Registry\RegistryDocumentsViewer;

/**
 * Fulltext ve vieweru: hlavička přes LIKE (ft_head sloupce) + obsah příloh
 * přes MATCH (extracted_text) AGAINST — dokument se najde podle textu PDF.
 */
class RegistryDocumentsViewerSearchTest extends TestCase
{
    /** @return array{0: string, 1: array<int, mixed>} zachycené (sql, params) */
    private function selectWithSearch(?string $search): array
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

        $viewer = new RegistryDocumentsViewer($db, 'base_registry_documents');
        // viewGroup=all → žádný stavový filtr, test se soustředí na search
        $viewer->selectRows($search, [['id' => 'viewGroup', 'value' => 'all']], 1);

        return [$capturedSql, $capturedParams];
    }

    public function testSearchCombinesHeadLikeWithExtractedTextMatch(): void
    {
        [$sql, $params] = $this->selectWithSearch('výpověď');

        $this->assertStringContainsString('d.`title` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);
        $this->assertStringContainsString('d.`ref_number` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);
        $this->assertStringContainsString('d.`ai_summary` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci', $sql);
        $this->assertStringContainsString('MATCH (d.`extracted_text`) AGAINST (%s)', $sql);

        // 3× surový term pro LIKE (wildcards přidá Dibi) + 1× surový term pro MATCH
        $this->assertSame(['výpověď', 'výpověď', 'výpověď', 'výpověď'], $params);
    }

    public function testNoSearchOmitsFulltextCondition(): void
    {
        [$sql, $params] = $this->selectWithSearch(null);

        $this->assertStringNotContainsString('MATCH', $sql);
        $this->assertStringNotContainsString('LIKE', $sql);
        $this->assertSame([], $params);
    }
}
