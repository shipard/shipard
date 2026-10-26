<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Validations;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\World\Validations\DomesticAccount;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\ValueRules;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Sdílený fixture `tests/Fixtures/World/value-cases.jsonc` (#118 D6):
 * tytéž případy čte Python generátoru korpusu. Sekce `repair` tu ověřuje
 * primitiva převodu účet ↔ IBAN a rozhodnutí „který z dvojice je platný“;
 * oprava v canonicalu je věc normalizátoru v `core.exchange`. Sekce
 * `format` (čitelný tvar, D21) je jen pro PHP.
 */
class ValueCasesFixtureTest extends TestCase
{
    public const FIXTURE = __DIR__ . '/../../../../Fixtures/World/value-cases.jsonc';

    /** @var array<string, list<array<string, mixed>>>|null */
    private static ?array $cases = null;

    /** @return array<string, list<array<string, mixed>>> */
    public static function cases(): array
    {
        self::$cases ??= JsoncParser::parseFile(self::FIXTURE);
        return self::$cases;
    }

    public function testFixtureHasAllSectionsAndKnownKinds(): void
    {
        $cases = self::cases();
        $this->assertSame(['normalize', 'validate', 'repair', 'format'], array_keys($cases));
        foreach (['normalize', 'validate', 'format'] as $section) {
            $this->assertNotEmpty($cases[$section]);
            foreach ($cases[$section] as $case) {
                $this->assertContains($case['kind'], ValueRules::kinds(), json_encode($case));
            }
        }
        $this->assertNotEmpty($cases['repair']);
    }

    public function testNormalizeCases(): void
    {
        foreach (self::cases()['normalize'] as $case) {
            $country = $case['country'] ?? null;
            $out = ValueRules::normalize($case['kind'], $case['in'], $country);
            $this->assertSame($case['out'], $out, json_encode($case, JSON_UNESCAPED_UNICODE));
            $this->assertSame($out, ValueRules::normalize($case['kind'], $out, $country), 'idempotence: ' . json_encode($case));
        }
    }

    public function testValidateCases(): void
    {
        foreach (self::cases()['validate'] as $case) {
            $this->assertSame(
                $case['valid'],
                ValueRules::isValid($case['kind'], $case['value'], $case['country'] ?? null),
                json_encode($case, JSON_UNESCAPED_UNICODE),
            );
        }
    }

    public function testFormatCases(): void
    {
        foreach (self::cases()['format'] as $case) {
            $country = $case['country'] ?? null;
            $label = json_encode($case, JSON_UNESCAPED_UNICODE);
            $out = ValueRules::format($case['kind'], $case['in'], $country);
            $this->assertSame($case['out'], $out, $label);
            $this->assertSame($out, ValueRules::format($case['kind'], $out, $country), "idempotence: {$label}");
            $this->assertSame(
                ValueRules::normalize($case['kind'], $case['in'], $country),
                ValueRules::normalize($case['kind'], $out, $country),
                "čitelný tvar se normalizuje zpět na tutéž hodnotu: {$label}",
            );
        }
    }

    public function testIbanFromAccountNumber(): void
    {
        $this->assertSame('CZ6508000000192000145399', ValueRules::ibanFromAccountNumber(' 19 - 2000145399 / 0800 ', 'cz'));
        $this->assertSame('SK2411000000002600000003', ValueRules::ibanFromAccountNumber('2600000003/1100', 'sk'));
        $this->assertNull(ValueRules::ibanFromAccountNumber('2000145399', 'cz'), 'bez kódu banky');
        $this->assertNull(ValueRules::ibanFromAccountNumber('CZ6508000000192000145399', 'cz'), 'IBAN místo čísla');
        $this->assertNull(ValueRules::ibanFromAccountNumber('1234567-2000145399/0800', 'cz'), 'dlouhé předčíslí');
        $this->assertNull(ValueRules::ibanFromAccountNumber('19-2000145399/0800', 'de'), 'stát bez tuzemského tvaru');
    }

    public function testRepairCases(): void
    {
        foreach (self::cases()['repair'] as $case) {
            $label = json_encode($case, JSON_UNESCAPED_UNICODE);
            $rules = ValueRulesRegistry::forCountry($case['country']);
            $ibanValid = Iban::isValid($case['iban']);
            $account = DomesticAccount::parse($case['accountNumber']);
            $accountValid = $account?->isValid() ?? false;

            if ($case['out'] === null) {
                $this->assertSame($ibanValid, $accountValid, "bez opravy jen když jsou oba platné nebo oba neplatné: {$label}");
                continue;
            }
            if ($case['out']['basis'] === 'accountNumber') {
                $this->assertFalse($ibanValid, $label);
                $this->assertTrue($accountValid, $label);
                $this->assertNotNull($account);
                $derived = $rules->ibanFromDomesticAccount($account);
                $this->assertSame($case['out']['iban'], $derived, $label);
                $this->assertTrue(Iban::isValid((string) $derived), "dopočtený IBAN musí být platný: {$label}");
                continue;
            }
            $this->assertSame('iban', $case['out']['basis'], $label);
            $this->assertTrue($ibanValid, $label);
            $this->assertFalse($accountValid, $label);
            $derived = $rules->domesticAccountFromIban($case['iban']);
            $this->assertNotNull($derived, $label);
            $this->assertSame($case['out']['accountNumber'], (string) $derived, $label);
            $this->assertTrue($derived->isValid(), "dopočtené číslo účtu musí být platné: {$label}");
        }
    }
}
