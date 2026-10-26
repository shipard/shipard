<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Isdoc;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Isdoc\IsdocReader;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Isdoc\IsdocDocumentCollector;

/**
 * Čistý sběr ISDOC → canonical (#103 D76): kandidáti ze samostatných
 * příloh i embedded v PDF, validace schématu, dedup identitou a volba
 * zdroje — bez databáze. Reálný IsdocReader + SchemaValidator nad
 * fixtures; embedded extrakce přes seam (reálná vyžaduje pdfdetach).
 * Dřív detekční případy IsdocImportServiceTest (#81 D4).
 */
final class IsdocDocumentCollectorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../../../../Fixtures/Exchange/isdoc';
    private const MESSAGE_NDX = 42;

    private string $tmpDir;

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->tmpDir = sys_get_temp_dir() . '/shpd_isdoccoll_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir . '/att/m', 0777, true);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        foreach ((array) glob($this->tmpDir . '/att/m/*') as $file) {
            @unlink((string) $file);
        }
        @rmdir($this->tmpDir . '/att/m');
        @rmdir($this->tmpDir . '/att');
        @rmdir($this->tmpDir);
    }

    /** @return array<string, mixed> Návrat AttachmentService::upload (výřez). */
    private function storedAttachment(int $id, string $displayName, string $contents, string $mime): array
    {
        $storedName = "stored_{$id}_" . $displayName;
        file_put_contents($this->tmpDir . '/att/m/' . $storedName, $contents);
        return ['id' => $id, 'name' => $displayName, 'file_name' => $storedName, 'file_path' => 'm', 'mime_type' => $mime];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES . '/' . $name);
    }

    private function collector(): IsdocDocumentCollector
    {
        return new IsdocDocumentCollector(new SchemaValidator(SchemaLoader::default()), $this->tmpDir);
    }

    /** @param list<array<string, mixed>> $embedded canonicaly per PDF příloha */
    private function collectorWithEmbedded(array $embedded): IsdocDocumentCollector
    {
        return new class(new SchemaValidator(SchemaLoader::default()), $this->tmpDir, $embedded) extends IsdocDocumentCollector {
            /** @param list<array<string, mixed>> $embeddedCanonicals */
            public function __construct(SchemaValidator $schemaValidator, string $dsPath, private readonly array $embeddedCanonicals)
            {
                parent::__construct($schemaValidator, $dsPath);
            }

            protected function extractEmbeddedCandidates(int $messageNdx, array $file): array
            {
                return $this->embeddedCanonicals;
            }
        };
    }

    /** @return array<string, mixed> */
    private function parsedCanonical(string $fixtureName): array
    {
        return new IsdocReader()->fromXmlString($this->fixture($fixtureName));
    }

    public function testStandaloneIsdocGivesCanonicalWithStructuredAttachment(): void
    {
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $document = $this->collector()->collect(self::MESSAGE_NDX, $files);

        $this->assertNotNull($document);
        $this->assertSame(501, $document['attachmentId']);
        $this->assertSame('invoiceReceived', $document['docType']);
        $this->assertFalse($document['embedded']);
        $canonical = $document['canonical'];
        $this->assertSame('isdoc', $canonical['source']['kind']);
        $this->assertSame(self::MESSAGE_NDX, $canonical['source']['message']);
        $this->assertSame('FV-2026-0042', $canonical['docNumber']);
        $this->assertSame(
            [['filename' => 'faktura.isdoc', 'kind' => 'structured', 'ref' => 'att:501', 'mimeType' => 'application/xml']],
            $canonical['attachments'],
        );
    }

    public function testEmbeddedIsdocInPdfRefersToTheCarrierPdf(): void
    {
        $files = [$this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf')];

        $document = $this->collectorWithEmbedded([$this->parsedCanonical('invoice_min.isdoc')])->collect(self::MESSAGE_NDX, $files);

        $this->assertNotNull($document);
        $this->assertTrue($document['embedded']);
        $this->assertSame(500, $document['attachmentId']);
        $this->assertSame(
            [['filename' => 'faktura.pdf', 'kind' => 'original', 'ref' => 'att:500', 'mimeType' => 'application/pdf']],
            $document['canonical']['attachments'],
        );
    }

    public function testPdfWithoutEmbeddedIsdocGivesNothing(): void
    {
        // Běžné PDF (sken, AI faktura) → null → zpráva jde do AI fronty jako dřív.
        $files = [$this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf')];

        $this->assertNull($this->collectorWithEmbedded([])->collect(self::MESSAGE_NDX, $files));
    }

    public function testMalformedStandaloneIsdocAbortsTheWholeBranch(): void
    {
        $files = [
            $this->storedAttachment(501, 'ok.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'vadna.isdoc', '<Invoice xmlns="http://isdoc.cz/n"><ID>1', 'application/xml'),
        ];

        $this->assertNull($this->collector()->collect(self::MESSAGE_NDX, $files));
    }

    public function testForeignXmlNextToValidIsdocIsSilentlySkipped(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'data.xml', $this->fixture('foreign_root.xml'), 'text/xml'),
        ];

        $document = $this->collector()->collect(self::MESSAGE_NDX, $files);
        $this->assertNotNull($document);
        $this->assertSame(501, $document['attachmentId']);
    }

    public function testTwoDistinctIdentitiesGiveNothing(): void
    {
        $files = [
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
            $this->storedAttachment(502, 'dobropis.isdoc', $this->fixture('credit_note.isdoc'), 'application/xml'),
        ];

        $this->assertNull($this->collector()->collect(self::MESSAGE_NDX, $files));
    }

    public function testDuplicateIdentityPrefersTheStandaloneAttachment(): void
    {
        // Tatáž faktura jako .isdoc i embedded v PDF → jeden doklad, zdroj samostatná příloha.
        $files = [
            $this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf'),
            $this->storedAttachment(501, 'faktura.isdoc', $this->fixture('invoice_min.isdoc'), 'application/xml'),
        ];

        $document = $this->collectorWithEmbedded([$this->parsedCanonical('invoice_min.isdoc')])->collect(self::MESSAGE_NDX, $files);

        $this->assertNotNull($document);
        $this->assertFalse($document['embedded']);
        $this->assertSame(501, $document['attachmentId']);
    }

    public function testDedupMergesCandidatesWithSpacedIdentifiers(): void
    {
        // #118 D2: normalizace před dedupem — bez UUID se identita skládá z čísla
        // dokladu, DIČ / IČ a data; tatáž faktura s IČO zapsaným s mezerou je jedna.
        $a = $this->parsedCanonical('invoice_min.isdoc');
        unset($a['source']['raw']['uuid']);
        $b = $a;
        foreach (['taxId', 'vatId', 'companyId'] as $key) {
            if (is_string($b['supplier'][$key] ?? null)) {
                $b['supplier'][$key] = strtolower(wordwrap($b['supplier'][$key], 3, ' ', true));
            }
        }
        $files = [$this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf')];

        $document = $this->collectorWithEmbedded([$a, $b])->collect(self::MESSAGE_NDX, $files);

        $this->assertNotNull($document, 'odlišný zápis téže identity nesmí větev vzdát');
        $this->assertSame($a['supplier']['companyId'], $document['canonical']['supplier']['companyId']);
    }

    public function testEmbeddedFailingSchemaIsIgnored(): void
    {
        $broken = $this->parsedCanonical('invoice_min.isdoc');
        $broken['docType'] = ''; // minLength 1 → schema issue
        $files = [$this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf')];

        $this->assertNull($this->collectorWithEmbedded([$broken])->collect(self::MESSAGE_NDX, $files));
    }

    public function testNoCandidateGivesNothing(): void
    {
        $files = [$this->storedAttachment(503, 'scan.jpg', 'JFIF', 'image/jpeg')];

        $this->assertNull($this->collector()->collect(self::MESSAGE_NDX, $files));
        $this->assertNull($this->collector()->collect(self::MESSAGE_NDX, []));
    }

    public function testMissingPdfdetachDegradesToNoEmbeddedCandidates(): void
    {
        // Chybějící binárka → embedded detekce vypnutá (reálná exec cesta, exit 127), žádná výjimka.
        $files = [$this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf')];
        $collector = new class(new SchemaValidator(SchemaLoader::default()), $this->tmpDir) extends IsdocDocumentCollector {
            protected string $pdfdetachBin = 'shpd-nonexistent-pdfdetach';
        };

        $this->assertNull($collector->collect(self::MESSAGE_NDX, $files));
    }

    public function testExtractionFailurePropagates(): void
    {
        // Výjimky polyká volající (detect / tryImport / evaluace) — collector je nemaskuje.
        $files = [$this->storedAttachment(500, 'faktura.pdf', '%PDF-fake', 'application/pdf')];
        $collector = new class(new SchemaValidator(SchemaLoader::default()), $this->tmpDir) extends IsdocDocumentCollector {
            protected function extractEmbeddedCandidates(int $messageNdx, array $file): array
            {
                throw new \RuntimeException('pdfdetach exploded');
            }
        };

        $this->expectException(\RuntimeException::class);
        $collector->collect(self::MESSAGE_NDX, $files);
    }
}
