<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Mail\Exception\MailComposeException;
use Shipard\Core\Mail\MailComposer;
use Shipard\Module\Core\Attachments\AttachmentService;

class MailComposerTest extends TestCase
{
    private string $tmpFile = '';

    protected function tearDown(): void
    {
        if ($this->tmpFile !== '' && is_file($this->tmpFile)) {
            unlink($this->tmpFile);
        }
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'id'          => 42,
            'email_from'  => 'noreply@firma.cz',
            'email_to'    => 'user@example.com',
            'subject'     => 'Testovací zpráva',
            'body_text'   => 'Ahoj.',
            'body_html'   => null,
            'attachments' => null,
        ], $overrides);
    }

    public function testTextAndHtmlAlternative(): void
    {
        $composer = new MailComposer($this->createMock(AttachmentService::class));

        $email = $composer->compose($this->baseRow([
            'body_html' => '<p>Ahoj.</p>',
        ]));

        $this->assertSame('noreply@firma.cz', $email->getFrom()[0]->getAddress());
        $this->assertSame('user@example.com', $email->getTo()[0]->getAddress());
        $this->assertSame('Testovací zpráva', $email->getSubject());
        $this->assertSame('Ahoj.', $email->getTextBody());
        $this->assertSame('<p>Ahoj.</p>', $email->getHtmlBody());
    }

    public function testRecipientListCopiesAndFromName(): void
    {
        $composer = new MailComposer($this->createMock(AttachmentService::class));

        $email = $composer->compose($this->baseRow([
            'email_from_name' => 'Firma s.r.o.',
            'email_to'        => 'ucetni@odberatel.cz, fakturace@odberatel.cz',
            'email_cc'        => 'obchod@firma.cz',
        ]));

        $this->assertSame('Firma s.r.o.', $email->getFrom()[0]->getName());
        $this->assertSame(
            ['ucetni@odberatel.cz', 'fakturace@odberatel.cz'],
            array_map(static fn ($a) => $a->getAddress(), $email->getTo()),
        );
        $this->assertSame('obchod@firma.cz', $email->getCc()[0]->getAddress());
    }

    public function testRowWithoutNameAndCopiesComposesAsBefore(): void
    {
        $composer = new MailComposer($this->createMock(AttachmentService::class));

        // Řádek zařazený před rozšířením fronty sloupce jména a kopií nemá.
        $email = $composer->compose($this->baseRow());

        $this->assertSame('', $email->getFrom()[0]->getName());
        $this->assertCount(1, $email->getTo());
        $this->assertSame([], $email->getCc());
    }

    public function testRowWithoutRecipientThrows(): void
    {
        $composer = new MailComposer($this->createMock(AttachmentService::class));

        $this->expectException(MailComposeException::class);
        $this->expectExceptionMessageMatches('/no recipient/');

        $composer->compose($this->baseRow(['email_to' => '']));
    }

    public function testEmptyBodyThrows(): void
    {
        $composer = new MailComposer($this->createMock(AttachmentService::class));

        $this->expectException(MailComposeException::class);
        $this->expectExceptionMessageMatches('/no body/');

        $composer->compose($this->baseRow(['body_text' => null]));
    }

    public function testAttachmentIsResolved(): void
    {
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'shpd-att-');
        file_put_contents($this->tmpFile, '%PDF-1.4 fake');

        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('getAttachment')->with(7)->willReturn([
            'id'         => 7,
            'name'       => 'faktura.pdf',
            'file_name'  => 'faktura-abc.pdf',
            'file_path'  => '2026/07/13/core_mail_outbox',
            'mime_type'  => 'application/pdf',
            'is_deleted' => 0,
        ]);
        $attachments->method('getFilePath')->willReturn($this->tmpFile);

        $composer = new MailComposer($attachments);
        $email = $composer->compose($this->baseRow(['attachments' => '[7]']));

        $parts = $email->getAttachments();
        $this->assertCount(1, $parts);
        $this->assertSame('faktura.pdf', $parts[0]->getFilename());
    }

    public function testMissingAttachmentRowThrows(): void
    {
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('getAttachment')->willReturn(null);

        $composer = new MailComposer($attachments);

        $this->expectException(MailComposeException::class);
        $this->expectExceptionMessageMatches('/attachment 9 not found/');

        $composer->compose($this->baseRow(['attachments' => '[9]']));
    }

    public function testDeletedAttachmentThrows(): void
    {
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('getAttachment')->willReturn([
            'id' => 9, 'name' => 'x', 'file_name' => 'x', 'file_path' => 'x',
            'mime_type' => 'text/plain', 'is_deleted' => 1,
        ]);

        $composer = new MailComposer($attachments);

        $this->expectException(MailComposeException::class);
        $this->expectExceptionMessageMatches('/attachment 9 not found/');

        $composer->compose($this->baseRow(['attachments' => '[9]']));
    }

    public function testAttachmentFileMissingOnDiskThrows(): void
    {
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('getAttachment')->willReturn([
            'id' => 9, 'name' => 'x', 'file_name' => 'x', 'file_path' => 'x',
            'mime_type' => 'text/plain', 'is_deleted' => 0,
        ]);
        $attachments->method('getFilePath')->willReturn('/nonexistent/path/file.pdf');

        $composer = new MailComposer($attachments);

        $this->expectException(MailComposeException::class);
        $this->expectExceptionMessageMatches('/file missing/');

        $composer->compose($this->baseRow(['attachments' => '[9]']));
    }

    public function testInvalidAttachmentsJsonThrows(): void
    {
        $composer = new MailComposer($this->createMock(AttachmentService::class));

        $this->expectException(MailComposeException::class);
        $this->expectExceptionMessageMatches('/invalid attachments JSON/');

        $composer->compose($this->baseRow(['attachments' => 'not-json']));
    }
}
