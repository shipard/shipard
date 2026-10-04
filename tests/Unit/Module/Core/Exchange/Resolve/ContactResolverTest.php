<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Resolve\ContactResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;

class ContactResolverTest extends TestCase
{
    public function testNameAndEmailMatchWinsWhenEmailSupplied(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('AND [email] = %s'),
                42,
                'Jan Novák',
                'novak@firma.cz',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 11]));

        $r = (new ContactResolver($db))->resolve([
            'name'  => 'Jan Novák',
            'email' => 'novak@firma.cz',
        ], personId: 42);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(11, $r->matchedId);
        $this->assertSame('nameEmail', $r->matchedBy);
    }

    public function testNameOnlyFallbackWhenNameEmailMisses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                       // (name, email) miss
                new Row(['id' => 22]),      // (name) hit
            );

        $r = (new ContactResolver($db))->resolve([
            'name'  => 'Jan Novák',
            'email' => 'novak@firma.cz',
        ], personId: 42);

        $this->assertSame(22, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    public function testNameOnlyProbeUsedWhenEmailMissing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->logicalNot($this->stringContains('AND [email]')),
                42,
                'Jan Novák',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 33]));

        $r = (new ContactResolver($db))->resolve([
            'name' => 'Jan Novák',
        ], personId: 42);

        $this->assertSame(33, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    public function testNoMatchYieldsCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(null, null);

        $r = (new ContactResolver($db))->resolve([
            'name'  => 'Eva Nová',
            'email' => 'nova@firma.cz',
            'role'  => 'Účetní',
            'phone' => '+420 123 456 789',
        ], personId: 42);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $p = $r->createPayload;
        $this->assertSame(42, $p['person']);
        $this->assertSame('Eva Nová', $p['name']);
        $this->assertSame('nova@firma.cz', $p['email']);
        $this->assertSame('Účetní', $p['role']);
        $this->assertSame('+420 123 456 789', $p['phone']);
        $this->assertSame(0, $p['order_pos']);
        $this->assertArrayNotHasKey('send_purposes', $p, 'payload bez sendPurposes sloupec nezapisuje');
    }

    public function testSendPurposesPayloadWritesOnlyWhenKeyIsPresent(): void
    {
        $this->assertSame([], ContactResolver::sendPurposesPayload(['name' => 'Eva Nová']));
        $this->assertSame([], ContactResolver::sendPurposesPayload(['name' => 'Eva Nová', 'sendPurposes' => null]));
        $this->assertSame(
            ['send_purposes' => '["invoices","reminders"]'],
            ContactResolver::sendPurposesPayload(['sendPurposes' => ['invoices', 'reminders']]),
        );
        // Prázdný seznam je výslovné „žádný účel“.
        $this->assertSame(['send_purposes' => null], ContactResolver::sendPurposesPayload(['sendPurposes' => []]));
    }

    public function testNullPersonIdShortCircuitsToCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $r = (new ContactResolver($db))->resolve([
            'name'  => 'Jan Novák',
            'email' => 'novak@firma.cz',
        ], personId: null);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertNull($r->createPayload['person']);
        $this->assertSame('Jan Novák', $r->createPayload['name']);
    }

    public function testEmptyNameStillFallsThroughToCanCreateWithoutProbing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $r = (new ContactResolver($db))->resolve([
            'name' => '',
        ], personId: 42);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        // payload still carries personId so applier can attempt insert
        // (PersonValidator/JSON Schema would have rejected this upstream).
        $this->assertSame(42, $r->createPayload['person']);
    }

    public function testWhitespaceOnlyEmailIsTreatedAsMissing(): void
    {
        $db = $this->createMock(Connection::class);
        // No (name, email) probe — email normalises to null, so only
        // the (name) probe should run.
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->logicalNot($this->stringContains('AND [email]')),
                42,
                'Jan Novák',
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 44]));

        $r = (new ContactResolver($db))->resolve([
            'name'  => 'Jan Novák',
            'email' => '   ',
        ], personId: 42);

        $this->assertSame(44, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }
}
