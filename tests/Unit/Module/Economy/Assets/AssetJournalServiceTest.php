<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetJournalService;

/**
 * Náklady a výnosy karty z deníku (docs/assets.md D64): výběr řádků
 * s dimenzí karty mimo operace `asset.*`, strop počtu řádků a roční obraty.
 */
class AssetJournalServiceTest extends TestCase
{
    public function testQueriesExcludeAssetPostingOperations(): void
    {
        $queries = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$queries): array {
            $queries[] = $args;
            // První dotaz = řádky; neprázdný výsledek spustí i roční obraty.
            return count($queries) === 1
                ? [['doc_head' => 21, 'doc_number' => '2260021', 'accounting_date' => new \DateTimeImmutable('2026-06-10'),
                    'account_number' => '518100', 'text' => 'Servis', 'money_dr' => '4200.00', 'money_cr' => '0.00']]
                : [['year_name' => '2026', 'expenses' => '4200.00', 'revenues' => '0.00', 'other_dr' => '0.00', 'other_cr' => '0.00']];
        });

        $overview = (new AssetJournalService($db))->overview(68);

        $this->assertCount(2, $queries);
        foreach ($queries as $query) {
            $this->assertStringContainsString(
                '[j].[asset] = %i AND ([j].[operation] IS NULL OR [j].[operation] NOT LIKE %s)',
                $query[0],
            );
        }
        $this->assertSame([68, 'asset.%', AssetJournalService::ROW_LIMIT + 1], array_slice($queries[0], 1));
        $this->assertSame(['5%', '6%', '5%', '6%', '5%', '6%', 68, 'asset.%'], array_slice($queries[1], 1));

        $this->assertSame([[
            'docId' => 21, 'docNumber' => '2260021', 'date' => '2026-06-10', 'accountNumber' => '518100',
            'text' => 'Servis', 'moneyDr' => 4200.0, 'moneyCr' => 0.0,
        ]], $overview['rows']);
        $this->assertSame(
            [['year' => '2026', 'expenses' => 4200.0, 'revenues' => 0.0, 'otherDr' => 0.0, 'otherCr' => 0.0]],
            $overview['years'],
        );
        $this->assertFalse($overview['more']);
    }

    public function testCardWithoutRowsSkipsYearTotals(): void
    {
        $calls = 0;
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function () use (&$calls): array {
            $calls++;
            return [];
        });

        $this->assertSame(['years' => [], 'rows' => [], 'more' => false], (new AssetJournalService($db))->overview(68));
        $this->assertSame(1, $calls);
        $this->assertSame([], (new AssetJournalService(null))->overview(68)['rows']);
    }

    public function testRowsAboveLimitAreCutAndFlagged(): void
    {
        $row = ['doc_head' => 1, 'doc_number' => 'X', 'accounting_date' => '2026-01-01', 'account_number' => '518100',
            'text' => 'T', 'money_dr' => 1, 'money_cr' => 0];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturn(array_fill(0, AssetJournalService::ROW_LIMIT + 1, $row));

        $overview = (new AssetJournalService($db))->overview(68);

        $this->assertCount(AssetJournalService::ROW_LIMIT, $overview['rows']);
        $this->assertTrue($overview['more']);
    }
}
