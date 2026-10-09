<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\ItemResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

class ItemResolverTest extends TestCase
{
    public function testOurCodeWinsOverEverything(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('economy_items'), 'code', 'K-001', 10, 40, 80)
            ->willReturn(new Row(['id' => 18]));

        $r = (new ItemResolver($db))->resolve([
            'ourCode'      => 'K-001',
            'supplierCode' => 'KONZ-001',
            'ean'          => '8590000000001',
            'name'         => 'Konzultace',
        ], 42);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(18, $r->matchedId);
        $this->assertSame('ourCode', $r->matchedBy);
    }

    public function testSupplierCodeMappingProbesAfterOurCodeMiss(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                              // ourCode miss
                new Row(['item' => 88]),           // supplier mapping hit
            );

        $r = (new ItemResolver($db))->resolve([
            'ourCode'      => 'K-001',
            'supplierCode' => 'KONZ-001',
        ], 42);

        $this->assertSame(88, $r->matchedId);
        $this->assertSame('supplierCode', $r->matchedBy);
    }

    public function testSupplierCodeSkippedWhenSupplierUnknown(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);
        // We expect NO supplier mapping query — supplierPersonId = null
        $db->expects($this->never())
            ->method('fetch')
            ->with($this->stringContains('economy_items_supplier_codes'));

        $r = (new ItemResolver($db))->resolve([
            'supplierCode' => 'KONZ-001',
            'name'         => 'Konzultace',
        ], null);

        // Should fall through to canCreate (name present, no match anywhere)
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
    }

    public function testEanProbeAfterCodeMisses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                             // ourCode miss
                new Row(['id' => 7]),             // ean hit
            );

        $r = (new ItemResolver($db))->resolve([
            'ourCode' => 'K-001',
            'ean'     => '8590000000001',
        ], null);

        $this->assertSame(7, $r->matchedId);
        $this->assertSame('ean', $r->matchedBy);
    }

    public function testSkuProbeAfterEanMiss(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                             // ourCode miss
                null,                             // ean miss
                new Row(['id' => 9]),             // sku hit
            );

        $r = (new ItemResolver($db))->resolve([
            'ourCode' => 'K-001',
            'ean'     => '8590000000001',
            'sku'     => 'K-001-EN',
        ], null);

        $this->assertSame(9, $r->matchedId);
        $this->assertSame('sku', $r->matchedBy);
    }

    public function testNameFuzzyOneCandidateMatches(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 22, 'name' => 'Konzultace IT', 'code' => 'K-IT']),
        ]);

        $r = (new ItemResolver($db))->resolve(['name' => 'Konzultace'], null);

        $this->assertSame(22, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    public function testNameFuzzyMultipleCandidatesAmbiguous(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 1, 'name' => 'Konzultace senior', 'code' => 'K-1']),
            new Row(['id' => 2, 'name' => 'Konzultace junior', 'code' => 'K-2']),
        ]);

        $r = (new ItemResolver($db))->resolve(['name' => 'Konzultace'], null);

        $this->assertSame(ResolveStatus::Ambiguous, $r->status);
        $this->assertCount(2, $r->candidates);
    }

    /**
     * Napárovaný výsledek nese předvyplnění nové položky jen s `name`
     * a `description` — bez `code` / `sku` / `ean`, přes které se řádek
     * napároval (#111 D5, U3).
     */
    public function testMatchedCarriesNameAndDescriptionOnlyAsCreatePayload(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 18]));

        $r = (new ItemResolver($db))->resolve([
            'ourCode'      => 'K-001',
            'supplierCode' => 'KONZ-001',
            'sku'          => 'K-001-EN',
            'ean'          => '8590000000001',
            'name'         => ' Konzultace ',
            'description'  => 'Hodinová sazba',
        ], 42);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(['name' => 'Konzultace', 'description' => 'Hodinová sazba'], $r->createPayload);
        $this->assertSame(
            ['status' => 'matched', 'matchedId' => 18, 'matchedBy' => 'ourCode',
             'createPayload' => ['name' => 'Konzultace', 'description' => 'Hodinová sazba']],
            $r->toArray(),
        );
    }

    public function testMatchedByNameCarriesCreatePayloadWithEmptyDescription(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 22, 'name' => 'Konzultace IT', 'code' => 'K-IT']),
        ]);

        $r = (new ItemResolver($db))->resolve(['name' => 'Konzultace'], null);

        $this->assertSame('name', $r->matchedBy);
        $this->assertSame(['name' => 'Konzultace', 'description' => ''], $r->createPayload);
    }

    public function testMatchedWithoutNameHasNoCreatePayload(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 18]));

        $r = (new ItemResolver($db))->resolve(['ourCode' => 'K-001'], null);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame([], $r->createPayload);
        $this->assertArrayNotHasKey('createPayload', $r->toArray());
    }

    public function testNoMatchWithNameProducesCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);

        $r = (new ItemResolver($db))->resolve([
            'name'        => 'Fresh Item',
            'description' => 'New stuff',
            'ourCode'     => 'F-001',
            'sku'         => 'F-001-SKU',
            'ean'         => '0000000000001',
        ], null);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('Fresh Item', $r->createPayload['name']);
        $this->assertSame('F-001', $r->createPayload['code']);
        $this->assertSame('F-001-SKU', $r->createPayload['sku']);
        $this->assertSame('0000000000001', $r->createPayload['ean']);
    }

    public function testNoMatchWithoutNameProducesNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->expects($this->never())->method('fetchAll');

        $r = (new ItemResolver($db))->resolve(['ourCode' => 'X-001'], null);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testIdentifiersOnlySkipsNameProbeAndCanCreate(): void
    {
        // Two distinct items share the name "Parkovné" in the DB — fuzzy match
        // would be ambiguous. With identifiersOnly the name probe is skipped
        // entirely (no fetchAll), so a code miss falls straight to canCreate.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);    // ourCode "100" miss
        $db->expects($this->never())->method('fetchAll');

        $r = (new ItemResolver($db))->resolve(
            ['ourCode' => '100', 'name' => 'Parkovné'],
            null,
            identifiersOnly: true,
        );

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('100', $r->createPayload['code']);
        $this->assertSame('Parkovné', $r->createPayload['name']);
    }

    public function testIdentifiersOnlyStillMatchesByCode(): void
    {
        // identifiersOnly suppresses only the name probe — a real code match
        // still wins (don't duplicate a seeded item that owns the code).
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('economy_items'), 'code', '100', 10, 40, 80)
            ->willReturn(new Row(['id' => 6]));

        $r = (new ItemResolver($db))->resolve(
            ['ourCode' => '100', 'name' => 'Parkovné'],
            null,
            identifiersOnly: true,
        );

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(6, $r->matchedId);
        $this->assertSame('ourCode', $r->matchedBy);
    }
}
