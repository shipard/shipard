<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Compare;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\Difference;
use Shipard\Module\Core\Exchange\Compare\EquivalentForms;
use Shipard\Module\Core\Exchange\Compare\Projection;

class CanonicalComparatorTest extends TestCase
{
    private CanonicalComparator $cmp;

    protected function setUp(): void
    {
        $this->cmp = new CanonicalComparator(Projection::load(ProjectionTest::projectionPath()));
    }

    /** @param list<Difference> $diffs @return list<string> */
    private static function paths(array $diffs): array
    {
        return array_map(static fn (Difference $d): string => $d->path, $diffs);
    }

    public function testIdenticalDocumentsHaveNoDifferences(): void
    {
        $doc = ProjectionTest::corpusDoc();
        $this->assertSame([], $this->cmp->compare($doc, $doc));
    }

    public function testToleranceOnAmounts(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['totals']['totalAmount'] = 20001.844;   // 0,004 pod tolerancí
        $this->assertSame([], $this->cmp->compare($a, $b));

        $b['totals']['totalAmount'] = 20001.85;    // 0,01 nad tolerancí
        $diffs = $this->cmp->compare($a, $b);
        $this->assertSame(['totals.totalAmount'], self::paths($diffs));
        $this->assertSame(20001.84, $diffs[0]->expected);
        $this->assertSame(20001.85, $diffs[0]->actual);
    }

    public function testNullEqualsMissingKeyAndExtraKeysAreIgnored(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        unset($b['supplier']['contact']['name'], $b['dates']['periodFrom']);
        $b['source'] = ['kind' => 'aiExtraction'];
        $b['rows'][0]['computed'] = ['vatBase' => 1];
        $this->assertSame([], $this->cmp->compare($a, $b));
    }

    public function testEquivalentForms(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['totals']['totalRounding'] = null;                 // 0 ≡ null
        $b['_resolve']['contentTag']['rowExceptions'] = [];    // porovnání [] ≡ null
        $a['_resolve']['contentTag']['rowExceptions'] = null;
        $b['customer']['contact'] = null;                     // objekt se samými null ≡ null
        $b['customer']['bankAccount'] = ['accountNumber' => null, 'iban' => null, 'bic' => null];
        $this->assertSame([], $this->cmp->compare($a, $b));

        $a['customer'] = null;                                // nullable uzel vs objekt s hodnotami
        $diffs = $this->cmp->compare($a, $b);
        $this->assertSame(['customer'], self::paths($diffs));
        $this->assertNull($diffs[0]->expected);
        $this->assertIsArray($diffs[0]->actual);

        $b['customer'] = ['name' => null, 'address' => ['city' => null]];   // všechny listy null ≡ null
        $this->assertSame([], $this->cmp->compare($a, $b));
    }

    public function testRowsByPositionAndCountMismatch(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['rows'][1]['quantity'] = 5;
        array_pop($b['rows']);
        $diffs = $this->cmp->compare($a, $b);
        $this->assertSame(['rows', 'rows[1].quantity'], self::paths($diffs));
        $this->assertSame(4, $diffs[0]->expected);
        $this->assertSame(3, $diffs[0]->actual);
        $this->assertSame('rows[].quantity', $diffs[1]->nodePath());
    }

    public function testValueShapesApplyToBothSides(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['supplier']['vatId'] = 'cz 43075894';
        $b['supplier']['address']['zip'] = '779 00';
        $b['supplier']['contact']['phone'] = '+420 585 201 304';
        $b['supplier']['bankAccount']['accountNumber'] = '000101968643/0100';
        $b['currency'] = 'czk';
        $b['rows'][0]['unitPrice'] = '486.5';
        $a['supplier']['contact']['web'] = 'https://www.drevomat-hana.example/';
        $this->assertSame([], $this->cmp->compare($a, $b));
    }

    public function testScalarVersusObjectIsOneDifference(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['supplier']['address'] = 'Pilařská 1204/7, Olomouc';
        $diffs = $this->cmp->compare($a, $b);
        $this->assertSame(['supplier.address'], self::paths($diffs));
        $this->assertStringContainsString('supplier.address: očekáváno {', (string) $diffs[0]);
    }

    public function testStringsAndBooleansCompareStrictly(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['vat']['reverseCharge'] = true;
        $b['docNumber'] = 'FV-2026-1181';
        $b['rows'][2]['vat']['pct'] = 12;
        $this->assertSame(['docNumber', 'vat.reverseCharge', 'rows[2].vat.pct'], self::paths($this->cmp->compare($a, $b)));
    }

    public function testIgnorePathsDropSubtrees(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['rows'][0]['item']['ean'] = '12345678';
        $b['rows'][3]['item']['supplierCode'] = 'X';
        $b['customer']['name'] = 'Jiná';
        $b['dates']['dueDate'] = '2026-09-09';
        $this->assertSame(
            ['customer.name', 'dates.dueDate', 'rows[0].item.ean', 'rows[3].item.supplierCode'],
            self::paths($this->cmp->compare($a, $b)),
            'rozdíly v pořadí projekce',
        );
        $this->assertSame(
            ['dates.dueDate'],
            self::paths($this->cmp->compare($a, $b, ['rows[].item.ean', 'rows[].item.supplierCode', 'customer'])),
        );
        $this->assertTrue(CanonicalComparator::isIgnored('rows[].item', ['rows[]']));
        $this->assertFalse(CanonicalComparator::isIgnored('rowsX', ['rows']));
    }

    public function testCollapseKeepsNonNullSiblings(): void
    {
        $out = EquivalentForms::collapse(['a' => ['x' => null, 'y' => 0], 'b' => [], 'totals' => ['totalRounding' => 0.0, 'totalAmount' => 0]]);
        $this->assertSame(['a' => ['x' => null, 'y' => 0], 'b' => null, 'totals' => ['totalRounding' => null, 'totalAmount' => 0]], $out);
    }

    public function testDifferenceSerialization(): void
    {
        $d = new Difference('rows[0].unit', 'ks', 'pcs');
        $this->assertSame(['path' => 'rows[0].unit', 'expected' => 'ks', 'actual' => 'pcs'], $d->toArray());
        $this->assertSame('rows[0].unit: očekáváno "ks", skutečnost "pcs"', (string) $d);
        $this->assertSame('rows[].unit', $d->nodePath());
        $this->assertStringEndsWith('…', Difference::show(array_fill(0, 40, 'dlouhý text')));
    }
}
