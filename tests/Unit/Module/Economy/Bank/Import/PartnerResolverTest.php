<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Import;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Bank\Import\PartnerResolver;

/** Protiúčet z výpisu proti účtům osob přes klíč účtu z obou sloupců (#118 D23). */
class PartnerResolverTest extends TestCase
{
    /** @param list<array<string, mixed>> $accounts */
    private function resolver(array $accounts): PartnerResolver
    {
        return new class ($this->createMock(Connection::class), $accounts) extends PartnerResolver {
            /** @param list<array<string, mixed>> $accounts */
            public function __construct(Connection $db, private readonly array $accounts)
            {
                parent::__construct($db);
            }

            protected function loadAccounts(): array
            {
                return $this->accounts;
            }
        };
    }

    public function testIbanStoredInAccountNumberColumnMatchesDomesticNumberFromStatement(): void
    {
        $resolver = $this->resolver([
            ['person' => 7, 'account_number' => 'CZ6508000000192000145399', 'iban' => null],
        ]);
        $this->assertSame(7, $resolver->resolve('19-2000145399/0800'));
        $this->assertSame(7, $resolver->resolve('000019-2000145399/0800'), 'vedoucí nuly z výpisu');
        $this->assertSame(7, $resolver->resolve('CZ65 0800 0000 1920 0014 5399'), 'IBAN z CAMT');
    }

    public function testDomesticNumberStoredMatchesIbanFromStatement(): void
    {
        $resolver = $this->resolver([
            ['person' => 3, 'account_number' => '19-2000145399/0800', 'iban' => ''],
            ['person' => 4, 'account_number' => '2308417557/2010', 'iban' => 'CZ8520100000002308417557'],
        ]);
        $this->assertSame(3, $resolver->resolve('CZ6508000000192000145399'));
        $this->assertSame(4, $resolver->resolve('2308417557/2010'));
        $this->assertNull($resolver->resolve('1234567890/0100'), 'neznámý účet');
        $this->assertNull($resolver->resolve(null));
        $this->assertNull($resolver->resolve('  '));
    }

    public function testTwoPersonsWithTheSameAccountDoNotMatch(): void
    {
        $resolver = $this->resolver([
            ['person' => 1, 'account_number' => '19-2000145399/0800', 'iban' => null],
            ['person' => 2, 'account_number' => '', 'iban' => 'CZ6508000000192000145399'],
        ]);
        $this->assertNull($resolver->resolve('19-2000145399/0800'), 'víc osob → nic');

        $same = $this->resolver([
            ['person' => 1, 'account_number' => '19-2000145399/0800', 'iban' => null],
            ['person' => 1, 'account_number' => '', 'iban' => 'CZ6508000000192000145399'],
        ]);
        $this->assertSame(1, $same->resolve('19-2000145399/0800'), 'dvě spojení téže osoby = jedna osoba');
    }

    public function testAccountWithoutBankCodeIsNotGuessed(): void
    {
        $resolver = $this->resolver([['person' => 9, 'account_number' => '123456789', 'iban' => null]]);
        $this->assertNull($resolver->resolve('123456789/0800'), 'uložené číslo bez kódu banky se nepáruje (žádné hádání)');
        $this->assertSame(9, $resolver->resolve('123456789'), 'shoda raw klíče');
    }
}
