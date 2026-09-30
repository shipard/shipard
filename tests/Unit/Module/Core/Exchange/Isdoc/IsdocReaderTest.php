<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Isdoc;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Isdoc\IsdocParseException;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * IsdocReader — konverze ISDOC 6.x na canonical shpd.docs.document.v1.
 * Fixtures jsou syntetické (smyšlená firma, smyšlené IČO/DIČ/IBAN) —
 * viz tests/Fixtures/Exchange/isdoc/.
 */
class IsdocReaderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/Exchange/isdoc';

    private IsdocReader $reader;

    /** @var list<string> Dočasné soubory k úklidu. */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        $this->reader = new IsdocReader();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function readFixture(string $name): array
    {
        return $this->reader->fromXmlString($this->fixture($name));
    }

    /**
     * @param array<string, string> $entries entryName => obsah
     */
    private function makeZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd_isdocx_');
        $this->tmpFiles[] = $path;
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        return $path;
    }

    private function assertValidCanonical(array $canonical): void
    {
        $validator = new SchemaValidator(SchemaLoader::default());
        $issues = $validator->validate(
            $canonical,
            DocumentApplier::FORMAT_ID,
            DocumentApplier::FORMAT_VERSION,
        );
        $this->assertSame([], $issues, 'Canonical must pass schema validation');
    }

    // ── Minimální faktura ───────────────────────────────────────────────────

    public function testMinimalInvoice(): void
    {
        $canonical = $this->readFixture('invoice_min.isdoc');

        $this->assertSame('shpd.docs.document', $canonical['format']);
        $this->assertSame('1.0', $canonical['formatVersion']);
        $this->assertSame('invoiceReceived', $canonical['docType']);
        $this->assertSame('FV-2026-0042', $canonical['docNumber']);
        $this->assertSame('customer', $canonical['selfParty']);
        $this->assertSame('CZK', $canonical['currency']);
        $this->assertArrayNotHasKey('exchangeRate', $canonical);

        $this->assertSame('isdoc', $canonical['source']['kind']);
        $this->assertSame(1.0, $canonical['source']['confidence']);
        $this->assertSame('6.0.1', $canonical['source']['raw']['version']);
        $this->assertSame(1, $canonical['source']['raw']['documentType']);
        $this->assertSame('a1b2c3d4-0000-4111-8222-333344445555', $canonical['source']['raw']['uuid']);

        $this->assertSame('Testovací dodavatel s.r.o.', $canonical['supplier']['name']);
        $this->assertSame('12345678', $canonical['supplier']['companyId']);
        $this->assertSame('cz', $canonical['supplier']['country']);
        // Bez <Contact> je contact.name null a celý (prázdný) objekt prune() vypustí.
        $this->assertArrayNotHasKey('contact', $canonical['supplier']);
        $this->assertSame('2026-06-30', $canonical['dates']['issueDate']);

        $this->assertCount(1, $canonical['rows']);
        $row = $canonical['rows'][0];
        $this->assertSame('item', $row['rowKind']);
        $this->assertSame(1, $row['orderPos']);
        $this->assertSame(1.0, $row['quantity']);
        $this->assertSame('ks', $row['unit']);
        $this->assertSame(100.0, $row['unitPrice']);
        $this->assertSame(100.0, $row['totalPrice']);
        $this->assertSame('fromUnitPrice', $row['priceCalcMode']);
        $this->assertSame(21.0, $row['vat']['pct']);
        $this->assertArrayNotHasKey('code', $row['vat']);
        $this->assertSame('Testovací služba', $row['item']['name']);

        $this->assertSame(100.0, $canonical['totals']['totalBase']);
        $this->assertSame(21.0, $canonical['totals']['totalVat']);
        $this->assertSame(121.0, $canonical['totals']['totalAmount']);

        $this->assertValidCanonical($canonical);
    }

    // ── Dobropis (namespace s prefixem) ─────────────────────────────────────

    public function testCreditNoteWithPrefixedNamespace(): void
    {
        $canonical = $this->readFixture('credit_note.isdoc');

        $this->assertSame('creditNote', $canonical['docType']);
        $this->assertSame('DOB-2026-0003', $canonical['docNumber']);
        $this->assertSame(2, $canonical['source']['raw']['documentType']);
        $this->assertSame(-200.0, $canonical['totals']['totalBase']);
        $this->assertSame(-224.0, $canonical['totals']['totalAmount']);
        $this->assertSame(-1.0, $canonical['rows'][0]['quantity']);

        $this->assertValidCanonical($canonical);
    }

    // ── Plná faktura ────────────────────────────────────────────────────────

    public function testFullInvoice(): void
    {
        $canonical = $this->readFixture('invoice_full.isdoc');

        $this->assertSame('Fakturujeme vám za dodané zboží a služby.', $canonical['docText']);
        $this->assertSame('2026-07-01', $canonical['dates']['taxPointDate']);
        $this->assertSame('2026-07-15', $canonical['dates']['dueDate']);

        $supplier = $canonical['supplier'];
        $this->assertSame('CZ12345678', $supplier['taxId']);
        $this->assertSame('CZ12345678', $supplier['vatId']);
        $this->assertSame('C 99999 vedená u Krajského soudu v Testově', $supplier['courtRegistration']);
        $this->assertSame('Smyšlená', $supplier['address']['street']);
        $this->assertSame('12', $supplier['address']['houseNumber']);
        $this->assertSame('Testov', $supplier['address']['city']);
        $this->assertSame('10000', $supplier['address']['zip']);
        $this->assertSame('Jan Novák', $supplier['contact']['name']);
        $this->assertSame('fakturace@dodavatel.test', $supplier['contact']['email']);
        $this->assertSame('+420111222333', $supplier['contact']['phone']);
        $this->assertSame('123456789/0100', $supplier['bankAccount']['accountNumber']);
        $this->assertSame('CZ0001000000000123456789', $supplier['bankAccount']['iban']);
        $this->assertSame('TESTCZPP', $supplier['bankAccount']['bic']);

        $this->assertSame('Testovací odběratel a.s.', $canonical['customer']['name']);
        $this->assertSame('87654321', $canonical['customer']['companyId']);

        $this->assertSame('cz', $canonical['vat']['registrationCountry']);

        $payment = $canonical['payment'];
        $this->assertSame('bankTransfer', $payment['method']);
        $this->assertSame('2026100077', $payment['paymentReference']);
        $this->assertSame('0308', $payment['constantSymbol']);
        $this->assertSame('555', $payment['specificSymbol']);

        $this->assertCount(2, $canonical['rows']);
        $this->assertSame('SRV-KONZ', $canonical['rows'][0]['item']['supplierCode']);
        $this->assertSame(2, $canonical['rows'][1]['orderPos']);
        $this->assertSame(12.0, $canonical['rows'][1]['vat']['pct']);

        $this->assertCount(2, $canonical['vatRecap']);
        $this->assertSame(21.0, $canonical['vatRecap'][0]['vatPct']);
        $this->assertSame(2000.0, $canonical['vatRecap'][0]['base']);
        $this->assertSame(420.0, $canonical['vatRecap'][0]['tax']);
        $this->assertSame(2420.0, $canonical['vatRecap'][0]['total']);
        $this->assertSame(12.0, $canonical['vatRecap'][1]['vatPct']);

        $this->assertSame(3000.0, $canonical['totals']['totalBase']);
        $this->assertSame(540.0, $canonical['totals']['totalVat']);
        $this->assertSame(3540.0, $canonical['totals']['totalAmount']);
        $this->assertSame(0.0, $canonical['totals']['totalRounding']);

        $this->assertValidCanonical($canonical);
    }

    // ── Cizí měna ───────────────────────────────────────────────────────────

    public function testForeignCurrencyTakesCurrAmounts(): void
    {
        $canonical = $this->readFixture('invoice_eur.isdoc');

        $this->assertSame('EUR', $canonical['currency']);
        $this->assertSame(25.5, $canonical['exchangeRate']);

        $row = $canonical['rows'][0];
        $this->assertSame(100.0, $row['totalPrice']);
        // ISDOC nemá UnitPriceCurr (UnitPrice je v lokální měně) —
        // řádek v cizí měně nese jen totalPrice + fromTotal.
        $this->assertArrayNotHasKey('unitPrice', $row);
        $this->assertSame('fromTotal', $row['priceCalcMode']);

        $this->assertSame(100.0, $canonical['vatRecap'][0]['base']);
        $this->assertSame(21.0, $canonical['vatRecap'][0]['tax']);
        $this->assertSame(121.0, $canonical['vatRecap'][0]['total']);

        $this->assertSame(100.0, $canonical['totals']['totalBase']);
        $this->assertSame(21.0, $canonical['totals']['totalVat']);
        $this->assertSame(121.0, $canonical['totals']['totalAmount']);

        // IBAN bez čísla účtu — accountNumber se nevymýšlí
        $this->assertArrayNotHasKey('accountNumber', $canonical['supplier']['bankAccount']);
        $this->assertSame('CZ0001000000000123456789', $canonical['supplier']['bankAccount']['iban']);

        $this->assertValidCanonical($canonical);
    }

    // ── .isdocx (ZIP obal) ──────────────────────────────────────────────────

    public function testIsdocxZipArchive(): void
    {
        $path = $this->makeZip(['faktura.isdoc' => $this->fixture('invoice_min.isdoc')]);

        $canonical = $this->reader->fromFile($path, 'faktura.ISDOCX');

        $this->assertSame('invoiceReceived', $canonical['docType']);
        $this->assertSame('FV-2026-0042', $canonical['docNumber']);
    }

    public function testIsdocxWithoutIsdocEntryFails(): void
    {
        $path = $this->makeZip(['readme.txt' => 'no invoice here']);

        try {
            $this->reader->fromFile($path, 'archiv.isdocx');
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_INVALID_ZIP, $e->reason);
        }
    }

    public function testIsdocxCorruptedZipFails(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'shpd_isdocx_');
        $this->tmpFiles[] = $path;
        file_put_contents($path, 'this is definitely not a zip archive');

        try {
            $this->reader->fromFile($path, 'archiv.isdocx');
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_INVALID_ZIP, $e->reason);
        }
    }

    // ── Chybové vstupy ──────────────────────────────────────────────────────

    public function testMalformedXmlFails(): void
    {
        try {
            $this->reader->fromXmlString('<Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>1');
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_INVALID_XML, $e->reason);
        }
    }

    public function testEmptyStringFails(): void
    {
        try {
            $this->reader->fromXmlString('');
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_INVALID_XML, $e->reason);
        }
    }

    public function testForeignRootFails(): void
    {
        try {
            $this->reader->fromXmlString($this->fixture('foreign_root.xml'));
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_FOREIGN_ROOT, $e->reason);
        }
    }

    public function testUnsupportedDocumentTypeFails(): void
    {
        try {
            $this->reader->fromXmlString($this->fixture('doctype4.isdoc'));
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_UNSUPPORTED_DOC_TYPE, $e->reason);
            $this->assertStringContainsString('4', $e->getMessage());
        }
    }

    public function testMissingDocumentTypeFails(): void
    {
        $xml = '<?xml version="1.0"?><Invoice xmlns="http://isdoc.cz/namespace/2013"><ID>X</ID></Invoice>';
        try {
            $this->reader->fromXmlString($xml);
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_MISSING_ELEMENT, $e->reason);
            $this->assertStringContainsString('DocumentType', $e->getMessage());
        }
    }

    public function testMissingIdFails(): void
    {
        $xml = '<?xml version="1.0"?><Invoice xmlns="http://isdoc.cz/namespace/2013">'
            . '<DocumentType>1</DocumentType></Invoice>';
        try {
            $this->reader->fromXmlString($xml);
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_MISSING_ELEMENT, $e->reason);
            $this->assertStringContainsString("'ID'", $e->getMessage());
        }
    }

    public function testDtdIsRejected(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY x "y">]>'
            . '<Invoice xmlns="http://isdoc.cz/namespace/2013"><DocumentType>1</DocumentType><ID>A</ID></Invoice>';
        try {
            $this->reader->fromXmlString($xml);
            $this->fail('Expected IsdocParseException');
        } catch (IsdocParseException $e) {
            $this->assertSame(IsdocParseException::REASON_INVALID_XML, $e->reason);
        }
    }

    // ── fromFile s .isdoc na disku ──────────────────────────────────────────

    public function testFromFileReadsPlainIsdoc(): void
    {
        $canonical = $this->reader->fromFile(
            self::FIXTURES . '/invoice_min.isdoc',
            'faktura.isdoc',
        );
        $this->assertSame('invoiceReceived', $canonical['docType']);
    }
}
