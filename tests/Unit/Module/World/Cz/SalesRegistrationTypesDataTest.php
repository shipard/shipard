<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Cz;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationType;

/**
 * Data typů evidence tržeb (`world.cz.salesRegistrationTypes`, #171 D2, D17).
 * Na id typu stojí sloupec `type` registrace a mapa enginů doplňku; masky
 * jsou opsané z XSD rozhraní EET v4 a musí sedět s tím, co kontroluje
 * knihovna před odesláním. Modul země nejmenuje žádnou třídu doplňku.
 */
class SalesRegistrationTypesDataTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/world/cz';
    private const CURRENCIES = __DIR__ . '/../../../../../modules/world/base/config/currencies.jsonc';

    /** @return array<string, array<string, mixed>> */
    private function types(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/config/salesRegistrationTypes.jsonc');
    }

    public function testIsRegisteredInModule(): void
    {
        $module = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $files  = array_column($module['config'] ?? [], 'file', 'id');

        $this->assertSame('config/salesRegistrationTypes.jsonc', $files['world.cz.salesRegistrationTypes'] ?? null);
    }

    public function testEveryTypeHasNamesCountryCurrencyAndEnvironments(): void
    {
        $currencies = JsoncParser::parseFile(self::CURRENCIES);

        foreach ($this->types() as $id => $type) {
            $this->assertMatchesRegularExpression('/^cz(\.[a-z0-9]+)+$/', (string) $id, 'id typu je tečková notace začínající zemí');
            foreach (['name', 'name:cs', 'name:en'] as $key) {
                $this->assertIsString($type[$key] ?? null, "{$id}: chybí {$key}");
                $this->assertNotSame('', trim($type[$key]), "{$id}: prázdné {$key}");
            }
            $this->assertSame('cz', $type['country'] ?? null);
            $this->assertArrayHasKey($type['currency'] ?? '', $currencies, "{$id}: currency musí být ve world.base.currencies");
            $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) ($type['validFrom'] ?? ''));
            $this->assertNotEmpty($type['environments'] ?? []);
            foreach ($type['environments'] as $name => $env) {
                $this->assertStringStartsWith('https://', (string) ($env['url'] ?? ''), "{$id}: prostředí {$name} jen přes HTTPS");
            }
            foreach (['taxpayerId', 'unitId', 'cashRegisterId', 'serialNumber'] as $field) {
                $this->assertTrue(
                    SalesRegistrationType::isUsablePattern((string) ($type[$field]['pattern'] ?? '')),
                    "{$id}: maska {$field} není platný regulární výraz",
                );
            }
            foreach (['label', 'label:cs', 'label:en'] as $key) {
                $this->assertNotSame('', trim((string) ($type['taxpayerId'][$key] ?? '')), "{$id}: taxpayerId.{$key}");
            }
            $this->assertArrayNotHasKey('engine', $type, 'třídu engine přiřazuje doplněk, ne modul země (#171 D17)');
        }
    }

    public function testEet2ValidFromAndEnvironments(): void
    {
        $eet = $this->types()['cz.eet2'] ?? null;

        $this->assertIsArray($eet);
        $this->assertSame('2027-01-01', $eet['validFrom']);
        $this->assertSame('czk', $eet['currency']);
        $this->assertSame('EIČ', $eet['taxpayerId']['label:cs']);
        $this->assertSame(
            [
                'production' => ['url' => 'https://trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'],
                'playground' => ['url' => 'https://pg.trzbyeet.gov.cz/eet/services/EETServiceSOAP/v4'],
            ],
            $eet['environments'],
        );
    }

    /**
     * Pravidla, kdy doklad do evidence spadá (#171 D8a, D23), míří jen na
     * existující typy dokladů, způsoby úhrady, směry a pohyby řádků —
     * překlep v datech by znamenal neodeslané tržby.
     */
    public function testEet2RulesReferToExistingDocTypesMethodsAndOperations(): void
    {
        $docsCore = self::MODULE . '/../../docs/core/config/';
        $docTypes = JsoncParser::parseFile($docsCore . 'docTypes.jsonc');
        $paymentMethods = JsoncParser::parseFile($docsCore . 'paymentMethods.jsonc');
        $cashDirections = JsoncParser::parseFile($docsCore . 'cashDirections.jsonc');
        $operations = array_keys(JsoncParser::parseFile($docsCore . 'rowOperations.jsonc'));

        $rules = $this->types()['cz.eet2']['rules'] ?? null;
        $this->assertIsArray($rules);
        $this->assertNotEmpty($rules);

        foreach ($rules as $i => $rule) {
            $this->assertSame([], array_diff(array_keys($rule), ['docTypes', 'paymentMethods', 'cashDir', 'rowOperations']), "pravidlo {$i}: neznámý klíč");
            foreach ($rule['docTypes'] as $docType) {
                $this->assertArrayHasKey($docType, $docTypes, "pravidlo {$i}: typ dokladu {$docType}");
            }
            foreach ($rule['paymentMethods'] ?? [] as $method) {
                $this->assertArrayHasKey((string) $method, $paymentMethods, "pravidlo {$i}: způsob úhrady {$method}");
            }
            if (array_key_exists('cashDir', $rule)) {
                $this->assertArrayHasKey((string) $rule['cashDir'], $cashDirections, "pravidlo {$i}: směr");
            }
            foreach ($rule['rowOperations'] ?? [] as $pattern) {
                $matching = array_filter(
                    $operations,
                    static fn(string $operation): bool => str_ends_with($pattern, '.*')
                        ? str_starts_with($operation, substr($pattern, 0, -1))
                        : $operation === $pattern,
                );
                $this->assertNotEmpty($matching, "pravidlo {$i}: pohybu {$pattern} neodpovídá žádná operace");
            }
        }

        // D15: Platební bránou (5) do evidence nespadá; převod a účetní zápis taky ne.
        $this->assertSame([0, 2], $rules[0]['paymentMethods']);
        $this->assertNotContains('transfer.in', $rules[1]['rowOperations']);
        $this->assertNotContains('acc.entry', $rules[1]['rowOperations']);
        $this->assertSame('Europe/Prague', $this->types()['cz.eet2']['timeZone']);
        $this->assertTrue(SalesRegistrationType::isUsablePattern($this->types()['cz.eet2']['amount']['pattern']));
    }

    /** Masky z XSD rozhraní v4 — hodnoty na hraně. */
    public function testEet2MasksFollowTheSchema(): void
    {
        $eet = $this->types()['cz.eet2'];
        $ok = static fn(string $field, string $value): bool => SalesRegistrationType::matches($eet[$field]['pattern'], $value);

        foreach (['CZ00000019', 'CZ683555118', 'CZ8551015704'] as $eic) {
            $this->assertTrue($ok('taxpayerId', $eic), $eic);
        }
        foreach (['CZ1234567', 'CZ12345678901', 'cz00000019', '00000019', "CZ00000019\n", ' CZ00000019'] as $bad) {
            $this->assertFalse($ok('taxpayerId', $bad), var_export($bad, true));
        }

        $this->assertTrue($ok('unitId', '1'));
        $this->assertTrue($ok('unitId', '999999999'));
        foreach (['0', '011', '1234567890', '12a', ''] as $bad) {
            $this->assertFalse($ok('unitId', $bad), var_export($bad, true));
        }

        $this->assertTrue($ok('cashRegisterId', 'SHIPARD-TEST'));
        $this->assertTrue($ok('cashRegisterId', 'pokl/1 #2.,:;_'));
        $this->assertTrue($ok('cashRegisterId', str_repeat('a', 20)));
        $this->assertFalse($ok('cashRegisterId', str_repeat('a', 21)));
        $this->assertFalse($ok('cashRegisterId', 'pokladna č.1'), 'diakritika v označení pokladny neprojde');
        $this->assertFalse($ok('cashRegisterId', ''));

        $this->assertTrue($ok('serialNumber', str_repeat('9', 25)));
        $this->assertFalse($ok('serialNumber', str_repeat('9', 26)));
        $this->assertTrue($ok('serialNumber', 'TEST-20261126143015'));
    }
}
