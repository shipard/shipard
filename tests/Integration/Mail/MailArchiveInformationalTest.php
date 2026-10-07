<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\MailController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Archivovat vše v sekci Ostatní + Vrátit (tasks/mail-other-attention.md
 * D5, #105): `POST /_mail/messages/archive-informational` odklidí jen řádky
 * čekající ostatní pošty s pozorností info / promo bez otevřeného návrhu
 * (přes TableGateway — docStateMain z configu), `restore-archived` vrací jen
 * ručně archivované zprávy (bez `auto_disposed_by`). Reálná DB,
 * spustitelné s `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`.
 */
class MailArchiveInformationalTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-ARCHINF';

    private MailController $controller;
    private int $mailboxId = 0;

    /** @var list<int> */
    private array $createdMessageIds = [];
    /** @var list<int> */
    private array $createdAnalysisIds = [];
    /** @var list<int> */
    private array $createdRuleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS missing core_mail_mailboxes — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $this->controller = new MailController(
            $this->db,
            $this->dsPath,
            $this->tables,
            DocumentLoader::load($this->dsConfig, $resolver),
            ConfigRuntime::load($this->realDsPath, 'cs'),
            $this->dsConfig,
        );
    }

    protected function onTearDown(): void
    {
        foreach ($this->createdAnalysisIds as $id) {
            $this->db->execute('DELETE FROM core_mail_message_analyses WHERE id = %i', $id);
        }
        foreach ($this->createdMessageIds as $id) {
            $this->db->execute('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        foreach ($this->createdRuleIds as $id) {
            $this->db->execute('DELETE FROM core_mail_sender_rules WHERE id = %i', $id);
        }
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /** @param array<string, mixed> $overrides */
    private function message(array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', $overrides + [
            'message_id'          => self::PREFIX . '-' . uniqid(),
            'mailbox'             => $this->mailboxId,
            'primary_type'        => 'other',
            'primary_type_source' => 'ai',
            'subject'             => self::PREFIX . ' message',
            'sender_email'        => 'notify@example.cz',
            'received_at'         => $now,
            'source_type'         => 2,
            'analysis_state'      => 30,
            'docState'            => 10,
            'docStateMain'        => 1,
            'created'             => $now,
            'modified'            => $now,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdMessageIds[] = $id;
        return $id;
    }

    /** Poslední úspěšný běh s otevřeným návrhem (canonical bez verdiktu). */
    private function openProposal(int $messageNdx): void
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_message_analyses', [
            'message'        => $messageNdx,
            'analyzed_at'    => $now,
            'status'         => 2,
            'model_name'     => 'fixture-model',
            'prompt_version' => 'v4.7.0',
            'canonical_json' => '{"docType":"invoiceReceived"}',
            'proposed_type'  => 'invoiceReceived',
            'created'        => $now,
        ])->execute();
        $this->createdAnalysisIds[] = (int) $dibi->getInsertId();
    }

    private function rule(): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_sender_rules', [
            'pattern_kind' => 'email',
            'pattern'      => self::PREFIX . '-rule@example.cz',
            'disposition'  => 'archiveIfOther',
            'origin'       => 'user',
            'hit_count'    => 0,
            'created'      => $now,
            'modified'     => $now,
            'docState'     => 40,
            'docStateMain' => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdRuleIds[] = $id;
        return $id;
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, 1, 'session', 'shpd_st_test');
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): Request
    {
        return Request::fromArray('POST', $path, [], (string) json_encode($body), ['HTTP_HOST' => 'test']);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $id);
        $this->assertNotNull($row);
        return $row;
    }

    // ── tests ──────────────────────────────────────────────────────────────

    public function testArchiveInformationalArchivesOnlyInfoAndPromoWithoutOpenProposal(): void
    {
        $info = $this->message(['attention' => 'info']);
        $promo = $this->message(['attention' => 'promo']);
        $action = $this->message(['attention' => 'action', 'action_note' => 'Zaplatit']);
        $legacy = $this->message(['attention' => null]);
        $withProposal = $this->message(['attention' => 'info']);
        $this->openProposal($withProposal);
        $inProgress = $this->message(['attention' => 'info', 'docState' => 20, 'docStateMain' => 2]);
        $invoice = $this->message(['attention' => null, 'primary_type' => 'invoiceReceived']);

        $resp = $this->controller->archiveInformational($this->auth());
        $this->assertSame(200, $this->statusOf($resp), json_encode($resp->getPayload()));
        $data = $resp->getPayload()['data'];

        // Fixturové zprávy jiných běhů na DS neřešíme — ověřujeme jen svoje id.
        $this->assertContains($info, $data['archived']);
        $this->assertContains($promo, $data['archived']);
        foreach ([$action, $legacy, $withProposal, $inProgress, $invoice] as $id) {
            $this->assertNotContains($id, $data['archived']);
        }
        $this->assertSame(count($data['archived']), $data['count']);

        foreach ([$info, $promo] as $id) {
            $row = $this->row($id);
            $this->assertSame(80, (int) $row['docState']);
            $this->assertSame(4, (int) $row['docStateMain'], 'docStateMain z configu přes gateway');
            $this->assertSame(30, (int) $row['analysis_state']);
            $this->assertNull($row['auto_disposed_by'], 'ruční archiv bez auditu pravidla');
        }
        $this->assertSame(10, (int) $this->row($action)['docState'], 'K vyřízení zůstává');
        $this->assertSame(10, (int) $this->row($legacy)['docState'], 'NULL pozornost zůstává per řádek');
        $this->assertSame(10, (int) $this->row($withProposal)['docState']);
        $this->assertSame(20, (int) $this->row($inProgress)['docState']);
    }

    public function testRestoreArchivedRestoresOnlyManuallyArchivedIds(): void
    {
        $manual = $this->message(['attention' => 'info', 'docState' => 80, 'docStateMain' => 4]);
        $byRule = $this->message([
            'attention' => 'info', 'docState' => 80, 'docStateMain' => 4,
            'auto_disposed_by' => $this->rule(), 'auto_disposed_at' => date('Y-m-d H:i:s'),
        ]);
        $stillNew = $this->message(['attention' => 'info']);

        $resp = $this->controller->restoreArchived($this->auth(), $this->post(
            '/_mail/messages/restore-archived',
            ['ids' => [$manual, $byRule, $stillNew, 0, 'x', 999999999]],
        ));
        $this->assertSame(200, $this->statusOf($resp), json_encode($resp->getPayload()));
        $this->assertSame(1, $resp->getPayload()['data']['restored']);

        $row = $this->row($manual);
        $this->assertSame(10, (int) $row['docState']);
        $this->assertSame(1, (int) $row['docStateMain']);
        $this->assertSame(30, (int) $row['analysis_state'], 'bez nové analýzy');
        $this->assertSame(80, (int) $this->row($byRule)['docState'], 'pravidlem archivovaná má vlastní Vrátit vše');
        $this->assertSame(10, (int) $this->row($stillNew)['docState']);
    }

    public function testRestoreArchivedValidatesBody(): void
    {
        $resp = $this->controller->restoreArchived($this->auth(), $this->post('/_mail/messages/restore-archived', []));
        $this->assertSame(422, $this->statusOf($resp));

        $resp = $this->controller->restoreArchived($this->auth(), $this->post('/_mail/messages/restore-archived', ['ids' => []]));
        $this->assertSame(200, $this->statusOf($resp));
        $this->assertSame(0, $resp->getPayload()['data']['restored']);
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $anonymous = new AuthContext(false, null, null, null);
        $this->assertSame(401, $this->statusOf($this->controller->archiveInformational($anonymous)));
        $this->assertSame(401, $this->statusOf($this->controller->restoreArchived(
            $anonymous,
            $this->post('/_mail/messages/restore-archived', ['ids' => [1]]),
        )));
    }
}
