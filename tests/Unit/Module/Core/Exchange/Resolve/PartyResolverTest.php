<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonType;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveStatus;
use Shipard\Module\Docs\Core\OwnCompanyResolver;

class PartyResolverTest extends TestCase
{
    private function buildResolver(Connection $db): PartyResolver
    {
        return new PartyResolver($db, new OwnCompanyResolver($db));
    }

    public function testCompanyIdHitWinsOverOthers(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with($this->stringContains('base_persons_persons'), 'company_id', '12345678', 10, 40, 80)
            ->willReturn(new Row(['id' => 42]));

        $r = $this->buildResolver($db)->resolve([
            'companyId' => '12345678',
            'vatId'     => 'CZ12345678',
            'taxId'     => 'CZ12345678',
            'name'      => 'Anything',
        ]);

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(42, $r->matchedId);
        $this->assertSame('companyId', $r->matchedBy);
    }

    public function testVatIdProbeRunsAfterCompanyIdMiss(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(2))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,                      // company_id miss
                new Row(['id' => 17]),     // vat_id hit
            );

        $r = $this->buildResolver($db)->resolve([
            'companyId' => '12345678',
            'vatId'     => 'CZ12345678',
        ]);

        $this->assertSame(17, $r->matchedId);
        $this->assertSame('vatId', $r->matchedBy);
    }

    public function testTaxIdProbeFallsThroughCompanyAndVatMisses(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->exactly(3))
            ->method('fetch')
            ->willReturnOnConsecutiveCalls(
                null,
                null,
                new Row(['id' => 5]),
            );

        $r = $this->buildResolver($db)->resolve([
            'companyId' => '12345678',
            'vatId'     => 'CZ12345678',
            'taxId'     => 'CZ12345678',
        ]);

        $this->assertSame(5, $r->matchedId);
        $this->assertSame('taxId', $r->matchedBy);
    }

    public function testNameFuzzySingleMatchProducesMatched(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 11, 'full_name' => 'Acme s.r.o.', 'company_id' => '99887766']),
        ]);

        $r = $this->buildResolver($db)->resolve(['name' => 'Acme']);

        $this->assertSame(11, $r->matchedId);
        $this->assertSame('name', $r->matchedBy);
    }

    public function testNameFuzzyMultipleMatchesProducesAmbiguous(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 1, 'full_name' => 'Acme s.r.o.', 'company_id' => '111']),
            new Row(['id' => 2, 'full_name' => 'Acme Lab',    'company_id' => '222']),
        ]);

        $r = $this->buildResolver($db)->resolve(['name' => 'Acme']);

        $this->assertSame(ResolveStatus::Ambiguous, $r->status);
        $this->assertCount(2, $r->candidates);
        $this->assertSame(['id' => 1, 'name' => 'Acme s.r.o.', 'companyId' => '111'], $r->candidates[0]);
    }

    public function testNoMatchWithNameProducesCanCreate(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);

        $r = $this->buildResolver($db)->resolve([
            'name'      => 'New Vendor s.r.o.',
            'companyId' => '99887766',
            'taxId'     => 'CZ99887766',
        ]);

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('New Vendor s.r.o.', $r->createPayload['full_name']);
        $this->assertSame('99887766', $r->createPayload['company_id']);
        $this->assertSame('CZ99887766', $r->createPayload['tax_id']);
        $this->assertSame(PersonType::Company->value, $r->createPayload['person_type']);
        $this->assertArrayNotHasKey('addresses', $r->createPayload, 'bez adresy v canonicalu klíč chybí');
    }

    public function testCanCreatePayloadCarriesHeadquartersAddress(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);

        $r = $this->buildResolver($db)->resolve([
            'name'    => 'New Vendor s.r.o.',
            'companyId' => '99887766',
            'address' => ['street' => ' Pilařská ', 'houseNumber' => '1204/7', 'city' => 'Olomouc', 'zip' => '77900', 'country' => 'CZ', 'cityPart' => null],
        ]);
        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame([[
            'address_type' => PartyResolver::ADDRESS_TYPE_HEADQUARTERS,
            'street'       => 'Pilařská',
            'house_number' => '1204/7',
            'city'         => 'Olomouc',
            'zip'          => '77900',
            'country'      => 'cz',
        ]], $r->createPayload['addresses']);

        $empty = $this->buildResolver($db)->resolve(['name' => 'X', 'address' => ['street' => null, 'city' => '']]);
        $this->assertArrayNotHasKey('addresses', $empty->createPayload, 'adresa se samými null = bez adresy');
    }

    public function testNoMatchWithoutNameProducesNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        // companyId probe → null, no fetchAll (no name)
        $db->method('fetch')->willReturn(null);
        $db->expects($this->never())->method('fetchAll');

        $r = $this->buildResolver($db)->resolve(['companyId' => '99887766']);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testEmptyPartyReturnsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');

        $r = $this->buildResolver($db)->resolve([]);
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testSelfPartyReturnsOwnCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1]));

        $r = $this->buildResolver($db)->resolveSelfParty();
        $this->assertSame(1, $r->matchedId);
        $this->assertSame('selfParty', $r->matchedBy);
    }

    public function testSelfPartyNotConfiguredReturnsNotFound(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);

        $r = $this->buildResolver($db)->resolveSelfParty();
        $this->assertSame(ResolveStatus::NotFound, $r->status);
    }

    public function testDiffSelfPartyIdentityDetectsMismatch(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')
            ->willReturnOnConsecutiveCalls(
                new Row(['id' => 1]),                                  // getOwnPersonId
                new Row(['id' => 1, 'company_id' => '11111111',        // getOwnPersonData
                        'vat_id' => 'CZ11111111', 'tax_id' => 'CZ11111111']),
            );

        $diff = $this->buildResolver($db)->diffSelfPartyIdentity([
            'companyId' => '22222222',                                  // mismatch
            'vatId'     => 'CZ11111111',                                // match
            'taxId'     => null,                                        // empty → ignored
        ]);

        $this->assertSame(['companyId'], $diff);
    }

    public function testIdentifiersOnlySkipsNameProbeAndCanCreate(): void
    {
        // Natural person without IČO whose name collides with others —
        // identifiersOnly must skip the fuzzy probe (no fetchAll) and fall
        // straight to canCreate instead of matching/ambiguous.
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetch');     // no identifier probes (none supplied)
        $db->expects($this->never())->method('fetchAll');  // name probe skipped

        $r = $this->buildResolver($db)->resolve(
            ['name' => 'Jan Novák'],
            PersonType::Person,
            true,
        );

        $this->assertSame(ResolveStatus::CanCreate, $r->status);
        $this->assertSame('Jan Novák', $r->createPayload['full_name']);
        $this->assertSame(PersonType::Person->value, $r->createPayload['person_type']);
    }

    public function testIdentifiersOnlyStillMatchesByCompanyId(): void
    {
        // identifiersOnly suppresses only the name probe — a real companyId
        // match still wins (don't duplicate a company already in the DB).
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('base_persons_persons'),
                'company_id', '12345678',
                PersonType::Company->value,   // probe narrowed to the requested person type
                10, 40, 80,
            )
            ->willReturn(new Row(['id' => 42]));
        $db->expects($this->never())->method('fetchAll');

        $r = $this->buildResolver($db)->resolve(
            ['companyId' => '12345678', 'name' => 'Anything'],
            PersonType::Company,
            true,
        );

        $this->assertSame(ResolveStatus::Matched, $r->status);
        $this->assertSame(42, $r->matchedId);
        $this->assertSame('companyId', $r->matchedBy);
    }
}
