<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core\Prints;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Module\Docs\Core\Prints\Blocks\DocAdvancesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocCashDatesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocCashDeskBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocDatesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocDocumentBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocPartiesBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocPaymentBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocRowsBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocTotalsBlock;
use Shipard\Module\Docs\Core\Prints\Blocks\DocVatRecapBlock;
use Shipard\Module\Docs\Core\Prints\DocPrintContext;
use Shipard\Module\Docs\Core\Prints\DocTitleContext;
use Shipard\Module\Docs\Core\Prints\DocVatCodes;
use Shipard\Module\Docs\Core\Prints\TitleVariantResolver;

/**
 * Bloky tisku dokladu nad ručně složeným kontextem — bez databáze. Celý
 * kontrakt nad uloženým dokladem hlídá integrační DocPrintBuilderTest.
 */
class DocPrintBlocksTest extends TestCase
{
    private const SUPPLIER = [
        'name'         => 'Dodavatel s.r.o.',
        'bank_account' => [
            'name' => 'Hlavní', 'account_number' => '19-2000145399/0800',
            'iban' => 'CZ6508000000192000145399', 'bic' => 'GIBACZPX', 'currency' => 'czk',
        ],
        'vat_registration' => ['country' => 'cz', 'vat_id' => 'CZ00000019'],
    ];

    private const CUSTOMER = ['name' => 'Odběratel a.s.', 'address' => ['country' => 'cz']];

    private string $configDir = '';

    protected function tearDown(): void
    {
        if ($this->configDir !== '') {
            @unlink($this->configDir . '/config/configuration/compiled.cs.json');
            @rmdir($this->configDir . '/config/configuration');
            @rmdir($this->configDir . '/config');
            @rmdir($this->configDir);
        }
    }

    private function config(): ConfigRuntime
    {
        $this->configDir = sys_get_temp_dir() . '/shpd_docprint_' . uniqid('', true);
        mkdir($this->configDir . '/config/configuration', 0755, true);
        file_put_contents(
            $this->configDir . '/config/configuration/compiled.cs.json',
            json_encode(['items' => [
                'docs.core.docTypes' => [
                    'invno' => ['name' => 'Faktura vydaná', 'trade_dir' => 1],
                    'invpo' => ['name' => 'Zálohová faktura vydaná', 'trade_dir' => 1, 'tax_document' => false],
                    'cash'  => ['name' => 'Pokladní doklad', 'trade_dir' => 0, 'trade_dir_column' => 'cash_dir'],
                ],
                'docs.core.paymentMethods' => [
                    ['name' => 'Hotovost'],
                    ['name' => 'Převodem'],
                ],
            ]]),
        );
        return ConfigRuntime::load($this->configDir, 'cs');
    }

    /**
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $recap
     * @param array<string, mixed>|null $supplier
     * @param array<string, mixed>|null $customer
     */
    private function context(
        array $head = [],
        array $rows = [],
        array $recap = [],
        ?array $supplier = self::SUPPLIER,
        ?ConfigRuntime $config = null,
        ?array $customer = self::CUSTOMER,
        ?array $cashDesk = null,
    ): DocPrintContext {
        return new DocPrintContext(
            head: $head + [
                'doc_type' => 'invno', 'doc_number' => '2026000123', 'doc_text' => 'Služby za září',
                'doc_notice' => '  ', 'vat_registration' => 1, 'vat_mode' => 1,
                'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1.0,
                'issue_date' => '2026-09-30', 'due_date' => '2026-10-14', 'vat_duzp' => '2026-09-30',
                'period_from' => null, 'period_to' => null,
                'payment_method' => 1, 'payment_reference' => '2026000123',
                'specific_symbol' => '', 'constant_symbol' => null,
                'total_base' => 1000.0, 'total_vat' => 210.0, 'total_amount' => 1210.0, 'total_rounding' => 0.0,
                'total_base_dom' => 1000.0, 'total_vat_dom' => 210.0, 'total_amount_dom' => 1210.0,
            ],
            rows: $rows,
            recap: $recap,
            supplier: $supplier,
            customer: $customer,
            units: [3 => 'ks'],
            vatCodes: new DocVatCodes(
                [
                    'cz-120' => ['name' => 'Základní', 'print' => 'Základní'],
                    'cz-150' => ['name' => 'Základní - PDP 4', 'print' => 'Základní - přenesení', 'note' => 'pdp4'],
                    'cz-152' => ['name' => 'Základní - PDP 5', 'note' => 'pdp5'],
                    'cz-190' => ['name' => 'EU', 'print' => 'EU/Zboží', 'note' => 'eu'],
                ],
                [
                    'pdp4' => ['text' => 'Daň odvede zákazník'],
                    'pdp5' => ['text' => 'Daň odvede zákazník'],
                    'eu'   => ['text' => 'Osvobozeno podle § 64'],
                ],
            ),
            translator: new PrintTranslator([
                'title.invoiceVatPayer'    => ['cs' => 'Faktura – daňový doklad'],
                'title.invoiceNonVatPayer' => ['cs' => 'Faktura'],
                'title.proforma'           => ['cs' => 'Zálohová faktura'],
                'message.qrNoAccount'      => ['cs' => 'QR chybí'],
                'message.qrSkipped.paymentReference' => ['cs' => 'QR bez VS'],
            ], 'cs'),
            config: $config,
            cashDesk: $cashDesk,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function itemRow(array $overrides = []): array
    {
        return $overrides + [
            'row_kind' => 1, 'operation' => 'sale.services', 'description' => 'Práce',
            'quantity' => 2.0, 'unit' => 3, 'unit_price' => 500.0, 'discount_pct' => null,
            'vat_code' => 'cz-120', 'vat_pct' => 21.0,
            'vat_base' => 1000.0, 'vat_amount' => 210.0, 'vat_total' => 1210.0,
        ];
    }

    // ── TitleVariantResolver ────────────────────────────────────────────────

    /** @return array<string, array{string, DocTitleContext}> */
    public static function titleVariants(): array
    {
        return [
            'faktura plátce'    => ['invoiceVatPayer', new DocTitleContext('invno', true, 1, true, 1210.0)],
            'faktura neplátce'  => ['invoiceNonVatPayer', new DocTitleContext('invno', false, 1)],
            'proforma plátce'   => ['proforma', new DocTitleContext('invpo', true, 1, true, 1210.0)],
            'proforma neplátce' => ['proforma', new DocTitleContext('invpo', false, 1)],
            // Pokladní doklad (D25)
            'příjem, plátce, rekapitulace'     => ['cashInTaxDocument', new DocTitleContext('cash', true, 1, true, 1210.0)],
            'příjem, plátce, bez rekapitulace' => ['cashIn', new DocTitleContext('cash', true, 1, false, 1210.0)],
            'příjem, neplátce'                 => ['cashIn', new DocTitleContext('cash', false, 1, false, 1210.0)],
            'výdej, plátce s rekapitulací'     => ['cashOut', new DocTitleContext('cash', true, 2, true, 121.0)],
            'výdej, neplátce'                  => ['cashOut', new DocTitleContext('cash', false, 2)],
        ];
    }

    #[DataProvider('titleVariants')]
    public function testTitleVariants(string $expected, DocTitleContext $context): void
    {
        $this->assertSame($expected, TitleVariantResolver::resolve($context));
    }

    public function testUnknownDocumentTypeHasNoTitleVariant(): void
    {
        $this->expectException(PrintBuildException::class);
        $this->expectExceptionMessage("Document type 'invni' has no print title variant");
        TitleVariantResolver::resolve(new DocTitleContext('invni', true, 2));
    }

    public function testCashDocumentWithoutDirectionHasNoTitleVariant(): void
    {
        $this->expectException(PrintBuildException::class);
        $this->expectExceptionMessage('Cash document has no direction');
        TitleVariantResolver::resolve(new DocTitleContext('cash', true, null));
    }

    public function testTitleContextComesFromHeadAndRecap(): void
    {
        $context = $this->context(
            ['total_amount' => -50.0],
            recap: [['vat_code' => 'cz-120', 'is_reverse_pair' => 1], ['vat_code' => 'cz-120']],
            config: $this->config(),
        )->titleContext();
        $this->assertEquals(new DocTitleContext('invno', true, 1, true, -50.0), $context);

        // Jen druhá strana reverse charge páru = rekapitulace se netiskne.
        $this->assertFalse($this->context(recap: [['is_reverse_pair' => 1]])->hasVatRecap());
        // Neplátce rekapitulaci nemá, i kdyby v datech byla.
        $this->assertFalse($this->context(['vat_registration' => null], recap: [['vat_code' => 'cz-120']])->hasVatRecap());
    }

    // ── document ────────────────────────────────────────────────────────────

    public function testDocumentBlockForVatPayerInHomeCurrency(): void
    {
        $document = (new DocDocumentBlock())->build($this->context(config: $this->config()))['document'];

        $this->assertSame([
            'type'            => 'invno',
            'tradeDir'        => 1,
            'titleVariant'    => 'invoiceVatPayer',
            'title'           => 'Faktura – daňový doklad',
            'number'          => '2026000123',
            'text'            => 'Služby za září',
            'notice'          => null,
            'isTaxDocument'   => true,
            'vatPayer'        => true,
            'vatMode'         => 1,
            'currency'        => 'CZK',
            'homeCurrency'    => 'CZK',
            'exchangeRate'    => null,
            'foreignCurrency' => false,
        ], $document);
    }

    public function testDocumentBlockForeignCurrencyNonPayerAndProforma(): void
    {
        $foreign = (new DocDocumentBlock())->build($this->context([
            'doc_currency' => 'eur', 'exchange_rate' => '24.310000', 'doc_notice' => 'Děkujeme.',
        ]))['document'];
        $this->assertSame('EUR', $foreign['currency']);
        $this->assertSame('CZK', $foreign['homeCurrency']);
        $this->assertSame(24.31, $foreign['exchangeRate']);
        $this->assertTrue($foreign['foreignCurrency']);
        $this->assertSame('Děkujeme.', $foreign['notice']);

        $nonPayer = (new DocDocumentBlock())->build($this->context([
            'vat_registration' => null, 'vat_mode' => 0,
        ]))['document'];
        $this->assertSame('invoiceNonVatPayer', $nonPayer['titleVariant']);
        $this->assertSame('Faktura', $nonPayer['title']);
        $this->assertFalse($nonPayer['vatPayer']);
        $this->assertSame(0, $nonPayer['vatMode']);

        $proforma = (new DocDocumentBlock())->build(
            $this->context(['doc_type' => 'invpo'], config: $this->config()),
        )['document'];
        $this->assertSame('proforma', $proforma['titleVariant']);
        $this->assertFalse($proforma['isTaxDocument']);
    }

    public function testDocumentBlockCarriesTradeDirection(): void
    {
        $config = $this->config();
        $tradeDir = fn (array $head): ?int => (new DocDocumentBlock())
            ->build($this->context($head, config: $config))['document']['tradeDir'];

        $this->assertSame(1, $tradeDir(['doc_type' => 'invpo']));
        // Bez konfigurace typů dokladů směr neznáme.
        $this->assertNull((new DocDocumentBlock())->build($this->context())['document']['tradeDir']);
    }

    // ── dates ───────────────────────────────────────────────────────────────

    public function testDatesBlock(): void
    {
        $config = $this->config();

        $this->assertSame(
            ['issue' => '2026-09-30', 'due' => '2026-10-14', 'duzp' => '2026-09-30',
             'periodFrom' => '2026-09-01', 'periodTo' => '2026-09-30'],
            (new DocDatesBlock())->build($this->context(
                ['period_from' => '2026-09-01', 'period_to' => new \DateTimeImmutable('2026-09-30')],
                config: $config,
            ))['dates'],
        );

        // Nedaňový doklad DUZP nemá, i kdyby ve sloupci něco zbylo.
        $proforma = (new DocDatesBlock())->build($this->context(['doc_type' => 'invpo'], config: $config))['dates'];
        $this->assertNull($proforma['duzp']);
        $this->assertNull($proforma['periodFrom']);
    }

    // ── supplier / customer ─────────────────────────────────────────────────

    public function testPartiesAreSnapshotsUnchanged(): void
    {
        $this->assertSame(
            ['supplier' => self::SUPPLIER, 'customer' => self::CUSTOMER],
            (new DocPartiesBlock())->build($this->context()),
        );
    }

    public function testMissingPartyIsNull(): void
    {
        $this->assertSame(
            ['supplier' => self::SUPPLIER, 'customer' => null],
            (new DocPartiesBlock())->build($this->context(customer: null)),
        );
        $this->assertSame(
            ['supplier' => null, 'customer' => self::CUSTOMER],
            (new DocPartiesBlock())->build($this->context(supplier: null)),
        );
    }

    // ── cashDesk, data pokladního dokladu ───────────────────────────────────

    public function testCashDeskBlock(): void
    {
        $this->assertSame(['cashDesk' => null], (new DocCashDeskBlock())->build($this->context()));

        $context = $this->context(cashDesk: ['id' => '35', 'code' => 'HP', 'name' => 'Hlavní pokladna', 'currency' => 'czk']);
        $this->assertSame(
            ['cashDesk' => ['id' => 35, 'code' => 'HP', 'name' => 'Hlavní pokladna']],
            (new DocCashDeskBlock())->build($context),
        );
    }

    public function testCashDatesCarryPaymentReceivedOnReceiptWithVatOnly(): void
    {
        $config = $this->config();
        $dates  = fn (array $head): array => (new DocCashDatesBlock())->build(
            $this->context(['doc_type' => 'cash', 'vat_dppd' => '2026-09-29'] + $head, config: $config),
        )['dates'];

        $receipt = $dates(['cash_dir' => 1]);
        $this->assertSame('2026-09-29', $receipt['paymentReceived']);
        $this->assertSame('2026-09-30', $receipt['issue'], 'ostatní data jako u každého dokladu');
        $this->assertSame('2026-09-30', $receipt['duzp']);

        $this->assertNull($dates(['cash_dir' => 2])['paymentReceived'], 'výdej');
        $this->assertNull($dates(['cash_dir' => 1, 'vat_registration' => null])['paymentReceived'], 'neplátce');
        $this->assertNull($dates(['cash_dir' => 1, 'vat_mode' => 0])['paymentReceived'], 'doklad bez DPH');
    }

    // ── payment ─────────────────────────────────────────────────────────────

    public function testPaymentBlockWithQr(): void
    {
        $context = $this->context(['constant_symbol' => '0308'], config: $this->config());
        $payment = (new DocPaymentBlock())->build($context)['payment'];

        $this->assertSame([
            'method'         => ['id' => 1, 'label' => 'Převodem'],
            'reference'      => '2026000123',
            'specificSymbol' => null,
            'constantSymbol' => '0308',
            'bankAccount'    => self::SUPPLIER['bank_account'],
            'amountToPay'    => 1210.0,
            'currency'       => 'CZK',
            'qr'             => [
                'standard' => 'spayd',
                'payload'  => 'SPD*1.0*ACC:CZ6508000000192000145399+GIBACZPX*AM:1210.00*CC:CZK'
                    . '*X-VS:2026000123*X-KS:0308*DT:20261014*MSG:2026000123',
            ],
        ], $payment);
        $this->assertSame([], $context->messages());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function headsWithoutQr(): array
    {
        return [
            'hotově'           => [['payment_method' => 0]],
            'nulová částka'    => [['total_amount' => 0.0]],
            'záporná částka'   => [['total_amount' => -100.0]],
        ];
    }

    /** @param array<string, mixed> $head */
    #[DataProvider('headsWithoutQr')]
    public function testNoQrWithoutBankTransferOrPositiveAmount(array $head): void
    {
        $context = $this->context($head);
        $payment = (new DocPaymentBlock())->build($context)['payment'];

        $this->assertNull($payment['qr']);
        $this->assertSame([], $context->messages(), 'QR se nečekal — žádné varování');
    }

    public function testBankAccountIsPrintedForBankTransferOnly(): void
    {
        $transfer = (new DocPaymentBlock())->build($this->context(['total_amount' => 0.0]))['payment'];
        $this->assertSame(self::SUPPLIER['bank_account'], $transfer['bankAccount']);

        // Hotově / kartou: jen způsob úhrady (symboly zůstávají podle hlavičky).
        $cash = (new DocPaymentBlock())->build($this->context(['payment_method' => 0]))['payment'];
        $this->assertNull($cash['bankAccount']);
        $this->assertNull($cash['qr']);
        $this->assertSame(0, $cash['method']['id']);
        $this->assertSame('2026000123', $cash['reference']);
    }

    public function testPaymentWithoutPartiesStillBuilds(): void
    {
        // Prodejka převodem bez snapshotu odběratele: QR vznikne, země se nezná.
        $context = $this->context(customer: null);
        $this->assertNotNull((new DocPaymentBlock())->build($context)['payment']['qr']);

        // Výdajový doklad bez partnera nemá dodavatele vůbec.
        $context = $this->context(['payment_method' => 0], supplier: null);
        $this->assertNull((new DocPaymentBlock())->build($context)['payment']['bankAccount']);
        $this->assertSame([], $context->messages());
    }

    public function testMissingAccountGivesWarningInsteadOfQr(): void
    {
        $context = $this->context(supplier: ['name' => 'Dodavatel s.r.o.']);
        $payment = (new DocPaymentBlock())->build($context)['payment'];

        $this->assertNull($payment['bankAccount']);
        $this->assertNull($payment['qr']);
        $this->assertSame(
            [['severity' => 'warning', 'code' => 'payment.qrNoAccount', 'text' => 'QR chybí']],
            array_map(static fn ($m) => $m->toArray(), $context->messages()),
        );
    }

    public function testNonNumericReferenceIsLeftOutOfQrWithWarning(): void
    {
        $context = $this->context(['payment_reference' => 'FV-2026/123']);
        $payment = (new DocPaymentBlock())->build($context)['payment'];

        $this->assertSame('FV-2026/123', $payment['reference'], 'na dokladu VS zůstává');
        $this->assertStringNotContainsString('X-VS', $payment['qr']['payload']);
        $this->assertSame(
            [['severity' => 'warning', 'code' => 'payment.qrSkipped.paymentReference', 'text' => 'QR bez VS']],
            array_map(static fn ($m) => $m->toArray(), $context->messages()),
        );
    }

    // ── rows ────────────────────────────────────────────────────────────────

    public function testRowsBlock(): void
    {
        $rows = (new DocRowsBlock())->build($this->context(rows: [
            self::itemRow(['discount_pct' => '10.00']),
            ['row_kind' => 0, 'description' => 'Děkujeme za spolupráci.', 'unit' => null],
            self::itemRow([
                'operation' => 'sale.advanceDeduction', 'description' => 'Odpočet zálohy',
                'quantity' => null, 'unit' => null, 'unit_price' => 0.0, 'discount_pct' => '0.00',
                'vat_base' => -500.0, 'vat_amount' => -105.0, 'vat_total' => -605.0,
            ]),
        ]))['rows'];

        $this->assertSame([
            [
                'kind' => 'item', 'description' => 'Práce', 'quantity' => 2.0,
                'unit' => ['id' => 3, 'label' => 'ks'], 'unitPrice' => 500.0,
                'unitPriceIncludesVat' => false, 'discountPct' => 10.0,
                'vat' => ['code' => 'cz-120', 'pct' => 21.0, 'label' => 'Základní', 'noteMark' => null],
                'base' => 1000.0, 'vatAmount' => 210.0, 'total' => 1210.0,
                'advanceDeduction' => false,
            ],
            ['kind' => 'text', 'description' => 'Děkujeme za spolupráci.'],
            [
                'kind' => 'item', 'description' => 'Odpočet zálohy', 'quantity' => null,
                'unit' => null, 'unitPrice' => 0.0,
                'unitPriceIncludesVat' => false, 'discountPct' => null,
                'vat' => ['code' => 'cz-120', 'pct' => 21.0, 'label' => 'Základní', 'noteMark' => null],
                'base' => -500.0, 'vatAmount' => -105.0, 'total' => -605.0,
                'advanceDeduction' => true,
            ],
        ], $rows);
    }

    public function testRowsOfNonPayerHaveNoVatAndPriceModeFollowsHead(): void
    {
        $nonPayer = (new DocRowsBlock())->build($this->context(
            ['vat_registration' => null, 'vat_mode' => 0],
            [self::itemRow(['vat_code' => null, 'vat_pct' => null, 'vat_amount' => 0.0, 'vat_total' => 1000.0])],
        ))['rows'][0];
        $this->assertNull($nonPayer['vat']);
        $this->assertSame(1000.0, $nonPayer['total']);

        $fromTotal = (new DocRowsBlock())->build($this->context(['vat_mode' => 2], [self::itemRow()]))['rows'][0];
        $this->assertTrue($fromTotal['unitPriceIncludesVat']);
    }

    // ── vatRecap + vatNotes ─────────────────────────────────────────────────

    public function testRecapSkipsReversePairAndSharesNoteMarksWithRows(): void
    {
        $context = $this->context(
            rows: [
                self::itemRow(['vat_code' => 'cz-190']),
                self::itemRow(['vat_code' => 'cz-150']),
                self::itemRow(['vat_code' => 'cz-152']),
            ],
            recap: [
                ['vat_code' => 'cz-150', 'vat_pct' => 21.0, 'base' => 1000.0, 'tax' => 0.0, 'total' => 1000.0,
                 'base_dom' => 1000.0, 'tax_dom' => 0.0, 'total_dom' => 1000.0, 'is_reverse_pair' => 0],
                ['vat_code' => 'cz-150', 'vat_pct' => 21.0, 'base' => 1000.0, 'tax' => 210.0, 'total' => 1210.0,
                 'base_dom' => 1000.0, 'tax_dom' => 210.0, 'total_dom' => 1210.0, 'is_reverse_pair' => 1],
                ['vat_code' => 'cz-190', 'vat_pct' => 0.0, 'base' => 50.0, 'tax' => 0.0, 'total' => 50.0,
                 'base_dom' => 50.0, 'tax_dom' => 0.0, 'total_dom' => 50.0, 'is_reverse_pair' => 0],
            ],
        );

        $rows = (new DocRowsBlock())->build($context)['rows'];
        $data = (new DocVatRecapBlock())->build($context);

        // Značky v pořadí prvního použití; pdp4 a pdp5 mají stejný text → jedna značka.
        $this->assertSame(['1', '2', '2'], array_map(static fn (array $r) => $r['vat']['noteMark'], $rows));
        $this->assertSame('Základní - PDP 5', $rows[2]['vat']['label'], 'bez `print` → název kódu');

        $this->assertSame([
            ['label' => 'Základní - přenesení', 'pct' => 21.0, 'base' => 1000.0, 'tax' => 0.0, 'total' => 1000.0,
             'baseDom' => null, 'taxDom' => null, 'totalDom' => null, 'noteMark' => '2'],
            ['label' => 'EU/Zboží', 'pct' => 0.0, 'base' => 50.0, 'tax' => 0.0, 'total' => 50.0,
             'baseDom' => null, 'taxDom' => null, 'totalDom' => null, 'noteMark' => '1'],
        ], $data['vatRecap']);
        $this->assertSame([
            ['mark' => '1', 'text' => 'Osvobozeno podle § 64'],
            ['mark' => '2', 'text' => 'Daň odvede zákazník'],
        ], $data['vatNotes']);
    }

    public function testRecapCarriesHomeCurrencyOnlyForForeignDocument(): void
    {
        $recap = [['vat_code' => 'cz-120', 'vat_pct' => 21.0, 'base' => 1000.0, 'tax' => 210.0, 'total' => 1210.0,
                   'base_dom' => 24310.0, 'tax_dom' => 5105.1, 'total_dom' => 29415.1, 'is_reverse_pair' => 0]];

        $row = (new DocVatRecapBlock())->build($this->context(['doc_currency' => 'eur'], recap: $recap))['vatRecap'][0];

        $this->assertSame([24310.0, 5105.1, 29415.1], [$row['baseDom'], $row['taxDom'], $row['totalDom']]);
    }

    public function testNonPayerAndDocumentWithoutVatHaveNoRecap(): void
    {
        $recap = [['vat_code' => 'cz-120', 'vat_pct' => 21.0, 'base' => 1.0, 'tax' => 0.0, 'total' => 1.0, 'is_reverse_pair' => 0]];

        foreach ([['vat_registration' => null], ['vat_mode' => 0]] as $head) {
            $data = (new DocVatRecapBlock())->build($this->context($head, recap: $recap));
            $this->assertSame(['vatRecap' => [], 'vatNotes' => []], $data);
        }
    }

    public function testUnknownVatCodeFallsBackToCode(): void
    {
        $vatCodes = new DocVatCodes([], []);

        $this->assertSame('cz-999', $vatCodes->label('cz-999'));
        $this->assertNull($vatCodes->noteMark('cz-999'));
        $this->assertSame([], $vatCodes->notes());
    }

    // ── advances + totals ───────────────────────────────────────────────────

    public function testAdvancesAndTotals(): void
    {
        $context = $this->context(
            ['total_base' => 100.0, 'total_vat' => 21.0, 'total_amount' => 121.0, 'total_rounding' => '0.40'],
            [
                self::itemRow(),
                self::itemRow(['operation' => 'sale.advanceDeduction',
                    'vat_base' => -500.0, 'vat_amount' => -105.0, 'vat_total' => -605.0]),
                self::itemRow(['operation' => 'sale.advanceDeduction',
                    'vat_base' => -400.0, 'vat_amount' => -84.0, 'vat_total' => -484.0]),
            ],
        );

        $this->assertSame(
            ['advances' => ['base' => 900.0, 'vat' => 189.0, 'total' => 1089.0]],
            (new DocAdvancesBlock())->build($context),
        );
        $this->assertSame([
            'base' => 100.0, 'vat' => 21.0, 'rounding' => 0.4, 'total' => 121.0,
            'totalBeforeAdvances' => 1210.0,
            'baseDom' => null, 'vatDom' => null, 'totalDom' => null,
        ], (new DocTotalsBlock())->build($context)['totals']);
    }

    public function testNoAdvancesAndForeignTotals(): void
    {
        $context = $this->context(
            ['doc_currency' => 'eur', 'total_base_dom' => '24310.00', 'total_vat_dom' => '5105.10', 'total_amount_dom' => '29415.10'],
            [self::itemRow()],
        );

        $this->assertSame(['advances' => null], (new DocAdvancesBlock())->build($context));

        $totals = (new DocTotalsBlock())->build($context)['totals'];
        $this->assertSame(1210.0, $totals['totalBeforeAdvances']);
        $this->assertSame([24310.0, 5105.1, 29415.1], [$totals['baseDom'], $totals['vatDom'], $totals['totalDom']]);
    }
}
