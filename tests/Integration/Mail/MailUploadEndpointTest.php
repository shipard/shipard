<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí `POST /_mail/messages/upload` (tasks/mail-dashboard-upload.md).
 * Testy volají MailController přímo (skutečná DB + file storage) — HTTP vrstva
 * je pokrytá unit testy (RouterTest, MailControllerUploadTest).
 */
class MailUploadEndpointTest extends IntegrationTestCase
{
    private const SUBJECT_PREFIX = 'IT-UP';

    private int $defaultMailboxId;
    private string $mailboxEmail;
    private int $userWithEmailId;
    private int $userWithoutEmailId;
    private \Shipard\Core\Document\DocumentRegistry $documentRegistry;

    /** @var list<int> Vytvořené message IDs pro teardown */
    private array $createdMessageIds = [];
    /** @var list<int> Vytvoření testovací uživatelé pro teardown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);

        $mailbox = $this->db->fetchRow(
            'SELECT id, email_address FROM core_mail_mailboxes WHERE is_default = %i LIMIT 1',
            1,
        );
        if ($mailbox === null) {
            $this->markTestSkipped('DS is missing default mailbox — run bin/shpd-ds mail-router-bootstrap.');
        }
        $this->defaultMailboxId = (int) $mailbox['id'];
        $this->mailboxEmail = trim((string) ($mailbox['email_address'] ?? ''));

        $dibi = $this->db->getDibiConnection();
        $suffix = uniqid();

        $dibi->insert('core_system_users', [
            'login' => "it-upload-{$suffix}",
            'full_name' => 'Upload Test User',
            'email' => 'upload-test@example.com',
        ])->execute();
        $this->userWithEmailId = (int) $dibi->getInsertId();
        $this->createdUserIds[] = $this->userWithEmailId;

        $dibi->insert('core_system_users', [
            'login' => "it-upload-noemail-{$suffix}",
            'full_name' => 'Upload Test User Without Email',
            'email' => null,
        ])->execute();
        $this->userWithoutEmailId = (int) $dibi->getInsertId();
        $this->createdUserIds[] = $this->userWithoutEmailId;

        $_POST = [];
        $_FILES = [];
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdMessageIds as $id) {
            // File storage žije pod $dsPath (temp dir) — uklidí rmTree v parentu.
            $this->db->execute('DELETE FROM core_attachments_files WHERE table_id = %i AND record_id = %i', 303, $id);
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        foreach ($this->createdUserIds as $id) {
            $this->db->execute('DELETE FROM core_system_users WHERE id = %i', $id);
        }

        $_POST = [];
        $_FILES = [];
    }

    public function testPerFileCreatesMessagePerFile(): void
    {
        $paths = $this->prepareFiles(3);
        $_POST = ['mode' => 'perFile'];
        $_FILES = ['attachments' => $this->fakeFileArray($paths, [
            self::SUBJECT_PREFIX . ' faktura.pdf',
            self::SUBJECT_PREFIX . ' smlouva.docx',
            self::SUBJECT_PREFIX . ' scan.png',
        ])];

        $response = $this->invokeController();
        $this->assertResponseStatus(201, $response);

        $payload = $response->getPayload();
        $this->assertSame('perFile', $payload['data']['mode']);
        $messages = $payload['data']['messages'];
        $this->assertCount(3, $messages);

        $expectedSubjects = [
            self::SUBJECT_PREFIX . ' faktura',
            self::SUBJECT_PREFIX . ' smlouva',
            self::SUBJECT_PREFIX . ' scan',
        ];
        $expectedAnalysisState = $this->expectedAnalysisState();

        foreach ($messages as $i => $msg) {
            $ndx = (int) $msg['ndx'];
            $this->createdMessageIds[] = $ndx;
            $this->assertSame($expectedSubjects[$i], $msg['subject']);
            $this->assertStringStartsWith('MSG-', (string) $msg['message_id']);

            $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $ndx);
            $this->assertNotNull($row);
            $this->assertSame($this->defaultMailboxId, (int) $row['mailbox']);
            $this->assertSame(1, (int) $row['source_type']);
            $this->assertSame(10, (int) $row['docState']);
            $this->assertSame('upload-test@example.com', $row['sender_email']);
            $this->assertSame('Upload Test User', $row['sender_name']);
            $this->assertSame($expectedAnalysisState, (int) $row['analysis_state']);
            $this->assertNull($row['raw_source_attachment']);

            $attCount = $this->db->fetchRow(
                'SELECT COUNT(*) AS c FROM core_attachments_files WHERE table_id = %i AND record_id = %i',
                303,
                $ndx,
            );
            $this->assertSame(1, (int) $attCount['c']);
        }
    }

    public function testSingleCreatesOneMessageWithAllFiles(): void
    {
        $paths = $this->prepareFiles(3);
        $_POST = ['mode' => 'single'];
        $_FILES = ['attachments' => $this->fakeFileArray($paths, [
            self::SUBJECT_PREFIX . ' vypis.pdf',
            self::SUBJECT_PREFIX . ' b.pdf',
            self::SUBJECT_PREFIX . ' c.pdf',
        ])];

        $response = $this->invokeController();
        $this->assertResponseStatus(201, $response);

        $messages = $response->getPayload()['data']['messages'];
        $this->assertCount(1, $messages);
        $ndx = (int) $messages[0]['ndx'];
        $this->createdMessageIds[] = $ndx;
        $this->assertSame(self::SUBJECT_PREFIX . ' vypis (+2)', $messages[0]['subject']);

        $attCount = $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM core_attachments_files WHERE table_id = %i AND record_id = %i',
            303,
            $ndx,
        );
        $this->assertSame(3, (int) $attCount['c']);
    }

    public function testEmptyBasenameFallsBackToPlaceholderSubject(): void
    {
        $paths = $this->prepareFiles(1);
        $_POST = ['mode' => 'perFile'];
        $_FILES = ['attachments' => $this->fakeFileArray($paths, ['.pdf'])];

        $response = $this->invokeController();
        $this->assertResponseStatus(201, $response);

        $messages = $response->getPayload()['data']['messages'];
        $this->createdMessageIds[] = (int) $messages[0]['ndx'];
        $this->assertSame('(bez předmětu)', $messages[0]['subject']);
    }

    public function testSenderFallsBackToMailboxAddress(): void
    {
        if ($this->mailboxEmail === '' || filter_var($this->mailboxEmail, FILTER_VALIDATE_EMAIL) === false) {
            $this->markTestSkipped('Default mailbox has no valid email_address — fallback cannot be verified.');
        }

        $paths = $this->prepareFiles(1);
        $_POST = ['mode' => 'perFile'];
        $_FILES = ['attachments' => $this->fakeFileArray($paths, [self::SUBJECT_PREFIX . ' fallback.pdf'])];

        $response = $this->invokeController(userId: $this->userWithoutEmailId);
        $this->assertResponseStatus(201, $response);

        $ndx = (int) $response->getPayload()['data']['messages'][0]['ndx'];
        $this->createdMessageIds[] = $ndx;

        $row = $this->db->fetchRow('SELECT sender_email, sender_name FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        // beforeSave normalizuje sender_email na lowercase
        $this->assertSame(strtolower($this->mailboxEmail), $row['sender_email']);
        $this->assertSame('Upload Test User Without Email', $row['sender_name']);
    }

    public function testRollbackOnFailedUploadLeavesNothingBehind(): void
    {
        [$goodPath] = $this->prepareFiles(1);
        $filesOnDiskBefore = $this->countStorageFiles();
        $messagesBefore = $this->countTestMessages();

        $_POST = ['mode' => 'single'];
        $_FILES = ['attachments' => [
            'name' => [self::SUBJECT_PREFIX . ' ok.pdf', self::SUBJECT_PREFIX . ' broken.pdf'],
            'tmp_name' => [$goodPath, '/nonexistent/shpd-upload-broken'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
            'size' => [10, 10],
            'type' => ['application/pdf', 'application/pdf'],
        ]];

        // FileStorage při selhání rename/copy emituje PHP warnings — tady
        // jsou očekávané (simulujeme selhání uploadu), potlačit lokálně.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $response = $this->invokeController();
        } finally {
            restore_error_handler();
        }
        $this->assertResponseStatus(500, $response);
        $this->assertSame('INTERNAL_ERROR', $response->getPayload()['error']['code']);

        $this->assertSame($messagesBefore, $this->countTestMessages(), 'rollback must remove the message row');
        $this->assertSame($filesOnDiskBefore, $this->countStorageFiles(), 'orphaned files must be unlinked');
    }

    public function testIsdocCandidateIsDeferredToPreprocessRunner(): void
    {
        // #81 D4: upload inline neimportuje — jen detekce; platný ISDOC →
        // preprocess_state 10, log s triggerem, spawn runneru per zpráva.
        $dir = sys_get_temp_dir();
        $isdocPath = tempnam($dir, 'shpd_isdoc_');
        file_put_contents($isdocPath, '<?xml version="1.0"?><Invoice></Invoice>');

        $detectedMessageIds = [];
        $service = $this->createMock(\Shipard\Module\Core\Mail\IsdocImportService::class);
        $service->method('detect')->willReturnCallback(
            function (int $messageId, array $attachments) use (&$detectedMessageIds): bool {
                $detectedMessageIds[] = $messageId;
                return true;
            },
        );
        $service->expects($this->never())->method('tryImport');
        $spawned = [];

        $_POST = ['mode' => 'perFile'];
        $_FILES = ['attachments' => $this->fakeFileArray([$isdocPath], [self::SUBJECT_PREFIX . ' faktura.isdoc'])];

        $response = $this->invokeController(
            isdocFactory: static fn() => $service,
            spawner: static function (int $messageId) use (&$spawned): void {
                $spawned[] = $messageId;
            },
        );
        $this->assertResponseStatus(201, $response);

        $ndx = (int) $response->getPayload()['data']['messages'][0]['ndx'];
        $this->createdMessageIds[] = $ndx;

        $this->assertSame([$ndx], $detectedMessageIds, 'ISDOC detection must run for the created message');
        $this->assertSame([$ndx], $spawned, 'preprocess runner must be spawned for the deferred message');

        $row = $this->db->fetchRow(
            'SELECT preprocess_state, preprocess_log, analysis_state FROM core_mail_incoming_messages WHERE id = %i',
            $ndx,
        );
        $this->assertSame(10, (int) $row['preprocess_state']);
        $log = json_decode((string) $row['preprocess_log'], true);
        $this->assertSame('isdoc', $log['trigger']);
        $this->assertSame([], $log['plan']);
        $this->assertSame($this->expectedAnalysisState(), (int) $row['analysis_state'], 'no inline import');
    }

    /** analysis_state, který beforeSave přidělí nové zprávě v tomto DS. */
    private function expectedAnalysisState(): int
    {
        $profile = $this->db->fetchRow('SELECT id FROM core_mail_ai_profiles WHERE is_active = %i LIMIT 1', 1);
        return $profile !== null ? 10 : 0;
    }

    private function countTestMessages(): int
    {
        $row = $this->db->fetchRow(
            'SELECT COUNT(*) AS c FROM core_mail_incoming_messages WHERE subject LIKE %s',
            self::SUBJECT_PREFIX . '%',
        );
        return (int) $row['c'];
    }

    private function countStorageFiles(): int
    {
        $attDir = $this->dsPath . '/att';
        if (!is_dir($attDir)) {
            return 0;
        }
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($attDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $count++;
            }
        }
        return $count;
    }

    /** @return list<string> paths to temp files */
    private function prepareFiles(int $count): array
    {
        $dir = sys_get_temp_dir();
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $path = tempnam($dir, 'shpd_up_');
            file_put_contents($path, "%PDF-1.4 fake {$i}");
            $paths[] = $path;
        }
        return $paths;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $names
     */
    private function fakeFileArray(array $paths, array $names): array
    {
        return [
            'name' => $names,
            'tmp_name' => $paths,
            'error' => array_fill(0, count($paths), UPLOAD_ERR_OK),
            'size' => array_map(static fn(string $p): int => filesize($p) ?: 0, $paths),
            'type' => array_fill(0, count($paths), 'application/octet-stream'),
        ];
    }

    private function invokeController(
        ?\Closure $isdocFactory = null,
        ?int $userId = null,
        ?\Closure $spawner = null,
    ): \Shipard\Api\Response {
        $ctrl = new MailController(
            $this->db,
            $this->dsPath,
            $this->tables,
            $this->documentRegistry,
            null,
            null,
            $isdocFactory,
            $spawner,
        );
        $auth = new AuthContext(true, $userId ?? $this->userWithEmailId, 'session', 'shpd_st_test');
        $server = ['HTTP_HOST' => 'test.local', 'REMOTE_ADDR' => '127.0.0.1'];
        $request = Request::fromArray('POST', '/_mail/messages/upload', [], '', $server);
        return $ctrl->uploadMessages($auth, $request);
    }

    private function assertResponseStatus(int $expected, \Shipard\Api\Response $response): void
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        $actual = (int) $prop->getValue($response);
        if ($actual !== $expected) {
            $payload = $response->getPayload();
            $msg = is_array($payload) && isset($payload['error']['message'])
                ? $payload['error']['message']
                : json_encode($payload);
            $this->assertSame($expected, $actual, "Unexpected status with payload: {$msg}");
        } else {
            $this->assertSame($expected, $actual);
        }
    }
}
