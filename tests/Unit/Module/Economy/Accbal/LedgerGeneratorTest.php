<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accbal\LedgerGenerator;

/**
 * Kanonický klíč pohybu (#69 D13): SHA-1 n-tice zdroj + platební identita
 * řádku, normalizované jako klíč případu (D10). Generování nad reálným
 * deníkem kryje integrační LedgerGeneratorTest.
 */
class LedgerGeneratorTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function identity(array $over = []): array
    {
        return array_merge([
            'source_kind'       => 'doc',
            'source_id'         => 5,
            'balance'           => 3,
            'bal_side'          => 0,
            'account_number'    => '311100',
            'partner'           => 42,
            'payment_reference' => 'VS1',
            'specific_symbol'   => null,
            'currency'          => 'czk',
        ], $over);
    }

    public function testKeyIsSha1OfCanonicalForm(): void
    {
        $key = LedgerGenerator::movementKey(self::identity());

        $this->assertSame(sha1('doc|5|3|0|311100|42|VS1||czk'), $key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $key);
    }

    public function testNullAndEmptySymbolsShareKey(): void
    {
        $base = LedgerGenerator::movementKey(self::identity());

        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['specific_symbol' => ''])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['specific_symbol' => '   '])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['payment_reference' => ' VS1 '])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['currency' => 'CZK'])));
        $this->assertSame($base, LedgerGenerator::movementKey(self::identity(['partner' => '42'])));
    }

    public function testMissingPartnerIsEmptyNotZero(): void
    {
        $null  = LedgerGenerator::movementKey(self::identity(['partner' => null]));
        $empty = LedgerGenerator::movementKey(self::identity(['partner' => '']));
        $zero  = LedgerGenerator::movementKey(self::identity(['partner' => 0]));

        $this->assertSame($null, $empty);
        $this->assertNotSame($null, $zero, 'partner 0 je hodnota, NULL je absence');
        $this->assertSame(sha1('doc|5|3|0|311100||VS1||czk'), $null);
    }

    public function testEveryKeyColumnDistinguishes(): void
    {
        $base = LedgerGenerator::movementKey(self::identity());
        $variants = [
            'source_kind'       => 'bankTransaction',
            'source_id'         => 6,
            'balance'           => 4,
            'bal_side'          => 1,
            'account_number'    => '311200',
            'partner'           => 43,
            'payment_reference' => 'VS2',
            'specific_symbol'   => 'SS',
            'currency'          => 'eur',
        ];
        foreach ($variants as $col => $value) {
            $this->assertNotSame($base, LedgerGenerator::movementKey(self::identity([$col => $value])), "sloupec {$col} musí klíč rozlišit");
        }
    }

    public function testNonKeyColumnsDoNotAffectKey(): void
    {
        $base = LedgerGenerator::movementKey(self::identity());
        $noise = self::identity([
            'id'              => 99,
            'journal_row'     => 123,
            'constant_symbol' => '0308',
            'due_date'        => '2026-07-10',
            'amount'          => 1210.0,
            'text'            => 'x',
            'fiscal_year'     => 7,
        ]);

        $this->assertSame($base, LedgerGenerator::movementKey($noise));
    }
}
