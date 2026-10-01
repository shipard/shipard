<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Checks;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\Checks\AwaitingActivationCheck;
use Shipard\Module\Economy\Assets\Checks\PurchaseWithoutAssetCheck;

/**
 * Alerty vazby majetku na doklady (docs/assets.md D61, D63): pořízení bez
 * karty (per doklad) a pořízený, ale nezařazený dlouhodobý majetek (per
 * karta). Výběr drží SQL — ověřuje se jeho tvar a parametry.
 */
class AssetDocumentChecksTest extends TestCase
{
    private const CATEGORIES = [
        'small'          => ['name' => 'Drobný', 'longTerm' => false],
        'tangible'       => ['name' => 'Hmotný', 'longTerm' => true, 'depreciable' => true],
        'nondepreciable' => ['name' => 'Neodepisovaný', 'longTerm' => true],
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<mixed>|null $captured
     */
    private function db(array $rows, ?array &$captured = null): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use ($rows, &$captured): array {
            $captured = $args;
            return $rows;
        });
        return $db;
    }

    private function config(?array $categories = self::CATEGORIES): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'economy.assets.categories' ? $categories : null,
        );
        return $config;
    }

    // --- pořízení bez karty ---------------------------------------------------

    public function testPurchaseWithoutAssetGivesOneFindingPerDocument(): void
    {
        $captured = null;
        $check = new PurchaseWithoutAssetCheck($this->db([
            ['id' => 4104, 'doc_number' => '2260012', 'row_count' => 2, 'amount' => '81000.00'],
            ['id' => 4105, 'doc_number' => '!0000004105', 'row_count' => 1, 'amount' => '500.00'],
        ], $captured), $this->config(), 'cs');

        $findings = $check->run();

        $this->assertCount(2, $findings);
        $this->assertSame('4104', $findings[0]->findingKey);
        $this->assertSame('warning', $findings[0]->severity);
        $this->assertSame('Doklad 2260012: pořízení majetku bez karty', $findings[0]->title);
        $this->assertStringContainsString('bez karty: 2, základ celkem 81 000,00', $findings[0]->message);
        $this->assertSame(401, $findings[0]->subjectTableId);
        $this->assertSame(4104, $findings[0]->subjectRowId);
        $this->assertSame(
            ['table' => 'docs_core_heads', 'mode' => 'edit', 'id' => 4104],
            $findings[0]->actions[0]['target'],
        );
        $this->assertTrue($findings[0]->actions[0]['primary']);
        // Doklad bez přiděleného čísla se označí id.
        $this->assertStringContainsString('#4105', $findings[1]->title);

        // Jen řádky pořízení bez karty na potvrzených dokladech; hlavička se neřeší.
        $this->assertStringContainsString(
            "[r].[operation] = %s AND ([r].[asset] IS NULL OR [r].[asset] = 0) AND [h].[docState] = %i",
            $captured[0],
        );
        $this->assertStringNotContainsString('[h].[asset]', $captured[0]);
        $this->assertSame(['purchase.asset', 40], array_slice($captured, 1));
    }

    public function testPurchaseWithoutAssetIsQuietWithoutRowsAndLocalized(): void
    {
        $this->assertSame([], (new PurchaseWithoutAssetCheck($this->db([]), $this->config(), 'cs'))->run());

        $finding = (new PurchaseWithoutAssetCheck(
            $this->db([['id' => 7, 'doc_number' => 'FP7', 'row_count' => 1, 'amount' => 10]]),
            $this->config(),
            'en',
        ))->run()[0];
        $this->assertSame('Document FP7: asset acquisition without an asset card', $finding->title);
        $this->assertSame('Open document', $finding->actions[0]['label']);
    }

    // --- majetek čeká na zařazení -----------------------------------------------

    public function testAwaitingActivationGivesOneInfoFindingPerCard(): void
    {
        $captured = null;
        $check = new AwaitingActivationCheck($this->db([
            ['id' => 68, 'asset_number' => 'MA0008', 'name' => 'Fréza', 'amount' => '268500.00'],
            ['id' => 69, 'asset_number' => null, 'name' => 'Lis', 'amount' => '1000.00'],
        ], $captured), $this->config(), 'cs');

        $findings = $check->run();

        $this->assertCount(2, $findings);
        $this->assertSame('68', $findings[0]->findingKey);
        $this->assertSame('info', $findings[0]->severity);
        $this->assertSame('Majetek MA0008 Fréza čeká na zařazení', $findings[0]->title);
        $this->assertStringContainsString('pořízení za 268 500,00', $findings[0]->message);
        $this->assertSame(450, $findings[0]->subjectTableId);
        $this->assertSame('open_viewer', $findings[0]->actions[0]['kind']);
        // Cíl v `target` — čte ho karta feedu i detail upozornění.
        $this->assertSame(['viewerId' => 'economy.assets.assets', 'recordId' => 68], $findings[0]->actions[0]['target']);
        $this->assertSame('Majetek Lis čeká na zařazení', $findings[1]->title);

        // Pořízení na 04x potvrzeného dokladu, dlouhodobé druhy z konfigurace,
        // bez potvrzeného zařazení / počátečního stavu.
        $this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM [economy_assets_events]', $captured[0]);
        $this->assertSame(
            ['purchase.asset', 40, '04%', [10, 40, 80], ['tangible', 'nondepreciable'], 40, ['activation', 'opening']],
            array_slice($captured, 1, 7),
        );
        // Jen pořízení mladší než lhůta — starší přebírá varování nesouladu pořízení (D67).
        $this->assertStringContainsString('HAVING MAX([h].[accounting_date]) >= %s', $captured[0]);
        $this->assertSame((new \DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'), $captured[8]);
    }

    public function testAwaitingActivationWithoutLongTermCategoriesDoesNotQuery(): void
    {
        $captured = null;
        $check = new AwaitingActivationCheck($this->db([['id' => 1]], $captured), $this->config(null), 'cs');

        $this->assertSame([], $check->run());
        $this->assertNull($captured);
    }
}
