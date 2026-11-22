<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Resolve;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Exchange\Resolve\ReferenceException;
use Shipard\Module\Core\Exchange\Resolve\ReferenceValue;

/**
 * Gramatika hodnoty reference (#166 D9, D15): číslo, `"@klíč"`,
 * `"@sloupec:hodnota"` — a pravidlo dvojtečky.
 */
class ReferenceValueTest extends TestCase
{
    /** Osoby: klíč `person_id`, hledání i přes `company_id` a `vat_id`. */
    private static function persons(bool $withKey = true): TableDefinition
    {
        return TableDefinition::fromArray([
            'tableId' => 1,
            'name'    => 'Persons',
            'columns' => [
                ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                ['id' => 'person_id', 'name' => 'Code', 'type' => 'varchar', 'length' => 10],
                ['id' => 'company_id', 'name' => 'Company ID', 'type' => 'varchar', 'length' => 30, 'nullable' => true],
                ['id' => 'vat_id', 'name' => 'VAT ID', 'type' => 'varchar', 'length' => 30, 'nullable' => true],
                ['id' => 'full_name', 'name' => 'Name', 'type' => 'varchar', 'length' => 100],
            ],
            'exchangeLookupColumns' => ['company_id', 'vat_id'],
        ] + ($withKey ? ['exchangeKey' => 'person_id'] : []));
    }

    /**
     * @return array<string, array{0: mixed, 1: string, 2: ?string, 3: int|string}>
     */
    public static function validValues(): array
    {
        return [
            'primární klíč'                    => [42, 'id', null, 42],
            'přirozený klíč'                   => ['@K00042', 'key', 'person_id', 'K00042'],
            'klíč se neořezává'                => ['@ K00042 ', 'key', 'person_id', ' K00042 '],
            'velikost písmen zůstává'          => ['@k00042', 'key', 'person_id', 'k00042'],
            'úvodní nula zůstává'              => ['@0123', 'key', 'person_id', '0123'],
            'jmenovaný sloupec'                => ['@company_id:12345678', 'column', 'company_id', '12345678'],
            'klíč jmenovitě'                   => ['@person_id:K00042', 'column', 'person_id', 'K00042'],
            'další dvojtečky patří hodnotě'    => ['@vat_id:CZ:123:4', 'column', 'vat_id', 'CZ:123:4'],
            'nedeklarovaný sloupec je klíč'    => ['@full_name:Novák', 'key', 'person_id', 'full_name:Novák'],
            'text před dvojtečkou není sloupec' => ['@A:1', 'key', 'person_id', 'A:1'],
            'dvojtečka na začátku'             => ['@:x', 'key', 'person_id', ':x'],
            'zavináč v klíči'                  => ['@@x', 'key', 'person_id', '@x'],
        ];
    }

    #[DataProvider('validValues')]
    public function testParsesValue(mixed $value, string $kind, ?string $column, int|string $parsed): void
    {
        $reference = ReferenceValue::parse($value, self::persons());

        $this->assertSame($kind, $reference->kind);
        $this->assertSame($column, $reference->column);
        $this->assertSame($parsed, $reference->value);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'nula'                       => [0],
            'záporné číslo'              => [-3],
            'desetinné číslo'            => [1.5],
            'celé číslo jako float'      => [42.0],
            'true'                       => [true],
            'false'                      => [false],
            'null'                       => [null],
            'pole'                       => [['id' => 1]],
            'číselný řetězec'            => ['42'],
            'řetězec bez zavináče'       => ['K00042'],
            'prázdný řetězec'            => [''],
            'jen zavináč'                => ['@'],
            'sloupec bez hodnoty'        => ['@company_id:'],
            'klíč jmenovitě bez hodnoty' => ['@person_id:'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsValue(mixed $value): void
    {
        try {
            ReferenceValue::parse($value, self::persons());
            $this->fail('očekávána chyba reference_invalid');
        } catch (ReferenceException $e) {
            $this->assertSame(ReferenceException::INVALID, $e->errorCode);
            $this->assertSame($value, $e->value);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function testKeyOfTableWithoutExchangeKeyHasNoColumn(): void
    {
        // Parser tabulku neposuzuje — že klíč chybí, ohlásí resolver.
        $reference = ReferenceValue::parse('@K00042', self::persons(withKey: false));

        $this->assertSame(ReferenceValue::KIND_KEY, $reference->kind);
        $this->assertNull($reference->column);

        $byColumn = ReferenceValue::parse('@company_id:12345678', self::persons(withKey: false));
        $this->assertSame(ReferenceValue::KIND_COLUMN, $byColumn->kind, 'sloupec pro hledání klíč nepotřebuje');

        $this->assertSame(
            'person_id:K1',
            ReferenceValue::parse('@person_id:K1', self::persons(withKey: false))->value,
            'bez exchangeKey není person_id deklarovaný sloupec',
        );
    }
}
