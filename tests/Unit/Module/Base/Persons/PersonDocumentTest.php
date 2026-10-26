<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Base\Persons\PersonDocument;
use Shipard\Module\Base\Persons\Values\PersonContext;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/** Kontext bez databáze s pevnou zemí zdroje dat a uloženou zemí osoby 50. */
final class StubPersonContext extends PersonContext
{
    public function __construct(string $dsCountry, private readonly ?string $storedCountryOf50 = null)
    {
        parent::__construct(null, $dsCountry);
    }

    protected function storedCountry(int $personId): ?string
    {
        return $personId === 50 ? $this->storedCountryOf50 : null;
    }
}

/** PersonDocument s podstrčeným kontextem (šev `personContext()`). */
class ContextPersonDocument extends PersonDocument
{
    public function __construct(private readonly PersonContext $context)
    {
    }

    protected function personContext(): PersonContext
    {
        return $this->context;
    }
}

/** Zachytí řádky adres místo INSERTu (Dibi\Connection je final). */
final class AddressCapturingPersonDocument extends PersonDocument
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    protected function insertAddress(array $row): void
    {
        $this->rows[] = $row;
    }
}

class PersonDocumentTest extends TestCase
{
    private function doc(): PersonDocument
    {
        return new PersonDocument();
    }

    // --- kanonický tvar údajů (#118 D18) ------------------------------------

    public function testCountryHintDrivesNormalizationAndNeverReachesSql(): void
    {
        $doc = new ContextPersonDocument(new StubPersonContext('cz'));
        $data = ['person_type' => 2, 'full_name' => 'Muster GmbH', 'person_id' => 'F00002', '_country' => 'DE',
            'company_id' => ' HRB 12345 ', 'phone' => '030 / 12 34 56', 'email' => ' Info@Muster.Example'];
        $this->assertTrue($doc->validate($data)->isValid());
        $doc->beforeSave($data);
        $this->assertArrayNotHasKey('_country', $data);
        $this->assertSame('HRB 12345', $data['company_id'], 'německé IČO jen trim — bez vedoucích nul');
        $this->assertSame('+4930123456', $data['phone']);
        $this->assertSame('info@muster.example', $data['email']);
    }

    public function testCountryOfNewPersonComesFromItsAddresses(): void
    {
        $doc = new class (new StubPersonContext('cz')) extends ContextPersonDocument {
            protected function insertAddress(array $row): void
            {
            }
        };
        $data = ['person_type' => 2, 'full_name' => 'Slovak s.r.o.', 'person_id' => 'F00003',
            'company_id' => '36339008', 'phone' => '0905 123 456',
            'addresses' => [
                ['address_type' => 2, 'city' => 'Praha', 'country' => 'cz', 'zip' => '110 00'],
                ['address_type' => 1, 'city' => 'Bratislava', 'country' => 'SK', 'zip' => '811 01'],
            ]];
        $doc->beforeSave($data);
        $this->assertSame('+421905123456', $data['phone'], 'sídlo má přednost před první adresou');
        $this->assertSame('36339008', $data['company_id']);
    }

    public function testPendingAddressesAreNormalizedByTheirOwnCountry(): void
    {
        $doc = new AddressCapturingPersonDocument();
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'person_id' => 'F00001', 'docState' => 10, 'docStateMain' => 1,
            'addresses' => [
                ['address_type' => 1, 'city' => 'Zlín', 'zip' => ' 760 01', 'country' => 'CZ'],
                ['address_type' => 2, 'city' => 'London', 'zip' => 'SW1A 1AA'],
            ]];
        $doc->beforeSave($data);
        $doc->afterPersist($data + ['id' => 42]);
        $this->assertSame('76001', $doc->rows[0]['zip']);
        $this->assertSame('cz', $doc->rows[0]['country']);
        $this->assertSame('SW1A1AA', $doc->rows[1]['zip'], 'adresa bez země podle země osoby — cz ze sídla (D24)');
        $this->assertArrayNotHasKey('country', $doc->rows[1], 'země se nedoplňuje');
    }

    public function testUpdateUsesStoredAddressesThenDataSourceCountry(): void
    {
        $stored = new ContextPersonDocument(new StubPersonContext('cz', storedCountryOf50: 'sk'));
        $data = ['id' => 50, 'person_type' => 2, 'full_name' => 'Slovak s.r.o.', 'phone' => '0905 123 456'];
        $stored->beforeSave($data);
        $this->assertSame('+421905123456', $data['phone'], 'země z uložených adres osoby');

        $fallback = new ContextPersonDocument(new StubPersonContext('sk'));
        $new = ['person_type' => 2, 'full_name' => 'Nová s.r.o.', 'person_id' => 'F00009', 'phone' => '0905 123 456'];
        $fallback->beforeSave($new);
        $this->assertSame('+421905123456', $new['phone'], 'nová osoba bez adres: země zdroje dat');

        $plain = ['person_type' => 2, 'full_name' => 'Česká s.r.o.', 'person_id' => 'F00010', 'phone' => '577 123 456', 'company_id' => '6947'];
        $this->doc()->beforeSave($plain);
        $this->assertSame('+420577123456', $plain['phone'], 'bez konfigurace zdroje dat platí cz');
        $this->assertSame('00006947', $plain['company_id']);
    }

    // --- platnost údajů (#118 D19) ------------------------------------------

    /** @return list<string> */
    private static function codes(array $entries): array
    {
        return array_map(static fn (array $e): string => $e['column'] . ':' . $e['code'], $entries);
    }

    public function testPartnerWithInvalidValuesSavesWithWarnings(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'company_id' => '52654151', 'vat_id' => 'CZ526541',
            'addresses' => [['address_type' => 1, 'city' => 'Zlín', 'zip' => '7790', 'country' => 'cz']]];
        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid(), 'varování uložení neblokuje');
        $this->assertSame(
            ['company_id:company_id_invalid', 'vat_id:vat_id_invalid', 'addresses.0.zip:zip_invalid'],
            self::codes($result->warningsToArray()),
        );
        $this->assertSame('IČO „52654151“ nemá platný kontrolní součet.', $result->warningsToArray()[0]['message']);
    }

    public function testOwnCompanyWithInvalidValuesIsRejected(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Moje s.r.o.', 'is_own' => 1, 'company_id' => '52654150', 'vat_id' => 'CZ07123451'];
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame(['vat_id:vat_id_company_id_mismatch'], self::codes($result->toArray()));
        $this->assertSame([], $result->warningsToArray());
    }

    public function testForeignCompanyIdHasNoFinding(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Muster GmbH', '_country' => 'de', 'company_id' => 'HRB 12345', 'vat_id' => 'DE136695976'];
        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->warningsToArray());

        $cz = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'company_id' => '6947', 'vat_id' => '00006947'];
        $this->assertSame([], $this->doc()->validate($cz)->warningsToArray(), 'kontrola nad normalizovaným tvarem (vedoucí nuly, prefix)');
    }

    // --- adresy s novou osobou (#103 D99) ----------------------------------

    public function testAddressesLeaveTheDataInBeforeSaveAndArePersistedAfterTheRow(): void
    {
        $doc = new AddressCapturingPersonDocument();
        $data = [
            'person_type' => 2, 'full_name' => 'Acme s.r.o.', 'person_id' => 'F00001',
            'docState' => 10, 'docStateMain' => 1,
            'addresses' => [
                ['address_type' => 1, 'street' => 'Pilařská', 'city' => 'Olomouc', 'zip' => '77900', 'country' => 'cz', 'city_part' => null],
                ['address_type' => 2, 'city' => 'Brno', 'country' => 'cz', 'id' => 99, 'person' => 5, 'docState' => 40],
            ],
        ];

        $this->assertTrue($doc->validate($data)->isValid());
        $doc->beforeSave($data);
        $this->assertArrayNotHasKey('addresses', $data, 'adresy nejsou sloupec osoby — do INSERTu nesmí');
        $this->assertSame([], $doc->rows, 'před uložením osoby se nic nezapisuje');

        $doc->afterPersist($data + ['id' => 42]);
        $this->assertSame([
            ['address_type' => 1, 'street' => 'Pilařská', 'city' => 'Olomouc', 'zip' => '77900', 'country' => 'cz',
                'order_pos' => 1, 'person' => 42, 'docState' => 10, 'docStateMain' => 1],
            ['address_type' => 2, 'city' => 'Brno', 'country' => 'cz',
                'order_pos' => 2, 'person' => 42, 'docState' => 10, 'docStateMain' => 1],
        ], $doc->rows, 'null hodnoty pryč, id / person / stav řádku z payloadu se nepoužijí, stav = stav osoby');

        // Druhé uložení téhož dokumentu bez adres nic nezapíše.
        $again = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'id' => 42];
        $doc->beforeSave($again);
        $doc->afterPersist($again);
        $this->assertCount(2, $doc->rows);
    }

    public function testAddressesAreIgnoredOnUpdateAndDefaultToStateDone(): void
    {
        $doc = new AddressCapturingPersonDocument();
        $update = ['id' => 7, 'person_type' => 2, 'full_name' => 'Acme', 'addresses' => [['city' => 'Brno']]];
        $doc->beforeSave($update);
        $this->assertArrayNotHasKey('addresses', $update);
        $doc->afterPersist($update);
        $this->assertSame([], $doc->rows, 'úprava osoby adresy nezakládá');

        // Applier bez stavu v datech → V pořádku (40 / 3), prázdný řádek se přeskočí.
        $new = ['person_type' => 2, 'full_name' => 'Acme', 'addresses' => [[], ['city' => 'Brno', 'country' => 'cz']]];
        $doc->beforeSave($new);
        $doc->afterPersist($new + ['id' => 8]);
        $this->assertSame([['city' => 'Brno', 'country' => 'cz', 'order_pos' => 1, 'person' => 8, 'docState' => 40, 'docStateMain' => 3]], $doc->rows);

        $this->assertNull((new \ReflectionProperty(PersonDocument::class, 'pendingAddresses'))->getValue($doc), 'po zápisu se nic nedrží');
    }

    public function testAddressesMustBeAListOfRows(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => 'Acme', 'addresses' => ['city' => 'Brno']];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertContains('addresses', array_column($result->toArray(), 'column'));

        $data = ['person_type' => 2, 'full_name' => 'Acme', 'addresses' => [['city' => 'Brno'], 'Praha']];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertContains('addresses.1', array_column($result->toArray(), 'column'));

        $data = ['person_type' => 2, 'full_name' => 'Acme', 'addresses' => null];
        $this->assertTrue($doc->validate($data)->isValid(), 'null = bez adres');
    }

    public function testInsertAddressWithoutDatabaseFailsLoudly(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => 'Acme', 'addresses' => [['city' => 'Brno']]];
        $doc->beforeSave($data);
        $this->expectException(\LogicException::class);
        $doc->afterPersist($data + ['id' => 1]);
    }

    // --- validate -----------------------------------------------------------

    public function testValidateCompanyWithoutFullNameFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => ''];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('full_name', $columns);
    }

    public function testValidatePersonWithoutLastNameFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => ''];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('last_name', $columns);
    }

    public function testValidatePersonWithoutFirstNameFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => '', 'last_name' => 'Novák'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('first_name', $columns);
    }

    public function testValidateMissingPersonTypeFails(): void
    {
        $doc = $this->doc();
        $data = ['full_name' => 'Test'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('person_type', $columns);
    }

    public function testValidateUndefinedPersonTypeFails(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 0, 'full_name' => 'Test'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('person_type', $columns);
    }

    public function testValidateValidCompany(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateValidPerson(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => 'Novák'];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsOwnOnPersonFails(): void
    {
        $doc = $this->doc();
        $data = [
            'person_type' => 1,
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $codes = array_column($result->toArray(), 'code');
        $this->assertContains('is_own_not_company', $codes);
    }

    public function testValidateIsOwnDuplicateFails(): void
    {
        // Mock returns an existing row → uniqueness check fails.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row(['id' => 7]));

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'person_type' => 2,
            'full_name' => 'Acme s.r.o.',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $codes = array_column($result->toArray(), 'code');
        $this->assertContains('is_own_duplicate', $codes);
    }

    public function testValidateIsOwnUniqueWhenNoOtherOwnExists(): void
    {
        // Mock returns null → no other own company in DS.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'person_type' => 2,
            'full_name' => 'Acme s.r.o.',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsOwnUpdateOfSameRecordPasses(): void
    {
        // Mock returns null because the SQL excludes the current id.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'id' => 42,
            'person_type' => 2,
            'full_name' => 'Acme s.r.o.',
            'is_own' => 1,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsOwnZeroSkipsChecks(): void
    {
        // No DB needed — the is_own block must short-circuit on falsy value.
        $doc = $this->doc();
        $data = [
            'person_type' => 1,
            'first_name' => 'Jan',
            'last_name' => 'Novák',
            'is_own' => 0,
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // --- language (#94 D1) --------------------------------------------------

    private function docWithLanguages(): PersonDocument
    {
        $doc = $this->doc();
        $doc->setConfig(ConfigRuntimeFactory::fromItems([
            'world.base.documentLanguages' => [
                'cs' => ['name' => 'čeština'],
                'en' => ['name' => 'angličtina'],
                'sk' => ['name' => 'slovenština'],
                'de' => ['name' => 'němčina'],
            ],
        ]));
        return $doc;
    }

    public function testValidateAcceptsDocumentLanguage(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'language' => 'de'];

        $result = $this->docWithLanguages()->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertSame('de', $data['language']);
    }

    public function testValidateTurnsEmptyLanguageIntoNull(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'language' => ''];

        $result = $this->docWithLanguages()->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertNull($data['language']);
    }

    public function testValidateRejectsLanguageOutsideDocumentLanguages(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'language' => 'fr'];

        $result = $this->docWithLanguages()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->toArray();
        $this->assertSame(['language'], array_column($errors, 'column'));
        $this->assertSame(['invalid_language'], array_column($errors, 'code'));
    }

    public function testValidateWithoutLanguageKeyLeavesPayloadAlone(): void
    {
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.'];

        $result = $this->docWithLanguages()->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertArrayNotHasKey('language', $data);
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveCompanyClearsFirstNameAndSetsLastName(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 2, 'full_name' => 'Acme s.r.o.', 'first_name' => 'ignored'];

        $doc->beforeSave($data);

        $this->assertSame('', $data['first_name']);
        $this->assertSame('Acme s.r.o.', $data['last_name']);
    }

    public function testBeforeSavePersonCombinesFullName(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => 'Jan', 'last_name' => 'Novák'];

        $doc->beforeSave($data);

        $this->assertSame('Jan Novák', $data['full_name']);
    }

    public function testBeforeSavePersonTrimsFullName(): void
    {
        $doc = $this->doc();
        $data = ['person_type' => 1, 'first_name' => '', 'last_name' => 'Novák'];

        $doc->beforeSave($data);

        $this->assertSame('Novák', $data['full_name']);
    }
}
