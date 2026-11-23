<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Exchange;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Document\Interpret\DocumentProposal;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * End-to-end pokrytí message-centrického apply (tasks/mail-message-centric.md):
 * analýza s canonical návrhem → applyMessage → vstup návrhu (pokyn) →
 * docs_core_heads + partner + supplier_codes mapping + lineage
 * (heads.source_message ↔ messages.target_*) + verdikt na analýze
 * (resolution 40) — a unapply reverz. Spustitelné s
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`.
 */
class AiProposalApplyTest extends IntegrationTestCase
{
    private const FIXTURE_PREFIX = 'IT-AIX';

    private AnalysisController $controller;
    private ConfigRuntime $configRuntime;
    private int $analysisRowId = 0;
    private int $messageRowId = 0;

    /** @var list<int> */
    private array $createdDocIds = [];
    /** @var list<int> */
    private array $createdPersonIds = [];
    /** @var list<int> */
    private array $createdItemIds = [];

    private ?int $ownCompanyPersonId = null;
    private bool $createdOwnCompany = false;

    protected function setUp(): void
    {
        parent::setUp();

        $modulePathResolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $modulePathResolver);

        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $invniSeries = $this->db->fetchRow(
            'SELECT id FROM docs_core_number_series WHERE doc_type = %s AND docState IN (%i,%i,%i) LIMIT 1',
            'invni', 10, 40, 80,
        );
        if ($invniSeries === null) {
            $this->markTestSkipped('DS missing invni number_series — run ds-upgrade.');
        }

        $applier = DocumentProposal::create(
            $this->db->getDibiConnection(),
            $this->configRuntime,
            $this->dsConfig,
            $documentRegistry,
            $this->tables,
        );

        $this->controller = new AnalysisController(
            $this->db, $this->dsConfig, $this->realDsPath, $this->tables, $documentRegistry,
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            $this->configRuntime,
        );

        $this->ensureOwnCompany();
    }

    private function ensureOwnCompany(): void
    {
        $row = $this->db->fetchRow('SELECT id FROM base_persons_persons WHERE is_own = 1 LIMIT 1');
        if ($row !== null) {
            $this->ownCompanyPersonId = (int) $row['id'];
            return;
        }
        $this->db->getDibiConnection()->insert('base_persons_persons', [
            'person_id' => 'F-OWN-AIX',
            'person_type' => 2,
            'full_name' => self::FIXTURE_PREFIX . ' Own',
            'last_name' => self::FIXTURE_PREFIX . ' Own',
            'first_name' => '',
            'company_id' => '00000099',
            'is_own' => 1,
            'docState' => 40,
            'docStateMain' => 3,
        ])->execute();
        $this->ownCompanyPersonId = (int) $this->db->getDibiConnection()->getInsertId();
        $this->createdOwnCompany = true;
    }

    /**
     * Provisioning message-centrického návrhu: zpráva (analysis_state 30,
     * K řešení 20) + poslední úspěšná analýza s canonical_json. Vrací ndx
     * zprávy — akce se od refaktoru volají nad zprávou, ne nad extracted
     * řádkem (ten zanikl).
     *
     * @param array<string, mixed> $canonical  Canonical návrhu (nebo
     *                                          _validationError wrapper).
     */
    private function provisionProposal(array $canonical, string $proposedType = 'invoiceReceived'): int
    {
        $dibi = $this->db->getDibiConnection();
        $now = date('Y-m-d H:i:s');

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS missing core_mail_mailboxes — run mail-router-bootstrap.');
        }
        $mailboxId = (int) $mailbox['id'];

        $dibi->insert('core_mail_incoming_messages', [
            'message_id' => self::FIXTURE_PREFIX . '-MSG-' . uniqid(),
            'mailbox' => $mailboxId,
            'primary_type' => $proposedType,
            'subject' => self::FIXTURE_PREFIX . ' Test invoice',
            'sender_email' => 'vendor@example.cz',
            'received_at' => $now,
            'source_type' => 2,
            'ai_analysis_enabled' => 1,
            'needs_reanalysis' => 0,
            'analysis_state' => 30, // Analyzováno
            'docState' => 20,       // K řešení
            'docStateMain' => 2,
            'created' => $now,
            'modified' => $now,
        ])->execute();
        $this->messageRowId = (int) $dibi->getInsertId();

        $dibi->insert('core_mail_message_analyses', [
            'message' => $this->messageRowId,
            'analyzed_at' => $now,
            'status' => 2,
            'model_name' => 'fixture',
            'prompt_version' => 'v4.0.0',
            'canonical_json' => json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'proposed_type' => $proposedType,
            'confidence' => 0.95,
            'created' => $now,
        ])->execute();
        $this->analysisRowId = (int) $dibi->getInsertId();

        return $this->messageRowId;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCanonical(string $uniqueSuffix): array
    {
        $payload = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $payload['supplier']['name'] = self::FIXTURE_PREFIX . ' Vendor ' . $uniqueSuffix;
        $payload['supplier']['companyId'] = '88' . substr((string) (microtime(true) * 10000), -6);
        $payload['supplier']['vatId'] = 'CZ' . $payload['supplier']['companyId'];
        $payload['supplier']['taxId'] = 'CZ' . $payload['supplier']['companyId'];
        $payload['docNumber'] = self::FIXTURE_PREFIX . '-' . $uniqueSuffix;
        return $payload;
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();

        if ($this->analysisRowId > 0) {
            $dibi->query('DELETE FROM core_mail_message_analyses WHERE id = %i', $this->analysisRowId);
        }
        if ($this->messageRowId > 0) {
            $dibi->query('DELETE FROM core_mail_incoming_messages WHERE id = %i', $this->messageRowId);
        }
        foreach ($this->createdDocIds as $id) {
            $dibi->query('DELETE FROM docs_core_rows WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_vat_recap WHERE doc_head = %i', $id);
            $dibi->query('DELETE FROM docs_core_heads WHERE id = %i', $id);
        }
        foreach ($this->createdPersonIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_bank_accounts WHERE person = %i', $id);
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $id);
        }
        foreach ($this->createdItemIds as $id) {
            $dibi->query('DELETE FROM economy_items_supplier_codes WHERE item = %i', $id);
            $dibi->query('DELETE FROM economy_items WHERE id = %i', $id);
        }
        if ($this->createdOwnCompany && $this->ownCompanyPersonId !== null) {
            $dibi->query('DELETE FROM base_persons_persons WHERE id = %i', $this->ownCompanyPersonId);
        }
    }

    private function authed(int $userId = 1): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function request(): Request
    {
        return Request::fromArray('POST', '/api/v1/_mail/messages/x/apply', [], '', ['HTTP_HOST' => 'test']);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    /** @return array<string, mixed> Řádek poslední analýzy (verdikt čteme SELECTem). */
    private function analysisRow(): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM core_mail_message_analyses WHERE id = %i',
            $this->analysisRowId,
        );
        $this->assertNotNull($row);
        return $row;
    }

    /** Zaeviduje partner/item vytvořené apply-em pro úklid v tearDown. */
    private function trackCreatedFromDoc(int $savedDocId): void
    {
        $this->createdDocIds[] = $savedDocId;
        $head = $this->db->fetchRow('SELECT partner FROM docs_core_heads WHERE id = %i', $savedDocId);
        if ($head !== null) {
            $this->createdPersonIds[] = (int) $head['partner'];
        }
        foreach ($this->db->fetchAll('SELECT item FROM docs_core_rows WHERE doc_head = %i', $savedDocId) as $r) {
            $this->createdItemIds[] = (int) $r['item'];
        }
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testApplyCreatesDocWithBidirectionalLineage(): void
    {
        $suffix = (string) uniqid();
        $messageNdx = $this->provisionProposal($this->buildCanonical($suffix));

        $resp = $this->controller->applyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(
            200,
            $this->statusOf($resp),
            'Expected success: ' . json_encode($resp->getPayload()),
        );

        $payload = $resp->getPayload();
        $this->assertTrue($payload['success']);
        $savedDocId = (int) $payload['data']['savedDocId'];
        $this->createdDocIds[] = $savedDocId;

        // 1. docs_core_heads row exists with lineage stamped
        $head = $this->db->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $savedDocId);
        $this->assertNotNull($head);
        $this->assertSame('invni', (string) $head['doc_type']);
        $this->assertSame('aiExtraction', (string) $head['source_kind']);
        $this->assertSame($messageNdx, (int) $head['source_message']);
        $this->assertNotNull($head['source_extracted_at']);

        // 2. Partner was autocreated (companyId present → safe mode allowed)
        $partnerId = (int) $head['partner'];
        $this->createdPersonIds[] = $partnerId;
        $partner = $this->db->fetchRow('SELECT * FROM base_persons_persons WHERE id = %i', $partnerId);
        $this->assertNotNull($partner);
        $this->assertStringStartsWith(self::FIXTURE_PREFIX, (string) $partner['full_name']);

        // 3. Row + item created
        $rows = $this->db->fetchAll('SELECT * FROM docs_core_rows WHERE doc_head = %i', $savedDocId);
        $this->assertCount(1, $rows);
        $newItemId = (int) $rows[0]['item'];
        $this->createdItemIds[] = $newItemId;

        // 4. Per-partner supplier-code mapping written
        $mapping = $this->db->fetchRow(
            'SELECT * FROM economy_items_supplier_codes WHERE person = %i AND supplier_code = %s',
            $partnerId, 'KONZ-001',
        );
        $this->assertNotNull($mapping);

        // 5. Reverzní lineage na zprávě (target_*).
        $message = $this->db->fetchRow(
            'SELECT * FROM core_mail_incoming_messages WHERE id = %i',
            $messageNdx,
        );
        $this->assertSame('docs_core_heads', (string) $message['target_table_id']);
        $this->assertSame($savedDocId, (int) $message['target_row']);

        // 6. Verdikt na analýze: resolution 40 + resolved_at/by.
        $analysis = $this->analysisRow();
        $this->assertSame(MessageProposalApplier::RESOLUTION_APPLIED, (int) $analysis['resolution']);
        $this->assertNotNull($analysis['resolved_at']);
        $this->assertSame(1, (int) $analysis['resolved_by']);

        // 7. Zpráva → Hotovo (40/3).
        $this->assertSame(40, (int) $message['docState']);
        $this->assertSame(3, (int) $message['docStateMain']);
    }

    public function testApplyIdempotentOnSecondClick(): void
    {
        $suffix = (string) uniqid();
        $messageNdx = $this->provisionProposal($this->buildCanonical($suffix));

        $first = $this->controller->applyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(200, $this->statusOf($first));
        $savedDocId = (int) $first->getPayload()['data']['savedDocId'];
        $this->trackCreatedFromDoc($savedDocId);

        // Second call — should idempotently return the same savedDocId
        $second = $this->controller->applyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(200, $this->statusOf($second));
        $secondPayload = $second->getPayload();
        $this->assertSame($savedDocId, (int) $secondPayload['data']['savedDocId']);
        $this->assertTrue($secondPayload['data']['idempotent'] ?? false);

        // No duplicate rows in docs_core_heads
        $heads = $this->db->fetchAll(
            'SELECT id FROM docs_core_heads WHERE source_message = %i',
            $messageNdx,
        );
        $this->assertCount(1, $heads);
    }

    public function testApplyRejectsAiFailedProposal(): void
    {
        // /result uložil forenzní wrapper (nevalidní AI výstup) — apply
        // musí odmítnout a poslat uživatele na reanalýzu.
        $messageNdx = $this->provisionProposal([
            '_validationError' => 'Canonical schema validation failed',
            '_validationIssues' => [['path' => 'docType', 'message' => 'missing']],
            '_rawOutput' => ['format' => 'shpd.docs.document'],
        ]);

        $resp = $this->controller->applyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('AI_OUTPUT_INVALID', $resp->getPayload()['error']['code']);

        // Nic se nezapsalo: bez targetu, bez verdiktu.
        $message = $this->db->fetchRow(
            'SELECT target_row, docState FROM core_mail_incoming_messages WHERE id = %i', $messageNdx,
        );
        $this->assertTrue($message['target_row'] === null || (int) $message['target_row'] === 0);
        $this->assertSame(20, (int) $message['docState']);
        $this->assertNull($this->analysisRow()['resolution']);
    }

    // ── unapply (undo) round-trip ────────────────────────────────────────────

    public function testUnapplyRoundTripTrashesDocAndReopensProposal(): void
    {
        $suffix = (string) uniqid();
        $messageNdx = $this->provisionProposal($this->buildCanonical($suffix));

        $apply = $this->controller->applyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(200, $this->statusOf($apply), 'apply: ' . json_encode($apply->getPayload()));
        $savedDocId = (int) $apply->getPayload()['data']['savedDocId'];
        $this->trackCreatedFromDoc($savedDocId);

        // Apply posunul zprávu na Hotovo (40).
        $this->assertSame(40, (int) $this->db->fetchRow(
            'SELECT docState FROM core_mail_incoming_messages WHERE id = %i', $messageNdx,
        )['docState']);

        $undo = $this->controller->unapplyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(200, $this->statusOf($undo), 'unapply: ' . json_encode($undo->getPayload()));
        $this->assertSame($savedDocId, (int) $undo->getPayload()['data']['trashedDocId']);

        // Doklad → Koš (90/5), ne hard-delete.
        $doc = $this->db->fetchRow('SELECT docState, docStateMain FROM docs_core_heads WHERE id = %i', $savedDocId);
        $this->assertSame(90, (int) $doc['docState']);
        $this->assertSame(5, (int) $doc['docStateMain']);

        // Zpráva: target_* vynulované, reverz 40 → 20 (K řešení).
        $message = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $messageNdx);
        $this->assertNull($message['target_table_id']);
        $this->assertTrue($message['target_row'] === null || (int) $message['target_row'] === 0);
        $this->assertSame(20, (int) $message['docState']);
        $this->assertSame(2, (int) $message['docStateMain']);

        // Analýza: návrh znovu otevřený (resolution/resolved_* NULL).
        $analysis = $this->analysisRow();
        $this->assertNull($analysis['resolution']);
        $this->assertNull($analysis['resolved_at']);
        $this->assertNull($analysis['resolved_by']);
    }

    public function testUnapplyRejectsUnappliedProposal(): void
    {
        $suffix = (string) uniqid();
        $messageNdx = $this->provisionProposal($this->buildCanonical($suffix)); // resolution NULL

        $resp = $this->controller->unapplyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('INVALID_STATE', $resp->getPayload()['error']['code']);
    }

    public function testUnapplyRejectsAdvancedDocument(): void
    {
        $suffix = (string) uniqid();
        $messageNdx = $this->provisionProposal($this->buildCanonical($suffix));

        $apply = $this->controller->applyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(200, $this->statusOf($apply), 'apply: ' . json_encode($apply->getPayload()));
        $savedDocId = (int) $apply->getPayload()['data']['savedDocId'];
        $this->trackCreatedFromDoc($savedDocId);

        // Doklad posunut z Konceptu dál (10→40) → unapply musí odmítnout.
        $this->db->getDibiConnection()->query(
            'UPDATE docs_core_heads SET docState = 40 WHERE id = %i', $savedDocId,
        );

        $resp = $this->controller->unapplyMessage($this->authed(), $this->request(), $messageNdx);
        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('DOC_ADVANCED', $resp->getPayload()['error']['code']);

        // Verdikt zůstal netknutý (resolution 40, target živý).
        $this->assertSame(MessageProposalApplier::RESOLUTION_APPLIED, (int) $this->analysisRow()['resolution']);
        $this->assertSame($savedDocId, (int) $this->db->fetchRow(
            'SELECT target_row FROM core_mail_incoming_messages WHERE id = %i', $messageNdx,
        )['target_row']);
    }
}
