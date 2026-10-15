<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Analysis;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Mail\Analysis\AnalysisClaimLostException;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputation;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AttachmentPreparer;
use Shipard\Module\Core\Mail\Analysis\OutputParser;
use Shipard\Module\Core\Mail\Analysis\PreparedAttachment;
use Shipard\Module\Core\Mail\Analysis\PromptRenderer;

/** Falešný LLM pro výpočet: fronta odpovědí nebo výjimek, zachycené parametry. */
final class ComputationLlmClient implements LlmClient
{
    /** @var list<LlmChatParams> */
    public array $calls = [];

    /** @param list<LlmChatResult|\Throwable> $script */
    public function __construct(private array $script) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta, ?callable $onThinking = null): LlmChatResult
    {
        $this->calls[] = $params;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \LogicException('ComputationLlmClient: no more scripted responses');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}

/**
 * Analýza nanečisto (tasks/testing-phase4.md D34): tělo v4 + validovaný
 * canonical bez jediného zápisu — validátor je jen `validateCanonical()`,
 * `storeResult` / `storeFailure` se nikdy nevolají; šablona promptu ze
 * souboru; mapování chyb jako v runneru; hook před pokusem propadá.
 */
final class AnalysisComputationTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'required' => ['overall_confidence', 'message_classification'],
        'additionalProperties' => false,
        'properties' => [
            'overall_confidence' => ['type' => 'number'],
            'message_classification' => ['type' => 'object', 'required' => ['primary_type']],
            'document' => ['type' => ['object', 'null']],
        ],
    ];

    private AnalysisResultWriter&MockObject $validator;
    /** @var list<array{0: ?array, 1: string}> */
    private array $validations = [];
    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->validations = [];
        $this->slept = [];
        $this->validator = $this->createMock(AnalysisResultWriter::class);
        $this->validator->method('validateCanonical')->willReturnCallback(
            function (?array $extracted, string $docType): array {
                $this->validations[] = [$extracted, $docType];
                return [json_encode(['enriched' => true] + (array) $extracted, JSON_UNESCAPED_UNICODE), true];
            },
        );
        $this->validator->expects($this->never())->method('storeResult');
        $this->validator->expects($this->never())->method('storeFailure');
        $this->validator->expects($this->never())->method('recordFailedRun');
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
    }

    private function computation(ComputationLlmClient $llm): AnalysisComputation
    {
        $attachments = $this->createMock(AttachmentPreparer::class);
        $attachments->method('prepare')->willReturn([
            new PreparedAttachment(5, PreparedAttachment::KIND_PDF, 'invoice.pdf', 'application/pdf', base64_encode('%PDF')),
        ]);
        $catalog = ModelCatalog::fromArray(JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/core/ai/config/models.jsonc'));
        return new AnalysisComputation(
            $attachments,
            new PromptRenderer(),
            new OutputParser(),
            $llm,
            $this->validator,
            $catalog,
            function (int $seconds): void {
                $this->slept[] = $seconds;
            },
        );
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides = [], ?\Closure $beforeAttempt = null, ?string $promptTemplate = null): AnalysisRequest
    {
        $call = new ResolvedAiCall(...($overrides + [
            'functionId' => 'mail.extraction', 'platform' => 'anthropic', 'baseUrl' => '', 'apiKey' => 'sk-plain',
            'model' => 'claude-sonnet-4-5', 'shapeAs' => 'claude-sonnet-4-5', 'catalogModel' => 'claude-sonnet-4-5',
            'maxTokens' => 32768, 'temperature' => 0.0, 'thinking' => null, 'effort' => null,
            'connectionId' => 5, 'connectionCode' => 'default',
        ]));
        return new AnalysisRequest(
            messageId: 42,
            message: [
                'subject' => 'Faktura 42', 'sender_email' => 'a@example.com', 'sender_name' => null,
                'body_plain' => 'V příloze.', 'body_html' => null, 'received_at' => '2026-10-09T10:00:00',
                'raw_source_attachment' => 9,
            ],
            profile: [
                'id' => 17, 'prompt_version' => 'v4.7.1', 'max_tokens' => 0,
                'prompt_template' => 'Subject: {{ message.subject }} / {{ attachments|length }} attachments',
                'output_schema' => json_encode(self::SCHEMA),
            ],
            call: $call,
            promptTemplate: $promptTemplate,
            beforeAttempt: $beforeAttempt,
            retryDelays: [1, 2],
        );
    }

    private static function reply(string $text = '', string $stop = 'end_turn', ?string $model = 'claude-sonnet-4-5-20260101'): LlmChatResult
    {
        $text = $text !== '' ? $text : json_encode([
            'overall_confidence' => 0.9,
            'message_classification' => ['primary_type' => 'invoiceReceived', 'confidence' => 0.95],
            'document' => ['doc_type' => 'invoiceReceived', 'confidence' => 0.8, 'extracted_json' => ['docNumber' => '42']],
        ]);
        return new LlmChatResult($text, 1000, 500, $stop, $model);
    }

    public function testSuccessReturnsTheBodyAndTheValidatedCanonicalWithoutWriting(): void
    {
        $llm = new ComputationLlmClient([self::reply()]);
        $outcome = $this->computation($llm)->compute($this->request());

        $this->assertTrue($outcome->ok);
        $this->assertTrue($outcome->hasDocument());
        $this->assertSame('claude-sonnet-4-5-20260101', $outcome->body['model_name']);
        $this->assertSame('v4.7.1', $outcome->body['prompt_version']);
        $this->assertSame(17, $outcome->body['profile_ndx']);
        $this->assertSame(5, $outcome->body['backend_ndx']);
        $this->assertSame(1000, $outcome->tokensIn);
        $this->assertSame(500, $outcome->tokensOut);
        // Sonnet 4.5: (1000 × 3 + 500 × 15) / 1M.
        $this->assertSame(0.0105, $outcome->costUsd);
        $this->assertSame('invoiceReceived', $outcome->body['document']['doc_type']);
        $this->assertSame('end_turn', $outcome->stopReason);
        $this->assertStringContainsString('"docNumber"', (string) $outcome->rawText);

        $this->assertSame([[['docNumber' => '42'], 'invoiceReceived']], $this->validations, 'validace právě jednou');
        $this->assertNotNull($outcome->validated);
        $this->assertTrue($outcome->validated->valid);
        $this->assertSame(['enriched' => true, 'docNumber' => '42'], $outcome->validated->canonical());

        $params = $llm->calls[0];
        $this->assertSame('Subject: Faktura 42 / 1 attachments', $params->messages[0]['content'][0]['text']);
        $this->assertSame('document', $params->messages[0]['content'][1]['type']);
        $this->assertSame(AnalysisComputation::STALL_TIMEOUT_SECONDS, $params->stallTimeoutSeconds);
        $this->assertSame(AnalysisComputation::CALL_TIMEOUT_SECONDS, $params->timeoutSeconds);
    }

    public function testPromptTemplateFromTheRequestReplacesTheProfileTemplateInMemory(): void
    {
        $llm = new ComputationLlmClient([self::reply()]);
        $outcome = $this->computation($llm)->compute($this->request(promptTemplate: 'Variant: {{ message.sender_email }}'));

        $this->assertTrue($outcome->ok);
        $this->assertSame('Variant: a@example.com', $llm->calls[0]->messages[0]['content'][0]['text']);
        $this->assertSame('v4.7.1', $outcome->body['prompt_version'], 'verze promptu zůstává z profilu — hash šablony nese soubor výsledků');
    }

    public function testWithoutADocumentOrClassificationNothingIsValidated(): void
    {
        $noDocument = json_encode(['overall_confidence' => 0.4, 'message_classification' => ['primary_type' => 'other']]);
        $outcome = $this->computation(new ComputationLlmClient([self::reply($noDocument)]))->compute($this->request());
        $this->assertTrue($outcome->ok);
        $this->assertFalse($outcome->hasDocument());
        $this->assertNull($outcome->validated);

        // Bez klasifikace zápis tělo odmítne (422) — validace (a LLM štítky) se nespouští.
        $schema = self::SCHEMA;
        $schema['required'] = ['overall_confidence'];
        $request = $this->request();
        $request = new AnalysisRequest($request->messageId, $request->message, ['output_schema' => json_encode($schema)] + $request->profile, $request->call);
        $noClassification = json_encode(['overall_confidence' => 0.4, 'document' => ['doc_type' => 'invoiceReceived', 'extracted_json' => ['docNumber' => '1']]]);
        $outcome = $this->computation(new ComputationLlmClient([self::reply($noClassification)]))->compute($request);
        $this->assertTrue($outcome->ok);
        $this->assertTrue($outcome->hasDocument());
        $this->assertNull($outcome->validated);
        $this->assertSame([], $this->validations);
    }

    public function testFailuresMapToTheRunnerErrorTypes(): void
    {
        $outcome = $this->computation(new ComputationLlmClient([self::reply('not json')]))->compute($this->request());
        $this->assertFalse($outcome->ok);
        $this->assertSame('schema_error', $outcome->errorType);
        $this->assertFalse($outcome->transient);
        $this->assertSame(1000, $outcome->tokensIn);
        $this->assertSame('not json', $outcome->rawText);

        $outcome = $this->computation(new ComputationLlmClient([self::reply('{"x":', 'max_tokens')]))->compute($this->request());
        $this->assertSame('ai_error', $outcome->errorType);
        $this->assertFalse($outcome->transient);
        $this->assertStringContainsString('max_tokens=32768', (string) $outcome->note);
        $this->assertSame(0.0105, $outcome->costUsd, 'useknutý výstup stál peníze');

        $outcome = $this->computation(new ComputationLlmClient([new LlmApiException(401, 'authentication_error', 'bad key')]))->compute($this->request());
        $this->assertSame('config_error', $outcome->errorType);
        $this->assertFalse($outcome->transient);
        $this->assertNull($outcome->costUsd);
        $this->assertStringContainsString('HTTP 401', (string) $outcome->note);

        $outcome = $this->computation(new ComputationLlmClient([
            new LlmApiException(529, 'overloaded_error', 'Overloaded'),
            new LlmApiException(0, 'transport_error', 'timeout'),
            new LlmApiException(503, 'api_error', 'down'),
        ]))->compute($this->request());
        $this->assertSame('ai_error', $outcome->errorType);
        $this->assertTrue($outcome->transient, 'o opakování rozhoduje volající stropem selhání');
        $this->assertSame([1, 2], $this->slept, 'tři pokusy s pauzami z požadavku');
        $this->assertStringContainsString('transient', (string) $outcome->note);

        $outcome = $this->computation(new ComputationLlmClient([self::reply()]))->compute($this->request(promptTemplate: '{{ message.subject'));
        $this->assertSame('config_error', $outcome->errorType);
        $this->assertStringStartsWith('prompt template:', (string) $outcome->note);
        $this->assertSame([], $this->validations, 'selhání nic nevaliduje');
    }

    public function testBeforeAttemptHookRunsPerAttemptAndItsExceptionPropagates(): void
    {
        $attempts = [];
        $llm = new ComputationLlmClient([new LlmApiException(529, 'overloaded_error', 'Overloaded'), self::reply()]);
        $outcome = $this->computation($llm)->compute($this->request(beforeAttempt: function (int $attempt) use (&$attempts): void {
            $attempts[] = $attempt;
        }));
        $this->assertTrue($outcome->ok);
        $this->assertSame([1, 2], $attempts);

        $this->expectException(AnalysisClaimLostException::class);
        $this->computation(new ComputationLlmClient([self::reply()]))->compute($this->request(beforeAttempt: static function (): void {
            throw new AnalysisClaimLostException('claim 31 is no longer active');
        }));
    }
}
