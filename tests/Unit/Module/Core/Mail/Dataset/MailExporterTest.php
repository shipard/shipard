<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Dataset\MailExporter;

class MailExporterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function messageRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 55, 'message_id' => 'MSG-20260601-0003', 'mailbox' => 1, 'primary_type' => 'invoiceReceived',
            'primary_type_source' => 'ai', 'subject' => 'Faktura 2026031', 'sender_email' => 'fakturace@dodavatel.example',
            'sender_name' => 'Dodavatel a.s.', 'sender_person' => 5,
            'received_at' => new \DateTimeImmutable('2026-06-01 07:55:10'),
            'external_message_id' => '<abc@dodavatel.example>', 'in_reply_to' => null, 'reply_references' => null,
            'is_bulk' => 0, 'body_plain' => "Dobrý den,\nv příloze faktura.", 'body_html' => '<p>Dobrý den</p>',
            'raw_source_attachment' => 91, 'target_table_id' => 'docs_core_heads', 'target_row' => 100,
            'source_type' => 2, 'analysis_state' => 30, 'ai_analysis_enabled' => null, 'needs_reanalysis' => 0,
            'profile_override' => null, 'ai_title' => null, 'attention' => null, 'action_note' => null, 'action_due' => null,
            'created' => new \DateTimeImmutable('2026-06-01 07:55:11'), 'created_by' => null,
            'auto_disposed_by' => null, 'auto_disposed_at' => null, 'docState' => 40, 'docStateMain' => 3,
            'mailbox_code' => 'default', 'profile_override_code' => null,
        ], $overrides);
    }

    /** @return list<array<string, mixed>> */
    private function attachmentRows(): array
    {
        return [
            ['id' => 91, 'name' => 'original.eml', 'file_name' => 'original-a1b2c.eml', 'file_path' => '2026/06/01/core_mail_incoming_messages',
             'file_size' => 20000, 'mime_type' => 'message/rfc822', 'checksum' => str_repeat('e', 64), 'att_order' => 0],
            ['id' => 92, 'name' => 'faktura.pdf', 'file_name' => 'faktura-x9y8z.pdf', 'file_path' => '2026/06/01/core_mail_incoming_messages',
             'file_size' => 54321, 'mime_type' => 'application/pdf', 'checksum' => str_repeat('f', 64), 'att_order' => 1],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function analysisRows(): array
    {
        return [[
            'id' => 7, 'message' => 55, 'profile' => 3, 'backend' => 1,
            'analyzed_at' => new \DateTimeImmutable('2026-06-01 08:00:00'), 'status' => 2,
            'model_name' => 'claude-sonnet-4-5', 'model_version' => '20260101', 'prompt_version' => 'v4.2.0',
            'analysis_json' => '{"overall_confidence":0.92,"message_classification":{"primary_type":"invoiceReceived","confidence":0.97},"secondary_findings":[]}',
            'canonical_json' => '{"format":"shpd.docs.document","formatVersion":"1.0","docType":"invoiceReceived","customer":null,'
                . '"attachments":[{"filename":"faktura.pdf","kind":"original","ref":"att:92"}],"source":{"kind":"aiExtraction","raw":{}}}',
            'confidence' => '0.950', 'proposed_type' => 'invoiceReceived', 'content_tag' => 'it.software', 'error_message' => null,
            'resolution' => 40, 'rejected_reason' => null, 'resolved_at' => new \DateTimeImmutable('2026-06-01 09:00:00'), 'resolved_by' => 1,
            'tokens_input' => 12000, 'tokens_output' => 900, 'duration_ms' => 4300, 'cost_usd' => '0.048000',
            'created' => new \DateTimeImmutable('2026-06-01 08:00:01'), 'created_by' => null,
            'profile_code' => 'czech_general',
        ]];
    }

    private function db(array $attachments, array $analyses, array $fetch = []): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (string $sql) use ($attachments, $analyses): array {
            $rows = match (true) {
                str_contains($sql, '[core_attachments_files]')      => $attachments,
                str_contains($sql, '[core_mail_message_analyses]')  => $analyses,
                default => [],
            };
            return array_map(static fn(array $r) => new Row($r), $rows);
        });
        $db->method('fetch')->willReturnCallback(function (string $sql) use ($fetch): ?Row {
            foreach ($fetch as $needle => $row) {
                if (str_contains($sql, $needle)) {
                    return $row === null ? null : new Row($row);
                }
            }
            return null;
        });
        return $db;
    }

    public function testMessageWithAnalysisAndAttachmentsMapsAndValidates(): void
    {
        $db = $this->db($this->attachmentRows(), $this->analysisRows(), [
            '[base_persons_persons]' => ['full_name' => 'Dodavatel a.s.', 'company_id' => '87654321', 'tax_id' => null, 'vat_id' => 'CZ87654321'],
            '[docs_core_heads]'      => ['doc_number' => 'FP-2026-0007'],
        ]);
        $exporter = new MailExporter($db, '/opt/ds');
        $record = $exporter->exportMessage($this->messageRow());
        $d = $record->data;

        $this->assertSame(55, $record->id);
        $this->assertSame('MSG-20260601-0003', $record->slug);
        $this->assertSame('shpd.mail.incomingMessage', $d['format']);
        $this->assertSame('MSG-20260601-0003', $d['messageId']);
        $this->assertSame('default', $d['mailbox']);
        $this->assertSame('invoiceReceived', $d['primaryType']);
        $this->assertSame('ai', $d['primaryTypeSource']);
        $this->assertSame('Faktura 2026031', $d['subject']);
        $this->assertSame(['name' => 'Dodavatel a.s.', 'companyId' => '87654321', 'vatId' => 'CZ87654321'], $d['senderPerson']);
        $this->assertSame('2026-06-01T07:55:10', $d['receivedAt']);
        $this->assertArrayNotHasKey('isBulk', $d);
        $this->assertSame("Dobrý den,\nv příloze faktura.", $d['bodyPlain']);
        $this->assertSame(2, $d['sourceType']);
        $this->assertSame(40, $d['docState']);
        $this->assertSame(30, $d['analysisState']);
        $this->assertSame(['table' => 'docs_core_heads', 'docNumber' => 'FP-2026-0007'], $d['target']);

        $this->assertCount(2, $d['attachments']);
        $this->assertSame(['file' => 'original.eml', 'name' => 'original.eml', 'mimeType' => 'message/rfc822', 'size' => 20000,
                           'sha256' => str_repeat('e', 64), 'isRawSource' => true, 'order' => 0], $d['attachments'][0]);
        $this->assertSame('faktura.pdf', $d['attachments'][1]['file']);
        $this->assertArrayNotHasKey('isRawSource', $d['attachments'][1]);
        $this->assertSame('/opt/ds/att/2026/06/01/core_mail_incoming_messages/faktura-x9y8z.pdf', $record->files[1]->sourcePath);
        $this->assertSame(92, $record->files[1]->attachmentId);

        $a = $d['analyses'][0];
        $this->assertSame('2026-06-01T08:00:00', $a['analyzedAt']);
        $this->assertSame(2, $a['status']);
        $this->assertSame('claude-sonnet-4-5', $a['modelName']);
        $this->assertSame('v4.2.0', $a['promptVersion']);
        $this->assertSame('czech_general', $a['profile']);
        $this->assertSame(0.95, $a['confidence']);
        $this->assertSame(40, $a['resolution']);
        $this->assertSame('2026-06-01T09:00:00', $a['resolvedAt']);
        $this->assertSame(0.048, $a['costUsd']);
        $this->assertInstanceOf(\stdClass::class, $a['analysisJson']);
        $this->assertSame(0.92, $a['analysisJson']->overall_confidence);
        $this->assertSame([], $a['analysisJson']->secondary_findings, 'arrays inside blobs stay arrays');

        $c = $a['canonicalJson'];
        $this->assertInstanceOf(\stdClass::class, $c);
        $this->assertNull($c->customer, 'blob content is verbatim — nulls are not pruned');
        $this->assertSame('att:2', $c->attachments[0]->ref, 'att:<id> rewritten to 1-based position in attachments[]');
        $this->assertInstanceOf(\stdClass::class, $c->source->raw, 'empty object stays an object');
        $this->assertStringContainsString('"raw": {}', DatasetWriter::encode($d));

        $this->assertSame([], $exporter->getWarnings());

        $validator = new SchemaValidator(new SchemaLoader(dirname(__DIR__, 6) . '/modules/core/mail/schemas'));
        $decoded = json_decode(DatasetWriter::encode($d), true);
        $this->assertSame([], $validator->validate($decoded, 'shpd.mail.incomingMessage', '1'), 'exported message must validate against the mail schema');
    }

    public function testUnknownAttachmentRefAndMissingTargetProduceWarnings(): void
    {
        $analyses = $this->analysisRows();
        $analyses[0]['canonical_json'] = '{"attachments":[{"ref":"att:999"}]}';
        $db = $this->db($this->attachmentRows(), $analyses, ['[docs_core_heads]' => null]);
        $exporter = new MailExporter($db, '/opt/ds');

        $d = $exporter->exportMessage($this->messageRow(['sender_person' => null]))->data;

        $this->assertSame('att:999', $d['analyses'][0]['canonicalJson']->attachments[0]->ref);
        $this->assertArrayNotHasKey('target', $d);
        $this->assertArrayNotHasKey('senderPerson', $d);
        $warnings = $exporter->getWarnings();
        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('přílohu #999', $warnings[0]);
        $this->assertStringContainsString('cílový doklad #100 neexistuje', $warnings[1]);
    }

    public function testRegistryTargetCarriesTitleAndCreated(): void
    {
        $db = $this->db([], [], [
            '[base_registry_documents]' => ['title' => 'Smlouva o dílo', 'created' => new \DateTimeImmutable('2026-02-01 09:30:00')],
        ]);
        $d = (new MailExporter($db, '/opt/ds'))->exportMessage($this->messageRow([
            'target_table_id' => 'base_registry_documents', 'target_row' => 30, 'raw_source_attachment' => null,
        ]))->data;

        $this->assertSame(['table' => 'base_registry_documents', 'title' => 'Smlouva o dílo', 'created' => '2026-02-01T09:30:00'], $d['target']);
        $this->assertArrayNotHasKey('attachments', $d);
        $this->assertArrayNotHasKey('analyses', $d);
    }

    public function testFailedAnalysisWithoutBlobsAndInvalidJsonWarning(): void
    {
        $analyses = $this->analysisRows();
        $analyses[0]['status'] = 3;
        $analyses[0]['analysis_json'] = null;
        $analyses[0]['canonical_json'] = 'not json';
        $analyses[0]['error_message'] = '[timeout] upstream';
        $analyses[0]['resolution'] = null;
        $exporter = new MailExporter($this->db([], $analyses), '/opt/ds');

        $a = $exporter->exportMessage($this->messageRow(['target_table_id' => null, 'target_row' => null, 'raw_source_attachment' => null]))->data['analyses'][0];

        $this->assertSame(3, $a['status']);
        $this->assertSame('[timeout] upstream', $a['errorMessage']);
        $this->assertArrayNotHasKey('analysisJson', $a);
        $this->assertArrayNotHasKey('canonicalJson', $a);
        $this->assertArrayNotHasKey('resolution', $a);
        $this->assertCount(1, $exporter->getWarnings());
        $this->assertStringContainsString('canonical_json', $exporter->getWarnings()[0]);
    }

    public function testMissingMailboxFallsBackToDefaultWithWarning(): void
    {
        $exporter = new MailExporter($this->db([], []), '/opt/ds');
        $d = $exporter->exportMessage($this->messageRow(['mailbox_code' => null, 'target_table_id' => null, 'target_row' => null]))->data;

        $this->assertSame('default', $d['mailbox']);
        $this->assertCount(1, $exporter->getWarnings());
    }

    // ── pozornost u zprávy bez dokladu (#105, demo sada #131) ──────────────

    private function mailSchemaValidator(): SchemaValidator
    {
        return new SchemaValidator(new SchemaLoader(dirname(__DIR__, 6) . '/modules/core/mail/schemas'));
    }

    public function testAttentionFieldsFollowAiTitleAndValidate(): void
    {
        $exporter = new MailExporter($this->db([], []), '/opt/ds');
        $d = $exporter->exportMessage($this->messageRow([
            'primary_type' => 'other', 'ai_title' => 'Expirace domény firma.example',
            'attention' => 'action', 'action_note' => 'Prodloužit doménu u registrátora.',
            'action_due' => new \DateTimeImmutable('2026-07-15 00:00:00'),
            'target_table_id' => null, 'target_row' => null, 'raw_source_attachment' => null,
        ]))->data;

        $this->assertSame('action', $d['attention']);
        $this->assertSame('Prodloužit doménu u registrátora.', $d['actionNote']);
        $this->assertSame('2026-07-15', $d['actionDue'], 'date column → Y-m-d, no time part');

        $keys = array_keys($d);
        $at = array_search('aiTitle', $keys, true);
        $this->assertIsInt($at);
        $this->assertSame(
            ['aiTitle', 'attention', 'actionNote', 'actionDue', 'created'],
            array_slice($keys, $at, 5),
            'attention keys sit right after aiTitle and before created',
        );

        $decoded = json_decode(DatasetWriter::encode($d), true);
        $this->assertSame([], $this->mailSchemaValidator()->validate($decoded, 'shpd.mail.incomingMessage', '1'));
    }

    public function testActionDueAsDbStringIsExportedAsDate(): void
    {
        $d = (new MailExporter($this->db([], []), '/opt/ds'))->exportMessage($this->messageRow([
            'attention' => 'action', 'action_due' => '2026-07-15',
            'target_table_id' => null, 'target_row' => null, 'raw_source_attachment' => null,
        ]))->data;

        $this->assertSame('2026-07-15', $d['actionDue']);
        $this->assertArrayNotHasKey('actionNote', $d);
    }

    public function testMessageWithoutAttentionHasNoAttentionKeys(): void
    {
        $d = (new MailExporter($this->db([], []), '/opt/ds'))->exportMessage($this->messageRow([
            'target_table_id' => null, 'target_row' => null, 'raw_source_attachment' => null,
        ]))->data;

        $this->assertArrayNotHasKey('attention', $d);
        $this->assertArrayNotHasKey('actionNote', $d);
        $this->assertArrayNotHasKey('actionDue', $d);
        $this->assertArrayNotHasKey('aiTitle', $d);
    }

    public function testSchemaRejectsUnknownAttentionAndInvalidDue(): void
    {
        $base = (new MailExporter($this->db([], []), '/opt/ds'))->exportMessage($this->messageRow([
            'target_table_id' => null, 'target_row' => null, 'raw_source_attachment' => null,
        ]))->data;
        $validator = $this->mailSchemaValidator();

        $bad = json_decode(DatasetWriter::encode($base + ['attention' => 'urgent']), true);
        $issues = $validator->validate($bad, 'shpd.mail.incomingMessage', '1');
        $this->assertCount(1, $issues);
        $this->assertSame('attention', $issues[0]['path']);
        $this->assertSame('enum', $issues[0]['code']);

        $bad = json_decode(DatasetWriter::encode($base + ['attention' => 'action', 'actionDue' => '2026-02-30']), true);
        $issues = $validator->validate($bad, 'shpd.mail.incomingMessage', '1');
        $this->assertCount(1, $issues);
        $this->assertSame('actionDue', $issues[0]['path']);
        $this->assertSame('format', $issues[0]['code']);

        $bad = json_decode(DatasetWriter::encode($base + ['attention' => 'action', 'actionNote' => str_repeat('x', 201)]), true);
        $issues = $validator->validate($bad, 'shpd.mail.incomingMessage', '1');
        $this->assertCount(1, $issues);
        $this->assertSame('actionNote', $issues[0]['path']);
        $this->assertSame('maxLength', $issues[0]['code']);
    }

    public function testExportAllSkipsTrashAndOrdersByReceivedAt(): void
    {
        $db = $this->createMock(Connection::class);
        $db->expects($this->once())->method('fetchAll')->willReturnCallback(function (string $sql): array {
            $this->assertStringContainsString('[m.docState] <> 90', $sql);
            $this->assertStringContainsString('ORDER BY [m.received_at], [m.message_id], [m.id]', $sql);
            return [];
        });

        $this->assertSame([], (new MailExporter($db, '/opt/ds'))->exportAll());
    }
}
