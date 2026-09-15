<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\CashDocs;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\CashDocs\CashDocument;

/**
 * Pokladní doklad (CashDeskDocumentBase přes CashDocument): způsob úhrady
 * jen hotově / kartou, měna dokladu = měna pokladny, partner nepovinný,
 * splatnost = vystavení, default Hotovost.
 */
class CashDocumentValidateTest extends TestCase
{
    private const SERIES_ID = 2;
    private const CASH_DESK_ID = 7;

    /**
     * Řada 2 = pokladní doklad vázaný na pokladnu 7 (měna dle parametru);
     * ostatní dotazy (vlastní firma) vrací řádek s id 1.
     */
    private function db(string $deskCurrency = 'czk', ?Row $terminal = null): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($deskCurrency, $terminal): ?Row {
                if (str_contains($sql, 'docs_core_number_series')) {
                    return new Row(['doc_type' => 'cash', 'cash_desk' => self::CASH_DESK_ID, 'warehouse' => null]);
                }
                if (str_contains($sql, 'economy_codebooks_cash_desks')) {
                    return new Row(['code' => 'HP1', 'name' => 'Hlavní pokladna', 'currency' => $deskCurrency]);
                }
                // Default terminál pokladny (PartnerBalanceResolver) — jen když ho test dodá.
                if (str_contains($sql, 'economy_codebooks_payment_terminals')) {
                    return $terminal;
                }
                return new Row(['id' => 1]);
            },
        );
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $docTypes = [
            'cash' => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    private function doc(string $deskCurrency = 'czk', ?Row $terminal = null): TestableCashDocument
    {
        $doc = new TestableCashDocument();
        $doc->setDb($this->db($deskCurrency, $terminal));
        $doc->setConfig($this->config());
        return $doc;
    }

    /** @return array<string, mixed> */
    private function konceptData(array $overrides = []): array
    {
        return array_merge([
            'docState'        => 10,
            'number_series'   => self::SERIES_ID,
            'cash_dir'        => 1,
            'issue_date'      => '2026-06-10',
            'accounting_date' => '2026-06-10',
            'doc_currency'    => 'czk',
        ], $overrides);
    }

    /** @return list<array{column: string, code: string}> */
    private function errorsFor(array $errors, string $column): array
    {
        return array_values(array_filter($errors, fn(array $e) => $e['column'] === $column));
    }

    public function testPaymentMethodOnlyCashOrCard(): void
    {
        $doc = $this->doc();

        $data = $this->konceptData(['payment_method' => 1]);
        $errors = $this->errorsFor($doc->validate($data)->toArray(), 'payment_method');
        $this->assertSame('invalid_value', $errors[0]['code']);

        // Karta na příjmu chce plátce (#72) — tady ho dává partner hlavičky.
        foreach ([0, 2] as $allowed) {
            $data = $this->konceptData(['payment_method' => $allowed, 'partner' => 5]);
            $this->assertTrue($doc->validate($data)->isValid(), "payment_method {$allowed} je povolený");
        }

        // bez způsobu úhrady projde — default doplní beforeSave
        $data = $this->konceptData();
        $this->assertTrue($doc->validate($data)->isValid());
    }

    /**
     * Příjem kartou = pohledávka 311 za plátcem (#72 D1/D2): bez terminálu
     * s protistranou i bez partnera hlavičky doklad neprojde; s partnerem je
     * plátce = partner; default terminál pokladny s protistranou má přednost.
     * Výdej kartou plátce nevyžaduje (není prodejní směr).
     */
    public function testCardReceiptRequiresPayer(): void
    {
        $data = $this->konceptData(['payment_method' => 2]);
        $errors = $this->errorsFor($this->doc()->validate($data)->toArray(), 'partner_balance');
        $this->assertSame('partner_balance_required', $errors[0]['code']);

        $data = $this->konceptData(['payment_method' => 2, 'partner' => 5]);
        $this->assertTrue($this->doc()->validate($data)->isValid());
        $this->assertSame(5, $data['partner_balance'], 'bez terminálu plátce = partner');

        $terminal = new Row(['id' => 9, 'kind' => 0, 'cash_desk' => self::CASH_DESK_ID, 'partner' => 33]);
        $data = $this->konceptData(['payment_method' => 2, 'partner' => 5]);
        $this->assertTrue($this->doc('czk', $terminal)->validate($data)->isValid());
        $this->assertSame(33, $data['partner_balance'], 'protistrana terminálu má přednost před partnerem');
        $this->assertSame(9, $data['payment_terminal'], 'default terminál pokladny se doplní do hlavičky');

        $data = $this->konceptData(['payment_method' => 2, 'cash_dir' => 2]);
        $this->assertTrue($this->doc()->validate($data)->isValid(), 'výdej kartou plátce nevyžaduje');
    }

    public function testDocCurrencyMustMatchCashDesk(): void
    {
        $doc = $this->doc(deskCurrency: 'czk');

        $data = $this->konceptData(['doc_currency' => 'eur']);
        $errors = $this->errorsFor($doc->validate($data)->toArray(), 'doc_currency');
        $this->assertSame('currency_mismatch', $errors[0]['code']);

        $data = $this->konceptData(['doc_currency' => 'CZK']);
        $this->assertTrue($doc->validate($data)->isValid(), 'porovnání měny je case-insensitive');
    }

    public function testCashDeskIsDenormalizedFromSeries(): void
    {
        $data = $this->konceptData(['cash_desk' => 99]);
        $this->doc()->validate($data);

        $this->assertSame('cash', $data['doc_type']);
        $this->assertSame(self::CASH_DESK_ID, $data['cash_desk'], 'řada vyhrává nad payloadem');
    }

    public function testConfirmWithoutPartnerIsValid(): void
    {
        $data = $this->konceptData([
            'docState'         => 40,
            'payment_method'   => 0,
            'vat_mode'         => 1,
            'vat_registration' => 1,
            'home_currency'    => 'czk',
            'rows'             => [['row_kind' => 1, 'operation' => 'sale.services', 'total_price' => 100]],
        ]);
        $result = $this->doc()->validate($data);

        $this->assertTrue($result->isValid(), json_encode($result->toArray()));
    }

    public function testConfirmWithoutRowsFails(): void
    {
        $data = $this->konceptData([
            'docState'         => 40,
            'vat_mode'         => 1,
            'vat_registration' => 1,
            'home_currency'    => 'czk',
            'rows'             => [],
        ]);
        $errors = $this->doc()->validate($data)->toArray();

        $this->assertSame('no_rows', $this->errorsFor($errors, 'rows')[0]['code']);
    }

    public function testDirectionIsRequiredForCashType(): void
    {
        $data = $this->konceptData(['cash_dir' => 0]);
        $errors = $this->doc()->validate($data)->toArray();

        $this->assertSame('invalid_value', $this->errorsFor($errors, 'cash_dir')[0]['code']);
    }

    public function testDueDateAlwaysEqualsIssueDate(): void
    {
        // i s partnerem (rodič by dosadil splatnost partnera) a i když je
        // due_date v payloadu
        $data = [
            'issue_date' => '2026-06-10',
            'due_date'   => '2026-07-10',
            'partner'    => 50,
        ];
        $this->doc()->applyDateDefaultsPub($data);

        $this->assertSame('2026-06-10', $data['due_date']);
        $this->assertSame('2026-06-10', $data['accounting_date']);
        $this->assertSame('2026-06-10', $data['vat_duzp']);
    }

    public function testEmptyDocCurrencyFallsBackToCashDeskCurrency(): void
    {
        $data = ['cash_desk' => self::CASH_DESK_ID, 'home_currency' => 'czk'];
        $this->doc(deskCurrency: 'eur')->applyHomeCurrencyPub($data);

        $this->assertSame('eur', $data['doc_currency']);
    }

    public function testPaymentMethodDefaultsToCash(): void
    {
        $doc = $this->doc();

        $data = [];
        $doc->applyPaymentMethodDefaultPub($data);
        $this->assertSame(0, $data['payment_method']);

        $data = ['payment_method' => 2];
        $doc->applyPaymentMethodDefaultPub($data);
        $this->assertSame(2, $data['payment_method'], 'explicitní hodnota vyhrává');
    }
}

/** Zveřejnění chráněných hooků pro izolované testy. */
class TestableCashDocument extends CashDocument
{
    public function applyDateDefaultsPub(array &$data): void
    {
        $this->applyDateDefaults($data);
    }

    public function applyHomeCurrencyPub(array &$data): void
    {
        $this->applyHomeCurrency($data);
    }

    public function applyPaymentMethodDefaultPub(array &$data): void
    {
        $this->applyPaymentMethodDefault($data);
    }
}
