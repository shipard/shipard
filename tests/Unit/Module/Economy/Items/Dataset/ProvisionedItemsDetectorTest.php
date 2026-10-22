<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\Dataset\ProvisionedItemsDetector;
use Shipard\Module\Economy\Items\Dataset\ProvisionedItemsResult;

class ProvisionedItemsDetectorTest extends TestCase
{
    /**
     * Nabídka varianty `default` ve tvaru, jak ji vrací `AccountingItemsOffer::loadSeed()`
     * — klíčovaná kódem, číselné kódy drží PHP jako int, `548100Z` sdílí účet
     * s písmenným sufixem.
     */
    private const OFFER = [
        568201    => ['code' => '568201', 'group' => 'finance', 'name:cs' => 'Bankovní poplatky', 'name:en' => 'Bank fees', 'account' => '568201', 'contentTags' => ['services.banking']],
        562100    => ['code' => '562100', 'group' => 'finance', 'name:cs' => 'Úroky placené', 'name:en' => 'Interest paid', 'account' => '562100'],
        '548100Z' => ['code' => '548100Z', 'group' => 'finance', 'name:cs' => 'Zaokrouhlení (náklad)', 'name:en' => 'Rounding (expense)', 'account' => '548100'],
    ];

    private const CHART = ['568201', '562100', '548100'];

    /**
     * Položky zdroje dat tak, jak je seed z nabídky založil (jazyk cs).
     *
     * @return list<array<string, mixed>>
     */
    private function dsRows(): array
    {
        return [
            ['id' => 1, 'code' => '568201', 'name' => 'Bankovní poplatky', 'content_tags' => '["services.banking"]', 'account_number' => '568201'],
            ['id' => 2, 'code' => '562100', 'name' => 'Úroky placené', 'content_tags' => null, 'account_number' => '562100'],
            ['id' => 3, 'code' => '548100Z', 'name' => 'Zaokrouhlení (náklad)', 'content_tags' => null, 'account_number' => '548100'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows řádky `economy_items` se `source_kind = setup.accountingItems`
     * @param list<string> $chart čísla účtů nabídky, která osnova zdroje dat má
     */
    private function detector(
        ?string $variant,
        array $rows,
        array $chart = self::CHART,
        bool $withAccountingColumn = true,
        string $language = 'cs',
        bool $queriesExpected = true,
    ): ProvisionedItemsDetector {
        $offer = $this->createMock(AccountingItemsOffer::class);
        $offer->method('variant')->willReturn($variant);
        $offer->method('loadSeed')->willReturnCallback(
            static fn(string $v): ?array => $v === 'default' ? ['groups' => [], 'items' => self::OFFER] : null,
        );

        $db = $this->createMock(DataSourceConnection::class);
        if (!$queriesExpected) {
            $db->expects($this->never())->method('fetchAll');
        } else {
            $db->method('fetchAll')->willReturnCallback(function (string $sql, mixed ...$args) use ($rows, $chart): array {
                if (str_contains($sql, 'FROM [economy_items]')) {
                    $this->assertSame(['setup.accountingItems'], $args, 'only items generated from the offer');
                    $this->assertStringContainsString('[i.docState] <> 90', $sql);
                    $this->assertStringContainsString('LEFT JOIN [economy_accounting_accounts]', $sql);
                    return $rows;
                }
                if (str_contains($sql, 'FROM [economy_accounting_accounts]')) {
                    $this->assertSame([self::CHART, [10, 40, 80]], $args, 'offer account numbers go in as strings, active analytic accounts only');
                    $this->assertStringContainsString('[account_level] = 4', $sql);
                    return array_map(static fn(string $n): array => ['number' => $n], $chart);
                }
                $this->fail("unexpected query: {$sql}");
            });
        }

        return new ProvisionedItemsDetector(
            $db,
            $offer,
            $language,
            ['economy_items' => self::itemsTableDef($withAccountingColumn)],
        );
    }

    private static function itemsTableDef(bool $withAccountingColumn): TableDefinition
    {
        $columns = [
            ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'autoIncrement' => true, 'primaryKey' => true],
            ['id' => 'code', 'name' => 'Code', 'type' => 'varchar', 'length' => 25, 'nullable' => false],
        ];
        if ($withAccountingColumn) {
            $columns[] = ['id' => 'accounting_account', 'name' => 'Account', 'type' => 'int', 'nullable' => true];
        }
        return TableDefinition::fromArray(['tableId' => 320, 'name' => 'Items', 'columns' => $columns]);
    }

    private function assertEmptyResult(ProvisionedItemsResult $r, string $case): void
    {
        $this->assertTrue($r->isEmpty(), $case);
        $this->assertNull($r->accountingItems, $case);
        $this->assertFalse($r->isAll(), $case);
        $this->assertSame([], $r->manifestProvision(), $case);
    }

    public function testUnchangedOfferItemsGiveAllAndTheirIds(): void
    {
        $r = $this->detector('default', $this->dsRows())->detect();

        $this->assertSame('all', $r->accountingItems);
        $this->assertSame([1, 2, 3], $r->itemIds);
        $this->assertTrue($r->isAll());
        $this->assertFalse($r->isEmpty());
        $this->assertSame(['accountingItems' => 'all'], $r->manifestProvision());
    }

    public function testRenamedItemIsLeftToTheExporter(): void
    {
        $rows = $this->dsRows();
        $rows[1]['name'] = 'Úroky z úvěru';

        $r = $this->detector('default', $rows)->detect();

        $this->assertSame(['568201', '548100Z'], $r->accountingItems, 'list of unchanged codes in offer order');
        $this->assertSame([1, 3], $r->itemIds);
        $this->assertFalse($r->isAll());
        $this->assertSame(['accountingItems' => ['568201', '548100Z']], $r->manifestProvision());
    }

    public function testChangedAccountOrTagsOrCodeOutsideOfferIsNotUnchanged(): void
    {
        $rows = $this->dsRows();
        $rows[0]['content_tags'] = '["services.banking","admin.other"]'; // doplněný štítek
        $rows[1]['account_number'] = '562200';                           // přeúčtováno
        // kód se sufixem z materializeForTag — nese source_kind nabídky, v nabídce není
        $rows[] = ['id' => 9, 'code' => '518100A', 'name' => 'Ostatní služby', 'content_tags' => null, 'account_number' => '518100'];

        $r = $this->detector('default', $rows)->detect();

        $this->assertSame(['548100Z'], $r->accountingItems);
        $this->assertSame([3], $r->itemIds);
    }

    public function testAccountMissingFromChartDoesNotBlockAll(): void
    {
        // Seed 562100 přeskočil (account_not_found) — položka chybí, osnova účet nemá.
        $rows = $this->dsRows();
        unset($rows[1]);
        $r = $this->detector('default', array_values($rows), ['568201', '548100'])->detect();
        $this->assertSame('all', $r->accountingItems);
        $this->assertSame([1, 3], $r->itemIds);

        // Položka existuje, ale její účet už v osnově aktivní není — seed `all`
        // by ji nezaložil, proto zůstává exportéru; zbytek je dál `all`.
        $r = $this->detector('default', $this->dsRows(), ['568201', '562100'])->detect();
        $this->assertSame('all', $r->accountingItems);
        $this->assertSame([1, 2], $r->itemIds);
    }

    public function testNameIsComparedInDataSourceLanguage(): void
    {
        $this->assertEmptyResult(
            $this->detector('default', $this->dsRows(), language: 'en')->detect(),
            'Czech names on an English data source are renames',
        );

        $rows = $this->dsRows();
        $rows[0]['name'] = 'Bank fees';
        $rows[1]['name'] = 'Interest paid';
        $rows[2]['name'] = 'Rounding (expense)';
        $this->assertSame('all', $this->detector('default', $rows, language: 'en')->detect()->accountingItems);
    }

    public function testNoProvisionedItemsGivesEmptyResult(): void
    {
        $this->assertEmptyResult($this->detector('default', [])->detect(), 'no items from the offer');
        $this->assertSame([], ProvisionedItemsResult::none()->itemIds);
    }

    public function testWithoutVariantSeedOrAccountingColumnResultIsEmptyWithoutQueries(): void
    {
        $this->assertEmptyResult(
            $this->detector(null, $this->dsRows(), queriesExpected: false)->detect(),
            'economy.accountChart undecided',
        );
        $this->assertEmptyResult(
            $this->detector('npo', $this->dsRows(), queriesExpected: false)->detect(),
            'offer seed unavailable for the variant',
        );
        $this->assertEmptyResult(
            $this->detector('default', $this->dsRows(), withAccountingColumn: false, queriesExpected: false)->detect(),
            'economy.accounting inactive — no accounting_account column to join on',
        );
    }
}
