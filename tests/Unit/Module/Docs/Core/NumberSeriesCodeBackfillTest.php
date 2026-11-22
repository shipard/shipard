<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\NumberSeriesCodeBackfill;

/** Doplnění kódu existujícím číselným řadám (#166 D17, U5). */
class NumberSeriesCodeBackfillTest extends TestCase
{
    use NumberSeriesDbTrait;

    private const CASH_DESKS = ['economy_codebooks_cash_desks' => [1 => 'POK', 2 => 'P 2']];

    /** @return list<array<string, mixed>> řady zdroje dat před zavedením kódu */
    private static function legacySeries(): array
    {
        return [
            ['id' => 1, 'doc_type' => 'invno', 'code' => null, 'doc_number_code' => null, 'docState' => 40],
            ['id' => 2, 'doc_type' => 'invni', 'code' => null, 'doc_number_code' => null, 'docState' => 40],
            ['id' => 3, 'doc_type' => 'cmnbkp', 'code' => null, 'doc_number_code' => null, 'docState' => 40],
            ['id' => 4, 'doc_type' => 'cmnbkp', 'code' => null, 'doc_number_code' => 'MA', 'docState' => 40],
            ['id' => 5, 'doc_type' => 'cash', 'code' => null, 'doc_number_code' => 'POK', 'cash_desk' => 1, 'docState' => 40],
            ['id' => 6, 'doc_type' => 'cashreg', 'code' => null, 'doc_number_code' => 'POK', 'cash_desk' => 1, 'docState' => 40],
            // Archivovaná pokladna má řady v archivu — i ty kód dostanou.
            ['id' => 7, 'doc_type' => 'cash', 'code' => null, 'doc_number_code' => 'P 2', 'cash_desk' => 2, 'docState' => 70],
            ['id' => 8, 'doc_type' => 'invno', 'code' => null, 'doc_number_code' => '', 'docState' => 40],
        ];
    }

    public function testFillsCodesByTheRule(): void
    {
        $store = new \ArrayObject();
        $filled = (new NumberSeriesCodeBackfill($this->seriesDb(self::legacySeries(), self::CASH_DESKS, $store)))->run();

        $this->assertSame(8, $filled);
        $this->assertSame([
            1 => 'invno',
            2 => 'invni',
            3 => 'cmnbkp',
            4 => 'cmnbkp-MA',
            5 => 'cash-POK',
            6 => 'cashreg-POK',
            7 => 'cash-P-2',
            8 => 'invno-2',
        ], self::codesOf($store));
    }

    public function testSecondRunFillsNothing(): void
    {
        $store = new \ArrayObject();
        $backfill = new NumberSeriesCodeBackfill($this->seriesDb(self::legacySeries(), self::CASH_DESKS, $store));
        $backfill->run();
        $codes = self::codesOf($store);
        $updates = count($store['updates']);

        $this->assertSame(0, $backfill->run());
        $this->assertSame($codes, self::codesOf($store));
        $this->assertCount($updates, $store['updates'], 'druhý běh nic nezapisuje');
    }

    public function testLiveRowsAreFilledBeforeTrash(): void
    {
        // Řada v koši má nejnižší id; „hezký“ kód patří živé řadě.
        $store = new \ArrayObject();
        (new NumberSeriesCodeBackfill($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => null, 'doc_number_code' => null, 'docState' => 90],
            ['id' => 2, 'doc_type' => 'invno', 'code' => null, 'doc_number_code' => null, 'docState' => 40],
            ['id' => 3, 'doc_type' => 'invno', 'code' => null, 'doc_number_code' => null, 'docState' => 40],
        ], [], $store)))->run();

        $this->assertSame([1 => 'invno-3', 2 => 'invno', 3 => 'invno-2'], self::codesOf($store));
        $this->assertSame([2, 3, 1], array_column($store['updates'], 0), 'pořadí: živé podle id, pak koš');
    }

    public function testFilledCodesAreKeptAndCountAsTaken(): void
    {
        $store = new \ArrayObject();
        $filled = (new NumberSeriesCodeBackfill($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => 'faktury', 'doc_number_code' => null, 'docState' => 40],
            ['id' => 2, 'doc_type' => 'invni', 'code' => 'INVNO-2', 'doc_number_code' => null, 'docState' => 40],
            ['id' => 3, 'doc_type' => 'invno', 'code' => '', 'doc_number_code' => null, 'docState' => 40],
        ], [], $store)))->run();

        $this->assertSame(1, $filled);
        // Kolace nerozlišuje velikost písmen: `invno-2` je obsazený kódem `INVNO-2`.
        $this->assertSame([1 => 'faktury', 2 => 'INVNO-2', 3 => 'invno-3'], self::codesOf($store));
    }

    public function testConcurrentWriterTakingTheCodeIsNotAnError(): void
    {
        // Dvě apply zakládají řadu téže pokladny: kód mezitím obsadil
        // souběžný zápis, čtení obsazených kódů ho ale (v transakci) nevidí.
        $store = new \ArrayObject(['collide' => ['cash-pok', 'cash-pok-2']]);
        $filled = (new NumberSeriesCodeBackfill($this->seriesDb([
            ['id' => 5, 'doc_type' => 'cash', 'code' => null, 'doc_number_code' => 'POK', 'cash_desk' => 1, 'docState' => 40],
        ], self::CASH_DESKS, $store)))->run();

        $this->assertSame(1, $filled);
        $this->assertSame([5 => 'cash-POK-3'], self::codesOf($store));
    }

    public function testNothingToFillDoesNotWrite(): void
    {
        $store = new \ArrayObject();
        $filled = (new NumberSeriesCodeBackfill($this->seriesDb([
            ['id' => 1, 'doc_type' => 'invno', 'code' => 'invno', 'docState' => 40],
        ], [], $store)))->run();

        $this->assertSame(0, $filled);
        $this->assertSame([], $store['updates']);
    }
}
