<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Export;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Export\PersonExporter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

class PersonExporterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function companyRow(): array
    {
        return [
            'id' => 7, 'person_id' => 'P-0007', 'person_type' => 2,
            'company_id' => '12345678', 'tax_id' => 'CZ12345678', 'vat_id' => 'CZ12345678',
            'court_registration' => 'MS Praha C 12345', 'gov_e_box_id' => 'abcd1ef',
            'full_name' => 'Žlutý kůň s.r.o.', 'complex_name' => 0,
            'title_before' => '', 'first_name' => '', 'middle_name' => '', 'last_name' => '', 'title_after' => '',
            'birth_date' => null, 'national_id' => '', 'id_card_number' => '',
            'email' => 'info@zluty.example', 'phone' => '+420123456789', 'web' => '',
            'is_closed' => 0, 'closed_date' => null, 'is_own' => 1,
            'source_kind' => 'registry', 'source_ref' => 'ares:12345678',
            'source_imported_at' => new \DateTimeImmutable('2026-05-01 10:20:30'),
            'docState' => 40, 'docStateMain' => 2,
        ];
    }

    private function dbWithChildren(array $addresses, array $banks, array $contacts, ?string $divisionCode = null): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql) use ($addresses, $banks, $contacts): array {
            $rows = match (true) {
                str_contains($sql, '[base_persons_addresses]')     => $addresses,
                str_contains($sql, '[base_persons_bank_accounts]') => $banks,
                str_contains($sql, '[base_persons_contacts]')      => $contacts,
                default => [],
            };
            return array_map(static fn(array $r) => new Row($r), $rows);
        });
        $db->method('fetch')->willReturnCallback(function (string $sql) use ($divisionCode): ?Row {
            if (str_contains($sql, '[world_divisions]')) {
                return $divisionCode === null ? null : new Row(['code' => $divisionCode]);
            }
            return null;
        });
        return $db;
    }

    public function testCompanyWithSubCollectionsMapsToCanonical(): void
    {
        $db = $this->dbWithChildren(
            addresses: [[
                'id' => 1, 'person' => 7, 'address_type' => 1, 'name' => null, 'place_reg_type' => 'ICP',
                'place_reg_id' => '123', 'is_standardized' => 1, 'street' => 'Dlouhá', 'house_number' => '12',
                'orientation_number' => '3a', 'city' => 'Praha', 'city_part' => 'Staré Město', 'district' => null,
                'zip' => '11000', 'country' => 'CZ', 'registry_code' => null, 'division' => 5,
                'latitude' => '50.0870000', 'longitude' => '14.4210000', 'manual_gps' => 0,
                'display_line' => 'Dlouhá 12, Praha', 'display_block' => null, 'order_pos' => 0,
                'valid_from' => new \DateTimeImmutable('2020-01-01'), 'valid_to' => null, 'note' => '',
                'docState' => 40,
            ]],
            banks: [[
                'id' => 2, 'person' => 7, 'name' => 'Hlavní', 'account_number' => '123456789/0100',
                'iban' => 'CZ6501000000000123456789', 'bic' => 'KOMBCZPP', 'currency' => 'czk',
                'source' => 0, 'order_pos' => 0, 'valid_from' => null, 'valid_to' => null, 'docState' => 40,
            ]],
            contacts: [[
                'id' => 3, 'person' => 7, 'name' => 'Jan Novák', 'role' => 'účtárna', 'email' => 'jan@zluty.example',
                'phone' => null, 'note' => null, 'order_pos' => 1, 'valid_from' => null, 'valid_to' => null, 'docState' => 40,
            ]],
            divisionCode: 'CZ010',
        );

        $record = (new PersonExporter($db))->exportPerson($this->companyRow());
        $c = $record->data;

        $this->assertSame(7, $record->id);
        $this->assertSame('Žlutý kůň s.r.o.', $record->slug);
        $this->assertSame('shpd.persons.person', $c['format']);
        $this->assertSame('1.1', $c['formatVersion']);
        $this->assertSame('company', $c['personType']);
        $this->assertSame('cz', $c['country'], 'country comes from the first address');
        $this->assertSame('P-0007', $c['personId']);
        $this->assertSame('12345678', $c['companyId']);
        $this->assertSame(['fullName' => 'Žlutý kůň s.r.o.'], $c['name'], 'empty name parts are pruned');
        $this->assertArrayNotHasKey('personal', $c, 'companies carry no personal block');
        $this->assertSame(['email' => 'info@zluty.example', 'phone' => '+420123456789'], $c['contact']);
        $this->assertSame(['isOwn' => true, 'docState' => 40], $c['status']);
        $this->assertSame(
            ['kind' => 'registry', 'registryRef' => 'ares:12345678'],
            $c['source'],
            'fetchedAt is not exported — the applier stamps import time itself',
        );

        $addr = $c['addresses'][0];
        $this->assertSame(1, $addr['addressType']);
        $this->assertSame('ICP', $addr['placeRegType']);
        $this->assertTrue($addr['isStandardized']);
        $this->assertSame('cz', $addr['country']);
        $this->assertSame('CZ010', $addr['divisionCode']);
        $this->assertSame(50.087, $addr['latitude']);
        $this->assertSame('2020-01-01', $addr['validFrom']);
        $this->assertArrayNotHasKey('manualGps', $addr);
        $this->assertArrayNotHasKey('note', $addr);

        $this->assertSame(
            ['name' => 'Hlavní', 'accountNumber' => '123456789/0100', 'iban' => 'CZ6501000000000123456789',
             'bic' => 'KOMBCZPP', 'currency' => 'CZK', 'source' => 0, 'orderPos' => 0],
            $c['bankAccounts'][0],
        );
        $this->assertSame(
            ['name' => 'Jan Novák', 'role' => 'účtárna', 'email' => 'jan@zluty.example', 'orderPos' => 1],
            $c['contacts'][0],
        );
        $this->assertSame(
            ['mergeStrategy' => 'createOnly', 'matchStrategy' => 'identifiersOnly', 'targetDocState' => 40],
            $c['applyOptions'],
        );

        $issues = (new SchemaValidator(SchemaLoader::default()))->validate($c, 'shpd.persons.person', '1');
        $this->assertSame([], $issues, 'exported canonical must validate against the persons schema');
    }

    public function testDocumentsBlockCarriesLanguageAndMergeFlag(): void
    {
        $db = $this->dbWithChildren([], [], []);
        $exporter = new PersonExporter($db);

        $c = $exporter->exportPerson(['language' => 'de', 'send_attachments_merged' => 1] + $this->companyRow())->data;
        $this->assertSame(['language' => 'de', 'sendAttachmentsMerged' => true], $c['documents']);

        // Bez jazyka blok zůstává — spojování je výslovné false, ne null.
        $c = $exporter->exportPerson(['language' => null, 'send_attachments_merged' => 0] + $this->companyRow())->data;
        $this->assertSame(['sendAttachmentsMerged' => false], $c['documents']);

        $issues = (new SchemaValidator(SchemaLoader::default()))->validate($c, 'shpd.persons.person', '1');
        $this->assertSame([], $issues);
    }

    public function testDocumentsBlockWithoutMergeColumnKeepsOnlyLanguage(): void
    {
        // Zdroj dat bez extension docs.core sloupec nemá.
        $db = $this->dbWithChildren([], [], []);
        $exporter = new PersonExporter($db);

        $c = $exporter->exportPerson(['language' => 'sk'] + $this->companyRow())->data;
        $this->assertSame(['language' => 'sk'], $c['documents']);

        $c = $exporter->exportPerson($this->companyRow())->data;
        $this->assertArrayNotHasKey('documents', $c);
    }

    public function testNaturalPersonUsesDefaultCountryAndPersonalBlock(): void
    {
        $db = $this->dbWithChildren([], [], []);
        $row = $this->companyRow();
        $row['person_type'] = 1;
        $row['company_id'] = '';
        $row['full_name'] = 'Jana Nováková';
        $row['first_name'] = 'Jana';
        $row['last_name'] = 'Nováková';
        $row['birth_date'] = '1985-03-04';
        $row['national_id'] = '855304/1234';
        $row['is_own'] = 0;
        $row['source_kind'] = null;
        $row['docState'] = 80;

        $c = (new PersonExporter($db, 'sk'))->exportPerson($row)->data;

        $this->assertSame('person', $c['personType']);
        $this->assertSame('sk', $c['country']);
        $this->assertSame(['birthDate' => '1985-03-04', 'nationalId' => '855304/1234'], $c['personal']);
        $this->assertSame(['docState' => 80], $c['status']);
        $this->assertSame(40, $c['applyOptions']['targetDocState'], 'archived persons seed as 40 (schema allows 10|40)');
        $this->assertArrayNotHasKey('source', $c);
        $this->assertArrayNotHasKey('addresses', $c);
    }

    public function testUndefinedPersonTypeFallsBackByCompanyId(): void
    {
        $db = $this->dbWithChildren([], [], []);
        $row = $this->companyRow();
        $row['person_type'] = 0;

        $this->assertSame('company', (new PersonExporter($db))->exportPerson($row)->data['personType']);

        $row['company_id'] = '';
        $this->assertSame('person', (new PersonExporter($db))->exportPerson($row)->data['personType']);
    }

    public function testAddressTypeZeroIsExportedAsOne(): void
    {
        $db = $this->dbWithChildren([[
            'id' => 1, 'person' => 7, 'address_type' => 0, 'street' => 'X', 'city' => 'Y', 'country' => 'cz',
            'order_pos' => 0, 'docState' => 40,
        ]], [], []);

        $c = (new PersonExporter($db))->exportPerson($this->companyRow())->data;
        $this->assertSame(1, $c['addresses'][0]['addressType']);
    }

    public function testExportAllQueriesNonDeletedPersonsInNaturalOrder(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->expects($this->atLeastOnce())->method('fetchAll')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'FROM [base_persons_persons]')) {
                $this->assertStringContainsString('[docState] <> 90', $sql);
                $this->assertStringContainsString('ORDER BY [full_name], [company_id], [person_id], [id]', $sql);
                return [new Row($this->companyRow())];
            }
            return [];
        });

        $records = (new PersonExporter($db))->exportAll();
        $this->assertCount(1, $records);
        $this->assertSame(7, $records[0]->id);
    }

    public function testExportByIdsWithEmptyListSkipsDb(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->never())->method('fetchAll');

        $this->assertSame([], (new PersonExporter($db))->exportByIds([]));
    }
}
