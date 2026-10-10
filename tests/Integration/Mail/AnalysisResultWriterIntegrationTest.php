<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultException;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Integrační pokrytí transakce `AnalysisResultWriter::storeResult()`
 * (kontrakt v4, message-centricky — tasks/mail-message-centric.md; dřív
 * přes `POST /_mail/analysis/{ndx}/result`, protokol zrušen #85 D20):
 * povinná message_classification, volitelný `document` (0..1) →
 * analyses.canonical_json + proposed_type, uvolnění claimu,
 * analysis_state → 30 a workflow posun Nová → K řešení jen při validním
 * dokumentu, archivace podle pravidla odesílatele. Reálná DB + reálný
 * SchemaValidator/ConfigRuntime; claim vložený přímo do
 * core_mail_analysis_claims. Spustitelné s
 * `SHIPARD_INTEGRATION_DS_PATH=/opt/shipard/data-sources/<id>`.
 */
class AnalysisResultWriterIntegrationTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-AIRES';

    private AnalysisResultWriter $writer;
    private ConfigRuntime $configRuntime;
    private int $mailboxId = 0;

    private int $messageRowId = 0;
    private int $claimRowId = 0;

    /** @var list<int> */
    private array $createdRuleIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Document třídy modulů (IncomingMessageDocument apod.) musí být
        // načtené kvůli autoloadu tříd modulů.
        DocumentLoader::load($this->dsConfig, new ModulePathResolver([dirname(__DIR__, 3) . '/modules']));
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');

        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes LIMIT 1');
        if ($mailbox === null) {
            $this->markTestSkipped('DS missing core_mail_mailboxes — run mail-router-bootstrap.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        // Canonical validaci nese SchemaValidator + ConfigRuntime (registry
        // routing ve validateCanonical); enricher tu není potřeba.
        $this->writer = new AnalysisResultWriter(
            $this->db,
            $this->dsConfig,
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

    /** Aktivní claim pro zprávu (id v $this->claimRowId). */
    private function createClaim(int $messageNdx): void
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

    /**
     * Zápis výsledku nad fixturovou zprávou a jejím claimem — strojový
     * kontext (`created_by` NULL) jako v runneru.
     *
     * @param array<string, mixed> $body
     * @return int id běhu v core_mail_message_analyses
     */
    private function storeResult(int $messageNdx, array $body): int
    {
        $analysisNdx = $this->writer->storeResult($messageNdx, $this->claimRowId, $body, null);
        $this->assertGreaterThan(0, $analysisNdx);
        return $analysisNdx;
    }

    private function expectValidationError(callable $call): void
    {
        try {
            $call();
        } catch (AnalysisResultException $e) {
            $this->assertSame('VALIDATION_ERROR', $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            return;
        }
        $this->fail('AnalysisResultException expected');
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
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.9,
            'extracted_json' => $this->validCanonical(),
        ];
        $body['overall_confidence'] = 0.8;

        $analysisNdx = $this->storeResult($messageNdx, $body);

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
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.4,
            // Schema-invalid výstup (chybí formatVersion/docType) → forenzní
            // wrapper, běh se přesto uloží.
            'extracted_json' => ['format' => 'shpd.docs.document'],
        ];

        $analysisNdx = $this->storeResult($messageNdx, $body);

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
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['message_classification']['primary_type'] = 'other';
        $body['overall_confidence'] = 0.7;

        $analysisNdx = $this->storeResult($messageNdx, $body);

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
        $this->createClaim($messageNdx);

        $this->storeResult($messageNdx, $this->otherBody(0.8));

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
        $this->createClaim($messageNdx);

        $this->storeResult($messageNdx, $this->otherBody(0.95));

        $message = $this->messageRow();
        $this->assertSame(80, (int) $message['docState']);
        $this->assertSame($ruleId, (int) $message['auto_disposed_by']);
    }

    public function testResultWithDocumentFromRuledSenderIsNotArchived(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.9,
            'extracted_json' => $this->validCanonical(),
        ];

        $this->storeResult($messageNdx, $body);

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
        $this->createClaim($messageNdx);

        $this->storeResult($messageNdx, $this->otherBody(0.5));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultOtherWithoutClassificationConfidenceStaysNew(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $this->createClaim($messageNdx);

        $this->storeResult($messageNdx, $this->otherBody(null));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultOtherOnRepeatedAnalysisIsNotArchived(): void
    {
        // Zpráva vrácená z Archivu / ručně reanalyzovaná: druhá úspěšná
        // analýza pravidlo neuplatní.
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage();
        $this->insertPriorSuccessfulAnalysis($messageNdx);
        $this->createClaim($messageNdx);

        $this->storeResult($messageNdx, $this->otherBody(0.95));

        $this->assertNotDisposed($ruleId);
    }

    public function testResultOtherFromManualUploadIsNotArchived(): void
    {
        $ruleId = $this->insertConfirmedRule('archiveIfOther');
        $messageNdx = $this->provisionMessage(['source_type' => 1]);
        $this->createClaim($messageNdx);

        $this->storeResult($messageNdx, $this->otherBody(0.95));

        $this->assertNotDisposed($ruleId);
    }

    // ── pozornost u zprávy bez dokladu (tasks/mail-other-attention.md D3, D7) ──

    public function testResultOtherWithActionWritesAttentionFieldsAndPartnerFromClassification(): void
    {
        $messageNdx = $this->provisionMessage(['sender_email' => 'forwarder@example.cz']);
        $this->createClaim($messageNdx);

        $body = $this->otherBody(0.9);
        $body['message_classification'] += [
            'attention' => 'action',
            'action_note' => 'Prodloužit 3 domény, jinak 15. 10. 2026 expirují.',
            'due_date' => '2026-10-15',
            'party' => ['name' => 'Registrátor a.s.', 'companyId' => '00000000', 'email' => 'podpora@registrator.example'],
        ];

        $this->storeResult($messageNdx, $body);

        $message = $this->messageRow();
        $this->assertSame('other', $message['primary_type']);
        $this->assertSame('action', $message['attention']);
        $this->assertSame('Prodloužit 3 domény, jinak 15. 10. 2026 expirují.', $message['action_note']);
        $this->assertSame('2026-10-15', (string) ($message['action_due'] instanceof \DateTimeInterface
            ? $message['action_due']->format('Y-m-d')
            : $message['action_due']));
        // D7: protistrana z klasifikace, ne přeposílající odesílatel.
        $this->assertSame('Registrátor a.s.', $message['partner_name']);
        $this->assertSame(10, (int) $message['docState'], 'zpráva bez dokladu zůstává v Nové');
    }

    public function testResultOtherActionWithNullOptionalFieldsIsStoredWithoutPartner(): void
    {
        // Oprava v4.7.1 (akční zpráva bez lhůty): due_date / action_note /
        // party null → běh se uloží, attention action, NULL sloupce, partner nedotčen.
        $messageNdx = $this->provisionMessage(['partner_name' => 'Původní partner']);
        $this->createClaim($messageNdx);

        $body = $this->otherBody(0.9);
        $body['message_classification'] += [
            'attention' => 'action',
            'action_note' => null,
            'due_date' => null,
            'party' => null,
        ];

        $this->storeResult($messageNdx, $body);

        $message = $this->messageRow();
        $this->assertSame('action', $message['attention']);
        $this->assertNull($message['action_note']);
        $this->assertNull($message['action_due']);
        $this->assertSame('Původní partner', $message['partner_name']);
        $this->assertSame(30, (int) $message['analysis_state']);
    }

    public function testResultOtherInfoDropsNoteAndDue(): void
    {
        $messageNdx = $this->provisionMessage();
        $this->createClaim($messageNdx);

        $body = $this->otherBody(0.9);
        $body['message_classification'] += [
            'attention' => 'info',
            'action_note' => 'Nic',
            'due_date' => '2026-10-15',
        ];

        $this->storeResult($messageNdx, $body);

        $message = $this->messageRow();
        $this->assertSame('info', $message['attention']);
        $this->assertNull($message['action_note']);
        $this->assertNull($message['action_due']);
    }

    public function testResultWithDocumentClearsAttentionFromEarlierOtherAnalysis(): void
    {
        // Zpráva dřív `other` s poznámkou „Zaplatit“ — reanalýza našla
        // fakturu: tři pole musí být NULL (faktura nenese starou poznámku).
        $messageNdx = $this->provisionMessage([
            'attention' => 'action',
            'action_note' => 'Zaplatit do 15. 10.',
            'action_due' => '2026-10-15',
        ]);
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['document'] = [
            'doc_type' => 'invoiceReceived',
            'confidence' => 0.9,
            'extracted_json' => $this->validCanonical(),
        ];
        $body['overall_confidence'] = 0.8;

        $this->storeResult($messageNdx, $body);

        $message = $this->messageRow();
        $this->assertSame('invoiceReceived', $message['primary_type']);
        $this->assertNull($message['attention']);
        $this->assertNull($message['action_note']);
        $this->assertNull($message['action_due']);
    }

    public function testResultRequiresMessageClassification(): void
    {
        $messageNdx = $this->provisionMessage();
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        unset($body['message_classification']);

        $this->expectValidationError(fn() => $this->storeResult($messageNdx, $body));

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
        $this->createClaim($messageNdx);

        $body = $this->baseBody();
        $body['extracted_documents'] = []; // kontrakt v3 — od v4 se nepřijímá

        $this->expectValidationError(fn() => $this->storeResult($messageNdx, $body));

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
