<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsGenerator;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\AccountingItemsUnavailableException;

/**
 * Sdílená orchestrace nabídky účetních položek (setup endpoint
 * + dataset-seed, #103 D22): brána dostupnosti, prerekvizity, smyčka.
 */
class AccountingItemsGeneratorTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $savedItems = [];

    /**
     * @param list<string> $existingCodes
     * @param array<string, int>|null $accountIdsByNumber null = každý účet existuje
     */
    private function generator(
        array $existingCodes = [],
        ?array $accountIdsByNumber = null,
        ?int $itemKindId = 5,
        ?int $unitId = 9,
        ?string $variant = 'default',
        bool $saveFails = false,
        bool $withAccountingColumn = true,
    ): AccountingItemsGenerator {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($itemKindId, $unitId, $accountIdsByNumber): mixed {
                $sql = (string) $args[0];
                if (str_contains($sql, 'economy_items_kinds')) {
                    return $itemKindId;
                }
                if (str_contains($sql, 'core_units')) {
                    return $unitId;
                }
                if (str_contains($sql, 'economy_accounting_accounts')) {
                    $number = (string) ($args[1] ?? '');
                    return $accountIdsByNumber === null ? crc32($number) % 100000 + 1 : ($accountIdsByNumber[$number] ?? null);
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use ($existingCodes): array {
                if (str_contains((string) $args[0], 'FROM economy_items')) {
                    return array_map(static fn (string $c): array => ['code' => $c], $existingCodes);
                }
                return [];
            },
        );

        $resolver = new ModulePathResolver([dirname(__DIR__, 5) . '/modules']);
        $offer = $this->getMockBuilder(AccountingItemsOffer::class)
            ->setConstructorArgs([$this->createMock(DataSourceConnection::class), $resolver])
            ->onlyMethods(['variant'])
            ->getMock();
        $offer->method('variant')->willReturn($variant);

        $this->savedItems = [];
        $materializer = new AccountingItemMaterializer(
            db: $db,
            offer: $offer,
            language: 'cs',
            config: $this->createMock(ConfigRuntime::class),
            saveItem: function (array $payload) use ($saveFails): DocumentResult {
                if ($saveFails) {
                    return DocumentResult::error('boom');
                }
                $this->savedItems[] = $payload;
                return DocumentResult::ok(['id' => 200 + count($this->savedItems)] + $payload);
            },
        );

        return new AccountingItemsGenerator($offer, $materializer, ['economy_items' => self::itemsTableDef($withAccountingColumn)]);
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

    // ── brána dostupnosti ──────────────────────────────────────────────────

    public function testUnavailableReasons(): void
    {
        $this->assertSame('chart_undecided', $this->generator(variant: null)->unavailableReason());
        $this->assertSame('chart_none', $this->generator(variant: 'none')->unavailableReason());
        $this->assertSame('accounting_inactive', $this->generator(withAccountingColumn: false)->unavailableReason());
        $this->assertNull($this->generator()->unavailableReason());
    }

    public function testGenerateThrowsBeforeAnyWriteWhenUnavailable(): void
    {
        $g = $this->generator(variant: null);
        try {
            $g->generate(['568201']);
            $this->fail('expected exception');
        } catch (AccountingItemsUnavailableException $e) {
            $this->assertSame('chart_undecided', $e->reason);
        }
        $this->assertSame([], $this->savedItems);
    }

    public function testUnknownVariantIsSeedMissing(): void
    {
        $this->expectException(AccountingItemsUnavailableException::class);
        $this->expectExceptionMessage('seed_missing');
        $this->generator(variant: 'xx')->generate('all');
    }

    public function testMissingPrerequisitesThrowWithReason(): void
    {
        try {
            $this->generator(itemKindId: null)->generate(['568201']);
            $this->fail('expected exception');
        } catch (AccountingItemsUnavailableException $e) {
            $this->assertSame('item_kind_missing', $e->reason);
            $this->assertSame('default', $e->variant);
        }
        try {
            $this->generator(unitId: null)->generate(['568201']);
            $this->fail('expected exception');
        } catch (AccountingItemsUnavailableException $e) {
            $this->assertSame('unit_missing', $e->reason);
        }
        $this->assertSame([], $this->savedItems);
    }

    // ── kódy a smyčka ──────────────────────────────────────────────────────

    public function testAllGeneratesEveryOfferCodeAsString(): void
    {
        $g = $this->generator();
        $codes = $g->offerCodes();
        $result = $g->generate('all');

        $this->assertCount(58, $codes, 'podnikatelská nabídka (docs/ds-setup.md D18-rev + ds-setup-12)');
        $this->assertContains('568201', $codes);
        $this->assertSame('string', gettype($codes[0]), 'numerické klíče seedu musí zpět na string');
        $this->assertCount(58, $result['created']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([], $result['failed']);
        $this->assertSame('568201', $result['created'][0]['code']);
    }

    public function testListIsDeduplicatedAndUnknownCodesAreSkipped(): void
    {
        $result = $this->generator()->generate(['568201', '568201', 'NOPE', '548100Z']);

        $this->assertSame(['568201', '548100Z'], array_column($result['created'], 'code'));
        $this->assertSame([['code' => 'NOPE', 'reason' => 'unknown_code']], $result['skipped']);
    }

    public function testExistingCodeAndMissingAccountAreSkippedWithReason(): void
    {
        $g = $this->generator(existingCodes: ['568201'], accountIdsByNumber: ['562100' => 11]);
        $result = $g->generate(['568201', '562100', '662100']);

        $this->assertSame(['562100'], array_column($result['created'], 'code'));
        $this->assertSame([
            ['code' => '568201', 'reason' => 'already_exists'],
            ['code' => '662100', 'reason' => 'account_not_found', 'accountNumber' => '662100'],
        ], $result['skipped']);
    }

    public function testStopOnFailureStopsAfterFirstFailedSave(): void
    {
        $fast = $this->generator(saveFails: true)->generate(['568201', '562100'], stopOnFailure: true);
        $this->assertCount(1, $fast['failed']);
        $this->assertSame('568201', $fast['failed'][0]['code']);
        $this->assertSame('boom', $fast['failed'][0]['message']);

        $all = $this->generator(saveFails: true)->generate(['568201', '562100']);
        $this->assertSame(['568201', '562100'], array_column($all['failed'], 'code'));
    }
}
