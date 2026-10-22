<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Dataset;

use Dibi\Connection;
use Dibi\Fluent;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Mail\Dataset\MailSeeder;
use Shipard\Module\Core\Mail\IncomingMessageDocument;

class MailSeederTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_mailseed_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    public function testRemapRewritesKnownOrdinalsAndReportsUnknown(): void
    {
        $canonical = json_decode('{"attachments":[{"ref":"att:1"},{"ref":"att:2"},{"ref":"att:7"}],"source":{"raw":{}},"nested":["att:2",{"x":"att:1"}]}', false);

        $missing = null;
        $out = MailSeeder::remapAttachmentRefs($canonical, [1 => 501, 2 => 502], $missing);

        $this->assertSame('att:501', $out->attachments[0]->ref);
        $this->assertSame('att:502', $out->attachments[1]->ref);
        $this->assertSame('att:7', $out->attachments[2]->ref, 'unknown ordinal is left untouched');
        $this->assertSame('att:502', $out->nested[0]);
        $this->assertSame('att:501', $out->nested[1]->x);
        $this->assertInstanceOf(\stdClass::class, $out->source->raw, 'empty objects survive');
        $this->assertSame(['att:7'], $missing);
    }

    public function testRemapLeavesNonRefStringsAlone(): void
    {
        $missing = null;
        $this->assertSame('attachment:1', MailSeeder::remapAttachmentRefs('attachment:1', [1 => 5], $missing));
        $this->assertSame(12, MailSeeder::remapAttachmentRefs(12, [1 => 5], $missing));
        $this->assertSame([], $missing);
    }

    // ── pozornost u zprávy bez dokladu (#105, demo sada #131) ──────────────

    /**
     * Obálka zprávy bez dokladu (primary_type other) — jen hlavička, bez příloh
     * a analýz, aby insert šel přes IncomingMessageDocument bez dalších dotazů.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function message(array $extra = []): array
    {
        return array_merge([
            'format' => 'shpd.mail.incomingMessage', 'formatVersion' => '1.0', 'messageId' => 'MSG-20260601-0001',
            'mailbox' => 'default', 'primaryType' => 'other', 'primaryTypeSource' => 'ai',
            'subject' => 'Expirace domény firma.example', 'senderEmail' => 'noreply@registrator.example',
            'receivedAt' => '2026-06-01T07:55:10', 'docState' => 10, 'analysisState' => 30,
            'aiTitle' => 'Expirace domény firma.example',
        ], $extra);
    }

    /** @param array<string, mixed> $message */
    private function writeSet(array $message): DatasetReader
    {
        $w = DatasetWriter::create($this->tmp . '/set');
        $w->writeJsonc('mail/0001-msg-20260601-0001.jsonc', $message);
        $w->writeManifest(new DatasetManifest('demo', 'Demo', null, 'fixed', '2026-08-26T10:00:00Z', ['mail' => 1]));
        return DatasetReader::open($this->tmp . '/set');
    }

    /**
     * Kontext s DB mockem: schránka `default` existuje (id 7), každý
     * `insert()` se zapíše do `$inserts` jako [tabulka, data].
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $inserts
     */
    private function context(DatasetReader $reader, array &$inserts): SeedContext
    {
        $fluent = $this->createMock(Fluent::class);
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(
            static fn(string $sql): ?Row => str_contains($sql, '[core_mail_mailboxes]') ? new Row(['id' => 7]) : null,
        );
        $db->method('insert')->willReturnCallback(function (string $table, iterable $data) use (&$inserts, $fluent): Fluent {
            $inserts[] = [$table, is_array($data) ? $data : iterator_to_array($data)];
            return $fluent;
        });
        $db->method('getInsertId')->willReturn(1);

        return new SeedContext(
            reader: $reader,
            db: $db,
            dsConnection: $this->createMock(DataSourceConnection::class),
            config: $this->createMock(ConfigRuntime::class),
            dsConfig: $this->createMock(DataSourceConfig::class),
            registry: new DocumentRegistry([['table' => 'core_mail_incoming_messages', 'class' => IncomingMessageDocument::class]]),
            tables: [],
            dsDir: $this->tmp,
            attachments: $this->createMock(AttachmentService::class),
            dispatcher: null,
            merge: false,
        );
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed> data vložená do core_mail_incoming_messages
     */
    private function seedOne(array $message): array
    {
        $inserts = [];
        $report = new SeedReport();
        (new MailSeeder($this->createMock(PartyResolver::class)))->seed($this->context($this->writeSet($message), $inserts), $report);

        $this->assertSame([], $report->errors());
        $this->assertSame(['ok' => 1, 'failed' => 0, 'skipped' => 0], $report->counts()['mail']);
        $this->assertCount(1, $inserts);
        $this->assertSame('core_mail_incoming_messages', $inserts[0][0]);
        return $inserts[0][1];
    }

    public function testAttentionFieldsFromEnvelopeAreInserted(): void
    {
        $data = $this->seedOne(self::message([
            'attention' => 'action',
            'actionNote' => 'Prodloužit doménu u registrátora.',
            'actionDue' => '2026-07-15',
        ]));

        $this->assertSame('MSG-20260601-0001', $data['message_id']);
        $this->assertSame(7, $data['mailbox']);
        $this->assertSame('other', $data['primary_type']);
        $this->assertSame('Expirace domény firma.example', $data['ai_title']);
        $this->assertSame('action', $data['attention']);
        $this->assertSame('Prodloužit doménu u registrátora.', $data['action_note']);
        $this->assertSame('2026-07-15', $data['action_due'], 'date stays Y-m-d as the live analysis writes it');
    }

    public function testEnvelopeWithoutAttentionLeavesColumnsUntouched(): void
    {
        $data = $this->seedOne(self::message());

        $this->assertArrayNotHasKey('attention', $data);
        $this->assertArrayNotHasKey('action_note', $data);
        $this->assertArrayNotHasKey('action_due', $data);
    }

    public function testInfoAttentionWithoutNoteAndDue(): void
    {
        $data = $this->seedOne(self::message(['attention' => 'info']));

        $this->assertSame('info', $data['attention']);
        $this->assertArrayNotHasKey('action_note', $data);
        $this->assertArrayNotHasKey('action_due', $data);
    }
}
