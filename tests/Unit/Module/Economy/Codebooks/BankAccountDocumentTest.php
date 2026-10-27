<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\BankAccountDocument;

class BankAccountDocumentTest extends TestCase
{
    private function doc(): BankAccountDocument
    {
        return new BankAccountDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'code'           => 'CSOB1',
            'name'           => 'Hlavní účet',
            'currency'       => 'czk',
            'account_number' => '19-2000145399/0800',
            'is_default'     => 0,
        ];
    }

    // --- validate -----------------------------------------------------------

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidateOnlyIbanIsValid(): void
    {
        $data = $this->validData();
        unset($data['account_number']);
        $data['iban'] = 'CZ6508000000192000145399';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidateOnlyAccountNumberIsValid(): void
    {
        $data = $this->validData();
        unset($data['iban']);
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingCodeFails(): void
    {
        $data = $this->validData();
        unset($data['code']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'code' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($errors);
    }

    public function testMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'name' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($errors);
    }

    public function testMissingCurrencyFails(): void
    {
        $data = $this->validData();
        unset($data['currency']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'currency' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($errors);
    }

    public function testUppercaseCurrencyFails(): void
    {
        $data = $this->validData();
        $data['currency'] = 'CZK';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'currency' && $e['code'] === 'invalid_format',
        );
        $this->assertNotEmpty($errors);
    }

    public function testBothAccountNumberAndIbanEmptyFails(): void
    {
        $data = $this->validData();
        unset($data['account_number']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'account_number' && $e['code'] === 'required_one_of',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidIbanFormatFails(): void
    {
        $data = $this->validData();
        unset($data['account_number']);
        $data['iban'] = 'CZ12';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'iban' && $e['code'] === 'iban_invalid',
        );
        $this->assertNotEmpty($errors);
    }

    public function testNumericIbanFails(): void
    {
        $data = $this->validData();
        $data['iban'] = '1234567890';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'iban' && $e['code'] === 'iban_invalid',
        );
        $this->assertNotEmpty($errors);
    }

    public function testIbanChecksumAndMismatchFail(): void
    {
        $data = $this->validData();
        $data['iban'] = 'CZ8520100000002308417558';
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame(['iban:iban_invalid'], $this->codes($result->toArray()));
        $this->assertSame('IBAN „CZ8520100000002308417558“ nemá platnou kontrolní číslici.', $result->toArray()[0]['message']);

        $data['iban'] = 'CZ8520100000002308417557';
        $result = $this->doc()->validate($data);
        $this->assertSame(['iban:iban_account_mismatch'], $this->codes($result->toArray()), 'platný IBAN jiného účtu než číslo účtu');

        $data['account_number'] = '2308417558/2010';
        $data['iban'] = null;
        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid(), 'číslo bez mod 11 je podezření (D30) — vlastní účet se uloží');
        $this->assertSame(['account_number:account_number_checksum'], $this->codes($result->warningsToArray()));

        $data['iban'] = 'CZ8520100000002308417557';
        $result = $this->doc()->validate($data);
        $this->assertFalse($result->isValid(), 'platný IBAN jiného účtu: nesoulad je chyba i při podezření');
        $this->assertSame(['iban:iban_account_mismatch'], $this->codes($result->toArray()));
        $this->assertSame(['account_number:account_number_checksum'], $this->codes($result->warningsToArray()));

        $data['account_number'] = '0/0100';
        $data['iban'] = null;
        $this->assertSame(['account_number:account_number_invalid'], $this->codes($this->doc()->validate($data)->toArray()), 'samé nuly jsou dál chyba');
    }

    public function testIbanTypedIntoAccountNumberIsValidAndMoved(): void
    {
        $data = $this->validData();
        $data['account_number'] = 'CZ65 0800 0000 1920 0014 5399';
        $data['iban'] = '';
        $this->assertTrue($this->doc()->validate($data)->isValid(), 'kontrola nad tvarem po přesunu (D20) — formulář posílá oba sloupce');
    }

    /** @return list<string> */
    private function codes(array $entries): array
    {
        return array_map(static fn (array $e): string => $e['column'] . ':' . $e['code'], $entries);
    }

    public function testIbanLowercaseAcceptedAfterUppercase(): void
    {
        // validate normalizes to uppercase pre-comparison, so lowercase IBAN must pass
        $data = $this->validData();
        unset($data['account_number']);
        $data['iban'] = 'cz6508000000192000145399';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testInvalidBicFormatFails(): void
    {
        $data = $this->validData();
        $data['bic'] = 'abc';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'bic' && $e['code'] === 'bic_invalid',
        );
        $this->assertNotEmpty($errors);
    }

    public function testNumericBicFails(): void
    {
        $data = $this->validData();
        $data['bic'] = '123456789';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'bic' && $e['code'] === 'bic_invalid',
        );
        $this->assertNotEmpty($errors);
    }

    public function testValidBicEightChars(): void
    {
        $data = $this->validData();
        $data['bic'] = 'CEKOCZPP';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidBicElevenChars(): void
    {
        $data = $this->validData();
        $data['bic'] = 'CEKOCZPPXXX';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testInvalidValidityRangeFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-31';
        $data['valid_to']   = '2026-01-01';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'valid_to' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($errors);
    }

    // --- beforeSave ---------------------------------------------------------

    public function testBeforeSaveNormalizesCurrency(): void
    {
        $doc = $this->doc();
        $data = ['currency' => '  EUR '];
        $doc->beforeSave($data);

        $this->assertSame('eur', $data['currency']);
    }

    public function testBeforeSaveUppercasesIban(): void
    {
        $doc = $this->doc();
        $data = ['iban' => '  cz6508000000192000145399 '];
        $doc->beforeSave($data);

        $this->assertSame('CZ6508000000192000145399', $data['iban']);
    }

    public function testBeforeSaveShapesAccountNumberAndMovesIbanTypedIntoIt(): void
    {
        $doc = $this->doc();
        $data = $this->validData() + ['iban' => 'cz65 0800 0000 1920 0014 5399', 'bic' => ' gibaczpx'];
        $data['account_number'] = ' 000019 - 2000145399 / 0800';
        $doc->beforeSave($data);
        $this->assertSame('19-2000145399/0800', $data['account_number']);
        $this->assertSame('CZ6508000000192000145399', $data['iban']);
        $this->assertSame('GIBACZPX', $data['bic']);

        $moved = $this->validData();
        $moved['account_number'] = 'CZ65 0800 0000 1920 0014 5399';
        $moved['iban'] = null;
        $doc->beforeSave($moved);
        $this->assertSame('19-2000145399/0800', $moved['account_number'], 'IBAN v čísle účtu → tuzemské číslo (#118 D20)');
        $this->assertSame('CZ6508000000192000145399', $moved['iban']);
    }

    public function testBeforeSaveUppercasesBic(): void
    {
        $doc = $this->doc();
        $data = ['bic' => ' cekoczpp '];
        $doc->beforeSave($data);

        $this->assertSame('CEKOCZPP', $data['bic']);
    }

    public function testBeforeSaveTrimsTextFields(): void
    {
        $doc = $this->doc();
        $data = [
            'code'           => '  CSOB1  ',
            'name'           => '  Hlavní účet  ',
            'notice'         => '  poznámka  ',
            'bank_name'      => '  ČSOB  ',
            'account_number' => '  19-2000145399/0800  ',
        ];
        $doc->beforeSave($data);

        $this->assertSame('CSOB1', $data['code']);
        $this->assertSame('Hlavní účet', $data['name']);
        $this->assertSame('poznámka', $data['notice']);
        $this->assertSame('ČSOB', $data['bank_name']);
        $this->assertSame('19-2000145399/0800', $data['account_number']);
    }

    // --- afterPersist -------------------------------------------------------
    //
    // afterPersist deleguje DB volání do protected clearOtherDefaults();
    // testujeme přes subclass spy, protože Dibi\Connection::query() je final.

    public function testAfterPersistSkipsUpdateWhenNotDefault(): void
    {
        $doc = new TestableBankAccountDocument();
        $doc->afterPersist([
            'id'         => 1,
            'currency'   => 'czk',
            'is_default' => 0,
        ]);

        $this->assertSame(0, $doc->clearCalls);
    }

    public function testAfterPersistRunsUpdateWhenDefault(): void
    {
        $doc = new TestableBankAccountDocument();
        $doc->afterPersist([
            'id'         => 42,
            'currency'   => 'eur',
            'is_default' => 1,
        ]);

        $this->assertSame(1, $doc->clearCalls);
        $this->assertSame('eur', $doc->lastCurrency);
        $this->assertSame(42, $doc->lastId);
    }

    public function testAfterPersistSkipsWhenIdMissing(): void
    {
        $doc = new TestableBankAccountDocument();
        $doc->afterPersist([
            'currency'   => 'czk',
            'is_default' => 1,
        ]);

        $this->assertSame(0, $doc->clearCalls);
    }
}

/**
 * Testovací subclass — overriduje protected clearOtherDefaults, aby
 * šlo testovat bez reálného Dibi (final query() nelze mockovat).
 */
class TestableBankAccountDocument extends BankAccountDocument
{
    public int $clearCalls = 0;
    public ?string $lastCurrency = null;
    public ?int $lastId = null;

    protected function clearOtherDefaults(string $currency, int $id): void
    {
        $this->clearCalls++;
        $this->lastCurrency = $currency;
        $this->lastId = $id;
    }
}
