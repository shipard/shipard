<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\World\Validations\BankAccountKey;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

/**
 * Párování účtů přes klíč účtu (#118 D16): účty osoby se načtou jedním
 * dotazem a porovnají v PHP z obou sloupců — IBAN uložený ve sloupci
 * čísla účtu se napáruje na IBAN i na tuzemské číslo z dokladu.
 */
class BankAccountResolverTest extends TestCase
{
    /** @param list<array<string, mixed>> $rows */
    private function dbWithAccounts(array $rows, string $table = 'base_persons_bank_accounts', ?int $person = 42): Connection
    {
        $db = $this->createMock(Connection::class);
        $expected = $person !== null
            ? [$this->stringContains($table), $person, 10, 40, 80]
            : [$this->stringContains($table), 10, 40, 80];
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(...$expected)
            ->willReturn(array_map(static fn(array $r): Row => new Row($r), $rows));
        $db->expects($this->never())->method('fetch');
        return $db;
    }

    public function testHasIdentifierDistinguishesEmptyProjectionFromReference(): void
    {
        $this->assertFalse(BankAccountResolver::hasIdentifier(['accountNumber' => null, 'iban' => null, 'bic' => null]));
        $this->assertFalse(BankAccountResolver::hasIdentifier(['iban' => ' ', 'bic' => 'KOMBCZPP']));
        $this->assertFalse(BankAccountResolver::hasIdentifier([]));
        $this->assertTrue(BankAccountResolver::hasIdentifier(['iban' => 'CZ65...']));
        $this->assertTrue(BankAccountResolver::hasIdentifier(['accountNumber' => '123/0100', 'iban' => null]));
    }

    public function testBankAccountKeyIsShapeDrivenAndChecksumIndependent(): void
    {
        $bban = 'bban:' . '2010' . '000000' . '2308417557';
        $this->assertSame($bban, BankAccountKey::of('cz85 2010 0000 0023 0841 7557'));
        $this->assertSame($bban, BankAccountKey::of('2308417557/2010'));
        $this->assertSame($bban, BankAccountKey::of('0002308417557 / 2010'));
        $this->assertSame('bban:' . '2010' . '000000' . '2308417558', BankAccountKey::of('CZ8520100000002308417558'), 'chybná kontrolní číslice: klíč je dál BBAN');
        $this->assertSame('bban:' . '1100' . '000000' . '2600000003', BankAccountKey::of('SK2411000000002600000003'));
        $this->assertSame('iban:DE72100700000532013000', BankAccountKey::of('de72 1007 0000 0532 0130 00'));
        $this->assertSame('raw:CZ65...', BankAccountKey::of('CZ65...'));
        $this->assertSame('iban:CZ06060000000083604053660', BankAccountKey::of('CZ06060000000083604053660'), 'cz IBAN s chybnou délkou nemá BBAN');
        $this->assertNull(BankAccountKey::of('  '));
    }

    public function testPartnerBankIbanMatchesExisting(): void
    {
        $db = $this->dbWithAccounts([
            ['id' => 7, 'account_number' => '2308417557/2010', 'iban' => 'CZ8520100000002308417557'],
        ]);

        $r = (new BankAccountResolver($db))->resolvePartnerBank(
            ['iban' => 'cz85 2010 0000 0023 0841 7557', 'accountNumber' => '123/0100'],
            42,
        );
        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(7, $r->matchedId);
        $this->assertSame('iban', $r->matchedBy);
    }

    public function testPartnerBankFallsBackToAccountNumber(): void
    {
        $db = $this->dbWithAccounts([
            ['id' => 8, 'account_number' => '19-2000145399/0800', 'iban' => 'CZ6508000000192000145399'],
            ['id' => 11, 'account_number' => '123/0100', 'iban' => null],
        ]);

        $r = (new BankAccountResolver($db))->resolvePartnerBank(
            ['iban' => 'CZ8520100000002308417557', 'accountNumber' => '123/0100'],
            42,
        );
        $this->assertSame(11, $r->matchedId);
        $this->assertSame('accountNumber', $r->matchedBy);
    }

    public function testIbanStoredInAccountNumberColumnMatchesIbanAndDomesticNumber(): void
    {
        // Stará data: IBAN ve sloupci čísla účtu, sloupec IBAN prázdný.
        $stored = [['id' => 9, 'account_number' => 'CZ8520100000002308417557', 'iban' => null]];

        $r = (new BankAccountResolver($this->dbWithAccounts($stored)))->resolvePartnerBank(['iban' => 'CZ85 2010 0000 0023 0841 7557'], 42);
        $this->assertSame([9, 'iban'], [$r->matchedId, $r->matchedBy]);

        $r = (new BankAccountResolver($this->dbWithAccounts($stored)))->resolvePartnerBank(['accountNumber' => '2308417557/2010'], 42);
        $this->assertSame([9, 'accountNumber'], [$r->matchedId, $r->matchedBy]);
    }

    public function testDomesticNumberStoredMatchesIbanFromDocument(): void
    {
        $db = $this->dbWithAccounts([['id' => 5, 'account_number' => '19-2000145399/0800', 'iban' => '']]);

        $r = (new BankAccountResolver($db))->resolvePartnerBank(['iban' => 'CZ6508000000192000145399'], 42);
        $this->assertSame([5, 'iban'], [$r->matchedId, $r->matchedBy]);
    }

    public function testForeignIbanMatchesExactlyAndOtherwiseCanCreate(): void
    {
        $stored = [['id' => 3, 'account_number' => '', 'iban' => 'DE72100700000532013000']];

        $r = (new BankAccountResolver($this->dbWithAccounts($stored)))->resolvePartnerBank(['iban' => 'de72 1007 0000 0532 0130 00'], 42);
        $this->assertSame([3, 'iban'], [$r->matchedId, $r->matchedBy]);

        $r = (new BankAccountResolver($this->dbWithAccounts($stored)))->resolvePartnerBank(['iban' => 'DE72100700000532013001'], 42);
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
    }

    public function testPartnerBankNoMatchProducesCanCreate(): void
    {
        $db = $this->dbWithAccounts([]);

        $r = (new BankAccountResolver($db))->resolvePartnerBank(
            ['iban' => 'CZ65...', 'accountNumber' => '123/0100', 'bic' => 'GIBACZPX', 'currency' => 'CZK'],
            42,
        );
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('CZ65...', $r->createPayload['iban']);
        $this->assertSame('123/0100', $r->createPayload['account_number']);
        $this->assertSame('GIBACZPX', $r->createPayload['bic']);
        $this->assertSame('czk', $r->createPayload['currency']);
        $this->assertSame(42, $r->createPayload['person']);
    }

    public function testPartnerBankPendingPersonProducesCanCreateWithoutPerson(): void
    {
        $db = $this->createMock(Connection::class);
        // No DB lookup possible — partner not resolved yet
        $db->expects($this->never())->method('fetchAll');

        $r = (new BankAccountResolver($db))->resolvePartnerBank(['iban' => 'CZ65...'], null);
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertArrayNotHasKey('person', $r->createPayload);
        $this->assertSame('CZ65...', $r->createPayload['iban']);
    }

    public function testEmptyBankAccountReturnsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $r = (new BankAccountResolver($db))->resolvePartnerBank([], 42);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testOwnBankMatchesCodebookByKey(): void
    {
        $db = $this->dbWithAccounts(
            [['id' => 3, 'account_number' => '2308417557/2010', 'iban' => null]],
            'economy_codebooks_bank_accounts',
            null,
        );

        $r = (new BankAccountResolver($db))->resolveOwnBank(['iban' => 'CZ8520100000002308417557']);
        $this->assertSame(3, $r->matchedId);
        $this->assertSame('iban', $r->matchedBy);
    }

    public function testOwnBankNoMatchReturnsNotFound(): void
    {
        $db = $this->dbWithAccounts([], 'economy_codebooks_bank_accounts', null);

        $r = (new BankAccountResolver($db))->resolveOwnBank(['iban' => 'CZ65...']);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }
}
