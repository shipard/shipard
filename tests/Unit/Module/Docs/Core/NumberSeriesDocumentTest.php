<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Module\Docs\Core\NumberSeriesDocument;

class NumberSeriesDocumentTest extends TestCase
{
    use NumberSeriesDbTrait;

    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    private function removeDir(string $path): void
    {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? $this->removeDir($full) : unlink($full);
        }
        rmdir($path);
    }

    private function doc(): NumberSeriesDocument
    {
        return new NumberSeriesDocument();
    }

    /**
     * Document s cfg docTypes: `invno` nevázaný, syntetické `cashb` vázané
     * na pokladnu a `whs` vázané na sklad.
     */
    private function docWithBindings(?Connection $db = null): NumberSeriesDocument
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
        $docTypes = [
            'invno' => ['name' => 'Faktura vydaná', 'trade_dir' => 1],
            'cashb' => ['name' => 'Pokladní doklad', 'trade_dir' => 0, 'series_binding' => 'cash_desk'],
            'whs'   => ['name' => 'Skladový doklad', 'trade_dir' => 0, 'series_binding' => 'warehouse'],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => ['docs.core.docTypes' => $docTypes]]),
        );

        $doc = new NumberSeriesDocument();
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        if ($db !== null) {
            $doc->setDb($db);
        }
        return $doc;
    }

    /** @return list<array{column: string, code: string}> */
    private function errorsFor(NumberSeriesDocument $doc, array $data, string $column): array
    {
        return array_values(array_filter(
            $doc->validate($data)->toArray(),
            fn(array $e) => $e['column'] === $column,
        ));
    }

    /** @return array<string, mixed> */
    private function validData(): array
    {
        return [
            'name'               => 'FVB - tuzemsko',
            'doc_type'           => 'invno',
            'doc_number_code'    => 'A',
            'doc_number_pattern' => '%D%y%C%4',
            'reset_scope'        => 'fiscal_year',
        ];
    }

    public function testValidateValid(): void
    {
        $data = $this->validData();
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateMissingNameFails(): void
    {
        $data = $this->validData();
        unset($data['name']);

        $errors = $this->doc()->validate($data)->toArray();

        $this->assertContains('name', array_column($errors, 'column'));
        $this->assertContains('required', array_column($errors, 'code'));
    }

    public function testValidateMissingDocTypeFails(): void
    {
        $data = $this->validData();
        unset($data['doc_type']);

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_type' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateMissingPatternFails(): void
    {
        $data = $this->validData();
        unset($data['doc_number_pattern']);

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_number_pattern' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidatePatternWithCcRequiresDocNumberCode(): void
    {
        $data = $this->validData();
        $data['doc_number_code'] = '';
        $data['doc_number_pattern'] = '%D%y%C%4';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_number_code'
                && $e['code'] === 'required_for_pattern',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidatePatternWithoutCcAllowsEmptyDocNumberCode(): void
    {
        $data = $this->validData();
        $data['doc_number_code'] = '';
        $data['doc_number_pattern'] = '%D%y%4';

        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateUnknownPlaceholderFails(): void
    {
        $data = $this->validData();
        $data['doc_number_pattern'] = '%D%X%4';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'doc_number_pattern'
                && $e['code'] === 'unknown_placeholder',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateInvalidResetScopeFails(): void
    {
        $data = $this->validData();
        $data['reset_scope'] = 'monthly';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'reset_scope' && $e['code'] === 'invalid_value',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateInvalidValidityRangeFails(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-12-01';
        $data['valid_to']   = '2026-01-01';

        $errors = $this->doc()->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'valid_to' && $e['code'] === 'invalid_range',
        );
        $this->assertNotEmpty($matched);
    }

    public function testValidateValidityRangeNullablePartIsValid(): void
    {
        $data = $this->validData();
        $data['valid_from'] = '2026-01-01';
        $data['valid_to']   = null;

        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
    }

    public function testValidateAllKnownPlaceholdersAreAccepted(): void
    {
        $data = $this->validData();
        $data['doc_number_pattern'] = '%D-%C-%y-%Y-%3-%4-%5-%6';
        $data['doc_number_code'] = 'A';

        $result = $this->doc()->validate($data);
        $this->assertTrue($result->isValid());
    }

    // ── series_binding ──────────────────────────────────────────────────────

    public function testBoundTypeRequiresCashDesk(): void
    {
        $data = $this->validData();
        $data['doc_type'] = 'cashb';

        $errors = $this->errorsFor($this->docWithBindings(), $data, 'cash_desk');

        $this->assertCount(1, $errors);
        $this->assertSame('required', $errors[0]['code']);
    }

    public function testBoundTypeWithCashDeskIsValidWithoutDb(): void
    {
        $data = $this->validData();
        $data['doc_type']  = 'cashb';
        $data['cash_desk'] = 7;

        $this->assertTrue($this->docWithBindings()->validate($data)->isValid());
    }

    public function testBoundCashDeskTypeRejectsWarehouse(): void
    {
        $data = $this->validData();
        $data['doc_type']  = 'cashb';
        $data['cash_desk'] = 7;
        $data['warehouse'] = 3;

        $errors = $this->errorsFor($this->docWithBindings(), $data, 'warehouse');

        $this->assertCount(1, $errors);
        $this->assertSame('binding_not_allowed', $errors[0]['code']);
    }

    public function testWarehouseBindingIsMirrored(): void
    {
        $data = $this->validData();
        $data['doc_type'] = 'whs';

        $doc = $this->docWithBindings();
        $this->assertSame('required', $this->errorsFor($doc, $data, 'warehouse')[0]['code']);

        $data['warehouse'] = 3;
        $data['cash_desk'] = 7;
        $this->assertSame('binding_not_allowed', $this->errorsFor($doc, $data, 'cash_desk')[0]['code']);

        unset($data['cash_desk']);
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testUnboundTypeRejectsAnyBinding(): void
    {
        $data = $this->validData();
        $data['cash_desk'] = 7;

        $errors = $this->errorsFor($this->docWithBindings(), $data, 'cash_desk');

        $this->assertCount(1, $errors);
        $this->assertSame('binding_not_allowed', $errors[0]['code']);
    }

    public function testUnboundTypeWithNullBindingsIsValid(): void
    {
        $data = $this->validData();
        $data['cash_desk'] = null;
        $data['warehouse'] = null;

        $this->assertTrue($this->docWithBindings()->validate($data)->isValid());
    }

    public function testBoundTypeRejectsMissingOrDeletedCashDesk(): void
    {
        $data = $this->validData();
        $data['doc_type']  = 'cashb';
        $data['cash_desk'] = 7;

        $missing = $this->createMock(Connection::class);
        $missing->method('fetch')->willReturn(null);
        $errors = $this->errorsFor($this->docWithBindings($missing), $data, 'cash_desk');
        $this->assertSame('not_found', $errors[0]['code']);

        $deleted = $this->createMock(Connection::class);
        $deleted->method('fetch')->willReturn(new Row(['docState' => 90]));
        $errors = $this->errorsFor($this->docWithBindings($deleted), $data, 'cash_desk');
        $this->assertSame('invalid_state', $errors[0]['code']);

        $active = $this->createMock(Connection::class);
        $active->method('fetch')->willReturn(new Row(['docState' => 40]));
        $this->assertTrue($this->docWithBindings($active)->validate($data)->isValid());
    }

    public function testWithoutConfigBindingIsNotChecked(): void
    {
        $data = $this->validData();
        $data['cash_desk'] = 7;

        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    // ── Odesílatel dokladů řady (#90 D39) ───────────────────────────────

    /** @param list<string> $allowed Adresy, ze kterých zdroj dat smí odesílat. */
    private function docWithAllowedSenders(array $allowed): NumberSeriesDocument
    {
        $senders = $this->createMock(AllowedSenders::class);
        $senders->method('isAllowed')->willReturnCallback(
            static fn (string $email): bool => in_array($email, $allowed, true),
        );

        $doc = new class ($senders) extends NumberSeriesDocument {
            public function __construct(private readonly AllowedSenders $senders) {}

            protected function allowedSenders(): AllowedSenders
            {
                return $this->senders;
            }
        };
        $doc->setDb($this->createMock(Connection::class));
        return $doc;
    }

    public function testAllowedSenderAddressPasses(): void
    {
        $data = $this->validData() + ['email_from' => ' fakturace@firma.example ', 'email_from_name' => " Fakturace\r\n"];
        $result = $this->docWithAllowedSenders(['fakturace@firma.example'])->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertSame('fakturace@firma.example', $data['email_from']);
        $this->assertSame('Fakturace', $data['email_from_name']);
    }

    public function testEmptySenderMeansAutomatic(): void
    {
        $data = $this->validData() + ['email_from' => '', 'email_from_name' => '  '];
        $result = $this->docWithAllowedSenders([])->validate($data);

        $this->assertTrue($result->isValid());
        $this->assertNull($data['email_from']);
        $this->assertNull($data['email_from_name']);
    }

    public function testSenderAddressOutsideAllowedListFails(): void
    {
        $doc = $this->docWithAllowedSenders(['fakturace@firma.example']);

        $errors = $this->errorsFor($doc, $this->validData() + ['email_from' => 'nekdo@jinde.example'], 'email_from');
        $this->assertSame('sender_not_allowed', $errors[0]['code']);

        $errors = $this->errorsFor($doc, $this->validData() + ['email_from' => 'neni-adresa'], 'email_from');
        $this->assertSame('invalid_email', $errors[0]['code']);
    }

    // ── kód řady (#166 D16, D17) ────────────────────────────────────────────

    /** Uložená výchozí řada vydaných faktur. */
    private const STORED = [
        'id' => 5, 'doc_type' => 'invno', 'code' => 'invno', 'name' => 'Faktury vydané',
        'doc_number_code' => null, 'doc_number_pattern' => '%D%y%4', 'reset_scope' => 'fiscal_year',
        'cash_desk' => null, 'warehouse' => null, 'valid_from' => null, 'valid_to' => null,
        'docState' => 40,
    ];

    /**
     * @param list<array<string, mixed>> $series
     * @param array<string, array<int, string>> $entities
     */
    private function docWithSeries(array $series = [self::STORED], array $entities = []): NumberSeriesDocument
    {
        return $this->docWithBindings($this->seriesDb($series, $entities));
    }

    /** @return list<string> kódy chyb sloupce `code` */
    private function codeErrors(NumberSeriesDocument $doc, array $data): array
    {
        return array_column($this->errorsFor($doc, $data, 'code'), 'code');
    }

    public function testNewSeriesWithoutCodeIsValidAndGetsOneOnSave(): void
    {
        $doc = $this->docWithSeries();
        $data = $this->validData() + ['code' => ''];

        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertNull($data['code'], 'prázdný řetězec se do sloupce s unikátním indexem neuloží');

        $doc->beforeSave($data, null);
        $this->assertSame('invno-A', $data['code'], 'další řada typu: kód z čísla dokladu');

        $data = ['doc_number_code' => ''] + $this->validData();
        $data['doc_number_pattern'] = '%D%y%4';
        $doc->beforeSave($data, null);
        $this->assertSame('invno-2', $data['code']);

        $first = $this->docWithSeries([]);
        $data = $this->validData();
        $first->beforeSave($data, null);
        $this->assertSame('invno', $data['code'], 'první řada typu');
    }

    public function testBoundSeriesGetsCodeFromItsEntity(): void
    {
        $doc = $this->docWithSeries([], ['economy_codebooks_cash_desks' => [4 => 'HL']]);
        $data = ['doc_type' => 'cashb', 'cash_desk' => 4, 'doc_number_code' => 'HL'] + $this->validData();

        $this->assertTrue($doc->validate($data)->isValid());
        $doc->beforeSave($data, null);
        $this->assertSame('cashb-HL', $data['code']);
    }

    public function testFilledCodeIsKeptOnSave(): void
    {
        $doc = $this->docWithSeries();
        $data = $this->validData() + ['code' => 'faktury'];
        $doc->beforeSave($data, null);
        $this->assertSame('faktury', $data['code']);

        // Částečné uložení bez kódu uložený kód nepřepisuje.
        $data = ['id' => 5, 'name' => 'Jiný název'];
        $doc->beforeSave($data, self::STORED);
        $this->assertArrayNotHasKey('code', $data);
    }

    public function testStoredSeriesWithoutCodeGetsOneOnSave(): void
    {
        // Založená mimo Document (provisioner, import) a ještě bez doplnění.
        $stored = ['code' => null] + self::STORED;
        $doc = $this->docWithSeries([$stored]);
        $data = ['id' => 5, 'name' => 'Faktury'];

        $this->assertTrue($doc->validate($data)->isValid());
        $doc->beforeSave($data, $stored);
        $this->assertSame('invno', $data['code']);
    }

    public function testPartialSaveWithCodeOnlyIsValid(): void
    {
        // Řada ve stavu V pořádku: formulář posílá jen kód.
        $doc = $this->docWithSeries();
        $data = ['id' => 5, 'code' => 'fv'];

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testPartialSaveStillValidatesTheMergedRow(): void
    {
        $doc = $this->docWithSeries([['valid_from' => new \DateTimeImmutable('2026-06-01')] + self::STORED]);

        $errors = $this->errorsFor($doc, ['id' => 5, 'valid_to' => '2026-01-31'], 'valid_to');
        $this->assertSame('invalid_range', $errors[0]['code']);
        $this->assertSame([], $this->errorsFor($doc, ['id' => 5, 'valid_to' => '2026-06-01'], 'valid_to'));

        $errors = $this->errorsFor($doc, ['id' => 5, 'name' => ''], 'name');
        $this->assertSame('required', $errors[0]['code']);
    }

    public function testCodeShapeIsValidated(): void
    {
        $doc = $this->docWithSeries();

        foreach (['invno:2', '@invno', 'inv no', '-invno', 'řada', str_repeat('a', 41)] as $code) {
            $this->assertSame(['invalid_value'], $this->codeErrors($doc, $this->validData() + ['code' => $code]), $code);
        }
        $data = $this->validData() + ['code' => ' invno-export '];
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame('invno-export', $data['code'], 'okraje se oříznou');
    }

    public function testCodeMustBeUniqueIncludingTrashAndCase(): void
    {
        $doc = $this->docWithSeries([
            self::STORED,
            ['id' => 9, 'code' => 'stara', 'name' => 'Stará řada', 'docState' => 90] + self::STORED,
        ]);

        $errors = $this->errorsFor($doc, $this->validData() + ['code' => 'INVNO'], 'code');
        $this->assertSame('duplicate', $errors[0]['code']);
        $this->assertStringContainsString('Faktury vydané', $errors[0]['message'], 'zpráva jmenuje, kdo kód má');

        $errors = $this->errorsFor($doc, $this->validData() + ['code' => 'stara'], 'code');
        $this->assertStringContainsString('Stará řada', $errors[0]['message']);
        $this->assertStringContainsString('v koši', $errors[0]['message']);

        // Vlastní kód duplicitou není; přepsat ho jde.
        $this->assertSame([], $this->codeErrors($doc, ['id' => 5, 'code' => 'invno']));
        $this->assertSame([], $this->codeErrors($doc, ['id' => 5, 'code' => 'invno-cz']));
    }

    public function testStoredCodeCannotBeCleared(): void
    {
        $doc = $this->docWithSeries();

        $this->assertSame(['required'], $this->codeErrors($doc, ['id' => 5, 'code' => '']));
        $this->assertSame(['required'], $this->codeErrors($doc, ['id' => 5, 'code' => null]));
        $this->assertSame([], $this->codeErrors($doc, ['id' => 5, 'name' => 'Faktury']), 'payload bez kódu ho nemaže');
    }

    public function testWithoutDbCodeIsNotGenerated(): void
    {
        $data = $this->validData();
        $this->doc()->beforeSave($data, null);
        $this->assertArrayNotHasKey('code', $data);
    }
}
