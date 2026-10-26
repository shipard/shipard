<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Person;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Person\MergeStrategy;
use Shipard\Module\Core\Exchange\Person\PersonResolver;
use Shipard\Module\Core\Exchange\Resolve\AddressResolver;
use Shipard\Module\Core\Exchange\Resolve\BankAccountResolver;
use Shipard\Module\Core\Exchange\Resolve\ContactResolver;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

class PersonResolverTest extends TestCase
{
    // ── Header resolve passes personType through to PartyResolver ─────────

    public function testCompanyPayloadResolvesHeaderViaCompanyIdProbe(): void
    {
        $db = $this->createMock(Connection::class);
        // PartyResolver hits the (company_id, person_type) probe directly.
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('AND [person_type] = %i'),
                'company_id', '12345678', 2, 10, 40, 80,
            )
            ->willReturn(new Row(['id' => 42]));

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'companyId'  => '12345678',
        ], MergeStrategy::MergeAdd);

        $this->assertSame(ResolveStatus::Matched, $r->header->status);
        $this->assertSame(42, $r->header->matchedId);
        $this->assertSame('companyId', $r->header->matchedBy);
    }

    public function testPersonPayloadFiltersByPersonType1(): void
    {
        $db = $this->createMock(Connection::class);
        // No company/vat/tax ids — falls to name probe (fetchAll).
        $db->expects($this->once())
            ->method('fetchAll')
            ->with(
                $this->stringContains('AND [person_type] = %i'),
                '%Novák%', 1, 10, 40, 80, 5,
            )
            ->willReturn([new Row(['id' => 77, 'full_name' => 'Jan Novák', 'company_id' => null])]);

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'person',
            'name'       => ['fullName' => 'Novák'],
        ], MergeStrategy::MergeAdd);

        $this->assertSame(ResolveStatus::Matched, $r->header->status);
        $this->assertSame(77, $r->header->matchedId);
    }

    public function testHeaderCanCreateWhenNoIdsAndNameSet(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetchAll')
            ->willReturn([]);

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'name'       => ['fullName' => 'Brand New s.r.o.'],
        ], MergeStrategy::MergeAdd);

        $this->assertSame(ResolveStatus::CanCreate, $r->header->status);
        // PartyResolver uses person_type from canonical personType.
        $this->assertSame(PersonType::Company->value, $r->header->createPayload['person_type']);
    }

    // ── Sub-collections short-circuit when header is CanCreate ────────────

    public function testCanCreateHeaderShortCircuitsSubResolvers(): void
    {
        $db = $this->createMock(Connection::class);
        // PartyResolver: name probe → no candidates (canCreate header)
        $db->expects($this->once())->method('fetchAll')->willReturn([]);
        // No sub-resolver probes — all short-circuit on personId=null.
        $db->expects($this->never())->method('fetch');

        $r = $this->buildResolver($db)->resolve([
            'personType'   => 'company',
            'name'         => ['fullName' => 'Brand New s.r.o.'],
            'addresses'    => [['addressType' => 1, 'street' => 'Hlavní']],
            'bankAccounts' => [['iban' => 'CZ65...']],
            'contacts'     => [['name' => 'Jan Novák']],
        ], MergeStrategy::MergeAdd);

        $this->assertSame(ResolveStatus::CanCreate, $r->header->status);
        $this->assertCount(1, $r->addresses);
        $this->assertSame(ResolveStatus::CanCreate, $r->addresses[0]->status);
        $this->assertCount(1, $r->bankAccounts);
        $this->assertSame(ResolveStatus::CanCreate, $r->bankAccounts[0]->status);
        $this->assertCount(1, $r->contacts);
        $this->assertSame(ResolveStatus::CanCreate, $r->contacts[0]->status);
    }

    // ── divisionCode warning ──────────────────────────────────────────────

    public function testUnknownDivisionCodeEmitsWarningIssue(): void
    {
        $db = $this->createMock(Connection::class);
        // Header probe (company_id) → hit (id=42).
        // Address: registryCode probe (miss) + world_divisions lookup (miss).
        $db->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                new Row(['id' => 42]),  // header company_id hit
                null,                   // address registryCode miss
                null,                   // world_divisions lookup miss
            );

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'companyId'  => '12345678',
            'addresses'  => [[
                'addressType'  => 1,
                'registryCode' => '99999999',
                'divisionCode' => 'BOGUS_CODE',
                'street'       => 'Hlavní',
            ]],
        ], MergeStrategy::MergeAdd);

        $this->assertCount(1, $r->issues);
        $this->assertSame('warning', $r->issues[0]['severity']);
        $this->assertSame('division_unknown', $r->issues[0]['code']);
        $this->assertSame('addresses.0.divisionCode', $r->issues[0]['path']);
    }

    public function testKnownDivisionCodeDoesNotEmitIssue(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                new Row(['id' => 42]),    // header hit
                null,                     // registryCode miss → canCreate
                new Row(['id' => 999]),   // world_divisions hit
            );

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'companyId'  => '12345678',
            'addresses'  => [[
                'addressType'  => 1,
                'registryCode' => '99999999',
                'divisionCode' => '554782',
                'street'       => 'Hlavní',
            ]],
        ], MergeStrategy::MergeAdd);

        $this->assertSame([], $r->issues);
        $this->assertSame(999, $r->addresses[0]->createPayload['division']);
    }

    // ── fullSync closing ──────────────────────────────────────────────────

    public function testFullSyncEnumeratesClosingExistingForMatchedHeader(): void
    {
        $db = $this->createMock(Connection::class);

        // Sequence of DB calls:
        //   1. fetch — PartyResolver company_id probe → header match (id=42)
        //   2. fetch — AddressResolver placeReg probe (miss)
        //   3. fetch — AddressResolver registryCode probe (miss for the
        //              one payload address; goes to canCreate)
        //   4. fetch — world_divisions lookup (no divisionCode supplied → SKIPPED)
        //              Actually skipped — lookupDivisionId returns null for null/empty.
        //   5. fetchAll — BankAccountResolver: účty osoby, porovnání klíčem
        //               v PHP (#118 D16) → hit (id=201)
        //   6. fetchAll — closing addresses
        //   7. fetchAll — closing bank accounts
        //   8. fetchAll — closing contacts

        $db->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                new Row(['id' => 42]),    // header company_id hit
                null,                     // address placeReg probe miss
                null,                     // address registryCode probe miss
            );

        $db->expects($this->exactly(4))
            ->method('fetchAll')
            ->willReturnOnConsecutiveCalls(
                [   // účty osoby — IBAN z payloadu sedí na id 201
                    new Row(['id' => 201, 'account_number' => '...', 'iban' => 'CZ65...']),
                ],
                [   // closing addresses — only address_type=1 enumerated
                    new Row(['id' => 100, 'address_type' => 1, 'display_line' => 'Stará 1, Praha', 'place_reg_type' => null, 'place_reg_id' => null]),
                    new Row(['id' => 101, 'address_type' => 1, 'display_line' => 'Stará 2, Brno', 'place_reg_type' => null, 'place_reg_id' => null]),
                ],
                [   // closing bank accounts — 201 matched, 202 to be closed
                    new Row(['id' => 201, 'iban' => 'CZ65...', 'account_number' => '...', 'name' => 'Main', 'currency' => 'czk']),
                    new Row(['id' => 202, 'iban' => 'DE89...', 'account_number' => '...', 'name' => 'EUR', 'currency' => 'eur']),
                ],
                [   // closing contacts — empty
                ],
            );

        $r = $this->buildResolver($db)->resolve([
            'personType'   => 'company',
            'companyId'    => '12345678',
            'addresses'    => [[
                'addressType'  => 1,
                'placeRegType' => 'ICP',
                'placeRegId'   => 'X',
                'registryCode' => '99999999',
                'street'       => 'Nová',
            ]],
            'bankAccounts' => [['iban' => 'CZ65...']],
            'contacts'     => [],
        ], MergeStrategy::FullSync);

        // 201 was matched → not in closing. 202 stays in closing.
        $this->assertCount(1, $r->closingExisting['bankAccounts']);
        $this->assertSame(202, (int) $r->closingExisting['bankAccounts'][0]['id']);

        // Address payload had only address_type=1; both existing get
        // closed because the one in payload was canCreate (no matched
        // ids to subtract).
        $this->assertCount(2, $r->closingExisting['addresses']);

        $this->assertCount(0, $r->closingExisting['contacts']);
    }

    public function testFullSyncWithEmptyAddressArrayDoesNotCloseAnyAddress(): void
    {
        $db = $this->createMock(Connection::class);
        // header probe + 2 bank/contact closing fetchAll, no address fetchAll
        $db->expects($this->once())
            ->method('fetch')
            ->willReturn(new Row(['id' => 42]));
        $db->expects($this->exactly(2))
            ->method('fetchAll')
            ->willReturn([]);  // bank + contact closing both empty

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'companyId'  => '12345678',
            'addresses'  => [],
        ], MergeStrategy::FullSync);

        $this->assertSame([], $r->closingExisting['addresses']);
    }

    public function testMergeAddDoesNotEnumerateClosing(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->willReturn(new Row(['id' => 42]));
        // No fetchAll for closing — mergeAdd does not close.
        $db->expects($this->never())->method('fetchAll');

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'companyId'  => '12345678',
        ], MergeStrategy::MergeAdd);

        $this->assertSame([], $r->closingExisting['addresses']);
        $this->assertSame([], $r->closingExisting['bankAccounts']);
        $this->assertSame([], $r->closingExisting['contacts']);
    }

    // ── toArray() shape ──────────────────────────────────────────────────

    public function testToArrayMirrorsSpecResolveShape(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 42]));

        $r = $this->buildResolver($db)->resolve([
            'personType' => 'company',
            'companyId'  => '12345678',
        ], MergeStrategy::MergeAdd);

        $arr = $r->toArray();
        $this->assertArrayHasKey('header', $arr);
        $this->assertArrayHasKey('addresses', $arr);
        $this->assertArrayHasKey('bankAccounts', $arr);
        $this->assertArrayHasKey('contacts', $arr);
        $this->assertArrayHasKey('closingExisting', $arr);
        $this->assertArrayHasKey('issues', $arr);
        $this->assertSame('matched', $arr['header']['status']);
        $this->assertSame(42, $arr['header']['matchedId']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function buildResolver(Connection $db): PersonResolver
    {
        return new PersonResolver(
            db: $db,
            partyResolver: new PartyResolver($db, new OwnCompanyResolver($db)),
            addressResolver: new AddressResolver($db),
            bankAccountResolver: new BankAccountResolver($db),
            contactResolver: new ContactResolver($db),
        );
    }
}
