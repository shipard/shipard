<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Items\AccountingItemMaterializer;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

/**
 * Generátor jedné účetní položky (tasks/content-tag-ui.md D26) — cesty
 * nabídkový kód (setup panel) a obsahový štítek (materialize endpoint).
 */
class AccountingItemMaterializerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $savedItems = [];

    /**
     * @param list<string> $existingCodes kódy už přítomné v economy_items
     * @param array<string, int> $accountIdsByNumber číslo účtu → id v osnově
     */
    private function materializer(
        array $existingCodes = [],
        array $accountIdsByNumber = [],
        ?int $itemKindId = 5,
        ?int $unitId = 9,
        ?string $variant = 'default',
        bool $saveFails = false,
    ): AccountingItemMaterializer {
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
                    return $accountIdsByNumber[(string) ($args[1] ?? '')] ?? null;
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

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', [
                'admin.other' => ['name' => 'Ostatní (bez zařazení)', 'order' => 410],
            ]],
        ]);

        $this->savedItems = [];
        return new AccountingItemMaterializer(
            db: $db,
            offer: $offer,
            language: 'cs',
            config: $config,
            saveItem: function (array $payload) use ($saveFails): DocumentResult {
                if ($saveFails) {
                    return DocumentResult::error('boom');
                }
                $this->savedItems[] = $payload;
                return DocumentResult::ok(['id' => 200 + count($this->savedItems)] + $payload);
            },
        );
    }

    // ── materializeForTag ──────────────────────────────────────────────────

    public function testTagWithOfferEntryCreatesTaggedItem(): void
    {
        $m = $this->materializer(accountIdsByNumber: ['503100' => 42]);
        $result = $m->materializeForTag('vehicle.fuel');

        $this->assertSame('created', $result['status']);
        $this->assertSame('503100', $result['code']);
        $this->assertCount(1, $this->savedItems);
        $item = $this->savedItems[0];
        $this->assertSame('503100', $item['code']);
        $this->assertSame('Spotřeba PHM', $item['name']);
        $this->assertSame(5, $item['item_kind']);
        $this->assertSame(9, $item['unit']);
        $this->assertSame(42, $item['accounting_account']);
        $this->assertSame(40, $item['docState']);
        $this->assertSame('setup.accountingItems', $item['source_kind']);
        $this->assertContains('vehicle.fuel', $item['content_tags']);
    }

    public function testGoodsStockCreatesMaterialsItemFromOffer(): void
    {
        // #103 D31 (ds-setup Task 12): goods.stock má v nabídce položku
        // Spotřeba materiálu — bez explicitního účtu jde entry cestou.
        $m = $this->materializer(accountIdsByNumber: ['501100' => 77]);
        $result = $m->materializeForTag('goods.stock');

        $this->assertSame('created', $result['status']);
        $this->assertSame('501100', $result['code']);
        $item = $this->savedItems[0];
        $this->assertSame('Spotřeba materiálu', $item['name']);
        $this->assertSame(77, $item['accounting_account']);
        $this->assertContains('goods.stock', $item['content_tags']);
    }

    public function testTagWithoutOfferEntryRequiresAccount(): void
    {
        // admin.other je jediný štítek bez položky v nabídce
        // (AccountingItemsOfferCoverageTest).
        $result = $this->materializer()->materializeForTag('admin.other');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('account_required', $result['reason']);
        $this->assertSame([], $this->savedItems);
    }

    public function testTagWithoutOfferEntryAndAccountCreatesItemNamedByTagLabel(): void
    {
        $m = $this->materializer(accountIdsByNumber: ['548100' => 77]);
        $result = $m->materializeForTag('admin.other', '548100');

        $this->assertSame('created', $result['status']);
        $this->assertSame('548100', $result['code']);
        $item = $this->savedItems[0];
        $this->assertSame('Ostatní (bez zařazení)', $item['name']);
        $this->assertSame(77, $item['accounting_account']);
        $this->assertSame(['admin.other'], $item['content_tags']);
    }

    public function testCodeCollisionAppendsLetterSuffix(): void
    {
        // Kód 501100 už existuje (legacy položka bez štítků) → 501100A,
        // konvence nabídky: sdílený účet, jiný kód.
        $m = $this->materializer(
            existingCodes: ['501100'],
            accountIdsByNumber: ['501100' => 77],
        );
        $result = $m->materializeForTag('goods.stock');

        $this->assertSame('created', $result['status']);
        $this->assertSame('501100A', $result['code']);
        $this->assertSame('501100A', $this->savedItems[0]['code']);
        // source_ref sleduje skutečný kód položky.
        $this->assertSame('501100A', $this->savedItems[0]['source_ref']);
    }

    public function testAccountOverrideOnOfferEntry(): void
    {
        // Override účtu na entry cestě (D26 „s account override, je-li dán").
        $m = $this->materializer(accountIdsByNumber: ['501100' => 77]);
        $result = $m->materializeForTag('vehicle.fuel', '501100');

        $this->assertSame('created', $result['status']);
        $this->assertSame(77, $this->savedItems[0]['accounting_account']);
    }

    public function testUnknownAccountFails(): void
    {
        $result = $this->materializer()->materializeForTag('admin.other', '999999');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('account_not_found', $result['reason']);
    }

    public function testMissingPrerequisiteFailsLoudly(): void
    {
        $m = $this->materializer(accountIdsByNumber: ['503100' => 42], itemKindId: null);

        $this->assertSame('item_kind_missing', $m->missingPrerequisite());
        $result = $m->materializeForTag('vehicle.fuel');
        $this->assertSame('failed', $result['status']);
        $this->assertSame('item_kind_missing', $result['reason']);
    }

    public function testSaveFailurePropagates(): void
    {
        $m = $this->materializer(accountIdsByNumber: ['503100' => 42], saveFails: true);
        $result = $m->materializeForTag('vehicle.fuel');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('save_failed', $result['reason']);
        $this->assertSame('boom', $result['message']);
    }

    // ── materializeOfferCode ───────────────────────────────────────────────

    public function testOfferCodeCreatesItem(): void
    {
        $m = $this->materializer(accountIdsByNumber: ['568201' => 42]);
        $result = $m->materializeOfferCode('568201');

        $this->assertSame('created', $result['status']);
        $this->assertSame('568201', $result['code']);
        $this->assertSame('Bankovní poplatky', $result['name']);
        $this->assertSame(['services.banking'], $this->savedItems[0]['content_tags']);
    }

    public function testOfferCodeSkipsExistingAndUnknown(): void
    {
        $m = $this->materializer(existingCodes: ['568201']);

        $this->assertSame(
            ['status' => 'skipped', 'reason' => 'already_exists'],
            $m->materializeOfferCode('568201'),
        );
        $this->assertSame(
            ['status' => 'skipped', 'reason' => 'unknown_code'],
            $m->materializeOfferCode('000000'),
        );
        $this->assertSame([], $this->savedItems);
    }

    public function testOfferCodeSkipsAccountMissingFromChart(): void
    {
        $result = $this->materializer()->materializeOfferCode('568201');

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('account_not_found', $result['reason']);
        $this->assertSame('568201', $result['accountNumber']);
    }
}
