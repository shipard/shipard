<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\PersonSnapshotBuilder;

/**
 * Pravidlo prázdné hodnoty ve snapshotu osoby (#166 D29): prázdný řetězec
 * i řetězec ze samých mezer je `null` — ať hodnoty přišly z adresáře
 * (formulář), nebo z kanonické strany dokladu (import). `name` zůstává
 * řetězec, vyplněná hodnota se nemění.
 */
final class PersonSnapshotBuilderTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function values(): array
    {
        return [
            'null'              => [null, null],
            'prázdný řetězec'   => ['', null],
            'samé mezery'       => ['   ', null],
            'tabulátor a řádek' => ["\t\n", null],
            'vyplněná hodnota'  => ['CZ12345678', 'CZ12345678'],
            'hodnota s mezerou' => [' 110 00 ', ' 110 00 '],
            'nula jako text'    => ['0', '0'],
        ];
    }

    #[DataProvider('values')]
    public function testEmptyToNull(mixed $value, mixed $expected): void
    {
        $this->assertSame($expected, PersonSnapshotBuilder::emptyToNull($value));
    }

    #[DataProvider('values')]
    public function testShapeAppliesTheRuleToEveryTextValue(mixed $value, mixed $expected): void
    {
        $snap = PersonSnapshotBuilder::shape(
            [
                'full_name' => 'Beta s.r.o.', 'company_id' => $value, 'tax_id' => $value, 'vat_id' => $value,
                'court_registration' => $value, 'email' => $value, 'phone' => $value, 'web' => $value, 'gov_e_box_id' => $value,
            ],
            [
                'street' => $value, 'house_number' => $value, 'city' => $value, 'city_part' => $value,
                'zip' => $value, 'country' => $value, 'display_block' => $value, 'display_line' => $value,
            ],
        );

        $this->assertSame('Beta s.r.o.', $snap['name']);
        foreach (['company_id', 'tax_id', 'vat_id', 'court_registration'] as $key) {
            $this->assertSame($expected, $snap[$key], $key);
        }
        $this->assertSame(array_fill_keys(['email', 'phone', 'web', 'gov_e_box_id'], $expected), $snap['contact']);
        $this->assertSame(
            array_fill_keys(['street', 'house_number', 'city', 'city_part', 'zip', 'country', 'display_block', 'display_line'], $expected),
            $snap['address'],
        );
    }

    public function testNameStaysAString(): void
    {
        $this->assertSame('', PersonSnapshotBuilder::shape([], null)['name']);
        $this->assertSame('  ', PersonSnapshotBuilder::shape(['full_name' => '  '], null)['name']);
    }

    /**
     * Adresář drží prázdné hodnoty u jedné osoby jako `''`, u druhé jako
     * `NULL` — snapshot partnera je v obou případech stejný.
     */
    public function testPartnerSnapshotIsTheSameForEmptyStringsAndNulls(): void
    {
        $person = ['id' => 5, 'full_name' => 'Beta s.r.o.', 'company_id' => '12345678'];
        $address = ['id' => 11, 'person' => 5, 'street' => 'Hlavní', 'city' => 'Praha', 'zip' => '11000', 'country' => 'cz'];
        $emptyStrings = ['tax_id' => '', 'vat_id' => '', 'court_registration' => ' ', 'email' => '', 'phone' => '', 'web' => '', 'gov_e_box_id' => ''];
        $nulls = array_fill_keys(array_keys($emptyStrings), null);

        $fromStrings = (new PersonSnapshotBuilder($this->db([$person + $emptyStrings, $address + ['house_number' => '', 'city_part' => '']])))
            ->buildPartner(5, 11);
        $fromNulls = (new PersonSnapshotBuilder($this->db([$person + $nulls, $address + ['house_number' => null, 'city_part' => null]])))
            ->buildPartner(5, 11);

        $this->assertSame($fromNulls, $fromStrings);
        $this->assertNull($fromStrings['tax_id']);
        $this->assertNull($fromStrings['contact']['gov_e_box_id']);
        $this->assertNull($fromStrings['address']['house_number']);
        $this->assertSame('12345678', $fromStrings['company_id']);
    }

    /** Vlastní firma: pravidlo platí i pro bankovní spojení a registraci DPH. */
    public function testOwnCompanySnapshotCarriesEmptyValuesAsNull(): void
    {
        $snap = (new PersonSnapshotBuilder($this->db([
            ['id' => 1, 'full_name' => 'Naše s.r.o.', 'company_id' => '87654321', 'tax_id' => '', 'web' => '', 'gov_e_box_id' => ' '],
            ['id' => 100, 'street' => 'Vlastní', 'house_number' => '1', 'city' => 'Brno', 'city_part' => '', 'zip' => '60200', 'country' => 'cz'],
            ['id' => 21, 'name' => 'Běžný účet', 'account_number' => '123/0300', 'iban' => '', 'bic' => '', 'currency' => 'czk'],
            ['id' => 31, 'country' => 'cz', 'vat_id' => ''],
        ])))->build(1, 100, 21, 31);

        $this->assertNull($snap['tax_id']);
        $this->assertSame(['email' => null, 'phone' => null, 'web' => null, 'gov_e_box_id' => null], $snap['contact']);
        $this->assertNull($snap['address']['city_part']);
        $this->assertSame(
            ['name' => 'Běžný účet', 'account_number' => '123/0300', 'iban' => null, 'bic' => null, 'currency' => 'czk'],
            $snap['bank_account'],
        );
        $this->assertSame(['country' => 'cz', 'vat_id' => null], $snap['vat_registration']);
    }

    /** @param list<array<string, mixed>> $rows řádky v pořadí dotazů builderu */
    private function db(array $rows): Connection
    {
        $db = $this->createMock(Connection::class);
        $index = 0;
        $db->method('fetch')->willReturnCallback(static function () use (&$index, $rows): ?Row {
            $row = $rows[$index++] ?? null;
            return $row === null ? null : new Row($row);
        });
        return $db;
    }
}
