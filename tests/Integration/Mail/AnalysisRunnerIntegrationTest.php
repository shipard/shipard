<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Mail;

use Shipard\Api\DocumentLoader;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunner;
use Shipard\Module\Core\Mail\Analysis\AnalysisServices;
use Shipard\Module\Core\Mail\Analysis\AnalysisSlots;
use Shipard\Module\Core\Mail\Analysis\AttachmentPreparer;
use Shipard\Module\Core\Mail\Analysis\OutputParser;
use Shipard\Module\Core\Mail\Analysis\PromptRenderer;
use Shipard\Tests\Integration\IntegrationTestCase;

/** Falešný LLM pro integrační běh: pevný výstup, zachycené parametry. */
final class FixedOutputLlmClient implements LlmClient
{
    /** @var list<LlmChatParams> */
    public array $calls = [];

    public function __construct(private readonly string $text) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        $this->calls[] = $params;
        $onTextDelta($this->text);
        return new LlmChatResult($this->text, 1200, 600, 'end_turn', 'claude-sonnet-4-5-20260101');
    }
}

/**
 * Runner analýzy v procesu nad reálnou DB (tasks/mail-analysis-inprocess.md
 * D12): zpráva s PDF přílohou → `AnalysisRunner::run()` s falešným LLM
 * vracejícím uložený výstup → řádek v `core_mail_message_analyses`, uvolněný
 * claim a stav zprávy podle kontraktu v4. Reálné služby (claim
 * s dešifrováním klíče backendu, writer s validací canonicalu), reálný
 * profil DS. Spustitelné s `SHIPARD_INTEGRATION_DS_PATH=…`; bez aktivního
 * backendu s klíčem se přeskočí.
 */
class AnalysisRunnerIntegrationTest extends IntegrationTestCase
{
    private const PREFIX = 'IT-AIRUN';

    private ConfigRuntime $configRuntime;
    private AttachmentService $attachments;
    private int $mailboxId = 0;
    private string $runDir;

    /** @var list<int> */
    private array $messageIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        ErrorLogger::setLogLevel('error');

        $backend = $this->db->fetchRow(
            'SELECT b.api_key FROM core_mail_ai_profiles p JOIN core_ai_backends b ON b.id = p.backend
              WHERE p.is_default = %i AND p.is_active = %i AND b.is_active = %i LIMIT 1',
            1, 1, 1,
        );
        if ($backend === null || (string) ($backend['api_key'] ?? '') === '') {
            $this->markTestSkipped('DS nemá aktivní výchozí profil s backendem a klíčem — run ai-backend-set-key.');
        }
        $mailbox = $this->db->fetchRow('SELECT id FROM core_mail_mailboxes WHERE ai_analysis_disabled = %i LIMIT 1', 0);
        if ($mailbox === null) {
            $this->markTestSkipped('DS missing a mailbox with AI analysis enabled.');
        }
        $this->mailboxId = (int) $mailbox['id'];

        $resolver = new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        DocumentLoader::load($this->dsConfig, $resolver);
        $this->configRuntime = ConfigRuntime::load($this->realDsPath, 'cs');
        $this->attachments = new AttachmentService($this->db, $this->realDsPath, $this->tables);

        $this->runDir = sys_get_temp_dir() . '/shpd_airun_' . bin2hex(random_bytes(6));
        mkdir($this->runDir, 0750, true);
    }

    protected function onTearDown(): void
    {
        ErrorLogger::resetForTesting();
        $dibi = $this->db->getDibiConnection();
        foreach ($this->messageIds as $id) {
            foreach ($this->attachments->listAttachments(AttachmentPreparer::MAIL_TABLE_ID, $id, true) as $file) {
                $path = $this->attachments->getFilePath((array) $file);
                @unlink($path);
                $dibi->query('DELETE FROM core_attachments_files WHERE id = %i', (int) $file['id']);
            }
            $dibi->query('DELETE FROM core_mail_message_analyses WHERE message = %i', $id);
            $dibi->query('DELETE FROM core_mail_analysis_claims WHERE message = %i', $id);
            $dibi->query('DELETE FROM core_mail_incoming_messages WHERE id = %i', $id);
        }
        foreach (glob($this->runDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->runDir);
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /** Zpráva ve frontě (analysis_state 10, Nová) s jednou PDF přílohou. */
    private function provisionQueuedMessage(string $suffix): int
    {
        $now = date('Y-m-d H:i:s');
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('core_mail_incoming_messages', [
            'message_id'          => self::PREFIX . '-MSG-' . uniqid(),
            'mailbox'             => $this->mailboxId,
            'primary_type'        => 'other',
            'primary_type_source' => 'mailbox',
            'subject'             => self::PREFIX . ' Faktura ' . $suffix,
            'sender_email'        => 'vendor@example.cz',
            'sender_name'         => 'Dodavatel s.r.o.',
            'body_plain'          => 'V příloze zasíláme fakturu.',
            'received_at'         => $now,
            'source_type'         => 2,
            'ai_analysis_enabled' => 1,
            'needs_reanalysis'    => 0,
            'analysis_state'      => 10,
            'preprocess_state'    => 0,
            'docState'            => 10,
            'docStateMain'        => 1,
            'created'             => $now,
            'modified'            => $now,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->messageIds[] = $id;

        $tmp = tempnam(sys_get_temp_dir(), 'shpd_airun_pdf_');
        file_put_contents($tmp, "%PDF-1.4\n% fake invoice for the runner integration test\n%%EOF\n");
        $result = $this->attachments->upload(AttachmentPreparer::MAIL_TABLE_ID, $id, 'faktura.pdf', $tmp, null);
        @unlink($tmp);
        $this->assertTrue((bool) ($result['success'] ?? false), 'upload: ' . json_encode($result));

        return $id;
    }

    /** @return array<string, mixed> Výstup modelu (kontrakt v4) s validním canonicalem. */
    private function modelOutput(): array
    {
        $canonical = json_decode(
            (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        return [
            'overall_confidence' => 0.92,
            'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.97, 'title' => 'Faktura 2026000123 — Dodavatel s.r.o.'],
            'document' => ['doc_type' => 'invoiceReceived', 'confidence' => 0.95, 'extracted_json' => $canonical],
            'secondary_findings' => [['type' => 'contract', 'note' => 'Rámcová smlouva v příloze']],
        ];
    }

    private function runner(FixedOutputLlmClient $llm): AnalysisRunner
    {
        return new AnalysisRunner(
            $this->db,
            AnalysisServices::create($this->db, $this->dsConfig, new SchemaValidator(SchemaLoader::default()), null, $this->configRuntime),
            new AttachmentPreparer($this->attachments),
            new PromptRenderer(),
            new OutputParser(),
            $llm,
            new AnalysisSlots(2, $this->runDir),
            null,
            static function (int $seconds): void {},
            'internal:integration-test:' . getmypid(),
        );
    }

    /** @return array<string, mixed> */
    private function latestAnalysis(int $messageId): array
    {
        $row = $this->db->fetchRow(
            'SELECT * FROM core_mail_message_analyses WHERE message = %i ORDER BY id DESC LIMIT 1',
            $messageId,
        );
        $this->assertNotNull($row);
        return $row;
    }

    /** @return array<string, mixed> */
    private function messageRow(int $messageId): array
    {
        $row = $this->db->fetchRow('SELECT * FROM core_mail_incoming_messages WHERE id = %i', $messageId);
        $this->assertNotNull($row);
        return $row;
    }

    // ── tests ──────────────────────────────────────────────────────────────

    public function testRunnerStoresResultRowsAndAdvancesMessage(): void
    {
        $output = $this->modelOutput();
        $llm = new FixedOutputLlmClient((string) json_encode($output, JSON_UNESCAPED_UNICODE));

        $viaRunner = $this->provisionQueuedMessage('runner');
        $result = $this->runner($llm)->run($viaRunner);
        $this->assertSame('done', $result['status'], 'runner: ' . json_encode($result));
        $this->assertTrue($result['hasDocument']);

        $this->assertCount(1, $llm->calls);
        $content = $llm->calls[0]->messages[0]['content'];
        $this->assertStringContainsString(self::PREFIX . ' Faktura runner', $content[0]['text'], 'prompt nese předmět zprávy');
        $this->assertSame('document', $content[1]['type'], 'PDF příloha jde modelu jako document blok');
        $this->assertSame('application/pdf', $content[1]['source']['media_type']);

        $runnerAnalysis = $this->latestAnalysis($viaRunner);
        $runnerMessage = $this->messageRow($viaRunner);
        $claim = $this->db->fetchRow('SELECT * FROM core_mail_analysis_claims WHERE message = %i', $viaRunner);
        $this->assertNotNull($claim);
        $this->assertSame(1, (int) $claim['released']);
        $this->assertSame('result', $claim['release_reason']);
        $this->assertStringStartsWith('internal:integration-test:', (string) $claim['analyzer_id']);
        $this->assertNull($runnerAnalysis['created_by'], 'běh v procesu = strojový kontext');
        $this->assertSame(30, (int) $runnerMessage['analysis_state']);
        $this->assertSame(20, (int) $runnerMessage['docState'], 'validní dokument posouvá Novou na K řešení');
        $this->assertSame('invoiceReceived', $runnerMessage['primary_type']);
        $this->assertSame('ai', $runnerMessage['primary_type_source']);
        $this->assertSame('Faktura 2026000123 — Dodavatel s.r.o.', $runnerMessage['ai_title']);
        $this->assertNotNull($runnerAnalysis['duration_ms'], 'trvání volání modelu se ukládá (falešný LLM odpoví pod milisekundu)');
        $this->assertSame(0.0126, (float) $runnerAnalysis['cost_usd'], 'cena podle tabulky: 1200 × 3 + 600 × 15 USD/Mtok');
    }

    public function testRunnerStoresSchemaFailureAndLeavesMessageForUser(): void
    {
        $llm = new FixedOutputLlmClient('Sorry, I cannot help with that.');
        $messageId = $this->provisionQueuedMessage('schema');

        $result = $this->runner($llm)->run($messageId);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('schema_error', $result['errorType']);
        $analysis = $this->latestAnalysis($messageId);
        $this->assertSame(3, (int) $analysis['status']);
        $this->assertSame('[schema_error] output is not valid JSON', $analysis['error_message']);
        $this->assertSame(70, (int) $this->messageRow($messageId)['analysis_state']);
        $this->assertSame(10, (int) $this->messageRow($messageId)['docState']);
    }
}
