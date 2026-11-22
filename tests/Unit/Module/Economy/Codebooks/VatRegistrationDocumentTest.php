<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Codebooks;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Codebooks\VatRegistrationCodeGenerator;
use Shipard\Module\Economy\Codebooks\VatRegistrationDocument;

/** DB seamy nahrazené in-memory daty: uložený řádek a živé osoby. */
final class TestableVatRegistrationDocument extends VatRegistrationDocument
{
    /** @var ?array<string, mixed> */
    public ?array $current = null;

    /** @var list<int> */
    public array $livePersons = [];

    public ?VatRegistrationCodeGenerator $generator = null;

    protected function codeGenerator(): ?VatRegistrationCodeGenerator
    {
        return $this->generator;
    }

    protected function loadCurrent(int $id): ?array
    {
        return $this->current !== null && (int) ($this->current['id'] ?? 0) === $id ? $this->current : null;
    }

    protected function personIsLive(int $personId): bool
    {
        return in_array($personId, $this->livePersons, true);
    }
}

class VatRegistrationDocumentTest extends TestCase
{
    use VatRegistrationDbTrait;

    private function doc(): VatRegistrationDocument
    {
        return new VatRegistrationDocument();
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'name'               => 'ČR DPH',
            'region'             => 'eu',
            'country'            => 'cz',
            'taxpayer_kind'      => 0,
            'tax_period_kind'    => 1,
            'cs_period_kind'     => 1,
            'rs_period_kind'     => 1,
            'valid_from'         => '2026-01-01',
            'valid_to'           => null,
            'vat_id'             => 'CZ12345678',
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidateOpenEndedValidityIsValid(): void
    {
        $data = $this->validData();
        $data['valid_to'] = null;
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('name', array_column($result->toArray(), 'column'));
    }

    public function testMissingRegionFails(): void
    {
        $data = $this->validData();
        unset($data['region']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('region', array_column($result->toArray(), 'column'));
    }

    public function testMissingCountryFails(): void
    {
        $data = $this->validData();
        unset($data['country']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('country', array_column($result->toArray(), 'column'));
    }

    public function testMissingTaxpayerKindFails(): void
    {
        $data = $this->validData();
        unset($data['taxpayer_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('taxpayer_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingTaxPeriodKindFails(): void
    {
        $data = $this->validData();
        unset($data['tax_period_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('tax_period_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingCsPeriodKindFails(): void
    {
        $data = $this->validData();
        unset($data['cs_period_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('cs_period_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingRsPeriodKindFails(): void
    {
        $data = $this->validData();
        unset($data['rs_period_kind']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('rs_period_kind', array_column($result->toArray(), 'column'));
    }

    public function testMissingValidFromFails(): void
    {
        $data = $this->validData();
        unset($data['valid_from']);
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertContains('valid_from', array_column($result->toArray(), 'column'));
    }

    public function testInvalidTaxpayerKindFails(): void
    {
        $data = $this->validData();
        $data['taxpayer_kind'] = 5;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'taxpayer_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidTaxPeriodKindFails(): void
    {
        $data = $this->validData();
        $data['tax_period_kind'] = 0;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'tax_period_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidCsPeriodKindFails(): void
    {
        $data = $this->validData();
        $data['cs_period_kind'] = 5;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'cs_period_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testInvalidRsPeriodKindFails(): void
    {
        $data = $this->validData();
        $data['rs_period_kind'] = 5;
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'rs_period_kind' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($errors);
    }

    public function testValidToBeforeValidFromFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-31';
        $data['valid_to']   = '2026-01-01';
        $result = $this->doc()->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            fn(array $e) => $e['column'] === 'valid_to' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($errors);
    }

    public function testValidToEqualValidFromIsValid(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-06-15';
        $data['valid_to']   = '2026-06-15';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testEmptyVatIdIsValid(): void
    {
        $data = $this->validData();
        $data['vat_id'] = null;
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // ── částečné uložení + správce daně (#55 D30) ────────────────────────

    private function testable(): TestableVatRegistrationDocument
    {
        $doc = new TestableVatRegistrationDocument();
        $doc->current = $this->validData() + ['id' => 3, 'docState' => 40];
        return $doc;
    }

    public function testPartialUpdateMergesStoredRow(): void
    {
        $doc = $this->testable();
        $doc->livePersons = [9];
        $data = ['id' => 3, 'tax_office_person' => 9];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testTaxOfficePersonMustBeLivePerson(): void
    {
        $doc = $this->testable();
        $data = ['id' => 3, 'tax_office_person' => 9];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('tax_office_person', $result->toArray()[0]['column']);
        $this->assertSame('invalid_value', $result->toArray()[0]['code']);
    }

    public function testTaxOfficePersonMayBeCleared(): void
    {
        $doc = $this->testable();
        $data = ['id' => 3, 'tax_office_person' => null];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testPartialUpdateStillValidatesRange(): void
    {
        $doc = $this->testable();
        $data = ['id' => 3, 'valid_to' => '2025-01-01'];
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('valid_to', $result->toArray()[0]['column']);
    }

    public function testStoredDateTimeValuesCompareAsDates(): void
    {
        $doc = $this->testable();
        $doc->current['valid_from'] = new \DateTimeImmutable('2026-01-01');
        $data = ['id' => 3, 'valid_to' => '2026-06-30'];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testWithoutDbSeamPersonIsNotChecked(): void
    {
        $data = $this->validData() + ['tax_office_person' => 9];
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // ── kód registrace (#166 D16, D17) ──────────────────────────────────────

    /**
     * Document nad registracemi v paměti: uložená registrace #3 s kódem `cz`.
     *
     * @param list<array<string, mixed>> $others další řádky tabulky
     */
    private function testableWithCodes(array $others = []): TestableVatRegistrationDocument
    {
        $doc = $this->testable();
        $doc->current['code'] = 'cz';
        $doc->generator = new VatRegistrationCodeGenerator($this->registrationsDb([$doc->current, ...$others]));
        return $doc;
    }

    /** @return list<string> kódy chyb sloupce `code` */
    private function codeErrors(VatRegistrationDocument $doc, array $data): array
    {
        return array_column(array_filter(
            $doc->validate($data)->toArray(),
            static fn(array $e): bool => $e['column'] === 'code',
        ), 'code');
    }

    public function testNewRegistrationWithoutCodeIsValidAndGetsOneOnSave(): void
    {
        $doc = $this->testableWithCodes();
        $data = $this->validData() + ['code' => ''];

        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertNull($data['code'], 'prázdný řetězec se do sloupce s unikátním indexem neuloží');

        $doc->beforeSave($data, null);
        $this->assertSame('cz-2', $data['code'], 'druhá registrace země');

        $first = new TestableVatRegistrationDocument();
        $first->generator = new VatRegistrationCodeGenerator($this->registrationsDb());
        $data = $this->validData();
        $first->beforeSave($data, null);
        $this->assertSame('cz', $data['code']);
    }

    public function testFilledCodeIsKeptOnSave(): void
    {
        $doc = $this->testableWithCodes();
        $data = $this->validData() + ['code' => 'hlavni'];
        $doc->beforeSave($data, null);
        $this->assertSame('hlavni', $data['code']);

        // Částečné uložení bez kódu uložený kód nepřepisuje.
        $data = ['id' => 3, 'tax_office_person' => null];
        $doc->beforeSave($data, $doc->current);
        $this->assertArrayNotHasKey('code', $data);
    }

    public function testStoredRegistrationWithoutCodeGetsOneOnPartialSave(): void
    {
        // Založená mimo Document (import) a ještě bez doplnění při ds-upgrade.
        $doc = $this->testable();
        $doc->current['code'] = null;
        $doc->generator = new VatRegistrationCodeGenerator($this->registrationsDb([$doc->current]));
        $data = ['id' => 3, 'tax_office_person' => null];

        $this->assertTrue($doc->validate($data)->isValid());
        $doc->beforeSave($data, $doc->current);
        $this->assertSame('cz', $data['code']);
    }

    public function testCodeShapeIsValidated(): void
    {
        $doc = $this->testableWithCodes();

        foreach (['cz:2', '@cz', 'c z', '-cz', 'čz', str_repeat('a', 21)] as $code) {
            $this->assertSame(['invalid_value'], $this->codeErrors($doc, $this->validData() + ['code' => $code]), $code);
        }
        $data = $this->validData() + ['code' => ' cz-oss '];
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame('cz-oss', $data['code'], 'okraje se oříznou');
    }

    public function testCodeMustBeUniqueIncludingTrashAndCase(): void
    {
        $doc = $this->testableWithCodes([
            ['id' => 8, 'country' => 'sk', 'code' => 'sk', 'name' => 'Slovensko', 'docState' => 90],
        ]);

        $new = $this->validData() + ['code' => 'CZ'];
        $result = $doc->validate($new);
        $error = array_values(array_filter($result->toArray(), static fn(array $e): bool => $e['column'] === 'code'))[0];
        $this->assertSame('duplicate', $error['code']);
        $this->assertStringContainsString('ČR DPH', $error['message'], 'zpráva jmenuje, kdo kód má');

        $new = $this->validData() + ['code' => 'sk'];
        $result = $doc->validate($new);
        $error = array_values(array_filter($result->toArray(), static fn(array $e): bool => $e['column'] === 'code'))[0];
        $this->assertStringContainsString('Slovensko', $error['message']);
        $this->assertStringContainsString('v koši', $error['message']);

        // Vlastní kód duplicitou není; přepsat ho jde.
        $this->assertSame([], $this->codeErrors($doc, ['id' => 3, 'code' => 'cz']));
        $this->assertSame([], $this->codeErrors($doc, ['id' => 3, 'code' => 'cz-hlavni']));
    }

    public function testStoredCodeCannotBeCleared(): void
    {
        $doc = $this->testableWithCodes();

        $this->assertSame(['required'], $this->codeErrors($doc, ['id' => 3, 'code' => '']));
        $this->assertSame(['required'], $this->codeErrors($doc, ['id' => 3, 'code' => null]));
        $this->assertSame([], $this->codeErrors($doc, ['id' => 3, 'tax_office_person' => null]), 'payload bez kódu ho nemaže');
    }

    public function testWithoutDbSeamCodeIsNotGenerated(): void
    {
        $data = $this->validData();
        $this->doc()->beforeSave($data, null);
        $this->assertArrayNotHasKey('code', $data);
    }
}
