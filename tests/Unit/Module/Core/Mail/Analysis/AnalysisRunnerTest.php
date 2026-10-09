<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\Exception\LlmUnsupportedProviderException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaim;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimException;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimService;
use Shipard\Module\Core\Mail\Analysis\AnalysisQueue;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunner;
use Shipard\Module\Core\Mail\Analysis\AnalysisServices;
use Shipard\Module\Core\Mail\Analysis\AnalysisSlots;
use Shipard\Module\Core\Mail\Analysis\AnalysisStates;
use Shipard\Module\Core\Mail\Analysis\AttachmentPreparer;
use Shipard\Module\Core\Mail\Analysis\OutputParser;
use Shipard\Module\Core\Mail\Analysis\PreparedAttachment;
use Shipard\Module\Core\Mail\Analysis\PromptRenderer;

/**
 * Falešný LLM klient: fronta odpovědí (výsledek nebo výjimka), zachycené
 * parametry každého volání.
 */
final class ScriptedLlmClient implements LlmClient
{
    /** @var list<LlmChatParams> */
    public array $calls = [];

    /** @param list<LlmChatResult|\Throwable> $script */
    public function __construct(private array $script) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        $this->calls[] = $params;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \LogicException('ScriptedLlmClient: no more scripted responses');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        $onTextDelta($next->text);
        return $next;
    }
}

/**
 * Runner s falešným LLM (D12–D18): úspěch, schema_error, přechodná chyba
 * a hodinový strop, max_tokens, strop útraty, bez slotu, bez konfigurace,
 * claim zaniklý během volání, sweep. Služby jsou mocky — SQL kryjí jejich
 * vlastní testy a integrační test.
 */
final class AnalysisRunnerTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'required' => ['overall_confidence', 'message_classification'],
        'additionalProperties' => false,
        'properties' => [
            'overall_confidence' => ['type' => 'number'],
            'message_classification' => ['type' => 'object', 'required' => ['primary_type']],
            'document' => ['type' => ['object', 'null']],
            'secondary_findings' => ['type' => 'array'],
        ],
    ];

    private string $runDir;
    private DataSourceConnection&MockObject $db;
    private AnalysisQueue&MockObject $queue;
    private AnalysisClaimService&MockObject $claims;
    private AnalysisResultWriter&MockObject $results;
    private AttachmentPreparer&MockObject $attachments;
    /** @var list<int> */
    private array $slept = [];
    /** @var list<int> */
    private array $spawned = [];

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->runDir = sys_get_temp_dir() . '/shpd_runner_' . bin2hex(random_bytes(6));
        mkdir($this->runDir, 0750, true);

        $this->db = $this->createMock(DataSourceConnection::class);
        $this->db->method('fetchRow')->willReturnCallback(static function (string $sql, ...$args): ?array {
            if (str_contains($sql, 'raw_source_attachment')) {
                return [
                    'subject' => 'Faktura 42', 'sender_email' => 'a@example.com', 'sender_name' => null,
                    'body_plain' => 'V příloze.', 'body_html' => null, 'received_at' => '2026-10-09T10:00:00',
                    'raw_source_attachment' => 9,
                ];
            }
            return ['api_key' => 'enc']; // hasUsableBackend
        });

        $this->queue = $this->createMock(AnalysisQueue::class);
        $this->queue->method('isEligible')->willReturn(true);
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willReturn($this->claim());
        $this->claims->method('extend')->willReturn(true);
        $this->claims->method('isActive')->willReturn(true);
        $this->results = $this->createMock(AnalysisResultWriter::class);
        $this->results->method('storeResult')->willReturn(77);
        $this->results->method('countRecentFailures')->willReturn(0);
        $this->attachments = $this->createMock(AttachmentPreparer::class);
        $this->attachments->method('prepare')->willReturn([
            new PreparedAttachment(5, PreparedAttachment::KIND_PDF, 'invoice.pdf', 'application/pdf', base64_encode('%PDF')),
            new PreparedAttachment(6, PreparedAttachment::KIND_TEXT, 'note.txt', 'text/plain', null, 'hello'),
        ]);
        $this->slept = [];
        $this->spawned = [];
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        foreach (glob($this->runDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->runDir);
    }

    private function claim(array $profileOverrides = [], array $backendOverrides = []): AnalysisClaim
    {
        return new AnalysisClaim(
            31,
            'ct_x',
            '2026-10-09 10:15:00',
            $profileOverrides + [
                'id' => 17, 'prompt_version' => 'v4.7.1', 'max_tokens' => 0,
                'prompt_template' => 'Subject: {{ message.subject }} / {{ attachments|length }} attachments',
                'output_schema' => json_encode(self::SCHEMA),
            ],
            $backendOverrides + [
                'id' => 5, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-5', 'base_url' => null,
                'max_tokens' => 0, 'temperature' => 0.0,
            ],
            'sk-plain',
        );
    }

    private function runner(ScriptedLlmClient $llm, int $maxConcurrent = 2): AnalysisRunner
    {
        return new AnalysisRunner(
            $this->db,
            new AnalysisServices($this->queue, $this->claims, $this->results),
            $this->attachments,
            new PromptRenderer(),
            new OutputParser(),
            $llm,
            new AnalysisSlots($maxConcurrent, $this->runDir),
            function (int $id): void {
                $this->spawned[] = $id;
            },
            function (int $seconds): void {
                $this->slept[] = $seconds;
            },
            'internal:test:1',
        );
    }

    private function okResult(string $text = '', string $stop = 'end_turn', ?string $model = 'claude-sonnet-4-5-20260101'): LlmChatResult
    {
        $text = $text !== '' ? $text : json_encode([
            'overall_confidence' => 0.9,
            'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.95],
            'document' => ['doc_type' => 'invoiceReceived', 'confidence' => 0.8, 'extracted_json' => ['docNumber' => '42']],
            'secondary_findings' => [['type' => 'contract', 'note' => 'x'], 'junk'],
        ]);
        return new LlmChatResult($text, 1000, 500, $stop, $model);
    }

    /** @return array<string, mixed> zachycené argumenty storeFailure */
    private function expectFailure(string $errorType, bool $retryable): array
    {
        $captured = [];
        $this->results->expects($this->once())->method('storeFailure')->willReturnCallback(
            function (int $messageId, int $claimId, string $type, string $note, bool $retry, ?int $tokens, ?string $model, ?string $prompt) use (&$captured, $errorType, $retryable): int {
                $captured = compact('messageId', 'claimId', 'type', 'note', 'retry', 'tokens', 'model', 'prompt');
                $this->assertSame($errorType, $type);
                $this->assertSame($retryable, $retry);
                return $retry ? AnalysisStates::QUEUED : AnalysisStates::FAILED;
            },
        );
        $this->results->expects($this->never())->method('storeResult');
        return $captured;
    }

    public function testSuccessStoresResultWithContractBody(): void
    {
        $llm = new ScriptedLlmClient([$this->okResult()]);
        $body = null;
        $this->results->expects($this->once())->method('storeResult')->willReturnCallback(
            function (int $messageId, int $claimId, array $b, ?int $userId) use (&$body): int {
                $body = $b;
                $this->assertSame(42, $messageId);
                $this->assertSame(31, $claimId);
                $this->assertNull($userId, 'běh v procesu zapisuje created_by = NULL');
                return 77;
            },
        );
        $this->claims->expects($this->once())->method('extend')->with(31, AnalysisRunner::LEASE_SECONDS)->willReturn(true);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('done', $result['status']);
        $this->assertSame(77, $result['analysisNdx']);
        $this->assertTrue($result['hasDocument']);

        $this->assertSame('claude-sonnet-4-5-20260101', $body['model_name']);
        $this->assertSame('v4.7.1', $body['prompt_version']);
        $this->assertSame(17, $body['profile_ndx']);
        $this->assertSame(5, $body['backend_ndx']);
        $this->assertSame(1000, $body['tokens_input']);
        $this->assertSame(500, $body['tokens_output']);
        $this->assertSame(0.0105, $body['cost_usd']);
        $this->assertSame(0.9, $body['overall_confidence']);
        $this->assertSame(['primary_type' => 'invoiceReceived', 'confidence' => 0.95], $body['message_classification']);
        $this->assertSame(['doc_type' => 'invoiceReceived', 'extracted_json' => ['docNumber' => '42'], 'confidence' => 0.8], $body['document']);
        $this->assertSame([['type' => 'contract', 'note' => 'x']], $body['secondary_findings']);
        $this->assertSame('invoiceReceived', $body['analysis_json']['message_classification']['primary_type']);
        $this->assertIsInt($body['duration_ms']);

        $params = $llm->calls[0];
        $this->assertSame('sk-plain', $params->apiKey);
        $this->assertSame(AnalysisRunner::DEFAULT_MAX_TOKENS, $params->maxTokens);
        $this->assertSame(0.0, $params->temperature);
        $this->assertSame(AnalysisRunner::STALL_TIMEOUT_SECONDS, $params->stallTimeoutSeconds);
        $this->assertSame(AnalysisRunner::CALL_TIMEOUT_SECONDS, $params->timeoutSeconds);
        $this->assertNull($params->system);
        $content = $params->messages[0]['content'];
        $this->assertSame('Subject: Faktura 42 / 2 attachments', $content[0]['text']);
        $this->assertSame('document', $content[1]['type']);
        $this->assertSame(base64_encode('%PDF'), $content[1]['source']['data']);
        $this->assertSame("--- Attachment note.txt (#6) ---\nhello", $content[2]['text']);
        $this->assertSame([], $this->slept);
    }

    public function testMaxTokensCascadeProfileOverBackend(): void
    {
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willReturn($this->claim(['max_tokens' => 2048], ['max_tokens' => 4096]));
        $this->claims->method('extend')->willReturn(true);
        $this->claims->method('isActive')->willReturn(true);
        $llm = new ScriptedLlmClient([$this->okResult()]);

        $this->runner($llm)->run(42);

        $this->assertSame(2048, $llm->calls[0]->maxTokens);
    }

    public function testSchemaErrorIsStoredWithoutRetry(): void
    {
        $llm = new ScriptedLlmClient([$this->okResult('not json at all')]);
        $this->expectFailure('schema_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(AnalysisStates::FAILED, $result['newState']);
        $this->assertSame('output is not valid JSON', $result['note']);
        $this->assertCount(1, $llm->calls);
    }

    public function testTransientErrorIsRetriedThenSucceeds(): void
    {
        $llm = new ScriptedLlmClient([
            new LlmApiException(529, 'overloaded_error', 'Overloaded'),
            new LlmApiException(0, 'transport_error', 'timeout'),
            $this->okResult(),
        ]);
        $this->claims->expects($this->exactly(3))->method('extend')->willReturn(true);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('done', $result['status']);
        $this->assertSame([10, 60], $this->slept);
        $this->assertCount(3, $llm->calls);
    }

    public function testTransientErrorExhaustedReturnsMessageToQueue(): void
    {
        $llm = new ScriptedLlmClient([
            new LlmApiException(503, 'api_error', 'down'),
            new LlmApiException(503, 'api_error', 'down'),
            new LlmApiException(503, 'api_error', 'down'),
        ]);
        $this->expectFailure('ai_error', true);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('failed', $result['status']);
        $this->assertSame(AnalysisStates::QUEUED, $result['newState']);
        $this->assertStringStartsWith('anthropic transient: HTTP 503 api_error: down', $result['note']);
        $this->assertSame([10, 60], $this->slept);
    }

    public function testTransientErrorOverHourlyCapFailsPermanently(): void
    {
        $this->results = $this->createMock(AnalysisResultWriter::class);
        $this->results->method('countRecentFailures')->with(42, AnalysisRunner::FAILURE_WINDOW_SECONDS)->willReturn(AnalysisRunner::MAX_FAILURES_PER_HOUR);
        $llm = new ScriptedLlmClient([
            new LlmApiException(503, 'api_error', 'down'),
            new LlmApiException(503, 'api_error', 'down'),
            new LlmApiException(503, 'api_error', 'down'),
        ]);
        $this->expectFailure('ai_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertSame(AnalysisStates::FAILED, $result['newState']);
    }

    public function testPermanentApiErrorFailsWithoutRetry(): void
    {
        $llm = new ScriptedLlmClient([new LlmApiException(401, 'authentication_error', 'invalid x-api-key')]);
        $this->expectFailure('ai_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('anthropic permanent: HTTP 401 authentication_error: invalid x-api-key', $result['note']);
        $this->assertCount(1, $llm->calls);
        $this->assertSame([], $this->slept);
    }

    public function testMaxTokensStopReasonFailsWithoutRetry(): void
    {
        $llm = new ScriptedLlmClient([$this->okResult('{"truncated', 'max_tokens')]);
        $this->expectFailure('ai_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('anthropic: output truncated at max_tokens=32768', $result['note']);
    }

    public function testSpendLimitIsConfigErrorWithSingleCall(): void
    {
        $llm = new ScriptedLlmClient([
            new LlmApiException(429, 'rate_limit_error', 'This organization has reached its monthly spend limit; resets on 2026-11-01.', LlmApiException::ERROR_CODE_SPEND_LIMIT),
        ]);
        $captured = $this->expectFailure('config_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('monthly spend limit', $result['note']);
        $this->assertCount(1, $llm->calls);
        $this->assertSame([], $this->slept);
    }

    public function testUnsupportedProviderIsConfigError(): void
    {
        $llm = new ScriptedLlmClient([new LlmUnsupportedProviderException('openai')]);
        $this->expectFailure('config_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('Unsupported LLM provider: openai', $result['note']);
    }

    public function testBrokenPromptTemplateIsConfigError(): void
    {
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willReturn($this->claim(['prompt_template' => '{{ nope }}']));
        $this->claims->method('isActive')->willReturn(true);
        $llm = new ScriptedLlmClient([]);
        $this->expectFailure('config_error', false);

        $result = $this->runner($llm)->run(42);

        $this->assertStringStartsWith('prompt template: ', $result['note']);
        $this->assertSame([], $llm->calls);
    }

    public function testNoFreeSlotEndsWithoutClaim(): void
    {
        $holder = new AnalysisSlots(1, $this->runDir);
        $held = $holder->tryAcquire();
        $this->assertNotNull($held);
        $this->claims->expects($this->never())->method('claim');

        $result = $this->runner(new ScriptedLlmClient([]), 1)->run(42);

        $this->assertSame('no_slot', $result['status']);
        $held->release();
    }

    public function testDisabledSlotsEndBeforeAnyQuery(): void
    {
        $this->queue->expects($this->never())->method('isEligible');

        $result = $this->runner(new ScriptedLlmClient([]), 0)->run(42);

        $this->assertSame('disabled', $result['status']);
    }

    public function testNotEligibleEndsWithoutClaim(): void
    {
        $this->queue = $this->createMock(AnalysisQueue::class);
        $this->queue->method('isEligible')->willReturn(false);
        $this->claims->expects($this->never())->method('claim');

        $this->assertSame('not_eligible', $this->runner(new ScriptedLlmClient([]))->run(42)['status']);
    }

    public function testMissingConfigurationLeavesMessageQueued(): void
    {
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willThrowException(new AnalysisClaimException(AnalysisClaimException::BACKEND_KEY_MISSING, 'no key', 409));
        $this->results->expects($this->never())->method('storeFailure');
        $this->results->expects($this->never())->method('storeResult');

        $result = $this->runner(new ScriptedLlmClient([]))->run(42);

        $this->assertSame('not_configured', $result['status']);
        $this->assertStringStartsWith('BACKEND_KEY_MISSING', $result['note']);
    }

    public function testClaimTakenByAnotherRunner(): void
    {
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willThrowException(new AnalysisClaimException(AnalysisClaimException::ALREADY_CLAIMED, 'taken', 409));

        $this->assertSame('claim_failed', $this->runner(new ScriptedLlmClient([]))->run(42)['status']);
    }

    public function testClaimLostWhenLeaseCannotBeExtended(): void
    {
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willReturn($this->claim());
        $this->claims->method('extend')->willReturnOnConsecutiveCalls(true, false);
        $this->claims->method('isActive')->willReturn(false);
        $this->results->expects($this->never())->method('storeFailure');
        $this->results->expects($this->never())->method('storeResult');
        $llm = new ScriptedLlmClient([new LlmApiException(529, 'overloaded_error', 'x'), $this->okResult()]);

        $result = $this->runner($llm)->run(42);

        $this->assertSame('lost_claim', $result['status']);
        $this->assertCount(1, $llm->calls);
    }

    public function testClaimExpiredBeforeWriteStoresNothing(): void
    {
        $this->claims = $this->createMock(AnalysisClaimService::class);
        $this->claims->method('claim')->willReturn($this->claim());
        $this->claims->method('extend')->willReturn(true);
        $this->claims->method('isActive')->willReturn(false);
        $this->results->expects($this->never())->method('storeResult');
        $this->results->expects($this->never())->method('storeFailure');

        $result = $this->runner(new ScriptedLlmClient([$this->okResult()]))->run(42);

        $this->assertSame('lost_claim', $result['status']);
    }

    public function testUnexpectedExceptionLeavesClaimToExpire(): void
    {
        $this->attachments = $this->createMock(AttachmentPreparer::class);
        $this->attachments->method('prepare')->willThrowException(new \RuntimeException('disk gone'));
        $this->results->expects($this->never())->method('storeFailure');

        $result = $this->runner(new ScriptedLlmClient([]))->run(42);

        $this->assertSame('crashed', $result['status']);
        $this->assertStringContainsString('disk gone', $result['note']);
    }

    public function testSweepSpawnsUpToFreeSlots(): void
    {
        $this->queue = $this->createMock(AnalysisQueue::class);
        $this->queue->expects($this->once())->method('eligible')->with(2)->willReturn([['ndx' => 8], ['ndx' => 9]]);

        $result = $this->runner(new ScriptedLlmClient([]), 2)->sweep();

        $this->assertSame(['spawned' => [8, 9], 'skipped' => null], $result);
        $this->assertSame([8, 9], $this->spawned);
    }

    public function testSweepSkipsWithoutUsableBackend(): void
    {
        $this->db = $this->createMock(DataSourceConnection::class);
        $this->db->method('fetchRow')->willReturn(['api_key' => null]);
        $this->queue->expects($this->never())->method('eligible');

        $result = $this->runner(new ScriptedLlmClient([]))->sweep();

        $this->assertSame('no usable AI backend', $result['skipped']);
        $this->assertSame([], $this->spawned);
    }

    public function testSweepSkipsWhenDisabledOrNoFreeSlot(): void
    {
        $this->assertStringContainsString('disabled', (string) $this->runner(new ScriptedLlmClient([]), 0)->sweep()['skipped']);

        $holder = new AnalysisSlots(1, $this->runDir);
        $held = $holder->tryAcquire();
        $this->assertSame('no free analysis slot', $this->runner(new ScriptedLlmClient([]), 1)->sweep()['skipped']);
        $held?->release();
    }
}
