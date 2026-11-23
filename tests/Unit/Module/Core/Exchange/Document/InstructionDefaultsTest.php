<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\CanonicalReferences;
use Shipard\Module\Core\Exchange\Document\InstructionDefaults;
use Shipard\Module\Docs\Core\Defaults\DocHeadDefaults;
use Shipard\Module\Docs\Core\Defaults\DocRowDefaults;
use Shipard\Tests\Unit\Module\Docs\Core\Defaults\DefaultsFixtures;

/**
 * Výchozí hodnoty pokynu (#166 D40–D44, D48–D50): adaptér mezi payloadem
 * a společnými třídami `docs.core`. Doplňuje se chybějící klíč a prázdný
 * text; výslovné `null` zůstává. Doplněná pole jdou do pracovní kopie
 * payloadu a do výčtu `{path, value}`.
 */
class InstructionDefaultsTest extends TestCase
{
    use DefaultsFixtures;
    use ReferenceFixture;

    /** Položky: 18 služba s cenou a jednotkou se systémovým kódem, 19 zásoba s jednotkou bez kódu. */
    private const ITEMS = [
        18 => ['name' => 'Správa IT', 'sales_price_no_vat' => '5000.00', 'unit' => 8, 'item_type' => 0],
        19 => ['name' => 'Kabel', 'sales_price_no_vat' => '249.00', 'unit' => 9, 'item_type' => 1],
    ];

    private const RECORDS = [
        'economy_items'                       => ['code=SLUZBA' => 18, 'code=KABEL' => 19],
        'economy_codebooks_vat_registrations' => ['code=cz' => 5],
        'economy_codebooks_bank_accounts'     => ['code=BU1' => 3],
        'economy_codebooks_cash_desks'        => ['code=EUR1' => 4],
    ];

    private function defaults(mixed ...$db): InstructionDefaults
    {
        $connection = $this->db(...$db + [
            'registration' => ['id' => 5, 'country' => 'cz'],
            'items'        => self::ITEMS,
            'units'        => [8 => 'mnth', 9 => null],
        ]);
        $config = $this->realConfig();
        return new InstructionDefaults(
            new DocHeadDefaults($connection, $config),
            new DocRowDefaults($connection, $config),
            $this->referenceResolver(self::RECORDS),
            $connection,
            $config,
        );
    }

    /** Sloupce hlavičky pro kontext řádků — doklad s DPH, registrace 5. */
    private const HEAD = [
        'doc_type' => 'invno', 'cash_dir' => 0, 'vat_place' => 0, 'vat_mode' => 1,
        'vat_registration' => 5, 'issue_date' => '2026-06-10',
    ];

    /** @return array<string, mixed> cesta → hodnota */
    private static function byPath(array $defaults): array
    {
        return array_column($defaults, 'value', 'path');
    }

    // ── Hlavička ─────────────────────────────────────────────────────────────

    public function testMinimalIssuedInvoiceGetsHeadDefaultsInFormatShape(): void
    {
        $result = $this->defaults()->head(['docType' => 'invoiceIssued'], 'invno', import: false);

        $this->assertSame([
            'currency'               => 'CZK',
            'payment.method'         => 'bankTransfer',
            'vat.mode'               => 'fromBase',
            'dates.issueDate'        => date('Y-m-d'),
            'vat.registration'       => 5,
            'payment.ownBankAccount' => 3,
        ], self::byPath($result['defaults']));
        // Pracovní kopie nese hodnoty tam, kde je čte zbytek applieru.
        $this->assertSame('CZK', $result['canonical']['currency']);
        $this->assertSame(['method' => 'bankTransfer', 'ownBankAccount' => 3], $result['canonical']['payment']);
        $this->assertSame(['mode' => 'fromBase', 'registration' => 5], $result['canonical']['vat']);
        $this->assertSame(date('Y-m-d'), $result['canonical']['dates']['issueDate']);
        $this->assertArrayNotHasKey('totals', $result['canonical'], 'automatiku zaokrouhlení řeší applier, do výčtu nepatří');
    }

    /** Hodnota z payloadu vždy vyhrává. */
    public function testPayloadValuesWin(): void
    {
        $payload = [
            'docType'  => 'invoiceIssued',
            'currency' => 'EUR',
            'dates'    => ['issueDate' => '2026-03-01'],
            'vat'      => ['mode' => 'fromTotal', 'registration' => '@cz'],
            'payment'  => ['method' => 'card', 'ownBankAccount' => '@BU1'],
        ];
        $result = $this->defaults()->head($payload, 'invno', import: false);

        $this->assertSame([], $result['defaults']);
        $this->assertSame($payload, $result['canonical']);
    }

    /** D50: výslovné `null` = záměrně prázdné — nedoplní se. */
    public function testExplicitNullStaysEmpty(): void
    {
        $payload = [
            'docType' => 'invoiceIssued',
            'dates'   => ['issueDate' => null],
            'vat'     => ['registration' => null],
            'payment' => ['ownBankAccount' => null],
        ];
        $result = $this->defaults()->head($payload, 'invno', import: false);

        $this->assertSame(['currency', 'payment.method', 'vat.mode'], array_column($result['defaults'], 'path'));
        $this->assertNull($result['canonical']['dates']['issueDate']);
        $this->assertNull($result['canonical']['vat']['registration']);
        $this->assertNull($result['canonical']['payment']['ownBankAccount']);
    }

    /** Chybějící klíč, prázdný text i `"vat": null` se doplní. */
    public function testMissingKeyEmptyTextAndNullObjectAreFilled(): void
    {
        $payload = ['docType' => 'invoiceIssued', 'currency' => '', 'vat' => null, 'dates' => ['issueDate' => '']];
        $paths = array_column($this->defaults()->head($payload, 'invno', import: false)['defaults'], 'path');

        $this->assertContains('currency', $paths);
        $this->assertContains('vat.mode', $paths);
        $this->assertContains('vat.registration', $paths);
        $this->assertContains('dates.issueDate', $paths);
    }

    /** D48: import datum vystavení nedoplňuje; ostatní výchozí hodnoty ano, registrace bez ohledu na platnost. */
    public function testImportDoesNotFillIssueDate(): void
    {
        $result = $this->defaults()->head(['docType' => 'invoiceIssued'], 'invno', import: true);

        $paths = array_column($result['defaults'], 'path');
        $this->assertNotContains('dates.issueDate', $paths);
        $this->assertContains('vat.registration', $paths);
        $this->assertContains('payment.ownBankAccount', $paths);
        $this->assertStringNotContainsString('valid_from', $this->queriesOn('economy_codebooks_vat_registrations')[0]);
    }

    /** Doklad bez DPH registraci nedostane; neplátce má režim `none`. */
    public function testNoVatDocumentAndNonPayer(): void
    {
        $noVat = $this->defaults()->head(['docType' => 'invoiceIssued', 'vat' => ['mode' => 'none']], 'invno', import: false);
        $this->assertArrayNotHasKey('vat.registration', self::byPath($noVat['defaults']));

        $nonPayer = $this->defaults(settings: ['economy.vatAgenda' => false])
            ->head(['docType' => 'invoiceIssued'], 'invno', import: false);
        $this->assertSame('none', self::byPath($nonPayer['defaults'])['vat.mode']);
        $this->assertArrayNotHasKey('vat.registration', self::byPath($nonPayer['defaults']));
    }

    /** Pokladní doklad: Hotově a měna pokladny z reference `cashDesk`, bez vlastního účtu. */
    public function testCashDocumentTakesCashAndDeskCurrency(): void
    {
        $result = $this->defaults(cashDesk: ['id' => 4, 'code' => 'EUR1', 'name' => 'Pokladna EUR', 'currency' => 'eur'])
            ->head(['docType' => 'cashDocument', 'cashDirection' => 1, 'cashDesk' => '@EUR1'], 'cash', import: false);
        $defaults = self::byPath($result['defaults']);

        $this->assertSame('EUR', $defaults['currency']);
        $this->assertSame('cash', $defaults['payment.method']);
        $this->assertArrayNotHasKey('payment.ownBankAccount', $defaults);
    }

    /** Nenalezená reference výchozí hodnotu jen nedostane — nález hlásí applier jinde. */
    public function testUnknownReferenceIsPresentAndNotReplaced(): void
    {
        $result = $this->defaults()->head(
            ['docType' => 'invoiceIssued', 'payment' => ['ownBankAccount' => '@NENI']],
            'invno',
            import: false,
        );

        $this->assertArrayNotHasKey('payment.ownBankAccount', self::byPath($result['defaults']));
        $this->assertSame('@NENI', $result['canonical']['payment']['ownBankAccount']);
    }

    // ── Řádky ────────────────────────────────────────────────────────────────

    /** @param list<array<string, mixed>> $rows */
    private function rows(array $rows, bool $import = false, array $head = self::HEAD): array
    {
        $canonical = CanonicalReferences::expand(['docType' => 'invoiceIssued', 'rows' => $rows]);
        return $this->defaults()->rows($canonical, $head, $import, 'cz');
    }

    public function testRowWithItemGetsContentFromItemCard(): void
    {
        $result = $this->rows([['item' => '@SLUZBA']]);

        $this->assertSame([
            'rows.0.operation'   => 'sale.services',
            'rows.0.description' => 'Správa IT',
            'rows.0.unitPrice'   => 5000.0,
            'rows.0.unit'        => 'mnth',
            'rows.0.quantity'    => 1,
            'rows.0.vat.code'    => 'cz-120',
        ], self::byPath($result['defaults']));
        $row = $result['canonical']['rows'][0];
        $this->assertSame(['ref' => '@SLUZBA'], $row['item']);
        $this->assertSame(['code' => 'cz-120'], $row['vat']);
        $this->assertSame('mnth', $row['unit']);
        $this->assertSame([], $result['unitIds']);
    }

    /** Jednotku bez systémového kódu formát vyjádřit neumí — id jde stranou. */
    public function testUnitWithoutSystemCodeGoesAside(): void
    {
        $result = $this->rows([['item' => '@KABEL']]);

        $this->assertSame([0 => 9], $result['unitIds']);
        $this->assertSame(9, self::byPath($result['defaults'])['rows.0.unit']);
        $this->assertArrayNotHasKey('unit', $result['canonical']['rows'][0]);
        $this->assertSame('sale.goods', $result['canonical']['rows'][0]['operation'], 'zásoba → prodej zboží');
    }

    /** D42: pokyn doplňuje jen to, co payload neuvádí. */
    public function testRowValuesFromPayloadWin(): void
    {
        $row = [
            'item' => '@SLUZBA', 'operation' => 'sale.goods', 'description' => 'Vlastní text',
            'unitPrice' => 4200, 'unit' => 'hr', 'quantity' => 3, 'vat' => ['code' => 'cz-121'],
        ];
        $result = $this->rows([$row]);

        $this->assertSame([], $result['defaults']);
        $this->assertSame(['ref' => '@SLUZBA'] , $result['canonical']['rows'][0]['item']);
        $this->assertSame('cz-121', $result['canonical']['rows'][0]['vat']['code']);
    }

    /** U6: `vat` bez klíče `code` je „chybí“; `{"code": null}` je záměrně bez kódu. */
    public function testVatObjectWithoutCodeIsFilledAndNullCodeStays(): void
    {
        $result = $this->rows([
            ['description' => 'a', 'unitPrice' => 1, 'vat' => ['rateDate' => '2026-06-01']],
            ['description' => 'b', 'unitPrice' => 1, 'vat' => ['code' => null]],
            ['description' => 'c', 'unitPrice' => 1, 'vat' => null],
        ]);
        $defaults = self::byPath($result['defaults']);

        $this->assertSame('cz-120', $defaults['rows.0.vat.code']);
        $this->assertSame(['rateDate' => '2026-06-01', 'code' => 'cz-120'], $result['canonical']['rows'][0]['vat']);
        $this->assertArrayNotHasKey('rows.1.vat.code', $defaults);
        $this->assertSame(['code' => null], $result['canonical']['rows'][1]['vat']);
        $this->assertSame('cz-120', $defaults['rows.2.vat.code']);
    }

    /** Řádek bez položky: pohyb, množství a kód DPH. */
    public function testRowWithoutItem(): void
    {
        $result = $this->rows([['description' => 'Práce', 'unitPrice' => 800]]);

        $this->assertSame(
            ['rows.0.operation', 'rows.0.quantity', 'rows.0.vat.code'],
            array_column($result['defaults'], 'path'),
        );
    }

    /** Výslovné `null` na řádku zůstává prázdné. */
    public function testRowNullsStayEmpty(): void
    {
        $result = $this->rows([[
            'item' => '@SLUZBA', 'operation' => null, 'description' => null,
            'unitPrice' => null, 'unit' => null, 'quantity' => null, 'vat' => ['code' => null],
        ]]);

        $this->assertSame([], $result['defaults']);
    }

    /** Text z položky v payloadu (`item.name`) je text řádku — název z karty ho nepřepíše. */
    public function testItemNameInPayloadIsRowText(): void
    {
        $result = $this->rows([['item' => ['ref' => '@SLUZBA', 'name' => 'Paušál leden']]]);

        $this->assertArrayNotHasKey('rows.0.description', self::byPath($result['defaults']));
    }

    /** Řádek zadaný celkovou cenou cenu za jednotku z karty nedostane. */
    public function testRowWithTotalPriceGetsNoUnitPrice(): void
    {
        $result = $this->rows([['item' => '@SLUZBA', 'totalPrice' => 900, 'priceCalcMode' => 'fromTotal']]);

        $this->assertArrayNotHasKey('rows.0.unitPrice', self::byPath($result['defaults']));
    }

    /** D49: import obsah řádku nedoplňuje; pohyb ano. */
    public function testImportFillsOnlyOperation(): void
    {
        $result = $this->rows([['item' => '@SLUZBA']], import: true);

        $this->assertSame(['rows.0.operation' => 'sale.services'], self::byPath($result['defaults']));
    }

    /** Textový řádek, řádek kontace a doklad bez DPH. */
    public function testTextContationAndNoVatRows(): void
    {
        $result = $this->rows([
            ['rowKind' => 'text', 'description' => 'Poznámka'],
            ['accSide' => 'debit', 'account' => '@518100', 'totalPrice' => 100],
        ]);
        $this->assertSame([], $result['defaults']);

        $noVat = $this->rows([['item' => '@SLUZBA']], head: ['vat_mode' => 0] + self::HEAD);
        $this->assertArrayNotHasKey('rows.0.vat.code', self::byPath($noVat['defaults']));
    }
}
