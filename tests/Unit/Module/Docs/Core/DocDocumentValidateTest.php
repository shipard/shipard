<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

class DocDocumentValidateTest extends TestCase
{
    private function dbWithOwn(): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person exists
        return $db;
    }

    /**
     * DB, kde řada 1 je faktura vydaná a řada 2 syntetický typ vázaný na
     * pokladnu (`$boundCashDesk` = pokladna řady, null = řada bez vazby).
     */
    private function dbWithSeries(?int $boundCashDesk = 7): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($boundCashDesk): ?Row {
                if (str_contains($sql, 'docs_core_number_series')) {
                    return (int) $params[0] === 2
                        ? new Row(['doc_type' => 'cashb', 'cash_desk' => $boundCashDesk, 'warehouse' => null])
                        : new Row(['doc_type' => 'invno', 'cash_desk' => null, 'warehouse' => null]);
                }
                return new Row(['id' => 1]);
            },
        );
        return $db;
    }

    private function configWithCashType(): ConfigRuntime
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1],
            'cashb' => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    private function docWithCashType(?int $boundCashDesk = 7): TestableDocsHeadsDocument
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithSeries($boundCashDesk));
        $doc->setConfig($this->configWithCashType());
        return $doc;
    }

    /** @return list<array{column: string, code: string}> */
    private function errorsFor(array $errors, string $column): array
    {
        return array_values(array_filter($errors, fn(array $e) => $e['column'] === $column));
    }

    /** @return array<string, mixed> */
    private function konceptData(): array
    {
        return [
            'docState'        => 10,
            'number_series'   => 1,
            'issue_date'      => '2026-05-06',
            'accounting_date' => '2026-05-06',
        ];
    }

    /** @return array<string, mixed> */
    private function confirmedData(): array
    {
        return [
            'docState'         => 40,
            'number_series'    => 1,
            'issue_date'       => '2026-05-06',
            'accounting_date'  => '2026-05-06',
            'partner'          => 50,
            'vat_registration' => 1,
            'vat_mode'         => 1,
            'rows' => [['row_kind' => 1, 'total_price' => 100]],
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
        ];
    }

    public function testKonceptMinimalValid(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->konceptData();
        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    // ── cash_desk / cash_dir (tasks/docs-core-bound-series.md §5) ──────────

    public function testInvoiceCashDeskRequiresCashPayment(): void
    {
        $doc = $this->docWithCashType();

        $data = $this->konceptData() + ['cash_desk' => 7, 'payment_method' => 1];
        $errors = $this->errorsFor($doc->validate($data)->toArray(), 'cash_desk');
        $this->assertSame('cash_desk_requires_cash_payment', $errors[0]['code']);

        $data = $this->konceptData() + ['cash_desk' => 7, 'payment_method' => 0];
        $this->assertTrue($doc->validate($data)->isValid());

        // bez payment_method platí schéma default 1 (převodem)
        $data = $this->konceptData() + ['cash_desk' => 7];
        $this->assertCount(1, $this->errorsFor($doc->validate($data)->toArray(), 'cash_desk'));
    }

    public function testInvoiceCashDirMustStayZero(): void
    {
        $doc = $this->docWithCashType();

        $data = $this->konceptData() + ['cash_dir' => 1];
        $errors = $this->errorsFor($doc->validate($data)->toArray(), 'cash_dir');
        $this->assertSame('invalid_value', $errors[0]['code']);

        $data = $this->konceptData() + ['cash_dir' => 0];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testBoundTypeDenormalizesCashDeskFromSeriesAndNeedsDirection(): void
    {
        $doc = $this->docWithCashType(boundCashDesk: 7);

        // payload posílá cizí pokladnu — vyhrává řada; bez cash_dir chyba
        $data = array_merge($this->konceptData(), ['number_series' => 2, 'cash_desk' => 99]);
        $errors = $doc->validate($data)->toArray();
        $this->assertSame('cashb', $data['doc_type']);
        $this->assertSame(7, $data['cash_desk']);
        $this->assertSame('invalid_value', $this->errorsFor($errors, 'cash_dir')[0]['code']);

        $data = array_merge($this->konceptData(), ['number_series' => 2, 'cash_dir' => 2]);
        $this->assertTrue($doc->validate($data)->isValid());
        $this->assertSame(7, $data['cash_desk']);
    }

    public function testBoundTypeSeriesWithoutCashDeskIsFormError(): void
    {
        $doc = $this->docWithCashType(boundCashDesk: null);

        $data = array_merge($this->konceptData(), ['number_series' => 2, 'cash_dir' => 1, 'cash_desk' => 5]);
        $errors = $doc->validate($data)->toArray();

        $this->assertNull($data['cash_desk'], 'řada bez pokladny přepíše payload na NULL');
        $this->assertSame('series_binding_missing', $this->errorsFor($errors, '_form')[0]['code']);
    }

    public function testKonceptMissingNumberSeriesFails(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->konceptData();
        unset($data['number_series']);

        $errors = $doc->validate($data)->toArray();
        $this->assertContains('number_series', array_column($errors, 'column'));
    }

    public function testKonceptMissingIssueDateFails(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->konceptData();
        unset($data['issue_date']);

        $errors = $doc->validate($data)->toArray();
        $this->assertContains('issue_date', array_column($errors, 'column'));
    }

    public function testConfirmedRequiresPartner(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['partner']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'partner' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedRequiresVatRegistrationWhenVatModeNonZero(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        unset($data['vat_registration']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'vat_registration' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedAllowsMissingVatRegistrationWhenVatModeZero(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['vat_mode'] = 0;
        unset($data['vat_registration']);

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'vat_registration',
        );
        $this->assertEmpty($matched);
    }

    public function testConfirmedRequiresAtLeastOneRow(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['rows'] = [];

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'rows' && $e['code'] === 'no_rows',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedHeaderOnlySaveReadsRowsFromDb(): void
    {
        // Header-only data-save ve 20: rows nejsou v payloadu (spravuje je
        // sub-form), řádky existují v DB → no_rows nesmí falešně padnout.
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person
        $db->method('fetchAll')->willReturn([
            new Row(['id' => 7, 'row_kind' => 1, 'total_price' => 100]),
        ]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = $this->confirmedData();
        unset($data['rows']);
        $data['id'] = 42;

        $this->assertTrue($doc->validate($data)->isValid());
    }

    public function testConfirmedHeaderOnlySaveFailsWithoutRowsInDb(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['id' => 1])); // own person
        $db->method('fetchAll')->willReturn([]);
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = $this->confirmedData();
        unset($data['rows']);
        $data['id'] = 42;

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'rows' && $e['code'] === 'no_rows',
        );
        $this->assertNotEmpty($matched);
    }

    public function testConfirmedRequiresExchangeRateForForeignCurrency(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());

        $data = $this->confirmedData();
        $data['doc_currency'] = 'eur';

        $errors = $doc->validate($data)->toArray();
        $matched = array_filter(
            $errors,
            fn(array $e) => $e['column'] === 'exchange_rate' && $e['code'] === 'required',
        );
        $this->assertNotEmpty($matched);
    }

    /**
     * `home_currency` je system sloupec — formulář ani applier ho neposílají.
     * Kontrola kurzu musí vystřelit i bez něj (#121 U5): domácí měna
     * z nastavení, bez nastavení czk.
     */
    public function testConfirmedRequiresExchangeRateWithoutHomeCurrencyInData(): void
    {
        $data = $this->confirmedData();
        unset($data['home_currency']);
        $data['doc_currency'] = 'eur';

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($this->dbWithOwn());
        $this->assertNotEmpty($this->errorsFor($doc->validate($data)->toArray(), 'exchange_rate'), 'bez nastavení = czk');

        $doc->setSettings($this->settingsWithHomeCurrency('czk'));
        $this->assertNotEmpty($this->errorsFor($doc->validate($data)->toArray(), 'exchange_rate'));

        $data['exchange_rate'] = '0';
        $this->assertNotEmpty($this->errorsFor($doc->validate($data)->toArray(), 'exchange_rate'), 'nula není kurz');

        $data['exchange_rate'] = 24.325;
        $this->assertSame([], $this->errorsFor($doc->validate($data)->toArray(), 'exchange_rate'));

        unset($data['exchange_rate']);
        $doc->setSettings($this->settingsWithHomeCurrency('eur'));
        $this->assertSame([], $this->errorsFor($doc->validate($data)->toArray(), 'exchange_rate'), 'EUR je domácí měna zdroje dat');
    }

    private function settingsWithHomeCurrency(?string $currency): SettingsStore
    {
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->willReturnCallback(
            static fn(string $key): mixed => $key === 'economy.homeCurrency' ? $currency : null,
        );
        return $settings;
    }

    public function testConfirmedRefusesWithoutOwnCompany(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null); // no own person

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);

        $data = $this->confirmedData();
        $errors = $doc->validate($data)->toArray();

        $matched = array_filter(
            $errors,
            fn(array $e) => $e['code'] === 'no_own_company',
        );
        $this->assertNotEmpty($matched);
    }

    public function testApplyPaymentReferenceDefaultUsesSequenceNumber(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['sequence_number' => 42];
        $doc->applyPaymentReferenceDefaultPub($data);
        $this->assertSame('42', $data['payment_reference']);
    }

    public function testApplyPaymentReferenceDefaultDoesNotOverrideUserValue(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = ['sequence_number' => 42, 'payment_reference' => '12345'];
        $doc->applyPaymentReferenceDefaultPub($data);
        $this->assertSame('12345', $data['payment_reference']);
    }

    public function testApplyPaymentReferenceDefaultNoSequenceNoOp(): void
    {
        $doc = new TestableDocsHeadsDocument();
        $data = [];
        $doc->applyPaymentReferenceDefaultPub($data);
        $this->assertArrayNotHasKey('payment_reference', $data);
    }
}
