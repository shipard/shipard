<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\SpecialPlaceSupply;

/**
 * Zvláštní místo plnění podle štítků řádků (#89 D3, D17, D19–D21):
 * klasifikace řádku (štítek z bloku → štítky položky) a predikát „všechny
 * položkové řádky jsou `special`“ — jediné místo pravidla pro applier
 * i strop pásma karty návrhu.
 */
final class SpecialPlaceSupplyTest extends TestCase
{
    private const TAXONOMY = [
        'travel.accommodation' => ['name' => 'Ubytování', 'crossBorderSupply' => 'special'],
        'travel.fares'         => ['name' => 'Jízdné', 'crossBorderSupply' => 'special'],
        'vehicle.toll'         => ['name' => 'Mýto', 'crossBorderSupply' => 'special'],
        'admin.fees'           => ['name' => 'Správní poplatky'],
        'it.software'          => ['name' => 'Software', 'crossBorderSupply' => 'services'],
        'goods.stock'          => ['name' => 'Zboží', 'crossBorderSupply' => 'goods'],
        'people.training'      => ['name' => 'Školení'],
        'broken'               => ['name' => 'Chyba', 'crossBorderSupply' => 'nonsense'],
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<array{rowIndex: int, tag: string}> $rowExceptions
     * @return array<string, mixed>
     */
    private function canonical(array $rows, ?string $tag, array $rowExceptions = []): array
    {
        $canonical = ['rows' => $rows];
        if ($tag !== null) {
            $canonical['_resolve']['contentTag'] = ['tag' => $tag, 'rowExceptions' => $rowExceptions];
        }
        return $canonical;
    }

    public function testTagSupplyReadsTaxonomyAndRejectsUnknownValues(): void
    {
        $this->assertSame('special', SpecialPlaceSupply::tagSupplyOf(self::TAXONOMY, 'travel.accommodation'));
        $this->assertSame('services', SpecialPlaceSupply::tagSupplyOf(self::TAXONOMY, 'it.software'));
        $this->assertNull(SpecialPlaceSupply::tagSupplyOf(self::TAXONOMY, 'people.training'));
        $this->assertNull(SpecialPlaceSupply::tagSupplyOf(self::TAXONOMY, 'broken'));
        $this->assertNull(SpecialPlaceSupply::tagSupplyOf(self::TAXONOMY, 'unknown.tag'));
        $this->assertNull(SpecialPlaceSupply::tagSupplyOf([], 'travel.accommodation'));
    }

    public function testAllItemRowsWithSpecialDocumentTag(): void
    {
        $canonical = $this->canonical([['rowKind' => 'item'], ['item' => ['name' => 'Snídaně']]], 'travel.accommodation');

        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY, []));
    }

    public function testRowExceptionWithOtherSupplyBreaksTheRule(): void
    {
        $canonical = $this->canonical(
            [['rowKind' => 'item'], ['rowKind' => 'item']],
            'travel.accommodation',
            [['rowIndex' => 1, 'tag' => 'it.software']],
        );

        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY, []));
    }

    public function testRowExceptionCanMakeRowSpecial(): void
    {
        // Štítek dokladu special není; oba řádky ho mají výjimkou.
        $canonical = $this->canonical(
            [['rowKind' => 'item'], ['rowKind' => 'item']],
            'it.software',
            [['rowIndex' => 0, 'tag' => 'travel.accommodation'], ['rowIndex' => 1, 'tag' => 'travel.accommodation']],
        );

        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY, []));
    }

    public function testNonItemAndAccountingRowsAreIgnored(): void
    {
        // Textový a kontační řádek o pravidlu nerozhodují — index řádku
        // výjimky je index v canonical.rows, ne pořadí položkových řádků.
        $canonical = $this->canonical(
            [
                ['rowKind' => 'text'],
                ['rowKind' => 'item'],
                ['rowKind' => 'item', 'accSide' => 'debit'],
            ],
            'travel.accommodation',
            [['rowIndex' => 2, 'tag' => 'it.software']],
        );

        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY, []));
    }

    public function testDocumentWithoutItemRowsIsNotSpecial(): void
    {
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical([], 'travel.accommodation'), self::TAXONOMY, []));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial(
            $this->canonical([['rowKind' => 'text']], 'travel.accommodation'),
            self::TAXONOMY,
            [],
        ));
    }

    public function testWithoutTagBlockOrTaxonomyIsNotSpecial(): void
    {
        $rows = [['rowKind' => 'item']];

        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical($rows, null), self::TAXONOMY, []));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical($rows, 'travel.accommodation'), [], []));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical($rows, 'people.training'), self::TAXONOMY, []));
    }

    // ── Štítek z položky pokrytého řádku (D19) ──────────────────────────────

    /** Mapa kód položky → štítky, jak ji vrací ItemContentTags::forCodes(). */
    private const ITEM_TAGS = [
        'cest'  => ['travel.accommodation', 'travel.fares'],
        'myto'  => ['vehicle.toll', 'admin.fees'],
        'ostat' => ['vehicle.toll', 'it.software', 'people.training'],
        'soft'  => ['it.software'],
        'skol'  => ['people.training'],
    ];

    /** @return array{state: ?string, source: ?string, tag: ?string, item: ?string} */
    private function classify(array $canonical, int $idx = 0): array
    {
        return SpecialPlaceSupply::classifyRow($canonical, $idx, self::TAXONOMY, self::ITEM_TAGS);
    }

    /** @return array<string, mixed> */
    private function coveredRow(string $code): array
    {
        return ['rowKind' => 'item', 'item' => ['ourCode' => $code]];
    }

    public function testItemWithOnlySpecialTagsIsSpecial(): void
    {
        $this->assertSame(
            ['state' => 'special', 'source' => 'item', 'tag' => null, 'item' => 'CEST'],
            $this->classify($this->canonical([$this->coveredRow('CEST')], null)),
        );
    }

    public function testTagsWithoutSupplyAreNotCounted(): void
    {
        // Mýto + správní poplatky: štítek bez určení není „ne“ — položka je special.
        $this->assertSame('special', $this->classify($this->canonical([$this->coveredRow('MYTO')], null))['state']);
    }

    public function testItemWithSpecialAndOtherSupplyIsAmbiguous(): void
    {
        $this->assertSame(
            ['state' => 'ambiguous', 'source' => 'item', 'tag' => null, 'item' => 'OSTAT'],
            $this->classify($this->canonical([$this->coveredRow('OSTAT')], null)),
        );
    }

    public function testItemWithoutSpecialTagGivesNothing(): void
    {
        $none = ['state' => null, 'source' => null, 'tag' => null, 'item' => null];

        foreach (['SOFT', 'SKOL', 'NEZNAMA'] as $code) {
            $this->assertSame($none, $this->classify($this->canonical([$this->coveredRow($code)], null)), $code);
        }
        // Řádek bez položky a prázdná mapa (neaktivní / neexistující položka).
        $this->assertSame($none, $this->classify($this->canonical([['rowKind' => 'item']], null)));
        $this->assertSame($none, SpecialPlaceSupply::classifyRow(
            $this->canonical([$this->coveredRow('CEST')], null),
            0,
            self::TAXONOMY,
            [],
        ));
    }

    public function testItemCodeIsMatchedCaseInsensitively(): void
    {
        $this->assertSame('special', $this->classify($this->canonical([$this->coveredRow(' cest ')], null))['state']);
    }

    public function testBlockTagAlwaysWinsOverItem(): void
    {
        // Štítek dokladu bez zvláštního místa přebíjí special položku…
        $this->assertSame(
            ['state' => null, 'source' => 'tag', 'tag' => 'it.software', 'item' => null],
            $this->classify($this->canonical([$this->coveredRow('CEST')], 'it.software')),
        );
        // …i štítek, který místo plnění sám neurčuje…
        $this->assertNull($this->classify($this->canonical([$this->coveredRow('CEST')], 'people.training'))['state']);
        // …a výjimka řádku přebíjí nejednoznačnou položku.
        $this->assertSame(
            ['state' => 'special', 'source' => 'tag', 'tag' => 'travel.accommodation', 'item' => null],
            $this->classify($this->canonical(
                [$this->coveredRow('OSTAT')],
                'it.software',
                [['rowIndex' => 0, 'tag' => 'travel.accommodation']],
            )),
        );
    }

    public function testRowWithUserItemChoiceIsNotClassifiedByItem(): void
    {
        // Uživatel v náhledu zvolil jinou položku — canonical nese původní kód.
        $canonical = $this->canonical([$this->coveredRow('CEST'), $this->coveredRow('CEST')], null);
        $canonical['_resolve']['rows'][1]['item']['userAction'] = 'useExisting:55';

        $this->assertSame('special', $this->classify($canonical, 0)['state']);
        $this->assertNull($this->classify($canonical, 1)['state']);
        $this->assertSame(['CEST'], SpecialPlaceSupply::itemCodes($canonical));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY, self::ITEM_TAGS));
    }

    public function testAllItemRowsSpecialCombinesBlockAndItems(): void
    {
        $fromItems = $this->canonical([$this->coveredRow('CEST'), $this->coveredRow('MYTO')], null);
        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($fromItems, self::TAXONOMY, self::ITEM_TAGS));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($fromItems, self::TAXONOMY, []));

        $withAmbiguous = $this->canonical([$this->coveredRow('CEST'), $this->coveredRow('OSTAT')], null);
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($withAmbiguous, self::TAXONOMY, self::ITEM_TAGS));

        $withPlain = $this->canonical([$this->coveredRow('CEST'), $this->coveredRow('SOFT')], null);
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($withPlain, self::TAXONOMY, self::ITEM_TAGS));
    }

    public function testItemCodesListsOnlyRowsClassifiedByItem(): void
    {
        $rows = [
            $this->coveredRow('CEST'),
            $this->coveredRow('cest'),
            $this->coveredRow('MYTO'),
            ['rowKind' => 'item', 'item' => ['name' => 'Bez kódu']],
            ['rowKind' => 'text', 'item' => ['ourCode' => 'TEXT']],
            ['rowKind' => 'item', 'accSide' => 'debit', 'item' => ['ourCode' => 'KONT']],
        ];

        $this->assertSame(['CEST', 'MYTO'], SpecialPlaceSupply::itemCodes($this->canonical($rows, null)));
        // Doklad se štítkem z bloku se na položky neptá.
        $this->assertSame([], SpecialPlaceSupply::itemCodes($this->canonical($rows, 'it.software')));
    }
}
