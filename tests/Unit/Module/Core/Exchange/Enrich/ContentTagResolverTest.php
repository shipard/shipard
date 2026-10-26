<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Enrich;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Economy\Items\AccountingItemsOffer;

class ContentTagResolverTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $items      řádky economy_items (fetchAll)
     * @param array<string, mixed>|null  $rule       řádek pravidla (fetch)
     * @param array<string, string>      $offerMap   tag → fallback účet z nabídky
     * @param array<string, mixed>       $defaults   cfgItem contentTagDefaults
     */
    private function resolver(
        array $items = [],
        ?array $rule = null,
        array $offerMap = [],
        array $defaults = [],
    ): ContentTagResolver {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new Row($row),
            $items,
        ));
        $db->method('fetch')->willReturn($rule !== null ? new Row($rule) : null);

        $offer = $this->createMock(AccountingItemsOffer::class);
        $offer->method('defaultAccountForTag')->willReturnCallback(
            static fn(string $tag): ?string => $offerMap[$tag] ?? null,
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.items.contentTagDefaults', $defaults],
        ]);

        return new ContentTagResolver($db, $offer, $config);
    }

    /** @return array<string, mixed> */
    private function itemRow(
        int $id,
        string $code,
        array $tags,
        ?string $accountNumber = '503100',
        ?string $name = null,
    ): array {
        return [
            'id'             => $id,
            'code'           => $code,
            'name'           => $name ?? ('Položka ' . $code),
            'content_tags'   => json_encode($tags),
            'account_number' => $accountNumber,
        ];
    }

    // ── Pravidla ────────────────────────────────────────────────────────────

    public function testRuleHitNormalizesCompanyId(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->anything(), '12345678')
            ->willReturn(new Row(['id' => 7, 'tag' => 'vehicle.fuel', 'origin' => 'learned']));

        $resolver = new ContentTagResolver($db);
        $hit = $resolver->resolveTagByRule(' 123 45-678 ');

        $this->assertSame(['id' => 7, 'tag' => 'vehicle.fuel', 'origin' => 'learned'], $hit);
    }

    public function testRuleMissReturnsNull(): void
    {
        $this->assertNull($this->resolver()->resolveTagByRule('12345678'));
    }

    public function testEmptyCompanyIdSkipsLookup(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $this->assertNull((new ContentTagResolver($db))->resolveTagByRule('  —  '));
    }

    // ── Resolution štítek → položka ─────────────────────────────────────────

    public function testSingleTaggedItemYieldsSuggestion(): void
    {
        $resolver = $this->resolver(items: [
            $this->itemRow(11, '503100', ['vehicle.fuel'], '503100', 'Spotřeba PHM'),
            $this->itemRow(12, '518100', ['vehicle.parking']),
        ]);

        $res = $resolver->resolveItemForTag('vehicle.fuel');

        $this->assertSame('item', $res['status']);
        $this->assertSame(['ourCode' => '503100', 'account' => '503100'], $res['suggested']);
        $this->assertSame('Spotřeba PHM', $res['itemName']);
        $this->assertSame(11, $res['sourceItemId']);
    }

    public function testItemWithoutAccountSuggestsOnlyOurCode(): void
    {
        $resolver = $this->resolver(items: [
            $this->itemRow(11, 'FUEL', ['vehicle.fuel'], accountNumber: null),
        ]);

        $res = $resolver->resolveItemForTag('vehicle.fuel');

        $this->assertSame('item', $res['status']);
        $this->assertSame(['ourCode' => 'FUEL'], $res['suggested']);
    }

    public function testTwoTaggedItemsAreAmbiguous(): void
    {
        $resolver = $this->resolver(items: [
            $this->itemRow(11, 'FUEL-A', ['vehicle.fuel']),
            $this->itemRow(12, 'FUEL-B', ['vehicle.fuel']),
        ]);

        $res = $resolver->resolveItemForTag('vehicle.fuel');

        $this->assertSame('ambiguous', $res['status']);
        $this->assertSame([], $res['suggested']);
        $this->assertSame(['FUEL-A', 'FUEL-B'], $res['candidates']);
    }

    public function testNoTaggedItemFallsBackToOfferAccount(): void
    {
        $resolver = $this->resolver(offerMap: ['vehicle.fuel' => '503100']);

        $res = $resolver->resolveItemForTag('vehicle.fuel');

        $this->assertSame('accountOnly', $res['status']);
        $this->assertSame(['account' => '503100'], $res['suggested']);
    }

    public function testNoMappingAnywhereIsUnmapped(): void
    {
        $res = $this->resolver()->resolveItemForTag('admin.other');

        $this->assertSame('unmapped', $res['status']);
        $this->assertSame([], $res['suggested']);
    }

    public function testTrashedItemDoesNotCount(): void
    {
        // Mock fetchAll simuluje SQL filtr docState IN (10,40,80): položka
        // v Koši se do výsledku vůbec nedostane → fallback na nabídku.
        $resolver = $this->resolver(items: [], offerMap: ['vehicle.fuel' => '503100']);

        $this->assertSame('accountOnly', $resolver->resolveItemForTag('vehicle.fuel')['status']);
    }

    // ── amountGuard + vatHint ───────────────────────────────────────────────

    private const GUARD_DEFAULTS = [
        'it.hardware' => ['amountGuard' => ['over' => 80000, 'action' => 'review']],
        'people.catering' => ['vatHint' => 'nonDeductible'],
    ];

    public function testAmountGuardBlocksOverLimit(): void
    {
        $resolver = $this->resolver(defaults: self::GUARD_DEFAULTS);

        $guard = $resolver->amountGuardFor('it.hardware', ['totalPrice' => 95000]);

        $this->assertNotNull($guard);
        $this->assertSame('review', $guard['action']);
    }

    public function testAmountGuardAllowsUnderLimit(): void
    {
        $resolver = $this->resolver(defaults: self::GUARD_DEFAULTS);

        $this->assertNull($resolver->amountGuardFor('it.hardware', ['totalPrice' => 12000]));
        $this->assertNull($resolver->amountGuardFor('it.hardware', []));
        $this->assertNull($resolver->amountGuardFor('vehicle.fuel', ['totalPrice' => 999999]));
    }

    public function testVatHint(): void
    {
        $resolver = $this->resolver(defaults: self::GUARD_DEFAULTS);

        $this->assertSame('nonDeductible', $resolver->vatHintFor('people.catering'));
        $this->assertNull($resolver->vatHintFor('vehicle.fuel'));
    }

    public function testNormalizeCompanyId(): void
    {
        $this->assertSame('12345678', ContentTagResolver::normalizeCompanyId('123 456 78'));
        $this->assertSame('CZ12345678', ContentTagResolver::normalizeCompanyId('cz-123.456/78'));
        $this->assertSame('', ContentTagResolver::normalizeCompanyId(' — '));
    }

    public function testRuleLookupTriesFormWithoutLeadingZerosForOldRules(): void
    {
        // #118 D16: pravidlo uložené před normalizací bez vedoucích nul se najde jako druhá varianta.
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnCallback(static fn(string $sql, string $companyId): ?Row =>
                $companyId === '6947' ? new Row(['id' => 9, 'tag' => 'it.software', 'origin' => 'learned']) : null);

        $hit = (new ContentTagResolver($db))->resolveTagByRule('6947', 'cz');

        $this->assertSame(['id' => 9, 'tag' => 'it.software', 'origin' => 'learned'], $hit);
    }

    public function testNormalizeCompanyIdWithCountryPadsAndKeepsLegacyKeyShape(): void
    {
        $this->assertSame('00006947', ContentTagResolver::normalizeCompanyId(' 6947 ', 'cz'));
        $this->assertSame('6947', ContentTagResolver::normalizeCompanyId(' 6947 ', 'us'));
        $this->assertSame('CZ12345678', ContentTagResolver::normalizeCompanyId('cz-123.456/78', 'cz'));
    }
}
