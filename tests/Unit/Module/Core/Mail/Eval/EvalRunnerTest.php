<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Enrich\ContentTagClassifier;
use Shipard\Module\Core\Exchange\Enrich\ContentTagResolver;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher;
use Shipard\Module\Core\Exchange\Isdoc\IsdocFieldCoverage;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Exchange\Resolve\ResolveResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;
use Shipard\Module\Core\Mail\Analysis\ValidatedCanonical;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;
use Shipard\Module\Core\Mail\Eval\EvalComputationRouter;
use Shipard\Module\Core\Mail\Eval\EvalDataSource;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalMessages;
use Shipard\Module\Core\Mail\Eval\EvalRunner;
use Shipard\Module\Core\Mail\Eval\PathReporting;
use Shipard\Module\Core\Mail\Isdoc\IsdocDocumentCollector;

/**
 * Běh evaluace: výsledky per zpráva × opakování, selhání jako výsledek,
 * nevalidní canonical, syrové výstupy, klasifikace; cesta `isdoc` přes
 * {@see EvalComputationRouter} (#103 D76) — ISDOC z korpusu na disku,
 * štítek z falešného LLM, bez volání AI extrakce.
 */
final class EvalRunnerTest extends TestCase
{
    public static function call(): ResolvedAiCall
    {
        return new ResolvedAiCall('mail.extraction', 'anthropic', 'https://api.anthropic.com', 'sk', 'claude-sonnet-4-6', 'claude-sonnet-4-6', 'claude-sonnet-4-6', 32768, 0.0, null, null, 0, 'server', ['connection' => 'server']);
    }

    /** @param array<string, mixed> $canonical */
    /** @param array{tokensIn: int, tokensOut: int, model: ?string, costUsd: ?float}|null $tags spotřeba LLM štítků (F6b D56) */
    public static function okOutcome(array $canonical, string $primaryType = 'invoiceReceived', bool $valid = true, float $cost = 0.02, ?array $tags = null): AnalysisOutcome
    {
        $body = ['message_classification' => ['primary_type' => $primaryType], 'document' => ['doc_type' => 'invoiceReceived', 'extracted_json' => $canonical]];
        $json = $valid ? json_encode($canonical) : json_encode(['_validationError' => 'x', '_validationIssues' => [['path' => 'rows', 'code' => 'type', 'message' => 'not an array']], '_rawOutput' => $canonical]);
        return AnalysisOutcome::success($body, $body, new ValidatedCanonical($json, $valid, $tags), 1000, 200, 1500, $cost, 'RAW ' . $primaryType, 'claude-sonnet-4-6-x', 'end_turn');
    }

    public static function tags(float $cost = 0.0003): array
    {
        return ['tokensIn' => 120, 'tokensOut' => 30, 'model' => 'claude-haiku-5-5', 'costUsd' => $cost];
    }

    public function testRunComparesEveryMessageAndRepeatWithoutWriting(): void
    {
        $reader = DatasetReader::open(dirname(__DIR__, 6) . '/tests/datasets/cz/vat-payer');
        $messages = EvalMessages::filter(EvalMessages::fromDataset($reader), ['0101', '0201'], null);
        $this->assertCount(2, $messages);
        usort($messages, static fn ($a, $b): int => strcmp($a->messageId, $b->messageId));   // pořadí sady je podle receivedAt
        [$m0101, $m0201] = $messages;

        $tweaked = $m0101->expectedCanonical;
        $tweaked['docNumber'] = 'X';
        $tweaked['_resolve']['contentTag']['tag'] = 'it.hosting';
        $computation = new ScriptedComputation([
            self::okOutcome($m0101->expectedCanonical),                 // 0101 / 1: přesně
            self::okOutcome($tweaked, 'other'),                          // 0101 / 2: odchylky
            AnalysisOutcome::failure('schema_error', 'not json', false, 500, 0.01),   // 0201 / 1
            new \RuntimeException('boom'),                                // 0201 / 2
        ]);
        $raw = sys_get_temp_dir() . '/shpd_evalraw_' . bin2hex(random_bytes(4));
        mkdir($raw);
        $progress = [];
        $runner = new EvalRunner(
            $computation,
            new CanonicalComparator(Projection::load($reader->resolvePath('expected/projection.jsonc'))),
            new ArrayEvalDataSource($this->createMock(DataSourceConnection::class), ['MSG-CORPUS-VP-0101' => 11, 'MSG-CORPUS-VP-0201' => 12]),
            ['id' => 1, 'prompt_version' => 'v4.7.1', 'prompt_template' => 'T'],
            self::call(),
            'Variant prompt',
            2,
            $raw,
            static function ($result, $message) use (&$progress): void {
                $progress[] = $message->messageId . '/' . $result->repeat . ':' . $result->status;
            },
        );

        $results = $runner->run($messages, ['MSG-CORPUS-VP-0101' => 11, 'MSG-CORPUS-VP-0201' => 12]);

        $this->assertCount(4, $results);
        $this->assertSame(['MSG-CORPUS-VP-0101/1:ok', 'MSG-CORPUS-VP-0101/2:ok', 'MSG-CORPUS-VP-0201/1:failed', 'MSG-CORPUS-VP-0201/2:failed'], $progress);
        $this->assertSame(11, $computation->requests[0]->messageId, 'ndx zprávy ve zdroji dat');
        $this->assertSame('Variant prompt', $computation->requests[0]->promptTemplate);
        $this->assertSame('msg 11', $computation->requests[0]->message['subject']);

        $first = $results[0];
        $this->assertTrue($first->isClean());
        $this->assertSame('invoiceReceived', $first->primaryType);
        $this->assertSame('goods.stock', $first->tag);
        $this->assertSame(0.02, $first->costUsd);
        $this->assertGreaterThan(50, $first->counts()['match']);

        $second = $results[1];
        $this->assertFalse($second->isClean());
        $deviations = [];
        foreach ($second->deviations() as $o) {
            $deviations[$o->path] = $o->state->value;
        }
        $this->assertSame(['docNumber' => 'wrong', '_resolve.contentTag.tag' => 'wrong', 'message_classification.primary_type' => 'wrong'], $deviations);
        $this->assertSame('other', $second->primaryType);
        $this->assertSame('it.hosting', $second->tag);

        $this->assertSame('schema_error', $results[2]->errorType);
        $this->assertFalse($results[2]->isInfrastructureFailure());
        $this->assertSame(0.01, $results[2]->costUsd);
        $this->assertSame('internal', $results[3]->errorType);
        $this->assertTrue($results[3]->isInfrastructureFailure());
        $this->assertStringContainsString('boom', (string) $results[3]->note);

        // D44: selhané volání nese neprázdné listy očekávání jako missing + klasifikaci.
        foreach ([$results[2], $results[3]] as $failed) {
            $counts = $failed->counts();
            $this->assertGreaterThan(50, $counts['missing'], $failed->errorType);
            $this->assertSame(0, $counts['match'] + $counts['matchNormalized'] + $counts['extra'] + $counts['wrong']);
            $this->assertFalse($failed->isClean());
            $this->assertArrayHasKey('counts', $failed->toArray());
            $this->assertArrayNotHasKey('deviations', $failed->toArray());
        }
        $classification = array_values(array_filter($results[2]->outcomes, static fn ($o): bool => $o->path === 'message_classification.primary_type'));
        $this->assertSame(FieldState::Missing, $classification[0]->state);
        $this->assertSame($m0201->expectedPrimaryType, $classification[0]->expected);

        $this->assertSame('RAW invoiceReceived', file_get_contents($raw . '/MSG-CORPUS-VP-0101-1.txt'));
        $this->assertSame('RAW other', file_get_contents($raw . '/MSG-CORPUS-VP-0101-2.txt'));
        $this->assertFileDoesNotExist($raw . '/MSG-CORPUS-VP-0201-2.txt');
        DatasetReader::removeTree($raw);
    }

    public function testInvalidCanonicalAndMissingMessageInTheDataSource(): void
    {
        $reader = DatasetReader::open(dirname(__DIR__, 6) . '/tests/datasets/cz/vat-payer');
        $messages = EvalMessages::filter(EvalMessages::fromDataset($reader), ['0101'], null);
        $comparator = new CanonicalComparator(Projection::load($reader->resolvePath('expected/projection.jsonc')));
        $ds = new ArrayEvalDataSource($this->createMock(DataSourceConnection::class), ['MSG-CORPUS-VP-0101' => 11]);

        $runner = new EvalRunner(new ScriptedComputation([self::okOutcome($messages[0]->expectedCanonical, valid: false)]), $comparator, $ds, [], self::call());
        $result = $runner->run($messages, ['MSG-CORPUS-VP-0101' => 11])[0];
        $this->assertSame('invalid_canonical', $result->errorType);
        $this->assertStringContainsString('rows type — not an array', (string) $result->note);
        $this->assertSame('invoiceReceived', $result->primaryType, 'klasifikace zůstává i bez validního dokladu');
        $this->assertSame(1, $result->counts()['match'], 'klasifikace sedí, doklad chybí celý');
        $this->assertGreaterThan(50, $result->counts()['missing']);

        $noDocument = AnalysisOutcome::success(['message_classification' => ['primary_type' => 'other']], [], null, 10, 5, 100, 0.001, 'raw', 'm', 'end_turn');
        $runner = new EvalRunner(new ScriptedComputation([$noDocument]), $comparator, $ds, [], self::call());
        $this->assertStringContainsString('without a document', (string) $runner->run($messages, ['MSG-CORPUS-VP-0101' => 11])[0]->note);

        $this->expectException(EvalException::class);
        $runner->run($messages, []);
    }

    /** F6b D56: spotřeba LLM štítků z výpočtu do výsledku volání; D61: strop útraty a zrušení zastaví další volání. */
    public function testTagUsageMaxCostAndCancellation(): void
    {
        $reader = DatasetReader::open(dirname(__DIR__, 6) . '/tests/datasets/cz/vat-payer');
        $messages = EvalMessages::filter(EvalMessages::fromDataset($reader), ['0101', '0201'], null);
        usort($messages, static fn ($a, $b): int => strcmp($a->messageId, $b->messageId));
        $comparator = new CanonicalComparator(Projection::load($reader->resolvePath('expected/projection.jsonc')));
        $ds = new ArrayEvalDataSource($this->createMock(DataSourceConnection::class), ['MSG-CORPUS-VP-0101' => 11, 'MSG-CORPUS-VP-0201' => 12]);
        $ndx = ['MSG-CORPUS-VP-0101' => 11, 'MSG-CORPUS-VP-0201' => 12];
        $ok = fn (int $i, ?array $tags = null) => self::okOutcome($messages[$i]->expectedCanonical, tags: $tags);

        // Štítky: spotřeba z ValidatedCanonical do výsledku, cena běhu = extrakce + štítky.
        $runner = new EvalRunner(new ScriptedComputation([$ok(0, self::tags()), $ok(1)]), $comparator, $ds, [], self::call());
        $results = $runner->run($messages, $ndx);
        $this->assertSame(self::tags(), $results[0]->tags);
        $this->assertSame(0.0003, $results[0]->tagsCostUsd());
        $this->assertNull($results[1]->tags);
        $this->assertSame(0.0, $results[1]->tagsCostUsd());
        $this->assertNull($runner->stoppedBy());
        $this->assertSame(0.0403, round($runner->spentUsd(), 4));

        // Strop útraty: 0,02 + 0,0003 ≥ 0,02 po prvním volání → druhé nezačne.
        $computation = new ScriptedComputation([$ok(0, self::tags()), $ok(1)]);
        $runner = new EvalRunner($computation, $comparator, $ds, [], self::call(), repeat: 1, maxCostUsd: 0.02);
        $results = $runner->run($messages, $ndx);
        $this->assertCount(1, $results);
        $this->assertCount(1, $computation->requests, 'další volání nezačalo');
        $this->assertSame(EvalRunner::STOPPED_MAX_COST, $runner->stoppedBy());

        // Zrušení před voláním: shouldContinue false po prvním výsledku.
        $cancelled = false;
        $computation = new ScriptedComputation([$ok(0), $ok(1)]);
        $runner = new EvalRunner($computation, $comparator, $ds, [], self::call(), onResult: function () use (&$cancelled): void { $cancelled = true; }, shouldContinue: function () use (&$cancelled): bool { return !$cancelled; });
        $this->assertCount(1, $runner->run($messages, $ndx));
        $this->assertSame(EvalRunner::STOPPED_CANCELLED, $runner->stoppedBy());
        $this->assertCount(1, $computation->requests);

        // Zrušení uprostřed čekání na opakování: hook beforeAttempt vyhodí EvalCancelledException → bez výsledku, bez dalšího volání.
        $cancelled = false;
        $hooking = new class($cancelled) implements AnalysisComputing {
            public int $calls = 0;
            public function __construct(private bool &$cancelled) {}
            public function compute(AnalysisRequest $request): AnalysisOutcome
            {
                $this->calls++;
                $this->cancelled = true;
                ($request->beforeAttempt)(2); // LlmRetry by tu začal další pokus
                throw new \LogicException('unreachable');
            }
        };
        $runner = new EvalRunner($hooking, $comparator, $ds, [], self::call(), shouldContinue: function () use (&$cancelled): bool { return !$cancelled; });
        $this->assertSame([], $runner->run($messages, $ndx));
        $this->assertSame(EvalRunner::STOPPED_CANCELLED, $runner->stoppedBy());
        $this->assertSame(1, $hooking->calls);

        // Bez shouldContinue se hook nepředává (ostrý runner má vlastní).
        $plain = new class implements AnalysisComputing {
            public ?\Closure $seen = null;
            public function compute(AnalysisRequest $request): AnalysisOutcome
            {
                $this->seen = $request->beforeAttempt;
                return EvalRunnerTest::okOutcome([]);
            }
        };
        (new EvalRunner($plain, $comparator, $ds, [], self::call()))->run([$messages[0]], $ndx);
        $this->assertNull($plain->seen);
    }

    /**
     * D76 / D82: zpráva s ISDOC (0102 — soubor korpusu jako příloha na
     * disku) jde cestou `isdoc`: výpočet AI se nezavolá, výstup je ISDOC →
     * canonical obohacený touž pipeline (štítek z falešného LLM, spotřeba
     * jen za štítky), pole, která ISDOC nenese, jsou `notCarried`; zpráva
     * bez ISDOC (0101) jde cestou `ai`. Výjimka sběru = cesta `ai`.
     */
    public function testIsdocMessageGoesTheIsdocPathWithoutAiExtraction(): void
    {
        $repo = dirname(__DIR__, 6);
        $reader = DatasetReader::open($repo . '/tests/datasets/cz/vat-payer');
        $messages = EvalMessages::filter(EvalMessages::fromDataset($reader), ['0101', '0102'], null);
        usort($messages, static fn ($a, $b): int => strcmp($a->messageId, $b->messageId));
        [$m0101, $m0102] = $messages;
        $this->assertTrue($m0102->hasIsdoc());
        $this->assertFalse($m0101->hasIsdoc());
        $comparator = new CanonicalComparator(Projection::load($reader->resolvePath('expected/projection.jsonc')));
        $ndx = ['MSG-CORPUS-VP-0101' => 11, 'MSG-CORPUS-VP-0102' => 12];
        $ds = new ArrayEvalDataSource($this->createMock(DataSourceConnection::class), $ndx);

        // Přílohy na disku jako ve zdroji dat: 0102 má PDF (soubor chybí → bez embedded) a ISDOC z korpusu.
        $dsDir = sys_get_temp_dir() . '/shpd_evalisdoc_' . bin2hex(random_bytes(4));
        mkdir($dsDir . '/att/m', 0700, true);
        copy($repo . '/tests/corpus/cz/out/vat-payer/0102-domestic-goods.isdoc', $dsDir . '/att/m/isdoc_0102');
        $attachments = $this->createMock(AttachmentService::class);
        $attachments->method('listAttachments')->willReturnCallback(static fn (int $tableId, int $messageId): array => match ($messageId) {
            12 => [
                ['id' => 71, 'name' => '0102-domestic-goods.pdf', 'file_name' => 'missing.pdf', 'file_path' => 'm', 'mime_type' => 'application/pdf'],
                ['id' => 72, 'name' => '0102-domestic-goods.isdoc', 'file_name' => 'isdoc_0102', 'file_path' => 'm', 'mime_type' => 'text/xml'],
            ],
            default => [['id' => 61, 'name' => '0101-domestic-goods.pdf', 'file_name' => 'missing.pdf', 'file_path' => 'm', 'mime_type' => 'application/pdf']],
        });

        // Štítky z falešného LLM (rowException jako v očekávání sady), spotřeba podle katalogu.
        $llmCalls = 0;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(static function (LlmChatParams $params, callable $onDelta) use (&$llmCalls): LlmChatResult {
            $llmCalls++;
            return new LlmChatResult('{"primaryTag": "goods.stock", "confidence": 0.9, "rowExceptions": [{"rowIndex": 4, "tag": "services.shipping"}]}', 100, 40, 'end_turn', 'claude-haiku-5-5');
        });
        $functions = $this->createMock(AiFunctionResolver::class);
        $functions->method('resolve')->willReturn(new ResolvedAiCall('exchange.contentTags', 'anthropic', '', 'sk', 'claude-haiku-5-5', 'claude-haiku-5-5', 'claude-haiku-5-5', 8000, null, null, null, 1, 'default'));
        $functions->method('catalog')->willReturn(ModelCatalog::fromArray([
            'claude-haiku-5-5' => ['name' => 'H', 'order' => 1, 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 1, 'output' => 5]],
        ]));
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.exchange.contentTags', ['goods.stock' => ['name' => 'Goods'], 'services.shipping' => ['name' => 'Shipping']]],
            ['economy.items.contentTagDefaults', []],
        ]);
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn([]);
        $dibi->method('fetch')->willReturn(null);
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(77, 'companyId'));
        $enricher = new RowEnrichmentPipeline(new RowHistoryEnricher($dibi, $party), new ContentTagResolver($dibi, null, $config), new ContentTagClassifier($llm, $functions, $config));

        $ai = new ScriptedComputation([self::okOutcome($m0101->expectedCanonical)]);
        $router = new EvalComputationRouter($ai, new IsdocDocumentCollector(new SchemaValidator(SchemaLoader::default()), $dsDir), $attachments, $enricher);
        $this->assertInstanceOf(PathReporting::class, $router);

        $results = (new EvalRunner($router, $comparator, $ds, [], self::call()))->run($messages, $ndx);
        DatasetReader::removeTree($dsDir);

        $this->assertCount(2, $results);
        [$r0101, $r0102] = $results;
        $this->assertSame(EvalCallResult::PATH_AI, $r0101->path);
        $this->assertSame(0, $r0101->counts()['notCarried']);
        $this->assertCount(1, $ai->requests, 'AI extrakce jen pro 0101');
        $this->assertSame(11, $ai->requests[0]->messageId);

        $this->assertSame(EvalCallResult::PATH_ISDOC, $r0102->path);
        $this->assertTrue($r0102->isOk(), (string) $r0102->note);
        $this->assertSame('isdoc', $r0102->model);
        $this->assertSame([0.0, 0, 0], [$r0102->costUsd, $r0102->tokensIn, $r0102->tokensOut]);
        $this->assertSame(1, $llmCalls, 'štítky jedním voláním');
        $this->assertSame(['tokensIn' => 100, 'tokensOut' => 40, 'model' => 'claude-haiku-5-5', 'costUsd' => 0.0003], $r0102->tags);
        $this->assertSame('invoiceReceived', $r0102->primaryType);
        $this->assertSame('goods.stock', $r0102->tag);
        $this->assertSame([], array_map(static fn ($o): string => $o->path . ':' . $o->state->value, $r0102->deviations()), 'ISDOC z korpusu sedí s očekáváním mimo pole, která nenese');
        $this->assertTrue($r0102->isClean());
        $byPath = [];
        foreach ($r0102->outcomes as $o) {
            $byPath[$o->path] = $o;
        }
        $this->assertSame(FieldState::NotCarried, $byPath['vat.place']->state);
        $this->assertSame(IsdocFieldCoverage::NOT_CARRIED['vat.place'], $byPath['vat.place']->reason);
        $this->assertSame(FieldState::NotCarried, $byPath['rows[0].priceCalcMode']->state);
        $this->assertSame(FieldState::Match, $byPath['docNumber']->state);
        $this->assertSame(FieldState::Match, $byPath['_resolve.contentTag.tag']->state);
        $this->assertSame(FieldState::Match, $byPath['_resolve.contentTag.rowExceptions[4].tag']->state);
        $counts = $r0102->counts();
        $this->assertGreaterThan(0, $counts['notCarried']);
        $this->assertSame(0, $counts['missing'] + $counts['extra'] + $counts['wrong']);
        $this->assertSame('isdoc', $r0102->toArray()['path']);

        // Výjimka sběru (I/O) = jako v produkci do AI: cesta ai, extrakce se zavolá.
        $exploding = new class(new SchemaValidator(SchemaLoader::default()), '/nonexistent') extends IsdocDocumentCollector {
            public function collect(int $messageNdx, array $uploadedFiles): ?array
            {
                throw new \RuntimeException('disk gone');
            }
        };
        $ai = new ScriptedComputation([self::okOutcome($m0102->expectedCanonical)]);
        $router = new EvalComputationRouter($ai, $exploding, $attachments, null);
        $result = (new EvalRunner($router, $comparator, $ds, [], self::call()))->run([$m0102], $ndx)[0];
        $this->assertSame(EvalCallResult::PATH_AI, $result->path);
        $this->assertCount(1, $ai->requests);
        $this->assertSame(0, $result->counts()['notCarried']);
    }

    public function testClassificationOutcome(): void
    {
        $this->assertSame(FieldState::Match, EvalRunner::classificationOutcome('other', 'other')->state);
        $this->assertSame(FieldState::Match, EvalRunner::classificationOutcome(null, null)->state);
        $this->assertSame(FieldState::Wrong, EvalRunner::classificationOutcome('invoiceReceived', 'other')->state);
        $this->assertSame(FieldState::Missing, EvalRunner::classificationOutcome('invoiceReceived', null)->state);
        $this->assertSame(FieldState::Extra, EvalRunner::classificationOutcome(null, 'other')->state);
        $this->assertSame('message_classification.primary_type', EvalRunner::classificationOutcome('a', 'b')->path);
    }
}
