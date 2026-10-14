<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

class BankAccountResolverTest extends TestCase
{
    public function testHasIdentifierDistinguishesEmptyProjectionFromReference(): void
    {
        $this->assertFalse(BankAccountResolver::hasIdentifier(['accountNumber' => null, 'iban' => null, 'bic' => null]));
        $this->assertFalse(BankAccountResolver::hasIdentifier(['iban' => ' ', 'bic' => 'KOMBCZPP']));
        $this->assertFalse(BankAccountResolver::hasIdentifier([]));
        $this->assertTrue(BankAccountResolver::hasIdentifier(['iban' => 'CZ65...']));
        $this->assertTrue(BankAccountResolver::hasIdentifier(['accountNumber' => '123/0100', 'iban' => null]));
    }

    public function testPartnerBankIbanMatchesExisting(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('iban'), 42, 'CZ65...', 10, 40, 80)
            ->willReturn(new Row(['id' => 7]));

        $r = (new BankAccountResolver($db))->resolvePartnerBank(
            ['iban' => 'CZ65...', 'accountNumber' => '123/0100'],
            42,
        );
        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(7, $r->matchedId);
        $this->assertSame('iban', $r->matchedBy);
    }

    public function testPartnerBankFallsBackToAccountNumber(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                               // IBAN miss
                new Row(['id' => 11]),              // accountNumber hit
            );

        $r = (new BankAccountResolver($db))->resolvePartnerBank(
            ['iban' => 'CZ65...', 'accountNumber' => '123/0100'],
            42,
        );
        $this->assertSame(11, $r->matchedId);
        $this->assertSame('accountNumber', $r->matchedBy);
    }

    public function testPartnerBankNoMatchProducesCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

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
        $db->expects($this->never())->method('fetch');

        $r = (new BankAccountResolver($db))->resolvePartnerBank(
            ['iban' => 'CZ65...'],
            null,
        );
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertArrayNotHasKey('person', $r->createPayload);
        $this->assertSame('CZ65...', $r->createPayload['iban']);
    }

    public function testEmptyBankAccountReturnsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $r = (new BankAccountResolver($db))->resolvePartnerBank([], 42);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testOwnBankMatchesCodebookIban(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('economy_codebooks_bank_accounts'), 'CZ65...', 10, 40, 80)
            ->willReturn(new Row(['id' => 3]));

        $r = (new BankAccountResolver($db))->resolveOwnBank(['iban' => 'CZ65...']);
        $this->assertSame(3, $r->matchedId);
        $this->assertSame('iban', $r->matchedBy);
    }

    public function testOwnBankNoMatchReturnsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $r = (new BankAccountResolver($db))->resolveOwnBank(['iban' => 'CZ65...']);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }
}
