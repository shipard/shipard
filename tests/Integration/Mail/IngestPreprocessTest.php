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
 * Intake s pravidly předzpracování (tasks/mail-preprocess.md): potvrzené
 * pravidlo uloží plán + preprocess_state=10 a spustí spawn; bez pravidla
 * se zpráva chová bit-přesně jako dnes (state 0, log NULL); archivované
 * pravidlo nematchuje; sender-rule archiv má přednost (žádné předzpracování).
 *
 * Testovací doména it-pp.example — na DS žijí systémová pravidla
 * (bolt-invoice-link), vzorky se jich nesmí dotknout.
 */
class IngestPreprocessTest extends IntegrationTestCase
{
    private const TEST_SUBJECT_PREFIX = 'IT-PP:';
    private const TEST_SENDER = 'preprocess-test@example.com';
    private const RULE_PREFIX = 'it-pp-';

    private int $routerUserId;
    private \Shipard\Core\Document\DocumentRegistry $documentRegistry;

    /** @var list<int> */
    private array $createdMessageIds = [];
    /** @var list<int> */
    private array $spawned = [];
    /** @var list<int> */
    private array $analysisSpawned = [];

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

        $this->spawned = [];
        $this->analysisSpawned = [];
        $_POST = [];
        $_FILES = [];
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdMessageIds as $id) {
            $this->db->execute('DELETE FROM core_attachments_files WHERE table_id = %i AND record_id = %i', 303, $id);
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        $this->db->execute('DELETE FROM core_mail_preprocess_rules WHERE rule_id LIKE %like~', self::RULE_PREFIX);
        $this->db->execute('DELETE FROM core_mail_sender_rules WHERE pattern = %s', self::TEST_SENDER);

        $_POST = [];
        $_FILES = [];
    }

    public function testConfirmedRuleStoresPlanAndSpawnsRunner(): void
    {
        $ruleNdx = $this->insertRule('bolt', ['body_regex' => 'invoice\.it-pp\.example'], 40);

        $ndx = $this->ingest('plan stored', 'Fwd: <a href="https://invoice.it-pp.example/abc">Invoice</a>');

        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(10, (int) $row['preprocess_state']);
        $this->assertSame(10, (int) $row['docState'], 'workflow zůstává Nová');

        $log = json_decode((string) $row['preprocess_log'], true);
        $this->assertIsArray($log);
        $this->assertSame(0, $log['attempts']);
        $this->assertSame([], $log['results']);
        $this->assertCount(1, $log['plan']);
        $this->assertSame(self::RULE_PREFIX . 'bolt', $log['plan'][0]['ruleId']);
        $this->assertSame($ruleNdx, $log['plan'][0]['ruleNdx']);
        $this->assertSame('fetchLinkedDocument', $log['plan'][0]['actions'][0]['action']);

        $this->assertSame([$ndx], $this->spawned);
        $this->assertSame([], $this->analysisSpawned, 'analýzu spustí až runner předzpracování (D14)');

        $rule = $this->db->fetchRow('SELECT hit_count, last_hit_at FROM core_mail_preprocess_rules WHERE id = %i', $ruleNdx);
        $this->assertSame(1, (int) $rule['hit_count']);
        $this->assertNotNull($rule['last_hit_at']);
    }

    public function testNoMatchingRuleKeepsCurrentBehaviour(): void
    {
        $this->insertRule('other', ['body_regex' => 'something-else'], 40);

        $ndx = $this->ingest('no match', 'Plain body without links');

        $row = $this->db->fetchRow('SELECT preprocess_state, preprocess_log FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(0, (int) $row['preprocess_state']);
        $this->assertNull($row['preprocess_log']);
        $this->assertSame([], $this->spawned);

        // #85 D14: bez předzpracování jde spawn analýzy rovnou z příjmu — právě když je zpráva ve frontě.
        $eligible = new \Shipard\Module\Core\Mail\Analysis\AnalysisQueue($this->db)->isEligible($ndx);
        $this->assertSame($eligible ? [$ndx] : [], $this->analysisSpawned);
    }

    public function testArchivedRuleDoesNotMatch(): void
    {
        $this->insertRule('archived', ['body_regex' => 'invoice\.it-pp\.example'], 70);

        $ndx = $this->ingest('archived rule', 'https://invoice.it-pp.example/abc');

        $row = $this->db->fetchRow('SELECT preprocess_state FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(0, (int) $row['preprocess_state']);
        $this->assertSame([], $this->spawned);
    }

    public function testSenderRuleArchiveTakesPrecedence(): void
    {
        $this->insertRule('bolt', ['body_regex' => 'invoice\.it-pp\.example'], 40);
        $now = date('Y-m-d H:i:s');
        $this->db->getDibiConnection()->insert('core_mail_sender_rules', [
            'pattern_kind' => 'email',
            'pattern' => self::TEST_SENDER,
            'disposition' => 'archive',
            'origin' => 'user',
            'hit_count' => 0,
            'created' => $now,
            'modified' => $now,
            'docState' => 40,
            'docStateMain' => 3,
        ])->execute();

        $ndx = $this->ingest('archived by sender rule', 'https://invoice.it-pp.example/abc');

        $row = $this->db->fetchRow('SELECT docState, preprocess_state FROM core_mail_incoming_messages WHERE id = %i', $ndx);
        $this->assertSame(80, (int) $row['docState']);
        $this->assertSame(0, (int) $row['preprocess_state']);
        $this->assertSame([], $this->spawned);
    }

    // --- helpers ---------------------------------------------------------

    /** @param array<string, string> $match */
    private function insertRule(string $suffix, array $match, int $docState): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->getDibiConnection()->insert('core_mail_preprocess_rules', array_merge([
            'rule_id' => self::RULE_PREFIX . $suffix,
            'origin' => 'user',
            'actions' => json_encode([[
                'action' => 'fetchLinkedDocument',
                'linkHrefRegex' => 'invoice\.it-pp\.example',
                'allowedDomains' => ['it-pp.example'],
            ]]),
            'hit_count' => 0,
            'created' => $now,
            'modified' => $now,
            'docState' => $docState,
            'docStateMain' => [10 => 1, 40 => 3, 70 => 4, 80 => 2, 90 => 5][$docState] ?? 1,
        ], $match))->execute();

        return (int) $this->db->getDibiConnection()->getInsertId();
    }

    private function ingest(string $subjectSuffix, string $bodyHtml): int
    {
        $raw = tempnam(sys_get_temp_dir(), 'shpd_raw_');
        file_put_contents($raw, "From: " . self::TEST_SENDER . "\r\nSubject: IT-PP\r\n\r\nHello");

        $_POST = [
            'mailbox' => '',
            'received_at' => '2026-08-29T10:00:00+02:00',
            'sender_email' => self::TEST_SENDER,
            'subject' => self::TEST_SUBJECT_PREFIX . ' ' . $subjectSuffix,
            'body_html' => $bodyHtml,
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

        $ctrl = new MailController(
            $this->db,
            $this->dsPath,
            $this->tables,
            $this->documentRegistry,
            null,
            null,
            null,
            function (int $messageId): void {
                $this->spawned[] = $messageId;
            },
            null,
            function (int $messageId): void {
                $this->analysisSpawned[] = $messageId;
            },
        );
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
}
