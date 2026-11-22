<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\RecordCode;
use Shipard\Module\Docs\Core\NumberSeriesCodeGenerator;

/** Pravidla kódu číselné řady (#166 D17, U3). */
class NumberSeriesCodeGeneratorTest extends TestCase
{
    use NumberSeriesDbTrait;

    private const CASH_DESKS = 'economy_codebooks_cash_desks';
    private const WAREHOUSES = 'economy_codebooks_warehouses';

    // ── čisté pravidlo ──────────────────────────────────────────────────────

    public function testFirstUnboundSeriesOfTypeGetsTheDocType(): void
    {
        $this->assertSame('invno', NumberSeriesCodeGenerator::compose('invno', null, true, '', []));
        // Kód z čísla dokladu výchozí řadě kód nemění.
        $this->assertSame('invno', NumberSeriesCodeGenerator::compose('invno', null, true, 'A', []));
    }

    public function testNextUnboundSeriesUsesTheDocNumberCode(): void
    {
        $this->assertSame('cmnbkp-MA', NumberSeriesCodeGenerator::compose('cmnbkp', null, false, 'MA', ['cmnbkp' => true]));
    }

    public function testNextUnboundSeriesWithoutDocNumberCodeIsNumberedFromTwo(): void
    {
        $this->assertSame('invno-2', NumberSeriesCodeGenerator::compose('invno', null, false, '', ['invno' => true]));
        $this->assertSame('invno-3', NumberSeriesCodeGenerator::compose('invno', null, false, '  ', ['invno' => true, 'invno-2' => true]));
        // I když je holý typ volný (výchozí řada má vlastní kód), další řada ho nedostane.
        $this->assertSame('invno-2', NumberSeriesCodeGenerator::compose('invno', null, false, '', ['faktury' => true]));
    }

    public function testBoundSeriesUsesTheEntityCode(): void
    {
        $this->assertSame('cash-HL', NumberSeriesCodeGenerator::compose('cash', 'HL', false, 'HL', []));
        $this->assertSame('cashreg-HL', NumberSeriesCodeGenerator::compose('cashreg', 'HL', false, 'HL', []));
        // Vázaná řada holý typ nedostane, ani když je první.
        $this->assertSame('cash-2', NumberSeriesCodeGenerator::compose('cash', '', true, '', []));
    }

    public function testTakenCodeGetsSuffix(): void
    {
        $this->assertSame('invno-2', NumberSeriesCodeGenerator::compose('invno', null, true, '', ['invno' => true]));
        $this->assertSame('cash-HL-2', NumberSeriesCodeGenerator::compose('cash', 'HL', false, '', ['cash-hl' => true]));
        $this->assertSame('invno-A-3', NumberSeriesCodeGenerator::compose('invno', null, false, 'A', ['invno-a' => true, 'invno-a-2' => true]));
    }

    public function testDisallowedCharacterBecomesDash(): void
    {
        $code = NumberSeriesCodeGenerator::compose('cash', 'H L:1', false, '', []);

        $this->assertSame('cash-H-L-1', $code);
        $this->assertTrue(RecordCode::isValid($code));
    }

    public function testBrokenRowStillGetsValidCode(): void
    {
        $code = NumberSeriesCodeGenerator::compose('', null, true, '', []);

        $this->assertTrue(RecordCode::isValid($code));
    }

    // ── nad databází ────────────────────────────────────────────────────────

    public function testNewSeriesOfTypeWithoutSeriesIsTheDefaultOne(): void
    {
        $generator = new NumberSeriesCodeGenerator($this->seriesDb());

        $this->assertSame('invno', $generator->generate(['doc_type' => 'invno']));
    }

    public function testNewSeriesNextToExistingOne(): void
    {
        $generator = new NumberSeriesCodeGenerator($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => 'invno', 'docState' => 40],
        ]));

        $this->assertSame('invno-2', $generator->generate(['doc_type' => 'invno']));
        $this->assertSame('invno-EX', $generator->generate(['doc_type' => 'invno', 'doc_number_code' => 'EX']));
        $this->assertSame('invni', $generator->generate(['doc_type' => 'invni']), 'jiný typ má vlastní výchozí řadu');
    }

    public function testExistingSeriesWithLowestIdIsTheDefaultOne(): void
    {
        $generator = new NumberSeriesCodeGenerator($this->seriesDb([
            ['id' => 3, 'doc_type' => 'cmnbkp', 'code' => null, 'docState' => 40],
            ['id' => 7, 'doc_type' => 'cmnbkp', 'code' => null, 'docState' => 40, 'doc_number_code' => 'MA'],
        ]));

        $this->assertSame('cmnbkp', $generator->generate(['id' => 3, 'doc_type' => 'cmnbkp', 'docState' => 40]));
        $this->assertSame('cmnbkp-MA', $generator->generate(['id' => 7, 'doc_type' => 'cmnbkp', 'docState' => 40, 'doc_number_code' => 'MA']));
    }

    public function testDeletedSeriesIsNeverTheDefaultOne(): void
    {
        // Řada v koši má nejnižší id, výchozí je ale první nesmazaná.
        $generator = new NumberSeriesCodeGenerator($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => null, 'docState' => 90],
            ['id' => 2, 'doc_type' => 'invno', 'code' => null, 'docState' => 40],
        ]));

        $this->assertSame('invno', $generator->generate(['id' => 2, 'doc_type' => 'invno', 'docState' => 40]));
        $this->assertSame('invno-2', $generator->generate(['id' => 1, 'doc_type' => 'invno', 'docState' => 90]));
    }

    public function testCodeHeldByDeletedSeriesIsTaken(): void
    {
        $generator = new NumberSeriesCodeGenerator($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => 'invno', 'docState' => 90],
        ]));

        $this->assertSame('invno-2', $generator->generate(['doc_type' => 'invno']));
    }

    public function testBoundSeriesReadsTheEntityCode(): void
    {
        $generator = new NumberSeriesCodeGenerator($this->seriesDb(
            [['id' => 1, 'doc_type' => 'cash', 'code' => 'cash-HL', 'docState' => 40, 'cash_desk' => 4]],
            [self::CASH_DESKS => [4 => 'HL', 5 => 'P2'], self::WAREHOUSES => [2 => 'S1']],
        ));

        $this->assertSame('cash-P2', $generator->generate(['doc_type' => 'cash', 'cash_desk' => 5]));
        $this->assertSame('cashreg-HL', $generator->generate(['doc_type' => 'cashreg', 'cash_desk' => 4]));
        $this->assertSame('whs-S1', $generator->generate(['doc_type' => 'whs', 'warehouse' => 2]));
        // Druhá řada téže pokladny a typu (první je v archivu) dostane příponu.
        $this->assertSame('cash-HL-2', $generator->generate(['doc_type' => 'cash', 'cash_desk' => 4]));
        // Vlastní kód se mezi obsazené nepočítá.
        $this->assertSame('cash-HL', $generator->generate(['id' => 1, 'doc_type' => 'cash', 'cash_desk' => 4]));
    }

    public function testHolderIsFoundRegardlessOfCase(): void
    {
        $generator = new NumberSeriesCodeGenerator($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => 'FV', 'name' => 'Faktury', 'docState' => 40],
            ['id' => 2, 'doc_type' => 'invno', 'code' => 'old', 'name' => 'Stará', 'docState' => 90],
        ]));

        $this->assertSame(['id' => 1, 'name' => 'Faktury', 'deleted' => false], $generator->holder('fv'));
        $this->assertNull($generator->holder('fv', 1), 'řada sama sebe nedrží');
        $this->assertSame(['id' => 2, 'name' => 'Stará', 'deleted' => true], $generator->holder('old'));
        $this->assertNull($generator->holder('volny'));
    }
}
