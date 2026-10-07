<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\DocumentLoader;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Integrační pokrytí transakce `POST /_mail/analysis/{ndx}/result`
 * (kontrakt v4, message-centricky — tasks/mail-message-centric.md):
 * povinná message_classification, volitelný `document` (0..1) →
 * analyses.canonical_json + proposed_type, uvolnění claimu,
 * analysis_state → 30 a workflow posun Nová → K řešení jen při validním
 * dokumentu. Reálná DB + reálný SchemaValidator/ConfigRuntime; analyzer
 * auth jede přes skutečného systémového uživatele `_ai_analyzer`
 * (verifyAnalyzerAuth kontroluje login v core_system_users) a claim token
 * vložený přímo do core_mail_analysis_claims. Spustitelné s
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`.
 */
class AnalysisResultEndpointTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-AIRES';

    private AnalysisController $controller;
    private ConfigRuntime $configRuntime;
    private int $mailboxId = 0;

    private int $analyzerUserId = 0;
    private bool $createdAnalyzerUser = false;

    private int $messageRowId = 0;
    private int $claimRowId = 0;

    /** @var list<int> */
    private array $createdRuleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        $documentRegistry = DocumentLoader::load($this->dsConfig, $resolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS missing core_mail_mailboxes — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        // Analyzer auth: verifyAnalyzerAuth vyžaduje api_key token uživatele
        // s loginem _ai_analyzer — zajistí (idempotentně) provisioner.
        $user = new AIAnalyzerProvisioner($this->db)->ensureAnalyzerUser();
        $this->analyzerUserId = $user['id'];
        $this->createdAnalyzerUser = $user['created'];

        // DocumentApplier tu není potřeba (/result doklady nezakládá) —
        // canonical validaci nese SchemaValidator + ConfigRuntime (registry
        // routing v validateAndStoreCanonical).
        $this->controller = new AnalysisController(
            $this->db, $this->dsConfig, $this->realDsPath, $this->tables, $documentRegistry,
            new SchemaValidator(SchemaLoader::default()),
            null,
            $this->configRuntime,
        );
    }

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();

        if ($this->messageRowId > 0) {
            $dibi->query('DELETE FROM core_mail_message_analyses WHERE message = %i', $this->messageRowId);
            $dibi->query('DELETE FROM core_mail_analysis_claims WHERE message = %i', $this->messageRowId);
            $dibi->query('DELETE FROM core_mail_incoming_messages WHERE id = %i', $this->messageRowId);
        }
        if ($this->createdAnalyzerUser && $this->analyzerUserId > 0) {
            $dibi->query('DELETE FROM core_system_users WHERE id = %i', $this->analyzerUserId);
        }
        foreach ($this->createdRuleIds as $id) {
            $dibi->query('DELETE FROM core_mail_sender_rules WHERE id = %i', $id);
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /**
     * Zpráva v Nové (10), analysis_state 20 (Analyzuje se — drží claim).
     *
     * @param array<string, mixed> $overrides sloupce navíc / přepsané
     */
    private function provisionMessage(array $overrides = []): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', $overrides + [
            'message_id'          => self::PREFIX . '-MSG-' . uniqid(),
            'mailbox'             => $this->mailboxId,
            'primary_type'        => 'other',
            'primary_type_source' => 'mailbox',
            'subject'             => self::PREFIX . ' Test message',
            'sender_email'        => 'vendor@example.cz',
            'received_at'         => $now,
            'source_type'         => 2,
            'ai_analysis_enabled' => 1,
            'needs_reanalysis'    => 0,
            'analysis_state'      => 20, // Analyzuje se
            'docState'            => 10, // Nová
            'docStateMain'        => 1,
            'created'             => $now,
            'modified'            => $now,
        ])->execute();
        $this->messageRowId = (int) $dibi->getInsertId();
        return $this->messageRowId;
    }

    /** Potvrzené pravidlo (40) pro odesílatele fixturové zprávy. */
    private function insertConfirmedRule(string $disposition, string $kind = 'email', string $pattern = 'vendor@example.cz'): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_sender_rules', [
            'pattern_kind' => $kind,
            'pattern' => $pattern,
            'disposition' => $disposition,
            'origin' => 'user',
            'hit_count' => 0,
            'notice' => self::PREFIX . ' rule',
            'created' => $now,
            'modified' => $now,
            'docState' => 40,
            'docStateMain' => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->createdRuleIds[] = $id;
        return $id;
    }

    /** Dřívější úspěšný běh analýzy zprávy (status 2) — zpráva už není „na první analýze“. */
    private function insertPriorSuccessfulAnalysis(int $messageNdx): void
    {
        $past = date('Y-m-d H:i:s', time() - 3600);
        $this->db->getDibiConnection()->insert('core_mail_message_analyses', [
            'message' => $messageNdx,
            'analyzed_at' => $past,
            'status' => 2,
            'model_name' => 'fixture-model',
            'prompt_version' => 'v4.0.0',
            'analysis_json' => '{"message_classification":{"primary_type":"other","confidence":0.9}}',
            'created' => $past,
        ])->execute();
    }

    /** Tělo běhu bez dokumentu s klasifikací `other` a danou jistotou. */
    private function otherBody(?float $confidence): array
    {
        $body = $this->baseBody();
        $body['message_classification'] = ['primary_type' => 'other'];
        if ($confidence !== null) {
            $body['message_classification']['confidence'] = $confidence;
        }
        $body['overall_confidence'] = 0.7;
        return $body;
    }

    /** Zpráva zůstala v Nové bez auditu a pravidlo bez zásahu. */
    private function assertNotDisposed(int $ruleId): void
    {
        $message = $this->messageRow();
        $this->assertSame(10, (int) $message['docState']);
        $this->assertSame(30, (int) $message['analysis_state']);
        $this->assertNull($message['auto_disposed_by']);
        $this->assertNull($message['auto_disposed_at']);
        $rule = $this->db->fetchRow('SELECT hit_count FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(0, (int) $rule['hit_count']);
    }

    /** Aktivní claim pro zprávu — vrací claim token pro X-Claim-Token. */
    private function createClaim(int $messageNdx): string
    {
        $token = 'ct_' . bin2hex(random_bytes(30));
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_analysis_claims', [
            'message'     => $messageNdx,
            'analyzer_id' => self::PREFIX . '-tester',
            'claim_token' => $token,
            'claimed_at'  => date('Y-m-d H:i:s'),
            'expires_at'  => date('Y-m-d H:i:s', time() + 300),
            'released'    => 0,
        ])->execute();
        $this->claimRowId = (int) $dibi->getInsertId();
        return $token;
    }

    /** @return array<string, mixed> Validní canonical (fixture happy path). */
    private function validCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * Základ těla resultu (kontrakt v4 — message_classification povinná).
     *
     * @return array<string, mixed>
     */
    private function baseBody(): array
    {
        return [
            'model_name' => 'fixture-model',
            'prompt_version' => 'v4.0.0',
            'message_classification' => [
                'primary_type' => 'invoiceReceived',
                'confidence' => 0.9,
            ],
        ];
    }

    private function analyzerAuth(): AuthContext
    {
        return new AuthContext(true, $this->analyzerUserId, 'api_key', 'shpd_ak_test');
    }

    /** @param array<string, mixed> $body */
    private function resultRequest(string $claimToken, array $body): Request
    {
        return Request::fromArray(
            'POST',
            '/api/v1/_mail/analysis/x/result',
            [],
            (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['HTTP_HOST' => 'test', 'HTTP_X_CLAIM_TOKEN' => $claimToken],
        );
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    /** @return array<string, mixed> */
    private function messageRow(): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM core_mail_incoming_messages WHERE id = %i',
            $this->messageRowId,
        );
        $this->assertNotNull($row);
        return $row;
    }

    /** @return array<string, mixed> */
    private function claimRow(): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM core_mail_analysis_claims WHERE id = %i',
            $this->claimRowId,
        );
        $this->assertNotNull($row);
        return $row;
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function testResultWithValidDocumentStoresCanonicalAndAdvancesMessage(): void
    {
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.9,
            'extracted_json' => $this->validCanonical(),
        ];
        $body['overall_confidence'] = 0.8;

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $body), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp), 'result: ' . json_encode($resp->getPayload()));
        $analysisNdx = (int) $resp->getPayload()['data']['analysis_ndx'];
        $this->assertGreaterThan(0, $analysisNdx);

        // 1. Analysis řádek: canonical_json s validním canonicalem (bez
        //    wrapperu), proposed_type + confidence návrhu (ne overall).
        $analysis = $this->db->fetchRow('SELECT * FROM core_mail_message_analyses WHERE id = %i', $analysisNdx);
        $this->assertNotNull($analysis);
        $this->assertSame($messageNdx, (int) $analysis['message']);
        $this->assertSame(2, (int) $analysis['status']);
        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $this->assertIsArray($canonical);
        $this->assertArrayNotHasKey('_validationError', $canonical);
        $this->assertSame('invoiceReceived', $canonical['docType']);
        $this->assertSame('invoiceReceived', (string) $analysis['proposed_type']);
        $this->assertEqualsWithDelta(0.9, (float) $analysis['confidence'], 0.001);
        $this->assertNull($analysis['resolution']);

        // 2. Claim uvolněný s důvodem result.
        $claim = $this->claimRow();
        $this->assertSame(1, (int) $claim['released']);
        $this->assertSame('result', (string) $claim['release_reason']);
        $this->assertNotNull($claim['released_at']);

        // 3. Zpráva: analysis_state → 30, workflow Nová → K řešení (10 → 20).
        $message = $this->messageRow();
        $this->assertSame(30, (int) $message['analysis_state']);
        $this->assertSame(0, (int) $message['needs_reanalysis']);
        $this->assertSame(20, (int) $message['docState']);
        $this->assertSame(2, (int) $message['docStateMain']);

        // 4. AI klasifikace zapsaná (source mailbox → ai smí přepsat).
        $this->assertSame('invoiceReceived', (string) $message['primary_type']);
        $this->assertSame('ai', (string) $message['primary_type_source']);
    }

    public function testResultWithInvalidCanonicalStoresWrapperAndKeepsMessageNew(): void
    {
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.4,
            // Schema-invalid výstup (chybí formatVersion/docType) → forenzní
            // wrapper, běh se přesto uloží a vrací 201.
            'extracted_json' => ['format' => 'shpd.docs.document'],
        ];

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $body), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp), 'result: ' . json_encode($resp->getPayload()));
        $analysisNdx = (int) $resp->getPayload()['data']['analysis_ndx'];

        $analysis = $this->db->fetchRow('SELECT * FROM core_mail_message_analyses WHERE id = %i', $analysisNdx);
        $canonical = json_decode((string) $analysis['canonical_json'], true);
        $this->assertIsArray($canonical);
        $this->assertArrayHasKey('_validationError', $canonical);
        $this->assertArrayHasKey('_rawOutput', $canonical);

        // Nevalidní dokument workflow neposouvá — zpráva zůstává v Nové.
        $message = $this->messageRow();
        $this->assertSame(10, (int) $message['docState']);
        $this->assertSame(30, (int) $message['analysis_state']);
        $this->assertSame(1, (int) $this->claimRow()['released']);
    }

    public function testResultWithoutDocumentKeepsMessageNew(): void
    {
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['message_classification']['primary_type'] = 'other';
        $body['overall_confidence'] = 0.7;

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $body), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp), 'result: ' . json_encode($resp->getPayload()));
        $analysisNdx = (int) $resp->getPayload()['data']['analysis_ndx'];

        // Bez dokumentu: canonical_json NULL, confidence = overall_confidence.
        $analysis = $this->db->fetchRow('SELECT * FROM core_mail_message_analyses WHERE id = %i', $analysisNdx);
        $this->assertNull($analysis['canonical_json']);
        $this->assertNull($analysis['proposed_type']);
        $this->assertEqualsWithDelta(0.7, (float) $analysis['confidence'], 0.001);

        // Zpráva zůstává v Nové (dashboard řeší karta ostatní pošty).
        $message = $this->messageRow();
        $this->assertSame(10, (int) $message['docState']);
        $this->assertSame(30, (int) $message['analysis_state']);
        $this->assertSame(1, (int) $this->claimRow()['released']);
    }

    // -------------------------------------------------------------------------
    // Pravidlo odesílatele po analýze (tasks/mail-sender-rules-after-analysis.md D4–D6)
    // -------------------------------------------------------------------------

    public function testResultOtherFromSenderWithArchiveIfOtherRuleArchivesMessage(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $this->otherBody(0.8)), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp), 'result: ' . json_encode($resp->getPayload()));

        // Archiv s auditem; analysis_state zůstává 30 (D7 — návrat bez nové analýzy).
        $message = $this->messageRow();
        $this->assertSame(80, (int) $message['docState']);
        $this->assertSame(4, (int) $message['docStateMain']);
        $this->assertSame(30, (int) $message['analysis_state']);
        $this->assertSame('other', (string) $message['primary_type']);
        $this->assertSame($ruleId, (int) $message['auto_disposed_by']);
        $this->assertNotNull($message['auto_disposed_at']);
        $this->assertSame(1, (int) $this->claimRow()['released']);

        $rule = $this->db->fetchRow('SELECT hit_count, last_hit_at FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(1, (int) $rule['hit_count']);
        $this->assertNotNull($rule['last_hit_at']);
    }

    public function testResultOtherWithArchiveRuleArchivesAfterAnalysisToo(): void
    {
        // D6: `archive` znamená „všechno“ — i zprávu, která pre-triage minula
        // (pravidlo potvrzené až po příjmu).
        $ruleId = $this->insertConfirmedRule('archive');
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $this->otherBody(0.95)), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp));

        $message = $this->messageRow();
        $this->assertSame(80, (int) $message['docState']);
        $this->assertSame($ruleId, (int) $message['auto_disposed_by']);
    }

    public function testResultWithDocumentFromRuledSenderIsNotArchived(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.9,
            'extracted_json' => $this->validCanonical(),
        ];

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $body), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp), 'result: ' . json_encode($resp->getPayload()));

        // Faktura od téhož odesílatele jde dál normálně (Nová → K řešení).
        $message = $this->messageRow();
        $this->assertSame(20, (int) $message['docState']);
        $this->assertNull($message['auto_disposed_by']);
        $rule = $this->db->fetchRow('SELECT hit_count FROM core_mail_sender_rules WHERE id = %i', $ruleId);
        $this->assertSame(0, (int) $rule['hit_count']);
    }

    public function testResultOtherBelowReviewThresholdStaysNew(): void
    {
        // D5: práh `review` profilu běhu (bez profilu výchozích 0,6).
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $this->otherBody(0.5)), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultOtherWithoutClassificationConfidenceStaysNew(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $this->otherBody(null)), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultOtherOnRepeatedAnalysisIsNotArchived(): void
    {
        // Zpráva vrácená z Archivu / ručně reanalyzovaná: druhá úspěšná
        // analýza pravidlo neuplatní.
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $this->insertPriorSuccessfulAnalysis($messageNdx);
        $token = $this->createClaim($messageNdx);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $this->otherBody(0.95)), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultOtherFromManualUploadIsNotArchived(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage(['source_type' => 1]);
        $token = $this->createClaim($messageNdx);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $this->otherBody(0.95)), $messageNdx);
        $this->assertSame(201, $this->statusOf($resp));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultRequiresMessageClassification(): void
    {
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $body = $this->baseBody();
        unset($body['message_classification']);

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $body), $messageNdx);
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('VALIDATION_ERROR', $resp->getPayload()['error']['code']);

        // Nic se nezapsalo — žádný běh, claim drží, stavy netknuté.
        $this->assertSame(0, (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_mail_message_analyses WHERE message = %i', $messageNdx,
        ));
        $this->assertSame(0, (int) $this->claimRow()['released']);
        $message = $this->messageRow();
        $this->assertSame(20, (int) $message['analysis_state']);
        $this->assertSame(10, (int) $message['docState']);
    }

    public function testResultRejectsLegacyExtractedDocumentsField(): void
    {
        $messageNdx = $this->provisionMessage();
        $token = $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['extracted_documents'] = []; // kontrakt v3 — od v4 se nepřijímá

        $resp = $this->controller->result($this->analyzerAuth(), $this->resultRequest($token, $body), $messageNdx);
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('VALIDATION_ERROR', $resp->getPayload()['error']['code']);

        // Nic se nezapsalo — žádný běh, claim drží, stavy netknuté.
        $this->assertSame(0, (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM core_mail_message_analyses WHERE message = %i', $messageNdx,
        ));
        $this->assertSame(0, (int) $this->claimRow()['released']);
        $message = $this->messageRow();
        $this->assertSame(20, (int) $message['analysis_state']);
        $this->assertSame(10, (int) $message['docState']);
    }
}
