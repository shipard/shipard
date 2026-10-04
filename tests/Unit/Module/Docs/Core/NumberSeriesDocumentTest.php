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
}
