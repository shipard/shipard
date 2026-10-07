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
 * Pre-triage při ingestu (Fáze 3 — šum): potvrzené pravidlo odesílatele
 * archivuje zprávu bez analýzy s auditem; nepotvrzené nematchuje;
 * bulk hlavičky plní is_bulk, ale samy nearchivují (D7).
 */
class IngestPreTriageTest extends IntegrationTestCase
{
    private const TEST_SUBJECT_PREFIX = 'IT-PT:';
    private const TEST_SENDER = 'pretriage-test@example.com';

    private int $routerUserId;
    private \Shipard\Core\Document\DocumentRegistry $documentRegistry;

    /** @var list<int> */
    private array $createdMessageIds = [];
    /** @var list<int> */
    private array $createdRuleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);

        $user = $this->db->fetchRow('SELECT id FROM core_system_users WHERE login = %s', '_mail_router');
        if ($user === null) {
            $this->markTestSkipped('DS is missing _mail_router user — run bin/shpd-ds mail-router-bootstrap.');
        }
        $this->routerUserId = (int) $user['id'];

        if ($this->db->fetchRow('SELECT id FROM core_mail_mailboxes WHERE is_default = %i LIMIT 1', 1) === null) {
            $this->markTestSkipped('DS is missing default mailbox — run bin/shpd-ds mail-router-bootstrap.');
        }

        $_POST = [];
        $_FILES = [];
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdMessageIds as $id) {
            $this->db->execute('DELETE FROM core_attachments_files WHERE table_id = %i AND record_id = %i', 303, $id);
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        foreach ($this->createdRuleIds as $id) {
            $this->db->execute('DELETE FROM core_mail_sender_rules WHERE id = %i', $id);
        }

        $_POST = [];
        $_FILES = [];
    }

    // --- pre-triage -----------------------------------------------------

    public function testConfirmedEmailRuleArchivesMessageWithAudit(): void
    {
        $ruleId = $this->insertRule('email', self::TEST_SENDER, 40);

        $ndx = $this->ingest(self::TEST_SENDER, 'confirmed rule');

        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(80, (int) $row['docState']);
        $this->assertSame(4, (int) $row['docStateMain']);
        $this->assertSame(0, (int) $row['analysis_state']);
        $this->assertSame($ruleId, (int) $row['auto_disposed_by']);
        $this->assertNotNull($row['auto_disposed_at']);
        // Raw .eml se ukládá i pro auto-archivované zprávy (plný audit).
        $this->assertNotNull($row['raw_source_attachment']);

        $rule = $this->db->fetchRow('SELECT hit_count, last_hit_at FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(1, (int) $rule['hit_count']);
        $this->assertNotNull($rule['last_hit_at']);
    }

    public function testConfirmedDomainRuleMatchesAnySenderOfDomain(): void
    {
        $this->insertRule('domain', 'bulk-domain.example.com', 40);

        $ndx = $this->ingest('anyone@bulk-domain.example.com', 'domain rule');

        $row = $this->db->fetchRow('SELECT docState, auto_disposed_by FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(80, (int) $row['docState']);
        $this->assertNotNull($row['auto_disposed_by']);
    }

    public function testDraftRuleDoesNotMatch(): void
    {
        $this->insertRule('email', self::TEST_SENDER, 10);

        $ndx = $this->ingest(self::TEST_SENDER, 'draft rule');

        $this->assertMessageWentThroughNormally($ndx);
    }

    public function testTrashedRuleDoesNotMatch(): void
    {
        $this->insertRule('email', self::TEST_SENDER, 90);

        $ndx = $this->ingest(self::TEST_SENDER, 'trashed rule');

        $this->assertMessageWentThroughNormally($ndx);
    }

    public function testNoRuleKeepsCurrentBehaviour(): void
    {
        $ndx = $this->ingest(self::TEST_SENDER, 'no rule');

        $this->assertMessageWentThroughNormally($ndx);
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->insertRule('email', self::TEST_SENDER, 40);

        $ndx = $this->ingest('PreTriage-Test@Example.COM', 'case-insensitive');

        $row = $this->db->fetchRow('SELECT docState FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(80, (int) $row['docState']);
    }

    // --- archiveIfOther (tasks/mail-sender-rules-after-analysis.md D6) --

    public function testArchiveIfOtherRuleLetsMessageThroughAtIngest(): void
    {
        $ruleId = $this->insertRule('email', self::TEST_SENDER, 40, 'archiveIfOther');

        $ndx = $this->ingest(self::TEST_SENDER, 'archiveIfOther rule');

        // Při příjmu archivuje jen „Archivovat hned“ — zpráva vzniká v Nové
        // a jde do fronty analýzy (10 s aktivním AI profilem, jinak 0);
        // pravidlo zasáhne až po analýze.
        $this->assertMessageWentThroughNormally($ndx);
        $row = $this->db->fetchRow('SELECT analysis_state FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $expected = $this->db->fetchRow('SELECT id FROM core_mail_ai_profiles WHERE is_active = 1 LIMIT 1') !== null ? 10 : 0;
        $this->assertSame($expected, (int) $row['analysis_state']);

        $rule = $this->db->fetchRow('SELECT hit_count, last_hit_at FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(0, (int) $rule['hit_count']);
        $this->assertNull($rule['last_hit_at']);
    }

    public function testEmailArchiveIfOtherWinsOverDomainArchive(): void
    {
        // D6: konkrétnější pravidlo určuje fázi — doménové „Archivovat hned“
        // zprávu od adresy s vlastním `archiveIfOther` při příjmu nespolkne.
        $domainRuleId = $this->insertRule('domain', 'example.com', 40, 'archive');
        $this->insertRule('email', self::TEST_SENDER, 40, 'archiveIfOther');

        $ndx = $this->ingest(self::TEST_SENDER, 'email beats domain');

        $this->assertMessageWentThroughNormally($ndx);
        $rule = $this->db->fetchRow('SELECT hit_count FROM core_mail_sender_rules WHERE id = %i', $domainRuleId);
        $this->assertSame(0, (int) $rule['hit_count']);
    }

    // --- is_bulk ---------------------------------------------------------

    public function testBulkHeaderSetsFlagButDoesNotArchive(): void
    {
        $eml = "From: news@example.com\r\nSubject: IT-PT\r\n"
            . "List-Unsubscribe: <https://example.com/unsub>\r\n\r\nBody";

        $ndx = $this->ingest(self::TEST_SENDER, 'bulk header', $eml);

        $row = $this->db->fetchRow('SELECT is_bulk, docState FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(1, (int) $row['is_bulk']);
        $this->assertSame(10, (int) $row['docState']);
    }

    public function testPlainMessageHasZeroBulkFlag(): void
    {
        $ndx = $this->ingest(self::TEST_SENDER, 'plain message');

        $row = $this->db->fetchRow('SELECT is_bulk FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(0, (int) $row['is_bulk']);
    }

    // --- helpers ---------------------------------------------------------

    private function insertRule(string $kind, string $pattern, int $docState, string $disposition = 'archive'): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->getDibiConnection()->insert('core_mail_sender_rules', [
            'pattern_kind' => $kind,
            'pattern' => strtolower($pattern),
            'disposition' => $disposition,
            'origin' => 'user',
            'hit_count' => 0,
            'created' => $now,
            'modified' => $now,
            'docState' => $docState,
            'docStateMain' => [10 => 1, 40 => 3, 70 => 4, 80 => 2, 90 => 5][$docState] ?? 1,
        ])->execute();

        $id = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdRuleIds[] = $id;
        return $id;
    }

    private function ingest(string $senderEmail, string $subjectSuffix, ?string $eml = null): int
    {
        $raw = tempnam(sys_get_temp_dir(), 'shpd_raw_');
        file_put_contents($raw, $eml ?? "From: {$senderEmail}\r\nSubject: IT-PT\r\n\r\nHello");

        $_POST = [
            'mailbox' => '',
            'received_at' => '2026-07-15T10:00:00+02:00',
            'sender_email' => $senderEmail,
            'subject' => self::TEST_SUBJECT_PREFIX . ' ' . $subjectSuffix,
            'source_type' => '2',
        ];
        $_FILES = [
            'raw_source' => [
                'name' => 'message.eml',
                'tmp_name' => $raw,
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($raw) ?: 0,
                'type' => 'application/octet-stream',
            ],
        ];

        $ctrl = new MailController($this->db, $this->dsPath, $this->tables, $this->documentRegistry);
        $auth = new AuthContext(true, $this->routerUserId, 'api_key', 'shpd_ak_test');
        $request = Request::fromArray('POST', '/_mail/incoming', [], '', [
            'HTTP_HOST' => 'test.local',
            'REMOTE_ADDR' => '127.0.0.1',
        ]);

        $response = $ctrl->receiveIncoming($auth, $request);

        $payload = $response->getPayload();
        $this->assertTrue((bool) ($payload['success'] ?? false), 'ingest failed: ' . json_encode($payload));

        $ndx = (int) $payload['data']['ndx'];
        $this->createdMessageIds[] = $ndx;
        return $ndx;
    }

    private function assertMessageWentThroughNormally(int $ndx): void
    {
        $row = $this->db->fetchRow(
            'SELECT docState, auto_disposed_by, auto_disposed_at FROM core_mail_incoming_messages WHERE id = %i',
            $ndx,
        );
        $this->assertSame(10, (int) $row['docState']);
        $this->assertNull($row['auto_disposed_by']);
        $this->assertNull($row['auto_disposed_at']);
    }
}
