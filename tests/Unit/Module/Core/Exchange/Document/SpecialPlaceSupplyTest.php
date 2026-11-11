<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\SpecialPlaceSupply;

/**
 * Predikát „všechny položkové řádky mají štítek se zvláštním místem
 * plnění“ (#89 D3, D17) — jediné místo pravidla pro applier i strop pásma
 * karty návrhu.
 */
final class SpecialPlaceSupplyTest extends TestCase
{
    private const TAXONOMY = [
        'travel.accommodation' => ['name' => 'Ubytování', 'crossBorderSupply' => 'special'],
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

        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY));
    }

    public function testRowExceptionWithOtherSupplyBreaksTheRule(): void
    {
        $canonical = $this->canonical(
            [['rowKind' => 'item'], ['rowKind' => 'item']],
            'travel.accommodation',
            [['rowIndex' => 1, 'tag' => 'it.software']],
        );

        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY));
    }

    public function testRowExceptionCanMakeRowSpecial(): void
    {
        // Štítek dokladu special není; oba řádky ho mají výjimkou.
        $canonical = $this->canonical(
            [['rowKind' => 'item'], ['rowKind' => 'item']],
            'it.software',
            [['rowIndex' => 0, 'tag' => 'travel.accommodation'], ['rowIndex' => 1, 'tag' => 'travel.accommodation']],
        );

        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY));
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

        $this->assertTrue(SpecialPlaceSupply::allItemRowsSpecial($canonical, self::TAXONOMY));
    }

    public function testDocumentWithoutItemRowsIsNotSpecial(): void
    {
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical([], 'travel.accommodation'), self::TAXONOMY));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial(
            $this->canonical([['rowKind' => 'text']], 'travel.accommodation'),
            self::TAXONOMY,
        ));
    }

    public function testWithoutTagBlockOrTaxonomyIsNotSpecial(): void
    {
        $rows = [['rowKind' => 'item']];

        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical($rows, null), self::TAXONOMY));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical($rows, 'travel.accommodation'), []));
        $this->assertFalse(SpecialPlaceSupply::allItemRowsSpecial($this->canonical($rows, 'people.training'), self::TAXONOMY));
    }
}
