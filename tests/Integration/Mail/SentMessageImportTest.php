<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Sent\SentMessageImportException;
use Shipard\Module\Core\Mail\Sent\SentMessageImportService;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Import odeslané zprávy ze starého systému nad skutečnou databází (#104
 * D1–D4): zpráva s přílohami v jedné transakci, zachovaný čas, odvozený
 * transport, deduplikace podle `import_ref` a odmítnutý payload bez stopy.
 *
 * Celý test běží v transakci, kterou `onTearDown` vrátí; soubory příloh
 * jdou do dočasného adresáře testu (`dsPath`), ne do skutečného `att/`.
 * Spustitelné s `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`
 * nad zdrojem po `ds-upgrade` (sloupec `import_ref`).
 */
class SentMessageImportTest extends IntegrationTestCase
{
    private const PDF   = "%PDF-1.4\n% IT-SENTIMP faktura\n";
    private const ISDOC = "<?xml version=\"1.0\"?><Invoice>IT-SENTIMP</Invoice>";

    private SentMessageImportService $service;
    private SentMessageStore $store;
    private string $importRef;
    private int $targetRow = 0;
    private ?int $personId = null;
    private ?int $userId = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->tables[SentMessageStore::TABLE])) {
            $this->markTestSkipped('DS nemá modul core.mail s Odeslanou poštou.');
        }
        $columns = $this->db->getTableColumns(SentMessageStore::TABLE);
        if (!isset($columns['import_ref'])) {
            $this->markTestSkipped('DS nemá sloupec import_ref — spusť ds-upgrade.');
        }
        $target = $this->db->fetchRow('SELECT [id] FROM [docs_core_heads] ORDER BY [id] LIMIT 1');
        if ($target === null) {
            $this->markTestSkipped('DS nemá žádný doklad pro vazbu zprávy.');
        }

        // Vše od této chvíle vrátí rollback v onTearDown.
        $this->db->begin();

        $this->targetRow = (int) $target['id'];
        $person          = $this->db->fetchRow('SELECT [id] FROM [base_persons_persons] ORDER BY [id] LIMIT 1');
        $this->personId  = $person === null ? null : (int) $person['id'];
        $user            = $this->db->fetchRow('SELECT [id] FROM [core_system_users] ORDER BY [id] LIMIT 1');
        $this->userId    = $user === null ? null : (int) $user['id'];
        $this->importRef = 'IT-SENTIMP:' . bin2hex(random_bytes(4));

        $this->store   = new SentMessageStore($this->db);
        $this->service = new SentMessageImportService(
            $this->db,
            $this->store,
            // Bez guardů — jako ve wiringu endpointu.
            new AttachmentService($this->db, $this->dsPath, $this->tables),
            $this->tables,
            ConfigRuntime::load($this->realDsPath, 'cs'),
        );
    }

    protected function onTearDown(): void
    {
        $this->db->rollback();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'import_ref'       => $this->importRef,
            'created'          => '2021-06-01 09:12:33',
            'email_from'       => 'fakturace@firma.example',
            'email_from_name'  => 'IT-SENTIMP Firma s.r.o.',
            'email_to'         => ['ucetni@odberatel.example'],
            'email_cc'         => ['obchod@firma.example'],
            'subject'          => 'IT-SENTIMP Faktura 2160011',
            'body_text'        => 'Dobrý den, v příloze posíláme fakturu.',
            'recipient_person' => $this->personId,
            'target_table_id'  => 'docs_core_heads',
            'target_row'       => $this->targetRow,
            'target_label'     => 'Faktura – daňový doklad 2160011',
            'purpose'          => 'invoices',
            'created_by'       => $this->userId,
        ];
    }

    /** @return list<array{name: string, tmp_name: string}> */
    private function files(): array
    {
        $out = [];
        foreach (['faktura-2160011.pdf' => self::PDF, 'faktura-2160011.isdoc' => self::ISDOC] as $name => $content) {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'shpd_it_sentimp_');
            file_put_contents($tmp, $content);
            $out[] = ['name' => $name, 'tmp_name' => $tmp];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> přílohy zprávy v pořadí vzniku */
    private function attachmentsOf(int $messageId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM [core_attachments_files] WHERE [table_id] = %i AND [record_id] = %i ORDER BY [id]',
            SentMessageStore::TABLE_ID,
            $messageId,
        );
    }

    public function testImportsMessageWithAttachmentsAndKeepsHistoricalTime(): void
    {
        $result = $this->service->import($this->payload(), $this->files(), $this->userId);

        $this->assertTrue($result->created);
        $this->assertSame(2, $result->attachments);

        $row = $this->store->get($result->id);
        $this->assertNotNull($row);
        $this->assertSame($this->importRef, $row['import_ref']);
        $this->assertSame('import', $row['send_trigger']);
        $this->assertSame('sent', $row['transport_state']);
        $this->assertSame(1, (int) $row['send_count']);
        $this->assertSame('2021-06-01 09:12:33', self::dateTime($row['created']));
        $this->assertSame('2021-06-01 09:12:33', self::dateTime($row['sent_at']));
        $this->assertSame('2021-06-01 09:12:33', self::dateTime($row['modified']));
        $this->assertSame('ucetni@odberatel.example', $row['email_to']);
        $this->assertSame('obchod@firma.example', $row['email_cc']);
        $this->assertSame('docs_core_heads', $row['target_table_id']);
        $this->assertSame($this->targetRow, (int) $row['target_row']);
        $this->assertSame(SentMessageStore::DOC_STATE_SENT, (int) $row['docState']);
        $this->assertNull($row['language']);
        $this->assertNull($row['print_id']);

        // Přílohy v pořadí požadavku, soubory na disku.
        $attachments = $this->attachmentsOf($result->id);
        $this->assertSame(['faktura-2160011.pdf', 'faktura-2160011.isdoc'], array_column($attachments, 'name'));
        foreach ($attachments as $attachment) {
            $this->assertFileExists($this->dsPath . '/att/' . $attachment['file_path'] . '/' . $attachment['file_name']);
        }
        $this->assertSame(array_map(static fn (array $a): int => (int) $a['id'], $attachments), $this->store->attachmentIds($result->id));

        // Zpráva se ukazuje u záznamu.
        $forTarget = $this->store->forTarget('docs_core_heads', $this->targetRow);
        $this->assertContains($result->id, array_map(static fn (array $m): int => (int) $m['id'], $forTarget));
    }

    public function testRepeatedImportReturnsExistingMessageWithoutNewAttachments(): void
    {
        $first  = $this->service->import($this->payload(), $this->files(), $this->userId);
        $second = $this->service->import($this->payload(['subject' => 'Jiný předmět']), $this->files(), $this->userId);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(0, $second->attachments);
        $this->assertCount(2, $this->attachmentsOf($first->id));
        // Obsah se neporovnává — platí první import.
        $this->assertSame('IT-SENTIMP Faktura 2160011', $this->store->get($first->id)['subject']);
    }

    public function testMessageWithoutRecipientHasUnknownTransport(): void
    {
        $result = $this->service->import($this->payload(['email_to' => [], 'email_cc' => null]), [], $this->userId);

        $row = $this->store->get($result->id);
        $this->assertSame('unknown', $row['transport_state']);
        $this->assertNull($row['sent_at']);
        $this->assertSame(0, (int) $row['send_count']);
        $this->assertSame('', $row['email_to']);
        $this->assertNull($row['email_cc']);
    }

    public function testRejectedPayloadLeavesNoTrace(): void
    {
        $files = $this->files();

        try {
            $this->service->import($this->payload(['target_row' => 2147483000]), $files, $this->userId);
            $this->fail('Expected SentMessageImportException');
        } catch (SentMessageImportException $e) {
            $this->assertSame('target_row', $e->field);
            $this->assertSame('not_found', $e->errorCode);
        }

        $this->assertNull($this->store->findByImportRef($this->importRef));
        foreach ($files as $file) {
            // Dočasné soubory požadavku zůstaly volajícímu.
            $this->assertFileExists($file['tmp_name']);
            @unlink($file['tmp_name']);
        }
        $this->assertSame([], glob($this->dsPath . '/att/*/*/*') ?: []);
    }

    /** Datetime z databáze (`DataSourceConnection` ho vrací jako ISO text) ve tvaru `Y-m-d H:i:s`. */
    private static function dateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return $value === null ? null : str_replace('T', ' ', substr((string) $value, 0, 19));
    }
}
