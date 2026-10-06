<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Sent;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Sent\SentMessageImportException;
use Shipard\Module\Core\Mail\Sent\SentMessageImportService;
use Shipard\Module\Core\Mail\Sent\SentMessageStore;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Import odeslané zprávy ze starého systému (#104 D1–D4) s falešnými
 * závislostmi: kontrakt payloadu, odvození transportu, zachované časy,
 * deduplikace bez zápisu, přílohy v pořadí požadavku a úklid po chybě.
 */
class SentMessageImportServiceTest extends TestCase
{
    private const EXISTING_PERSON = 12;
    private const EXISTING_USER   = 3;
    private const EXISTING_DOC    = 55;
    private const API_USER        = 9;

    private DataSourceConnection&MockObject $db;
    private \Dibi\Connection&MockObject $dibi;
    private SentMessageStore&MockObject $store;
    private AttachmentService&MockObject $attachments;

    /** @var list<array<string, mixed>> Řádky předané `store->import()`. */
    private array $imported = [];
    /** @var list<array{0: int, 1: string, 2: string, 3: ?int}> Volání `upload()`: zpráva, název, tmp, autor. */
    private array $uploads = [];
    /** @var list<string> Cesty souborů, které se mají po chybě smazat. */
    private array $storedFiles = [];

    protected function setUp(): void
    {
        $this->dibi = $this->createMock(\Dibi\Connection::class);
        $this->dibi->method('fetchSingle')->willReturn(0);

        $this->db = $this->createMock(DataSourceConnection::class);
        $this->db->method('getDibiConnection')->willReturn($this->dibi);
        // Existence osoby, uživatele a cílového záznamu.
        $this->db->method('fetchSingle')->willReturnCallback(static function (string $sql, string $table, int $id): ?int {
            $known = [
                'base_persons_persons' => [self::EXISTING_PERSON],
                'core_system_users'    => [self::EXISTING_USER],
                'docs_core_heads'      => [self::EXISTING_DOC],
            ];
            return in_array($id, $known[$table] ?? [], true) ? $id : null;
        });

        $this->store = $this->createMock(SentMessageStore::class);
        $this->store->method('import')->willReturnCallback(function (array $fields): int {
            $this->imported[] = $fields;
            return 70 + count($this->imported);
        });

        $this->attachments = $this->createMock(AttachmentService::class);
        $this->attachments->method('upload')->willReturnCallback(
            function (int $tableId, int $recordId, string $name, string $tmp, ?int $userId): array {
                $this->assertSame(SentMessageStore::TABLE_ID, $tableId);
                $this->uploads[] = [$recordId, $name, $tmp, $userId];
                $path = tempnam(sys_get_temp_dir(), 'shpd-import-test-');
                $this->storedFiles[] = $path;
                return ['success' => true, 'data' => ['id' => 100 + count($this->uploads), 'path' => $path]];
            },
        );
        $this->attachments->method('getFilePath')->willReturnCallback(
            static fn (array $attachment): string => (string) $attachment['path'],
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function service(?ConfigRuntime $config = null): SentMessageImportService
    {
        return new SentMessageImportService(
            $this->db,
            $this->store,
            $this->attachments,
            ['docs_core_heads' => $this->createStub(TableDefinition::class)],
            $config,
        );
    }

    private function config(): ConfigRuntime
    {
        return ConfigRuntimeFactory::fromItems([
            'base.persons.sendPurposes' => ['invoices' => ['name' => 'Faktury a daňové doklady']],
            'core.mail.docStatesSent'   => [
                '40' => ['stateName' => 'Odeslaná', 'mainState' => 1],
                '70' => ['stateName' => 'V archivu', 'mainState' => 4],
                '90' => ['stateName' => 'Smazaná', 'mainState' => 5],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'import_ref'       => 'oldShipard:4711',
            'created'          => '2024-03-05 10:15:00',
            'email_from'       => 'fakturace@firma.example',
            'email_from_name'  => 'Naše firma s.r.o.',
            'email_to'         => ['ucetni@odberatel.example', 'jana@odberatel.example'],
            'subject'          => 'Faktura – daňový doklad 2260011',
            'body_text'        => "Dobrý den,\nv příloze posíláme fakturu.",
            'recipient_person' => self::EXISTING_PERSON,
            'target_table_id'  => 'docs_core_heads',
            'target_row'       => self::EXISTING_DOC,
            'target_label'     => 'Faktura – daňový doklad 2260011',
            'purpose'          => 'invoices',
            'created_by'       => self::EXISTING_USER,
        ];
    }

    /** @return list<array{name: string, tmp_name: string}> */
    private function files(int $count = 2): array
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $files[] = ['name' => "priloha-{$i}.pdf", 'tmp_name' => "/tmp/php-upload-{$i}"];
        }
        return $files;
    }

    /** @return list<array{array<string, mixed>, string, string}> */
    public static function contractViolations(): array
    {
        $tooLongList = array_map(static fn (int $i): string => sprintf('prijemce-%03d@odberatel.example', $i), range(1, 80));

        return [
            'import_ref chybí'                 => [['import_ref' => '  '], 'import_ref', 'required'],
            'import_ref přes 100 znaků'        => [['import_ref' => str_repeat('x', 101)], 'import_ref', 'too_long'],
            'created chybí'                    => [['created' => null], 'created', 'required'],
            'created není datum'               => [['created' => 'včera'], 'created', 'invalid_datetime'],
            'created má špatný měsíc'          => [['created' => '2024-13-05 10:00:00'], 'created', 'invalid_datetime'],
            'email_from chybí'                 => [['email_from' => ''], 'email_from', 'required'],
            'email_from neplatný'              => [['email_from' => 'fakturace@'], 'email_from', 'invalid_email'],
            'email_to s neplatnou adresou'     => [['email_to' => ['ok@odberatel.example', 'spatne']], 'email_to', 'invalid_email'],
            'email_to není seznam'             => [['email_to' => 42], 'email_to', 'invalid'],
            'email_to s ne-řetězcem'           => [['email_to' => ['ok@odberatel.example', 7]], 'email_to', 'invalid'],
            'email_to přes 2000 znaků'         => [['email_to' => $tooLongList], 'email_to', 'too_long'],
            'email_cc s neplatnou adresou'     => [['email_cc' => ['kopie@']], 'email_cc', 'invalid_email'],
            'body_text není text'              => [['body_text' => ['x']], 'body_text', 'invalid'],
            'recipient_person neexistuje'      => [['recipient_person' => 999], 'recipient_person', 'not_found'],
            'recipient_person není číslo'      => [['recipient_person' => 'abc'], 'recipient_person', 'invalid'],
            'target_table_id bez target_row'   => [['target_row' => null], 'target_row', 'incomplete'],
            'target_row bez target_table_id'   => [['target_table_id' => null], 'target_table_id', 'incomplete'],
            'target_table_id neznámá tabulka'  => [['target_table_id' => 'cizi_tabulka'], 'target_table_id', 'unknown_table'],
            'target_row neexistuje'            => [['target_row' => 999], 'target_row', 'not_found'],
            'purpose neznámý'                  => [['purpose' => 'postcards'], 'purpose', 'unknown_purpose'],
            'doc_state mimo 40/70/90'          => [['doc_state' => 10], 'doc_state', 'invalid_state'],
            'created_by neexistuje'            => [['created_by' => 999], 'created_by', 'not_found'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('contractViolations')]
    public function testContractViolationIsRejectedWithFieldAndCode(array $overrides, string $field, string $code): void
    {
        $this->store->method('findByImportRef')->willReturn(null);

        try {
            $this->service($this->config())->import($this->payload($overrides), $this->files(), self::API_USER);
            $this->fail('Expected SentMessageImportException');
        } catch (SentMessageImportException $e) {
            $this->assertSame($field, $e->field);
            $this->assertSame($code, $e->errorCode);
        }

        // Nic nevzniklo — ani řádek, ani soubory.
        $this->assertSame([], $this->imported);
        $this->assertSame([], $this->uploads);
    }

    public function testMessageWithRecipientsIsStoredAsSentAtCreationTime(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);

        $result = $this->service($this->config())->import($this->payload(), [], self::API_USER);

        $this->assertTrue($result->created);
        $this->assertSame(71, $result->id);
        $this->assertSame(0, $result->attachments);

        $row = $this->imported[0];
        $this->assertSame('oldShipard:4711', $row['import_ref']);
        $this->assertSame('import', $row['send_trigger']);
        $this->assertSame('email', $row['channel']);
        // Transport odvozený (D4): s adresou zpráva odešla v okamžiku vzniku.
        $this->assertSame('sent', $row['transport_state']);
        $this->assertSame('2024-03-05 10:15:00', $row['sent_at']);
        $this->assertSame(1, $row['send_count']);
        // Historický čas zachovaný, modified = created.
        $this->assertSame('2024-03-05 10:15:00', $row['created']);
        $this->assertSame('2024-03-05 10:15:00', $row['modified']);
        $this->assertSame('ucetni@odberatel.example, jana@odberatel.example', $row['email_to']);
        $this->assertNull($row['email_cc']);
        $this->assertSame('fakturace@firma.example', $row['email_from']);
        $this->assertSame('Naše firma s.r.o.', $row['email_from_name']);
        $this->assertSame(self::EXISTING_PERSON, $row['recipient_person']);
        $this->assertSame('docs_core_heads', $row['target_table_id']);
        $this->assertSame(self::EXISTING_DOC, $row['target_row']);
        $this->assertSame('invoices', $row['purpose']);
        $this->assertSame(self::EXISTING_USER, $row['created_by']);
        $this->assertSame(40, $row['docState']);
        $this->assertSame(1, $row['docStateMain']);
        // Co import nenese.
        foreach (['language', 'print_id', 'last_outbox_id', 'last_error', 'safety_action', 'safety_target'] as $column) {
            $this->assertArrayHasKey($column, $row);
            $this->assertNull($row[$column], $column);
        }
    }

    public function testMessageWithoutRecipientsHasUnknownTransport(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);

        $this->service()->import($this->payload(['email_to' => [], 'subject' => '  ', 'created_by' => null]), [], self::API_USER);

        $row = $this->imported[0];
        $this->assertSame('unknown', $row['transport_state']);
        $this->assertNull($row['sent_at']);
        $this->assertSame(0, $row['send_count']);
        $this->assertSame('', $row['email_to']);
        $this->assertSame('(bez předmětu)', $row['subject']);
        // Autor jen z payloadu — uživatel API klíče zprávu nepodepisuje.
        $this->assertArrayHasKey('created_by', $row);
        $this->assertNull($row['created_by']);
    }

    public function testOptionalFieldsAreTrimmedAndStatesMapWithoutConfig(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);

        $this->service()->import($this->payload([
            'email_from_name' => str_repeat('N', 250),
            'subject'         => str_repeat('P', 600),
            'target_label'    => str_repeat('L', 300),
            'email_cc'        => 'kopie@firma.example, kopie@firma.example',
            'body_text'       => '',
            'purpose'         => 'cokoliv',
            'doc_state'       => '70',
            'target_table_id' => null,
            'target_row'      => null,
        ]), [], self::API_USER);

        $row = $this->imported[0];
        $this->assertSame(200, mb_strlen($row['email_from_name']));
        $this->assertSame(500, mb_strlen($row['subject']));
        $this->assertSame(250, mb_strlen($row['target_label']));
        $this->assertSame('kopie@firma.example', $row['email_cc']);
        $this->assertNull($row['body_text']);
        // Bez konfigurace se účel neověřuje a `docStateMain` jde z fallbacku.
        $this->assertSame('cokoliv', $row['purpose']);
        $this->assertSame(70, $row['docState']);
        $this->assertSame(4, $row['docStateMain']);
        $this->assertNull($row['target_table_id']);
        $this->assertNull($row['target_row']);
    }

    public function testCreatedWithZoneIsConvertedToServerTime(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Prague');

        try {
            $this->service()->import($this->payload(['created' => '2024-03-05T10:15:00+00:00']), [], self::API_USER);
            $this->service()->import($this->payload(['import_ref' => 'oldShipard:4712', 'created' => '2024-07-05T10:15:00']), [], self::API_USER);
        } finally {
            date_default_timezone_set($previous);
        }

        // Se zónou převod, bez zóny čas serveru tak, jak přišel.
        $this->assertSame('2024-03-05 11:15:00', $this->imported[0]['created']);
        $this->assertSame('2024-07-05 10:15:00', $this->imported[1]['created']);
    }

    public function testExistingImportRefReturnsMessageWithoutWriting(): void
    {
        $this->store->method('findByImportRef')->with('oldShipard:4711')->willReturn(12);
        $this->dibi->expects($this->never())->method('begin');

        $result = $this->service()->import($this->payload(), $this->files(), self::API_USER);

        $this->assertFalse($result->created);
        $this->assertSame(12, $result->id);
        $this->assertSame(0, $result->attachments);
        $this->assertSame([], $this->imported);
        $this->assertSame([], $this->uploads);
    }

    public function testAttachmentsAreStoredInRequestOrderWithinTheTransaction(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);
        $this->dibi->expects($this->once())->method('begin');
        $this->dibi->expects($this->once())->method('commit');
        $this->dibi->expects($this->never())->method('rollback');

        $result = $this->service()->import($this->payload(), $this->files(3), self::API_USER);

        $this->assertSame(3, $result->attachments);
        $this->assertSame(
            [[71, 'priloha-1.pdf', '/tmp/php-upload-1', self::EXISTING_USER],
             [71, 'priloha-2.pdf', '/tmp/php-upload-2', self::EXISTING_USER],
             [71, 'priloha-3.pdf', '/tmp/php-upload-3', self::EXISTING_USER]],
            $this->uploads,
        );
    }

    public function testAttachmentsWithoutAuthorInPayloadBelongToApiUser(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);

        $this->service()->import($this->payload(['created_by' => null]), $this->files(1), self::API_USER);

        $this->assertSame(self::API_USER, $this->uploads[0][3]);
    }

    public function testFailedAttachmentRollsBackAndRemovesStoredFiles(): void
    {
        $this->store->method('findByImportRef')->willReturn(null);
        $attachments = $this->createMock(AttachmentService::class);
        $stored      = tempnam(sys_get_temp_dir(), 'shpd-import-test-');
        $this->storedFiles[] = $stored;
        $attachments->method('upload')->willReturnOnConsecutiveCalls(
            ['success' => true, 'data' => ['id' => 101, 'path' => $stored]],
            ['success' => false, 'error' => 'disk full'],
        );
        $attachments->method('getFilePath')->willReturnCallback(static fn (array $a): string => (string) $a['path']);
        $this->dibi->expects($this->once())->method('rollback');
        $this->dibi->expects($this->never())->method('commit');

        $service = new SentMessageImportService($this->db, $this->store, $attachments, [], null);
        try {
            $service->import($this->payload(['target_table_id' => null, 'target_row' => null]), $this->files(), self::API_USER);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('priloha-2.pdf', $e->getMessage());
        }

        $this->assertFileDoesNotExist($stored);
    }

    public function testConcurrentDuplicateIsReportedAsExistingMessage(): void
    {
        $store = $this->createMock(SentMessageStore::class);
        $store->method('findByImportRef')->willReturnOnConsecutiveCalls(null, 12);
        $store->method('import')->willThrowException(new \Dibi\UniqueConstraintViolationException('Duplicate entry'));
        $this->dibi->expects($this->once())->method('rollback');

        $service = new SentMessageImportService($this->db, $store, $this->attachments, [], null);
        $result  = $service->import($this->payload(['target_table_id' => null, 'target_row' => null]), $this->files(), self::API_USER);

        $this->assertFalse($result->created);
        $this->assertSame(12, $result->id);
        $this->assertSame([], $this->uploads);
    }
}
