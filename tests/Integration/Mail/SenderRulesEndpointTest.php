<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\SenderRulesController;
use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Endpointy Fáze 3 (šum): confirm/reject návrhu pravidla a undo denního
 * auto-archivu (obnova docState + analysis_state + audit sloupců).
 *
 * Potvrzení jede přes TableGateway s dispatcherem — po commitu
 * SenderRuleConfirmedHandler odklidí čekající řádky Ostatní od adresy
 * (tasks/mail-sender-rules-after-analysis.md D8); undo vrací zprávu
 * archivovanou po analýze bez nové analýzy (D7).
 */
class SenderRulesEndpointTest extends IntegrationTestCase
{
    private const TEST_SENDER = 'rules-endpoint-test@example.com';

    private SenderRulesController $controller;
    private DocumentRegistry $documentRegistry;
    private ConfigRuntime $configRuntime;
    private int $mailboxId = 0;

    /** @var list<int> */
    private array $createdRuleIds = [];
    /** @var list<int> */
    private array $createdMessageIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->documentRegistry = DocumentLoader::load($this->dsConfig, $resolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        if ($this->configRuntime->cfgItem('core.mail.senderRulePatternKinds') === null) {
            $this->markTestSkipped('compiled config missing sender rule cfgItems — run ds-upgrade.');
        }

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes ORDER BY is_default DESC, id LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS has no mailbox — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        // Dispatcher jako v public/index.php — bez něj by handler potvrzení (D8) neběžel.
        $dispatcher = DocumentEventHandlerLoader::load(
            $this->dsConfig,
            $resolver,
            $this->db->getDibiConnection(),
            $this->configRuntime,
        );

        $this->controller = new SenderRulesController(
            $this->db,
            $this->tables,
            $this->documentRegistry,
            $this->configRuntime,
            $this->dsConfig,
            $dispatcher,
        );
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdMessageIds as $id) {
            $this->db->execute('DELETE FROM core_mail_message_analyses WHERE message = %i', $id);
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        foreach ($this->createdRuleIds as $id) {
            $this->db->execute('DELETE FROM core_mail_sender_rules WHERE id = %i', $id);
        }
    }

    // --- confirm / reject -------------------------------------------------

    public function testConfirmMovesDraftToConfirmed(): void
    {
        $ruleId = $this->insertRule(10);

        $response = $this->controller->confirmRule($this->auth(), $ruleId);
        $this->assertResponseStatus(200, $response);

        $row = $this->db->fetchRow('SELECT docState, docStateMain FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(40, (int) $row['docState']);
        $this->assertSame(3, (int) $row['docStateMain']);
    }

    public function testRejectMovesDraftToTrash(): void
    {
        $ruleId = $this->insertRule(10);

        $response = $this->controller->rejectRule($this->auth(), $ruleId);
        $this->assertResponseStatus(200, $response);

        $row = $this->db->fetchRow('SELECT docState FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(90, (int) $row['docState']);
    }

    public function testConfirmNonDraftReturns409(): void
    {
        $ruleId = $this->insertRule(40);

        $response = $this->controller->confirmRule($this->auth(), $ruleId);

        $this->assertResponseStatus(409, $response);
        $this->assertSame('INVALID_STATE', $response->getPayload()['error']['code']);
    }

    public function testConfirmMissingRuleReturns404(): void
    {
        $response = $this->controller->confirmRule($this->auth(), 999999999);

        $this->assertResponseStatus(404, $response);
    }

    public function testAnonymousReturns401(): void
    {
        $response = $this->controller->confirmRule(AuthContext::anonymous(), 1);

        $this->assertResponseStatus(401, $response);
    }

    // --- confirm → čekající řádky Ostatní (D8) ------------------------------

    public function testConfirmArchivesWaitingOtherMessagesFromSender(): void
    {
        $ruleId = $this->insertRule(10, 'archiveIfOther');
        $confident = $this->insertWaitingOtherMessage(self::TEST_SENDER, 0.9);
        $unsure = $this->insertWaitingOtherMessage(self::TEST_SENDER, 0.3);
        $noConfidence = $this->insertWaitingOtherMessage(self::TEST_SENDER, null);
        $otherSender = $this->insertWaitingOtherMessage('someone-else@example.com', 0.95);
        $manual = $this->insertWaitingOtherMessage(self::TEST_SENDER, 0.95, ['source_type' => 1]);

        $response = $this->controller->confirmRule($this->auth(), $ruleId);
        $this->assertResponseStatus(200, $response);

        $row = $this->db->fetchRow(
            'SELECT docState, docStateMain, analysis_state, auto_disposed_by, auto_disposed_at'
            . ' FROM core_mail_incoming_messages WHERE id = %i',
            $confident,
        );
        $this->assertSame(80, (int) $row['docState']);
        $this->assertSame(4, (int) $row['docStateMain']);
        $this->assertSame(30, (int) $row['analysis_state'], 'po analýze zůstává analyzovaná (D7)');
        $this->assertSame($ruleId, (int) $row['auto_disposed_by']);
        $this->assertNotNull($row['auto_disposed_at']);

        foreach (['unsure' => $unsure, 'noConfidence' => $noConfidence, 'otherSender' => $otherSender, 'manual' => $manual] as $label => $id) {
            $row = $this->db->fetchRow('SELECT docState, auto_disposed_by FROM core_mail_incoming_messages WHERE id = %i', $id);
            $this->assertSame(10, (int) $row['docState'], $label);
            $this->assertNull($row['auto_disposed_by'], $label);
        }

        $rule = $this->db->fetchRow('SELECT docState, hit_count, last_hit_at FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(40, (int) $rule['docState']);
        $this->assertSame(1, (int) $rule['hit_count']);
        $this->assertNotNull($rule['last_hit_at']);
    }

    public function testRejectLeavesWaitingMessagesAlone(): void
    {
        $ruleId = $this->insertRule(10, 'archiveIfOther');
        $waiting = $this->insertWaitingOtherMessage(self::TEST_SENDER, 0.9);

        $this->assertResponseStatus(200, $this->controller->rejectRule($this->auth(), $ruleId));

        $row = $this->db->fetchRow('SELECT docState, auto_disposed_by FROM core_mail_incoming_messages WHERE id = %i', $waiting);
        $this->assertSame(10, (int) $row['docState']);
        $this->assertNull($row['auto_disposed_by']);
    }

    // --- undo auto-archive --------------------------------------------------

    public function testUndoKeepsAnalyzedStateForMessagesArchivedAfterAnalysis(): void
    {
        // D7: pre-triage (analysis_state 0) → znovu do fronty; po analýze (30) → beze změny.
        $ruleId = $this->insertRule(40);
        $preTriage = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 08:00:00');
        $afterAnalysis = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 09:00:00', 30);

        $response = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertResponseStatus(200, $response);
        $this->assertSame(2, $response->getPayload()['data']['restored']);

        $expectedQueued = $this->db->fetchRow('SELECT id FROM core_mail_ai_profiles WHERE is_active = 1 LIMIT 1') !== null ? 10 : 0;
        $row = $this->db->fetchRow('SELECT docState, analysis_state FROM core_mail_incoming_messages WHERE id = %i', $preTriage);
        $this->assertSame(10, (int) $row['docState']);
        $this->assertSame($expectedQueued, (int) $row['analysis_state']);

        $row = $this->db->fetchRow(
            'SELECT docState, analysis_state, auto_disposed_by, auto_disposed_at FROM core_mail_incoming_messages WHERE id = %i',
            $afterAnalysis,
        );
        $this->assertSame(10, (int) $row['docState']);
        $this->assertSame(30, (int) $row['analysis_state']);
        $this->assertNull($row['auto_disposed_by']);
        $this->assertNull($row['auto_disposed_at']);
    }

    public function testUndoRestoresTodaysAutoArchivedMessages(): void
    {
        $ruleId = $this->insertRule(40);
        $m1 = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 08:00:00');
        $m2 = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 09:30:00');
        // Starší auto-archiv nesmí být dotčen.
        $mOld = $this->insertAutoArchivedMessage($ruleId, date('Y-m-d', strtotime('-5 days')) . ' 10:00:00');

        $response = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertResponseStatus(200, $response);
        $this->assertSame(2, $response->getPayload()['data']['restored']);

        foreach ([$m1, $m2] as $id) {
            $row = $this->db->fetchRow(
                'SELECT docState, docStateMain, analysis_state, auto_disposed_by, auto_disposed_at'
                . ' FROM core_mail_incoming_messages WHERE id = %i',
                $id,
            );
            $this->assertSame(10, (int) $row['docState']);
            $this->assertSame(1, (int) $row['docStateMain']);
            $this->assertNull($row['auto_disposed_by']);
            $this->assertNull($row['auto_disposed_at']);
            // Re-queue analýzy: 10 s aktivním AI profilem, jinak 0.
            $expected = $this->db->fetchRow('SELECT id FROM core_mail_ai_profiles WHERE is_active = 1 LIMIT 1') !== null ? 10 : 0;
            $this->assertSame($expected, (int) $row['analysis_state']);
        }

        $old = $this->db->fetchRow('SELECT docState, auto_disposed_by FROM core_mail_incoming_messages WHERE id = %i', $mOld);
        $this->assertSame(80, (int) $old['docState']);
        $this->assertNotNull($old['auto_disposed_by']);
    }

    public function testRepeatedUndoRestoresNothing(): void
    {
        $ruleId = $this->insertRule(40);
        $this->insertAutoArchivedMessage($ruleId, date('Y-m-d') . ' 08:00:00');

        $first = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertSame(1, $first->getPayload()['data']['restored']);

        $second = $this->controller->undoAutoArchive($this->auth(), $this->request([]));
        $this->assertSame(0, $second->getPayload()['data']['restored']);
    }

    public function testUndoRejectsOldDate(): void
    {
        $response = $this->controller->undoAutoArchive(
            $this->auth(),
            $this->request(['date' => date('Y-m-d', strtotime('-3 days'))]),
        );

        $this->assertResponseStatus(422, $response);
    }

    // --- helpers -------------------------------------------------------------

    private function auth(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'test-token');
    }

    private function request(array $body): Request
    {
        return Request::fromArray('POST', '/_mail/auto-archive/undo', [], (string) json_encode($body), [
            'HTTP_HOST' => 'test.local',
            'REMOTE_ADDR' => '127.0.0.1',
            'CONTENT_TYPE' => 'application/json',
        ]);
    }

    private function insertRule(int $docState, string $disposition = 'archive'): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_sender_rules', [
            'pattern_kind' => 'email',
            'pattern' => self::TEST_SENDER,
            'disposition' => $disposition,
            'origin' => 'suggested',
            'hit_count' => 0,
            'notice' => 'IT: endpoint test',
            'created' => $now,
            'modified' => $now,
            'docState' => $docState,
            'docStateMain' => [10 => 1, 40 => 3, 90 => 5][$docState] ?? 1,
        ])->execute();

        $id = (int) $dibi->getInsertId();
        $this->createdRuleIds[] = $id;
        return $id;
    }

    /**
     * Čekající řádek Ostatní: Nová (10), analyzovaná (30), `other`, bez
     * ručního nahrání, s poslední úspěšnou analýzou nesoucí jistotu
     * klasifikace v `analysis_json` (null = bez jistoty).
     *
     * @param array<string, mixed> $overrides
     */
    private function insertWaitingOtherMessage(string $sender, ?float $confidence, array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', $overrides + [
            'message_id' => 'MSG-IT-' . uniqid(),
            'mailbox' => $this->mailboxId,
            'subject' => 'IT-SR: waiting other',
            'sender_email' => $sender,
            'received_at' => $now,
            'source_type' => 2,
            'primary_type' => 'other',
            'primary_type_source' => 'ai',
            'analysis_state' => 30,
            'docState' => 10,
            'docStateMain' => 1,
            'created' => $now,
            'modified' => $now,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdMessageIds[] = $id;

        $classification = ['primary_type' => 'other'];
        if ($confidence !== null) {
            $classification['confidence'] = $confidence;
        }
        $dibi->insert('core_mail_message_analyses', [
            'message' => $id,
            'analyzed_at' => $now,
            'status' => 2,
            'model_name' => 'fixture-model',
            'prompt_version' => 'v4.0.0',
            'analysis_json' => json_encode(['message_classification' => $classification]),
            'created' => $now,
        ])->execute();

        return $id;
    }

    private function insertAutoArchivedMessage(int $ruleId, string $disposedAt, int $analysisState = 0): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', [
            'message_id' => 'MSG-IT-' . uniqid(),
            'mailbox' => $this->mailboxId,
            'subject' => 'IT-SR: auto-archived',
            'sender_email' => self::TEST_SENDER,
            'received_at' => $disposedAt,
            'source_type' => 2,
            'analysis_state' => $analysisState,
            'auto_disposed_by' => $ruleId,
            'auto_disposed_at' => $disposedAt,
            'docState' => 80,
            'docStateMain' => 4,
            'created' => $now,
            'modified' => $now,
        ])->execute();

        $id = (int) $dibi->getInsertId();
        $this->createdMessageIds[] = $id;
        return $id;
    }

    private function assertResponseStatus(int $expected, \Shipard\Api\Response $response): void
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        $actual = (int) $prop->getValue($response);
        $payload = $response->getPayload();
        $this->assertSame($expected, $actual, 'Unexpected status with payload: ' . json_encode($payload));
    }
}
