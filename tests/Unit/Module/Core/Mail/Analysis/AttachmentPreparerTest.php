<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Analysis\AttachmentPreparer;
use Shipard\Module\Core\Mail\Analysis\PreparedAttachment;
use Shipard\Module\Core\Mail\Analysis\RawAttachment;

/**
 * Příprava příloh pro model (D16): třídění, ZIP, odhad typu, `.eml`
 * přeskočená, limity s prioritou a pořadím, dekódování textu. Čistá
 * část bez databáze (`prepareRaw`); `prepare()` jen nad mockem služby
 * příloh. Varování v logu jsou záměr — tlumí se na úroveň error.
 */
final class AttachmentPreparerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->tmpDir = sys_get_temp_dir() . '/shpd_att_prep_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function preparer(): AttachmentPreparer
    {
        return new AttachmentPreparer($this->createMock(AttachmentService::class));
    }

    /** @param array<string, string> $entries název → obsah */
    private function zipBytes(array $entries): string
    {
        $path = $this->tmpDir . '/' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        return (string) file_get_contents($path);
    }

    public function testPdfPassesThroughAsBase64(): void
    {
        $out = $this->preparer()->prepareRaw([new RawAttachment(1, 'invoice.pdf', 'application/pdf', '%PDF-1.4 fake body')]);

        $this->assertCount(1, $out);
        $this->assertSame(PreparedAttachment::KIND_PDF, $out[0]->kind);
        $this->assertSame('application/pdf', $out[0]->mimeType);
        $this->assertSame('%PDF-1.4 fake body', base64_decode((string) $out[0]->base64));
        $this->assertNull($out[0]->text);
        $this->assertSame(18, $out[0]->sizeBytes());
    }

    public function testImagePassesThroughWithNormalizedMime(): void
    {
        $out = $this->preparer()->prepareRaw([new RawAttachment(2, 'scan.png', 'image/PNG; charset=binary', "\x89PNG\r\n")]);

        $this->assertSame(PreparedAttachment::KIND_IMAGE, $out[0]->kind);
        $this->assertSame('image/png', $out[0]->mimeType);
    }

    public function testTextAttachmentIsDecoded(): void
    {
        $out = $this->preparer()->prepareRaw([new RawAttachment(3, 'notes.txt', 'text/plain', 'Pozn: faktura')]);

        $this->assertSame(PreparedAttachment::KIND_TEXT, $out[0]->kind);
        $this->assertSame('Pozn: faktura', $out[0]->text);
        $this->assertNull($out[0]->base64);
    }

    public function testUnsupportedTypesAreSkipped(): void
    {
        $out = $this->preparer()->prepareRaw([
            new RawAttachment(1, 'virus.exe', 'application/x-msdownload', "MZ\x90"),
            new RawAttachment(2, 'report.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', "PK\x03\x04"),
            new RawAttachment(3, 'data.json', 'application/json', '{}'),
        ]);

        $this->assertSame([], $out);
    }

    public function testForwardedEmlIsSkippedNotExpanded(): void
    {
        $eml = "From: a@example.com\r\nSubject: Fwd\r\nContent-Type: text/plain\r\n\r\nPosílám fakturu.";
        $out = $this->preparer()->prepareRaw([
            new RawAttachment(9, 'forwarded.eml', 'message/rfc822', $eml),
            new RawAttachment(10, 'also.eml', 'text/plain', $eml),
        ]);

        $this->assertSame([], $out);
    }

    public function testZipExpandsOneLevelAndInheritsParentNdx(): void
    {
        $zip = $this->zipBytes(['inv1.pdf' => '%PDF-1.4 first', 'inv2.pdf' => '%PDF-1.4 second', 'readme.txt' => 'hi', 'dir/' => '']);
        $out = $this->preparer()->prepareRaw([new RawAttachment(42, 'bundle.zip', 'application/zip', $zip)]);

        $this->assertCount(3, $out);
        $this->assertSame(['pdf', 'pdf', 'text'], array_map(static fn($a) => $a->kind, $out));
        $this->assertSame([42, 42, 42], array_map(static fn($a) => $a->ndx, $out));
        $this->assertSame(['bundle.zip/inv1.pdf', 'bundle.zip/inv2.pdf', 'bundle.zip/readme.txt'], array_map(static fn($a) => $a->filename, $out));
        $this->assertSame('%PDF-1.4 second', base64_decode((string) $out[1]->base64));
    }

    public function testZipByExtensionWithOctetStreamMime(): void
    {
        $zip = $this->zipBytes(['a.pdf' => '%PDF-1.4 a']);
        $out = $this->preparer()->prepareRaw([new RawAttachment(5, 'Faktury.ZIP', 'application/octet-stream', $zip)]);

        $this->assertCount(1, $out);
        $this->assertSame('Faktury.ZIP/a.pdf', $out[0]->filename);
    }

    public function testNestedZipIsSkipped(): void
    {
        $inner = $this->zipBytes(['a.pdf' => '%PDF-1.4 inner']);
        $outer = $this->zipBytes(['nested.zip' => $inner, 'top.pdf' => '%PDF-1.4 top']);
        $out = $this->preparer()->prepareRaw([new RawAttachment(1, 'bundle.zip', 'application/zip', $outer)]);

        $this->assertSame(['bundle.zip/top.pdf'], array_map(static fn($a) => $a->filename, $out));
    }

    public function testBadZipYieldsNothing(): void
    {
        $out = $this->preparer()->prepareRaw([new RawAttachment(1, 'broken.zip', 'application/zip', 'definitely not a zip')]);

        $this->assertSame([], $out);
    }

    public function testOctetStreamIsGuessedByExtension(): void
    {
        $out = $this->preparer()->prepareRaw([
            new RawAttachment(1, 'scan.JPG', 'application/octet-stream', 'jpegdata'),
            new RawAttachment(2, 'doc.pdf', '', '%PDF'),
            new RawAttachment(3, 'notes.csv', 'application/octet-stream', 'a;b'),
            new RawAttachment(4, 'blob.bin', 'application/octet-stream', 'x'),
        ]);

        $this->assertSame(['image', 'pdf', 'text'], array_map(static fn($a) => $a->kind, $out));
        $this->assertSame('image/jpeg', $out[0]->mimeType);
        $this->assertSame('application/pdf', $out[1]->mimeType);
    }

    public function testCountLimitKeepsPdfsOverTextAndRestoresOrder(): void
    {
        $raw = [];
        $raw[] = new RawAttachment(100, 'memo.txt', 'text/plain', 'unrelated note');
        for ($i = 1; $i <= AttachmentPreparer::MAX_ATTACHMENTS; $i++) {
            $raw[] = new RawAttachment($i, "inv{$i}.pdf", 'application/pdf', '%PDF-1.4 ' . str_repeat('x', $i));
        }
        $raw[] = new RawAttachment(200, 'photo.png', 'image/png', 'png');

        $out = $this->preparer()->prepareRaw($raw);

        $this->assertCount(AttachmentPreparer::MAX_ATTACHMENTS, $out);
        $this->assertSame(range(1, AttachmentPreparer::MAX_ATTACHMENTS), array_map(static fn($a) => $a->ndx, $out));
    }

    public function testCountLimitPrefersLargerWithinSamePriority(): void
    {
        $raw = [new RawAttachment(0, 'tiny.pdf', 'application/pdf', '%PDF')];
        for ($i = 1; $i <= AttachmentPreparer::MAX_ATTACHMENTS; $i++) {
            $raw[] = new RawAttachment($i, "big{$i}.pdf", 'application/pdf', '%PDF-1.4 ' . str_repeat('x', 100));
        }

        $out = $this->preparer()->prepareRaw($raw);

        $this->assertNotContains(0, array_map(static fn($a) => $a->ndx, $out));
    }

    public function testTotalSizeBudgetDropsLowerPriorityFirst(): void
    {
        $big = str_repeat('X', 12 * 1024 * 1024); // 12 MiB → 16 MiB base64
        $text = str_repeat('t', 15 * 1024 * 1024); // 15 MiB textu = 15 MiB v požadavku
        $out = $this->preparer()->prepareRaw([
            new RawAttachment(1, 'a.pdf', 'application/pdf', $big),
            new RawAttachment(2, 'b.pdf', 'application/pdf', $big),
            new RawAttachment(3, 'c.txt', 'text/plain', $text),
        ]);

        // 30 MiB rozpočet: po prvním PDF (16 MiB) se nevejde druhé PDF (16) ani text (15).
        $this->assertSame([1], array_map(static fn($a) => $a->ndx, $out));
    }

    public function testOversizedAttachmentIsKept(): void
    {
        $out = $this->preparer()->prepareRaw([
            new RawAttachment(1, 'huge.pdf', 'application/pdf', str_repeat('X', AttachmentPreparer::WARN_ATTACHMENT_BYTES + 1)),
        ]);

        $this->assertCount(1, $out);
    }

    public function testDecodeTextChain(): void
    {
        $this->assertSame('Příloha', AttachmentPreparer::decodeText('Příloha'));
        $this->assertSame('Příloha', AttachmentPreparer::decodeText((string) iconv('UTF-8', 'CP1250', 'Příloha')));
        $this->assertSame('Příloha', AttachmentPreparer::decodeText("\xFF\xFE" . mb_convert_encoding('Příloha', 'UTF-16LE', 'UTF-8')));
        $this->assertSame('Příloha', AttachmentPreparer::decodeText("\xFE\xFF" . mb_convert_encoding('Příloha', 'UTF-16BE', 'UTF-8')));
        // Byte 0x81 není ve windows-1250 definovaný → latin-1 nikdy neselže.
        $this->assertSame("a\u{81}b", AttachmentPreparer::decodeText("a\x81b"));
        $this->assertSame('', AttachmentPreparer::decodeText(''));
    }

    public function testPrepareReadsFilesAndSkipsRawSourceAndMissing(): void
    {
        $pdfPath = $this->tmpDir . '/inv.pdf';
        file_put_contents($pdfPath, '%PDF-1.4 from disk');
        $service = $this->createMock(AttachmentService::class);
        $service->method('listAttachments')->with(AttachmentPreparer::MAIL_TABLE_ID, 7)->willReturn([
            ['id' => 1, 'name' => 'inv.pdf', 'mime_type' => 'application/pdf', 'file_path' => 'x', 'file_name' => 'inv.pdf'],
            ['id' => 2, 'name' => 'message.eml', 'mime_type' => 'message/rfc822', 'file_path' => 'x', 'file_name' => 'raw.eml'],
            ['id' => 3, 'name' => 'gone.pdf', 'mime_type' => 'application/pdf', 'file_path' => 'x', 'file_name' => 'gone.pdf'],
        ]);
        $service->method('getFilePath')->willReturnCallback(
            fn(array $att): string => $att['file_name'] === 'inv.pdf' ? $pdfPath : $this->tmpDir . '/missing',
        );

        $out = new AttachmentPreparer($service)->prepare(7, 2);

        $this->assertCount(1, $out);
        $this->assertSame(1, $out[0]->ndx);
        $this->assertSame('%PDF-1.4 from disk', base64_decode((string) $out[0]->base64));
    }
}
