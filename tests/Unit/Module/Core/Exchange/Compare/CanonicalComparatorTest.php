<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Compare;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\Difference;
use Shipard\Module\Core\Exchange\Compare\EquivalentForms;
use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;
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

    // ── režim evaluace (#103 D37, tasks/testing-phase4.md § 5) ─────────────

    /** @param list<FieldOutcome> $outcomes @return array<string, FieldOutcome> cesta → výsledek */
    private static function byPath(array $outcomes): array
    {
        $out = [];
        foreach ($outcomes as $o) {
            $out[$o->path] = $o;
        }
        return $out;
    }

    /** @param list<FieldOutcome> $outcomes @return array<string, string> cesta → stav mimo match */
    private static function deviations(array $outcomes): array
    {
        $out = [];
        foreach ($outcomes as $o) {
            if ($o->state !== FieldState::Match) {
                $out[$o->path] = $o->state->value;
            }
        }
        return $out;
    }

    public function testEvaluateIdenticalDocumentIsAllMatchLeafByLeaf(): void
    {
        $doc = ProjectionTest::corpusDoc();
        $outcomes = $this->cmp->evaluate($doc, $doc);

        $this->assertSame([], self::deviations($outcomes));
        $byPath = self::byPath($outcomes);
        $this->assertArrayHasKey('docNumber', $byPath);
        $this->assertArrayHasKey('rows[3].vat.pct', $byPath);
        $this->assertArrayHasKey('_resolve.contentTag.rowExceptions[0].tag', $byPath);
        $this->assertArrayNotHasKey('rows', $byPath, 'pole stejné délky nemá výsledek na cestě pole');
        $this->assertSame(FieldState::Match, $byPath['supplier.contact.name']->state, 'null = null je shoda');
        $this->assertSame('rows[].vat.pct', $byPath['rows[3].vat.pct']->nodePath());
    }

    public function testEvaluateMatchNormalizedKeepsRawValuesAndSimilarity(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['supplier']['vatId'] = 'cz 430 75894';            // tvar hodnoty (D15)
        $b['totals']['totalAmount'] = 20001.844;              // tolerance čísel
        $b['totals']['totalRounding'] = null;                 // 0 ≡ null
        $b['supplier']['name'] = "  Dřevomat   Haná s.r.o. "; // jen mezery
        $b['rows'][0]['item']['name'] = 'Spárovka smrková 18 mm, formát 2500x1250 mm'; // 1 znak ze 43

        $byPath = self::byPath($this->cmp->evaluate($a, $b));
        $this->assertSame([
            'supplier.name' => 'matchNormalized',
            'supplier.vatId' => 'matchNormalized',
            'rows[0].item.name' => 'matchNormalized',
            'totals.totalAmount' => 'matchNormalized',
            'totals.totalRounding' => 'matchNormalized',
        ], self::deviations($this->cmp->evaluate($a, $b)));
        $this->assertSame('cz 430 75894', $byPath['supplier.vatId']->actual, 'syrová hodnota modelu');
        $this->assertSame('CZ43075894', $byPath['supplier.vatId']->expected);
        $this->assertSame(1.0, $byPath['supplier.name']->similarity);
        $this->assertEqualsWithDelta(0.977, (float) $byPath['rows[0].item.name']->similarity, 0.001);
        $this->assertNull($byPath['supplier.vatId']->similarity, 'podobnost jen u volného textu');
    }

    public function testEvaluateMissingExtraAndWrong(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $b['dates']['periodFrom'] = null;                      // očekáváno, model nenašel — hmm, v korpusu je null
        $a['dates']['periodFrom'] = '2026-08-01';
        $b['supplier']['contact']['name'] = 'Jan Novák';       // na dokladu není, model přidal
        $b['docNumber'] = 'FV-2026-1181';                      // jiná hodnota
        $b['rows'][1]['item']['name'] = 'Úplně jiný text položky'; // podobnost pod prahem
        $b['supplier']['address']['zip'] = ['77900'];          // jiný typ

        $byPath = self::byPath($this->cmp->evaluate($a, $b));
        $this->assertSame([
            'docNumber' => 'wrong',   // číslo dokladu není volný text — jedna číslice = chyba
            'supplier.address.zip' => 'wrong',
            'supplier.contact.name' => 'extra',
            'dates.periodFrom' => 'missing',
            'rows[1].item.name' => 'wrong',
        ], self::deviations($this->cmp->evaluate($a, $b)));
        $this->assertNull($byPath['docNumber']->similarity);
        $this->assertLessThan(CanonicalComparator::TEXT_SIMILARITY_MATCH, (float) $byPath['rows[1].item.name']->similarity);
        $this->assertSame(['path' => 'docNumber', 'state' => 'wrong', 'expected' => 'FV-2026-1180', 'actual' => 'FV-2026-1181'], $byPath['docNumber']->toArray());
        $this->assertArrayHasKey('similarity', $byPath['rows[1].item.name']->toArray());
    }

    public function testEvaluateArraysByPositionWithCountOutcome(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        array_pop($b['rows']);                                  // model vynechal poslední řádek
        $b['vatRecap'][] = ['vatPct' => 12, 'base' => 1, 'tax' => 0.12, 'total' => 1.12];

        $outcomes = $this->cmp->evaluate($a, $b);
        $byPath = self::byPath($outcomes);
        $this->assertSame(FieldState::Missing, $byPath['rows']->state);
        $this->assertSame([4, 3], [$byPath['rows']->expected, $byPath['rows']->actual]);
        $this->assertSame(FieldState::Extra, $byPath['vatRecap']->state);
        $this->assertSame([1, 2], [$byPath['vatRecap']->expected, $byPath['vatRecap']->actual]);
        $this->assertArrayHasKey('rows[2].totalPrice', $byPath);
        $this->assertArrayNotHasKey('rows[3].totalPrice', $byPath, 'přesahující prvky se neporovnávají');
        $this->assertArrayNotHasKey('vatRecap[1].base', $byPath);
        $this->assertSame(['rows' => 'missing', 'vatRecap' => 'extra'], self::deviations($outcomes));

        // Prázdné pole ≡ chybějící: žádný rozdíl délky.
        $a['_resolve']['contentTag']['rowExceptions'] = [];
        unset($b['_resolve']['contentTag']['rowExceptions']);
        $this->assertArrayNotHasKey('_resolve.contentTag.rowExceptions', self::byPath($this->cmp->evaluate($a, $b)));
    }

    public function testEvaluateNullableNode(): void
    {
        $a = ProjectionTest::corpusDoc();
        $b = $a;
        $a['customer'] = null;
        $b['customer'] = null;
        $byPath = self::byPath($this->cmp->evaluate($a, $b));
        $this->assertSame(FieldState::Match, $byPath['customer']->state, 'účtenka bez odběratele: jeden výsledek za uzel');
        $this->assertArrayNotHasKey('customer.name', $byPath);

        $b['customer'] = ['name' => null, 'address' => ['city' => null]];
        $this->assertSame(FieldState::MatchNormalized, self::byPath($this->cmp->evaluate($a, $b))['customer']->state, 'samé null ≡ null');

        $b['customer'] = ProjectionTest::corpusDoc()['customer'];
        $this->assertSame(FieldState::Extra, self::byPath($this->cmp->evaluate($a, $b))['customer']->state);
        $this->assertSame(FieldState::Missing, self::byPath($this->cmp->evaluate($b, $a))['customer']->state);
        $this->assertArrayNotHasKey('customer.name', self::byPath($this->cmp->evaluate($b, $a)));
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
