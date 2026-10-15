<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\FieldState;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;
use Shipard\Module\Core\Mail\Analysis\ValidatedCanonical;
use Shipard\Module\Core\Mail\Eval\EvalDataSource;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalMessages;
use Shipard\Module\Core\Mail\Eval\EvalRunner;

/** Běh evaluace: výsledky per zpráva × opakování, selhání jako výsledek, nevalidní canonical, syrové výstupy, klasifikace. */
final class EvalRunnerTest extends TestCase
{
    public static function call(): ResolvedAiCall
    {
        return new ResolvedAiCall('mail.extraction', 'anthropic', 'https://api.anthropic.com', 'sk', 'claude-sonnet-4-6', 'claude-sonnet-4-6', 'claude-sonnet-4-6', 32768, 0.0, null, null, 0, 'server', ['connection' => 'server']);
    }

    /** @param array<string, mixed> $canonical */
    public static function okOutcome(array $canonical, string $primaryType = 'invoiceReceived', bool $valid = true, float $cost = 0.02): AnalysisOutcome
    {
        $body = ['message_classification' => ['primary_type' => $primaryType], 'document' => ['doc_type' => 'invoiceReceived', 'extracted_json' => $canonical]];
        $json = $valid ? json_encode($canonical) : json_encode(['_validationError' => 'x', '_validationIssues' => [['path' => 'rows', 'code' => 'type', 'message' => 'not an array']], '_rawOutput' => $canonical]);
        return AnalysisOutcome::success($body, $body, new ValidatedCanonical($json, $valid), 1000, 200, 1500, $cost, 'RAW ' . $primaryType, 'claude-sonnet-4-6-x', 'end_turn');
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
