<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Isdoc;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\EInvoice\CountryNames;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceFormats;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceInputException;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceOptions;
use Shipard\Module\Core\Exchange\EInvoice\EInvoiceUuid;
use Shipard\Module\Core\Exchange\Isdoc\IsdocSchemaValidator;
use Shipard\Module\Core\Exchange\Isdoc\IsdocWriter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * IsdocWriter — canonical → ISDOC 6.0.1 (#119 D1–D7, D10). Fixtury jsou
 * syntetické vydané doklady (tests/Fixtures/Exchange/einvoice/), každý
 * výstup musí projít XSD 6.0.1 i 6.0.2.
 */
class IsdocWriterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/Exchange/einvoice';
    private const COUNTRIES = __DIR__ . '/../../../../../../modules/world/base/config/countries.jsonc';
    private const UUID = '03f8260d-4689-5cee-abab-e361a124160c';

    private IsdocWriter $writer;
    private IsdocSchemaValidator $xsd;

    protected function setUp(): void
    {
        $this->writer = new IsdocWriter();
        $this->xsd = new IsdocSchemaValidator();
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(self::FIXTURES . "/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
    }

    private function options(string $uuid = self::UUID): EInvoiceOptions
    {
        return new EInvoiceOptions($uuid, CountryNames::fromModuleFile(self::COUNTRIES));
    }

    private function write(string $name): string
    {
        return $this->writer->write($this->fixture($name), $this->options());
    }

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml, LIBXML_NONET));
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('i', IsdocWriter::NAMESPACE);
        return $xp;
    }

    private function text(\DOMXPath $xp, string $query): ?string
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes, $query);
        return $nodes->length > 0 ? $nodes->item(0)?->textContent : null;
    }

    private function nodeCount(\DOMXPath $xp, string $query): int
    {
        $nodes = $xp->query($query);
        $this->assertNotFalse($nodes, $query);
        return $nodes->length;
    }

    private function assertValidIsdoc(string $xml): void
    {
        $this->assertSame([], $this->xsd->validateAll($xml), 'výstup musí projít XSD 6.0.1 i 6.0.2');
    }

    // ── Fixtury: platný canonical, platné ISDOC, determinismus ──────────────

    /** @return iterable<string, array{string}> */
    public static function fixtures(): iterable
    {
        foreach (glob(self::FIXTURES . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            yield $name => [$name];
        }
    }

    #[DataProvider('fixtures')]
    public function testFixtureIsValidCanonicalAndWritesValidIsdoc(string $name): void
    {
        $canonical = $this->fixture($name);
        $issues = (new SchemaValidator(SchemaLoader::default()))
            ->validate($canonical, DocumentApplier::FORMAT_ID, DocumentApplier::FORMAT_VERSION);
        $this->assertSame([], $issues, 'fixtura musí být platný canonical');

        $xml = $this->writer->write($canonical, $this->options());
        $this->assertValidIsdoc($xml);
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertSame($xml, $this->writer->write($canonical, $this->options()), 'stejný vstup = stejné bajty');
        $this->assertStringNotContainsString(date('Y-m-d\TH'), $xml, 'žádné časové razítko');

        $xp = $this->xpath($xml);
        $this->assertSame(IsdocWriter::VERSION, $xp->document->documentElement?->getAttribute('version'));
        $this->assertSame(self::UUID, $this->text($xp, '/i:Invoice/i:UUID'));
        $this->assertSame('Shipard', $this->text($xp, '/i:Invoice/i:IssuingSystem'));
        $this->assertSame('CZK', $this->text($xp, '/i:Invoice/i:LocalCurrencyCode'));
        $this->assertSame('1', $this->text($xp, '/i:Invoice/i:CurrRate'));
        $this->assertSame('', $this->text($xp, '/i:Invoice/i:ElectronicPossibilityAgreementReference'));
    }

    public function testFormatRegistry(): void
    {
        $this->assertSame(['isdoc'], EInvoiceFormats::ids());
        $this->assertInstanceOf(IsdocWriter::class, EInvoiceFormats::forFormat('isdoc'));
        $this->assertSame('isdoc', $this->writer->format());
        $this->assertNull(EInvoiceFormats::forFormat('ubl'));
    }

    // ── Mapování ────────────────────────────────────────────────────────────

    public function testTwoRatesTransferWithDerivedIban(): void
    {
        $xp = $this->xpath($this->write('issued_two_rates'));

        $this->assertSame('1', $this->text($xp, '/i:Invoice/i:DocumentType'));
        $this->assertSame('FV-2026-0101', $this->text($xp, '/i:Invoice/i:ID'));
        $this->assertSame('2026-09-10', $this->text($xp, '/i:Invoice/i:IssueDate'));
        $this->assertSame('2026-09-10', $this->text($xp, '/i:Invoice/i:TaxPointDate'));
        $this->assertSame('true', $this->text($xp, '/i:Invoice/i:VATApplicable'));
        $this->assertSame('Zboží zůstává do úplného zaplacení majetkem dodavatele.', $this->text($xp, '/i:Invoice/i:Note'));

        $s = '/i:Invoice/i:AccountingSupplierParty/i:Party';
        $this->assertSame('12345678', $this->text($xp, "{$s}/i:PartyIdentification/i:ID"));
        $this->assertSame('Alfa Nábytek s.r.o.', $this->text($xp, "{$s}/i:PartyName/i:Name"));
        $this->assertSame('Výrobní', $this->text($xp, "{$s}/i:PostalAddress/i:StreetName"));
        $this->assertSame('1230/5', $this->text($xp, "{$s}/i:PostalAddress/i:BuildingNumber"));
        $this->assertSame('CZ', $this->text($xp, "{$s}/i:PostalAddress/i:Country/i:IdentificationCode"));
        $this->assertSame('Česko', $this->text($xp, "{$s}/i:PostalAddress/i:Country/i:Name"));
        $this->assertSame('CZ12345678', $this->text($xp, "{$s}/i:PartyTaxScheme/i:CompanyID"));
        $this->assertSame('VAT', $this->text($xp, "{$s}/i:PartyTaxScheme/i:TaxScheme"));
        $this->assertSame('C 12345 vedená u Krajského soudu v Brně', $this->text($xp, "{$s}/i:RegisterIdentification/i:Preformatted"));
        $this->assertSame('+420511222333', $this->text($xp, "{$s}/i:Contact/i:Telephone"));
        $this->assertSame('fakturace@alfa-nabytek.example', $this->text($xp, "{$s}/i:Contact/i:ElectronicMail"));
        $this->assertSame(0, $this->nodeCount($xp, "{$s}/i:Contact/i:Name"));
        $c = '/i:Invoice/i:AccountingCustomerParty/i:Party';
        $this->assertSame('87654321', $this->text($xp, "{$c}/i:PartyIdentification/i:ID"));
        $this->assertSame('Petr Odběratel', $this->text($xp, "{$c}/i:Contact/i:Name"));
        $this->assertSame(0, $this->nodeCount($xp, "{$c}/i:RegisterIdentification"));

        $l = '/i:Invoice/i:InvoiceLines/i:InvoiceLine';
        $this->assertSame(3, $this->nodeCount($xp, $l));
        $this->assertSame(['1', '2', '3'], array_map(
            static fn (\DOMNode $n): string => $n->textContent,
            iterator_to_array($xp->query("{$l}/i:ID") ?: new \DOMNodeList()),
        ));
        $this->assertSame('2', $this->text($xp, "{$l}[1]/i:InvoicedQuantity"));
        $this->assertSame('ks', $this->text($xp, "{$l}[1]/i:InvoicedQuantity/@unitCode"));
        $this->assertSame('24800.00', $this->text($xp, "{$l}[1]/i:LineExtensionAmount"));
        $this->assertSame('30008.00', $this->text($xp, "{$l}[1]/i:LineExtensionAmountTaxInclusive"));
        $this->assertSame('5208.00', $this->text($xp, "{$l}[1]/i:LineExtensionTaxAmount"));
        $this->assertSame('12400.00', $this->text($xp, "{$l}[1]/i:UnitPrice"));
        $this->assertSame('15004.00', $this->text($xp, "{$l}[1]/i:UnitPriceTaxInclusive"), 'cena s daní dopočtená');
        $this->assertSame('21', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:Percent"));
        $this->assertSame('0', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:VATCalculationMethod"));
        $this->assertSame(0, $this->nodeCount($xp, "{$l}[1]/i:LineExtensionAmountBeforeDiscount"));
        $this->assertSame('KS-180', $this->text($xp, "{$l}[1]/i:Item/i:SellersItemIdentification/i:ID"));
        $this->assertSame('8594012345678', $this->text($xp, "{$l}[2]/i:Item/i:CatalogueItemIdentification/i:ID"));
        $this->assertSame('Kniha, ISBN smyšlené', $this->text($xp, "{$l}[3]/i:Note"));
        $this->assertSame('12', $this->text($xp, "{$l}[3]/i:ClassifiedTaxCategory/i:Percent"));

        $t = '/i:Invoice/i:TaxTotal';
        $this->assertSame(2, $this->nodeCount($xp, "{$t}/i:TaxSubTotal"));
        $this->assertSame('21', $this->text($xp, "{$t}/i:TaxSubTotal[1]/i:TaxCategory/i:Percent"));
        $this->assertSame('37700.00', $this->text($xp, "{$t}/i:TaxSubTotal[1]/i:TaxableAmount"));
        $this->assertSame('7917.00', $this->text($xp, "{$t}/i:TaxSubTotal[1]/i:TaxAmount"));
        $this->assertSame('0.00', $this->text($xp, "{$t}/i:TaxSubTotal[1]/i:AlreadyClaimedTaxAmount"));
        $this->assertSame('7917.00', $this->text($xp, "{$t}/i:TaxSubTotal[1]/i:DifferenceTaxAmount"));
        $this->assertSame(0, $this->nodeCount($xp, "{$t}/i:TaxSubTotal/i:TaxCategory/i:LocalReverseChargeFlag"));
        $this->assertSame('8023.80', $this->text($xp, "{$t}/i:TaxAmount"));

        $m = '/i:Invoice/i:LegalMonetaryTotal';
        $this->assertSame('38590.00', $this->text($xp, "{$m}/i:TaxExclusiveAmount"));
        $this->assertSame('46613.80', $this->text($xp, "{$m}/i:TaxInclusiveAmount"));
        $this->assertSame('0.00', $this->text($xp, "{$m}/i:PayableRoundingAmount"));
        $this->assertSame('0.00', $this->text($xp, "{$m}/i:PaidDepositsAmount"));
        $this->assertSame('46613.80', $this->text($xp, "{$m}/i:PayableAmount"));

        $p = '/i:Invoice/i:PaymentMeans/i:Payment';
        $this->assertSame('46613.80', $this->text($xp, "{$p}/i:PaidAmount"));
        $this->assertSame('42', $this->text($xp, "{$p}/i:PaymentMeansCode"));
        $this->assertSame('2026-09-24', $this->text($xp, "{$p}/i:Details/i:PaymentDueDate"));
        $this->assertSame('123457', $this->text($xp, "{$p}/i:Details/i:ID"));
        $this->assertSame('0100', $this->text($xp, "{$p}/i:Details/i:BankCode"));
        $this->assertSame('', $this->text($xp, "{$p}/i:Details/i:Name"));
        $this->assertSame('CZ7001000000000000123457', $this->text($xp, "{$p}/i:Details/i:IBAN"), 'IBAN dopočtený');
        $this->assertSame('', $this->text($xp, "{$p}/i:Details/i:BIC"));
        $this->assertSame('20260101', $this->text($xp, "{$p}/i:Details/i:VariableSymbol"));
        $this->assertSame('0308', $this->text($xp, "{$p}/i:Details/i:ConstantSymbol"));
        $this->assertSame(0, $this->nodeCount($xp, "{$p}/i:Details/i:SpecificSymbol"));
    }

    public function testDomesticReverseCharge(): void
    {
        $xp = $this->xpath($this->write('issued_reverse_charge'));
        $l = '/i:Invoice/i:InvoiceLines/i:InvoiceLine';
        $this->assertSame('0', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:Percent"));
        $this->assertSame('4', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:LocalReverseCharge/i:LocalReverseChargeCode"));
        $this->assertSame('4', $this->text($xp, "{$l}[2]/i:ClassifiedTaxCategory/i:LocalReverseCharge/i:LocalReverseChargeCode"));
        $this->assertSame('true', $this->text($xp, '/i:Invoice/i:TaxTotal/i:TaxSubTotal[1]/i:TaxCategory/i:LocalReverseChargeFlag'));
        $this->assertSame('0.00', $this->text($xp, '/i:Invoice/i:TaxTotal/i:TaxAmount'));
        $this->assertSame('true', $this->text($xp, '/i:Invoice/i:VATApplicable'));
        $this->assertSame('CZ7001000000000000123457', $this->text($xp, '/i:Invoice/i:PaymentMeans/i:Payment/i:Details/i:IBAN'));
        $this->assertSame('KOMBCZPP', $this->text($xp, '/i:Invoice/i:PaymentMeans/i:Payment/i:Details/i:BIC'));
    }

    public function testNonPayerSupplierWithoutVat(): void
    {
        $xp = $this->xpath($this->write('issued_non_payer'));
        $this->assertSame('false', $this->text($xp, '/i:Invoice/i:VATApplicable'));
        $this->assertSame(0, $this->nodeCount($xp, '/i:Invoice/i:TaxPointDate'));
        $this->assertSame(0, $this->nodeCount($xp, '/i:Invoice/i:AccountingSupplierParty/i:Party/i:PartyTaxScheme'));
        $l = '/i:Invoice/i:InvoiceLines/i:InvoiceLine';
        $this->assertSame('0', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:Percent"));
        $this->assertSame('false', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:VATApplicable"));
        $this->assertSame('5800.00', $this->text($xp, "{$l}[1]/i:LineExtensionAmountTaxInclusive"));
        $this->assertSame('2900.00', $this->text($xp, "{$l}[1]/i:UnitPriceTaxInclusive"));
        $t = '/i:Invoice/i:TaxTotal';
        $this->assertSame(1, $this->nodeCount($xp, "{$t}/i:TaxSubTotal"));
        $this->assertSame('0', $this->text($xp, "{$t}/i:TaxSubTotal/i:TaxCategory/i:Percent"));
        $this->assertSame('false', $this->text($xp, "{$t}/i:TaxSubTotal/i:TaxCategory/i:VATApplicable"));
        $this->assertSame('6440.00', $this->text($xp, "{$t}/i:TaxSubTotal/i:TaxableAmount"));
        $this->assertSame('0.00', $this->text($xp, "{$t}/i:TaxSubTotal/i:TaxAmount"));
        $this->assertSame('0.00', $this->text($xp, "{$t}/i:TaxAmount"));
        $this->assertSame('6440.00', $this->text($xp, '/i:Invoice/i:LegalMonetaryTotal/i:TaxExclusiveAmount'));
        $this->assertSame('6440.00', $this->text($xp, '/i:Invoice/i:LegalMonetaryTotal/i:PayableAmount'));
    }

    public function testProformaWithoutTaxPointDate(): void
    {
        $xp = $this->xpath($this->write('proforma_issued'));
        $this->assertSame('4', $this->text($xp, '/i:Invoice/i:DocumentType'));
        $this->assertSame(0, $this->nodeCount($xp, '/i:Invoice/i:TaxPointDate'));
        $this->assertSame('true', $this->text($xp, '/i:Invoice/i:VATApplicable'));
    }

    public function testCardPaymentWithoutDetails(): void
    {
        $xp = $this->xpath($this->write('issued_card'));
        $this->assertSame('48', $this->text($xp, '/i:Invoice/i:PaymentMeans/i:Payment/i:PaymentMeansCode'));
        $this->assertSame('2178.00', $this->text($xp, '/i:Invoice/i:PaymentMeans/i:Payment/i:PaidAmount'));
        $this->assertSame(0, $this->nodeCount($xp, '/i:Invoice/i:PaymentMeans/i:Payment/i:Details'));
        $this->assertSame('1.5', $this->text($xp, '/i:Invoice/i:InvoiceLines/i:InvoiceLine[1]/i:InvoicedQuantity'));
    }

    public function testDiscountWritesAmountBeforeDiscount(): void
    {
        $xp = $this->xpath($this->write('issued_discount'));
        $l = '/i:Invoice/i:InvoiceLines/i:InvoiceLine';
        $this->assertSame('8892.00', $this->text($xp, "{$l}[1]/i:LineExtensionAmount"));
        $this->assertSame('9360.00', $this->text($xp, "{$l}[1]/i:LineExtensionAmountBeforeDiscount"), 'sleva 5 % procentem');
        $this->assertSame('2340.00', $this->text($xp, "{$l}[1]/i:UnitPrice"), 'jednotková cena před slevou');
        $this->assertSame('857.20', $this->text($xp, "{$l}[2]/i:LineExtensionAmount"));
        $this->assertSame('907.20', $this->text($xp, "{$l}[2]/i:LineExtensionAmountBeforeDiscount"), 'sleva částkou');
        $this->assertSame(0, $this->nodeCount($xp, "{$l}[3]/i:LineExtensionAmountBeforeDiscount"));
    }

    public function testTextAndSectionRowsAreSkipped(): void
    {
        $xp = $this->xpath($this->write('issued_text_row'));
        $l = '/i:Invoice/i:InvoiceLines/i:InvoiceLine';
        $this->assertSame(2, $this->nodeCount($xp, $l));
        $this->assertSame('1', $this->text($xp, "{$l}[1]/i:ID"));
        $this->assertSame('Hosting', $this->text($xp, "{$l}[1]/i:Item/i:Description"));
        $this->assertSame('měs', $this->text($xp, "{$l}[1]/i:InvoicedQuantity/@unitCode"));
        $this->assertSame('2', $this->text($xp, "{$l}[2]/i:ID"));
        $this->assertSame('Doména', $this->text($xp, "{$l}[2]/i:Item/i:Description"));
    }

    public function testPricesWithVatAndRounding(): void
    {
        $xp = $this->xpath($this->write('issued_prices_with_vat'));
        $l = '/i:Invoice/i:InvoiceLines/i:InvoiceLine';
        $this->assertSame('1', $this->text($xp, "{$l}[1]/i:ClassifiedTaxCategory/i:VATCalculationMethod"));
        $this->assertSame('38.90', $this->text($xp, "{$l}[1]/i:UnitPriceTaxInclusive"), 'cena s daní z canonicalu');
        $this->assertSame('32.15', $this->text($xp, "{$l}[1]/i:UnitPrice"), 'cena bez daně dopočtená');
        $this->assertSame('1239.67', $this->text($xp, "{$l}[1]/i:LineExtensionAmount"));
        $this->assertSame('1500.00', $this->text($xp, "{$l}[1]/i:LineExtensionAmountTaxInclusive"));
        $this->assertSame('38.56', $this->text($xp, "{$l}[1]/i:InvoicedQuantity"));
        $m = '/i:Invoice/i:LegalMonetaryTotal';
        $this->assertSame('1280.21', $this->text($xp, "{$m}/i:TaxExclusiveAmount"));
        $this->assertSame('1545.40', $this->text($xp, "{$m}/i:TaxInclusiveAmount"));
        $this->assertSame('-0.40', $this->text($xp, "{$m}/i:PayableRoundingAmount"));
        $this->assertSame('1545.00', $this->text($xp, "{$m}/i:PayableAmount"));
    }

    public function testNoteOmittedWhenEmptyAndUuidLowercased(): void
    {
        $canonical = $this->fixture('issued_card');
        $canonical['notes']['onDocument'] = '  ';
        $xml = $this->writer->write($canonical, $this->options(strtoupper(self::UUID)));
        $xp = $this->xpath($xml);
        $this->assertSame(0, $this->nodeCount($xp, '/i:Invoice/i:Note'));
        $this->assertSame(self::UUID, $this->text($xp, '/i:Invoice/i:UUID'));
        $this->assertValidIsdoc($xml);
    }

    public function testUuidFromNameFitsOptions(): void
    {
        $uuid = EInvoiceUuid::fromName('corpus/cz/vat-payer/0102-domestic-goods');
        $xml = $this->writer->write($this->fixture('issued_two_rates'), $this->options($uuid));
        $this->assertSame($uuid, $this->text($this->xpath($xml), '/i:Invoice/i:UUID'));
    }

    // ── Chyby vstupu (D2, D4, D5, D10) ──────────────────────────────────────

    public function testInputErrorsAreCollectedWithPaths(): void
    {
        $canonical = $this->fixture('issued_two_rates');
        unset($canonical['supplier']['companyId']);
        $canonical['customer']['address']['zip'] = '';
        $canonical['customer']['address']['country'] = 'xx';
        $canonical['currency'] = 'EUR';
        $canonical['exchangeRate'] = 24.5;
        unset($canonical['rows'][1]['computed']);
        $canonical['rows'][2]['vat']['pct'] = null;
        $canonical['dates']['taxPointDate'] = '10.9.2026';
        unset($canonical['totals']['totalBase']);
        $canonical['supplier']['bankAccount']['accountNumber'] = '123457';

        try {
            $this->writer->write($canonical, $this->options());
            $this->fail('neúplný vstup musí selhat');
        } catch (EInvoiceInputException $e) {
            $paths = $e->paths();
            foreach ([
                'supplier.companyId', 'customer.address.zip', 'customer.address.country', 'currency', 'exchangeRate',
                'rows[1].computed.vatBase', 'rows[1].computed.vatAmount', 'rows[1].computed.vatTotal',
                'rows[2].vat.pct', 'dates.taxPointDate', 'totals.totalBase', 'supplier.bankAccount.accountNumber',
            ] as $path) {
                $this->assertContains($path, $paths);
            }
            $this->assertStringContainsString('supplier.companyId: chybí', $e->getMessage());
            $this->assertStringContainsString('D4', $e->getMessage());
            $this->assertCount(count($paths), $e->lines());
        }
    }

    public function testUnsupportedDocTypeAndMissingRows(): void
    {
        $canonical = $this->fixture('issued_two_rates');
        $canonical['docType'] = 'creditNote';
        $canonical['rows'] = [];
        try {
            $this->writer->write($canonical, $this->options());
            $this->fail();
        } catch (EInvoiceInputException $e) {
            $this->assertSame(['docType', 'rows'], $e->paths());
            $this->assertStringContainsString('není podporován', $e->getMessage());
        }
    }

    public function testReverseChargeConsistency(): void
    {
        $canonical = $this->fixture('issued_reverse_charge');
        $canonical['rows'][0]['vat']['reverseChargeCode'] = null;
        $canonical['rows'][1]['vat']['reverseChargeCode'] = null;
        try {
            $this->writer->write($canonical, $this->options());
            $this->fail();
        } catch (EInvoiceInputException $e) {
            $this->assertSame(['vat.reverseCharge'], $e->paths());
        }

        $canonical = $this->fixture('issued_reverse_charge');
        $canonical['vat']['reverseCharge'] = false;
        try {
            $this->writer->write($canonical, $this->options());
            $this->fail();
        } catch (EInvoiceInputException $e) {
            $this->assertSame(['rows[0].vat.reverseChargeCode', 'rows[1].vat.reverseChargeCode'], $e->paths());
        }
    }

    public function testNonPayerDoesNotNeedTaxPointDateButInvoiceDoes(): void
    {
        $canonical = $this->fixture('issued_two_rates');
        $canonical['dates']['taxPointDate'] = null;
        try {
            $this->writer->write($canonical, $this->options());
            $this->fail();
        } catch (EInvoiceInputException $e) {
            $this->assertSame(['dates.taxPointDate'], $e->paths());
        }
        $this->assertValidIsdoc($this->write('issued_non_payer'));
    }

    public function testSchemaValidatorReportsBrokenOrder(): void
    {
        $xml = $this->write('issued_card');
        $broken = str_replace('</i:Invoice>', '', str_replace('<IssueDate>', '<VATApplicable>true</VATApplicable><IssueDate>', $xml));
        $errors = $this->xsd->validateAll($broken);
        $this->assertSame(['6.0.1', '6.0.2'], array_keys($errors));
        $this->assertStringContainsString('řádek', $errors['6.0.1'][0]);
        $this->assertNotSame([], $this->xsd->validate('<x/>', '6.0.2'));
        $this->expectException(\DomainException::class);
        $this->xsd->validate($xml, '5.0');
    }
}
