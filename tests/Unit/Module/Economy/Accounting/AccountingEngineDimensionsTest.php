<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingEngine;

/**
 * Dimenze deníku v enginu (docs/accounting.md „Dimenze deníku“, assets
 * D47): hodnota řádku dokladu se kopíruje do řádku deníku, prázdná se bere
 * z hlavičky (má-li dimenze `headColumn`), a vstupuje do klíče seskupení.
 * Bez dimenzí se výsledek nemění. Engine je final — privátní metody reflexí.
 */
class AccountingEngineDimensionsTest extends TestCase
{
    private const HEAD = [
        'partner' => 7, 'payment_reference' => null, 'specific_symbol' => null,
        'constant_symbol' => null, 'due_date' => null, 'cost_centre' => 3,
    ];

    private const ASSET = [
        'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => null,
        'journalColumn' => 'asset', 'table' => 'economy_assets_assets', 'name' => 'Majetek',
    ];

    private const CENTRE = [
        'id' => 'centre', 'rowColumn' => 'centre', 'headColumn' => 'cost_centre',
        'journalColumn' => 'centre', 'table' => 'x_centres', 'name' => 'Středisko',
    ];

    private const STEP = ['src' => 'rows', 'accountSrc' => 'row', 'sideSrc' => 'row', 'operation' => 'asset.depreciation'];

    /** @param array<string, mixed>|null $dimensions cfgItem dimenzí, null = DS bez dimenzí */
    private function engine(?array $dimensions): AccountingEngine
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturnCallback(
            static fn(string $sql, int $accountId): \Dibi\Row => new \Dibi\Row(['id' => $accountId, 'number' => '55' . $accountId]),
        );

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['docs.core.rowOperations', ['asset.depreciation' => ['rowSide' => 1, 'rowAccount' => 'direct', 'rowAsset' => 1]]],
            [JournalDimensionSet::CFG_ITEM, $dimensions],
        ]);

        return new AccountingEngine($db, $config);
    }

    /** @return array<string, mixed> */
    private function row(int $id, int $side, float $amount, array $overrides = []): array
    {
        return $overrides + [
            'id' => $id, 'operation' => 'asset.depreciation', 'description' => 'Odpis',
            'account' => $side === 0 ? 1100 : 8200, 'acc_side' => $side,
            'vat_base_dom' => $amount, 'vat_base' => $amount,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> seskupené řádky deníku
     */
    private function journal(AccountingEngine $engine, array $rows): array
    {
        $lines = (new \ReflectionMethod(AccountingEngine::class, 'buildRowLines'))->invoke($engine, self::STEP, self::HEAD, $rows);

        return (new \ReflectionMethod(AccountingEngine::class, 'groupLines'))->invoke($engine, $lines);
    }

    public function testRowValueIsCopiedToJournalLine(): void
    {
        $journal = $this->journal($this->engine(['asset' => self::ASSET]), [
            $this->row(1, 0, 1000.0, ['asset' => 5]),
            $this->row(2, 1, 1000.0, ['asset' => 5]),
        ]);

        $this->assertCount(2, $journal);
        $this->assertSame(['asset' => 5], $journal[0]['dimensions']);
        $this->assertSame(['asset' => 5], $journal[1]['dimensions']);
    }

    public function testTwoAssetsOnSameAccountStaySeparate(): void
    {
        // Odpisy dvou karet na tentýž účet se nesmí slít — invariant
        // „evidence = deník“ potřebuje obrat per karta.
        $journal = $this->journal($this->engine(['asset' => self::ASSET]), [
            $this->row(1, 0, 1000.0, ['asset' => 5]),
            $this->row(2, 0, 400.0, ['asset' => 6]),
            $this->row(3, 0, 50.0, ['asset' => 5]),
        ]);

        $this->assertCount(2, $journal);
        $this->assertSame([5 => 1050.0, 6 => 400.0], array_column(
            array_map(static fn(array $l): array => ['a' => $l['dimensions']['asset'], 'm' => $l['money_dr']], $journal),
            'm',
            'a',
        ));
    }

    public function testRowWithoutValueGetsNullNotHeadPartner(): void
    {
        $journal = $this->journal($this->engine(['asset' => self::ASSET]), [
            $this->row(1, 0, 1000.0, ['asset' => 5]),
            $this->row(2, 0, 300.0),
        ]);

        $this->assertCount(2, $journal);
        $this->assertSame([5, null], array_map(static fn(array $l): ?int => $l['dimensions']['asset'], $journal));
    }

    public function testEmptyRowValueFallsBackToHeadColumn(): void
    {
        $journal = $this->journal($this->engine(['centre' => self::CENTRE]), [
            $this->row(1, 0, 1000.0, ['centre' => 9]),
            $this->row(2, 0, 300.0, ['centre' => null]),
        ]);

        $this->assertSame([9, 3], array_map(static fn(array $l): ?int => $l['dimensions']['centre'], $journal));
    }

    public function testHeadStepLineCarriesOnlyHeadDefault(): void
    {
        $engine = $this->engine(['asset' => self::ASSET, 'centre' => self::CENTRE]);
        $line = (new \ReflectionMethod(AccountingEngine::class, 'makeLine'))->invoke(
            $engine,
            ['side' => 1],
            self::HEAD,
            ['id' => 1, 'number' => '321000'],
            100.0,
            100.0,
            'Celkem',
            null,
            null,
        );

        $this->assertSame(['asset' => null, 'centre' => 3], $line['dimensions']);
    }

    public function testRowOwningTheDimensionDoesNotInheritHead(): void
    {
        // Pořízení majetku je věc řádku (assets fáze 4): řádek s vlajkou
        // `rowFlag` dimenze kartu z hlavičky nedědí, běžný řádek ano.
        $engine = $this->engine(['asset' => ['headColumn' => 'asset', 'rowFlag' => 'rowAsset'] + self::ASSET]);
        $head = ['asset' => 8] + self::HEAD;
        $line = fn(array $row): array => (new \ReflectionMethod(AccountingEngine::class, 'makeLine'))->invoke(
            $engine,
            ['side' => 0],
            $head,
            ['id' => 1, 'number' => '042000'],
            100.0,
            100.0,
            'Řádek',
            $row['operation'],
            1,
            null,
            $row,
        );

        $this->assertSame(['asset' => null], $line(['operation' => 'asset.depreciation'])['dimensions']);
        $this->assertSame(['asset' => 5], $line(['operation' => 'asset.depreciation', 'asset' => 5])['dimensions']);
        $this->assertSame(['asset' => 8], $line(['operation' => 'purchase.goods'])['dimensions']);
        $this->assertSame(['asset' => 6], $line(['operation' => 'purchase.goods', 'asset' => 6])['dimensions']);
    }

    public function testWithoutDimensionsResultIsUnchanged(): void
    {
        // DS bez modulu s dimenzí: žádný klíč navíc, řádky se sloučí jako dřív
        // (hodnota `asset` na řádku se ignoruje).
        $journal = $this->journal($this->engine(null), [
            $this->row(1, 0, 1000.0, ['asset' => 5]),
            $this->row(2, 0, 400.0, ['asset' => 6]),
        ]);

        $this->assertCount(1, $journal);
        $this->assertSame(1400.0, $journal[0]['money_dr']);
        $this->assertSame([], $journal[0]['dimensions']);
    }
}
