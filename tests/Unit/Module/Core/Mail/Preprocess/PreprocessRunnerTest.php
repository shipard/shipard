<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Preprocess;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\IsdocImportService;
use Shipard\Module\Core\Mail\Preprocess\ActionRegistry;
use Shipard\Module\Core\Mail\Preprocess\ActionResult;
use Shipard\Module\Core\Mail\Preprocess\PreprocessAction;
use Shipard\Module\Core\Mail\Preprocess\PreprocessFailureCode;
use Shipard\Core\Render\RenderClient;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRuleMatcher;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunnerFactory;

/** Akce, která si zapíše volání a vrátí předem daný výsledek. */
final class RecordingAction implements PreprocessAction
{
    /** @var list<array{ruleId: string, params: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(private readonly ActionResult|\Throwable $outcome)
    {
    }

    public function execute(array $message, string $ruleId, array $params): ActionResult
    {
        $this->calls[] = ['ruleId' => $ruleId, 'params' => $params];
        if ($this->outcome instanceof \Throwable) {
            throw $this->outcome;
        }
        return $this->outcome;
    }
}

/**
 * Runner: claim race, plán ze snapshotu, selhání → 40 bez výjimky, ISDOC
 * po akcích nad všemi obsahovými přílohami, --force (mazání dle provenance,
 * re-match, odmítnutí při aktivním AI claimu), sweep (requeue + spawn,
 * strop pokusů).
 */
class PreprocessRunnerTest extends TestCase
{
    /** @var list<list<mixed>> */
    private array $executes = [];
    /** @var array<int, int> index execute → affected rows (default 1) */
    private array $affected = [];
    /** @var list<list<mixed>> */
    private array $updates = [];

    protected function setUp(): void
    {
        ErrorLogger::resetForTesting();
        ErrorLogger::setLogPath(sys_get_temp_dir() . '/shpd_preprocess_runner_test.log');
        $this->executes = [];
        $this->affected = [];
        $this->updates = [];
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        @unlink(sys_get_temp_dir() . '/shpd_preprocess_runner_test.log');
    }

    // --- helpers ---------------------------------------------------------

    /** @param list<array<string, mixed>> $fetchAll */
    private function db(?array $message, array $fetchAll = []): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('execute')->willReturnCallback(function (...$args): void {
            $this->executes[] = $args;
        });
        $db->method('getAffectedRows')->willReturnCallback(
            fn(): int => $this->affected[count($this->executes) - 1] ?? 1,
        );
        $db->method('updateWhere')->willReturnCallback(function (...$args): void {
            $this->updates[] = $args;
        });
        $db->method('fetchRow')->willReturn($message);
        $db->method('fetchAll')->willReturn($fetchAll);
        return $db;
    }

    /** @param list<array<string, mixed>> $files */
    private function attachments(array $files = [], array &$softDeleted = []): AttachmentService
    {
        $att = $this->createMock(AttachmentService::class);
        $att->method('listAttachments')->willReturn($files);
        $att->method('softDelete')->willReturnCallback(function (int $id) use (&$softDeleted): bool {
            $softDeleted[] = $id;
            return true;
        });
        return $att;
    }

    /** @return array<string, mixed> */
    private function message(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'sender_email' => 'noreply@bolt.eu',
            'subject' => 'Fwd: Receipt',
            'body_html' => '<a href="https://invoice.bolt.eu/x">inv</a>',
            'body_plain' => null,
            'raw_source_attachment' => 5,
            'analysis_state' => 10,
            'preprocess_state' => 10,
            'preprocess_log' => json_encode([
                'plan' => [[
                    'ruleId' => 'bolt-invoice-link',
                    'ruleNdx' => 1,
                    'actions' => [['action' => 'fetchLinkedDocument', 'linkHrefRegex' => 'bolt']],
                ]],
                'results' => [],
                'attempts' => 0,
                'createdAt' => '2026-08-29T10:00:00+02:00',
            ]),
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function finalWrite(): array
    {
        $last = $this->executes[count($this->executes) - 1];
        return ['state' => $last[2], 'log' => json_decode((string) $last[3], true), 'id' => $last[5], 'guard' => $last[6]];
    }

    // --- claim -----------------------------------------------------------

    public function testLostClaimRaceEndsSilently(): void
    {
        $this->affected = [0 => 0];
        $db = $this->db($this->message());
        $runner = new PreprocessRunner($db, $this->attachments(), new ActionRegistry());

        $result = $runner->run(42);

        $this->assertSame('skipped', $result['status']);
        $this->assertCount(1, $this->executes, 'jen claim UPDATE, žádný zápis výsledku');
        $sql = (string) $this->executes[0][0];
        $this->assertStringContainsString('WHERE id = %i AND preprocess_state = %i', $sql);
        $this->assertSame(20, $this->executes[0][2]);
        $this->assertSame(10, $this->executes[0][5]);
    }

    // --- plán ze snapshotu -------------------------------------------------

    public function testExecutesStoredPlanNotCurrentRules(): void
    {
        $action = new RecordingAction(ActionResult::success('fetched', [77]));
        $registry = new ActionRegistry()->register('fetchLinkedDocument', $action);

        // Matcher by dnes vrátil jiný plán — bez --force se nesmí použít.
        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $matcher->expects($this->never())->method('match');

        $runner = new PreprocessRunner($this->db($this->message()), $this->attachments(), $registry, null, null, $matcher);
        $result = $runner->run(42);

        $this->assertSame('done', $result['status']);
        $this->assertCount(1, $action->calls);
        $this->assertSame('bolt-invoice-link', $action->calls[0]['ruleId']);
        $this->assertSame('bolt', $action->calls[0]['params']['linkHrefRegex']);

        $final = $this->finalWrite();
        $this->assertSame(30, $final['state']);
        $this->assertSame(20, $final['guard'], 'zápis výsledku je podmíněn stavem 20');
        $this->assertSame(1, $final['log']['attempts']);
        $this->assertSame(77, $final['log']['results'][0]['attachmentId']);
        $this->assertTrue($final['log']['results'][0]['ok']);
        $this->assertSame('skipped', $final['log']['isdoc'], 'bez ISDOC factory se krok přeskočí');
        $this->assertArrayHasKey('startedAt', $final['log']);
        $this->assertArrayHasKey('finishedAt', $final['log']);
    }

    public function testFailedActionEndsInStateFortyWithoutThrowing(): void
    {
        $registry = new ActionRegistry()->register(
            'fetchLinkedDocument',
            new RecordingAction(ActionResult::failure('link expired (HTTP 404)', PreprocessFailureCode::LINK_EXPIRED)),
        );
        $runner = new PreprocessRunner($this->db($this->message()), $this->attachments(), $registry);

        $result = $runner->run(42);

        $this->assertSame('done_with_errors', $result['status']);
        $final = $this->finalWrite();
        $this->assertSame(40, $final['state']);
        $this->assertFalse($final['log']['results'][0]['ok']);
        $this->assertSame('link expired (HTTP 404)', $final['log']['results'][0]['note']);
        $this->assertSame(PreprocessFailureCode::LINK_EXPIRED, $final['log']['results'][0]['code']);
    }

    public function testRenderBodyToPdfPlanRunsThroughDefaultRegistryAndUnconfiguredRenderEndsInForty(): void
    {
        // Produkční registr (defaultActions) s nenakonfigurovanou render
        // službou: akce selže provozně, zpráva doteče do 40 a projde gate fronty.
        $message = $this->message(['preprocess_log' => json_encode([
            'plan' => [['ruleId' => 'apple-invoice-body', 'ruleNdx' => 2, 'actions' => [['action' => 'renderBodyToPdf']]]],
            'results' => [],
            'attempts' => 0,
        ])]);
        $db = $this->db($message);
        $attachments = $this->attachments();
        $registry = PreprocessRunnerFactory::defaultActions($db, $attachments, new RenderClient(null));
        $this->assertContains('renderBodyToPdf', $registry->keys());

        $result = new PreprocessRunner($db, $attachments, $registry)->run(42);

        $this->assertSame('done_with_errors', $result['status']);
        $final = $this->finalWrite();
        $this->assertSame(40, $final['state']);
        $this->assertSame('renderBodyToPdf', $final['log']['results'][0]['action']);
        $this->assertSame('apple-invoice-body', $final['log']['results'][0]['ruleId']);
        $this->assertFalse($final['log']['results'][0]['ok']);
        $this->assertStringContainsString('unconfigured', $final['log']['results'][0]['note']);
        $this->assertSame(PreprocessFailureCode::BODY_RENDER, $final['log']['results'][0]['code']);
    }

    public function testUnknownActionAndThrowingActionAreRecordedAsFailures(): void
    {
        $message = $this->message(['preprocess_log' => json_encode([
            'plan' => [[
                'ruleId' => 'r',
                'ruleNdx' => 1,
                'actions' => [['action' => 'renderBodyToPdf'], ['action' => 'boom']],
            ]],
            'attempts' => 2,
        ])]);
        $registry = new ActionRegistry()->register('boom', new RecordingAction(new \RuntimeException('kaboom')));
        $runner = new PreprocessRunner($this->db($message), $this->attachments(), $registry);

        $result = $runner->run(42);

        $this->assertSame('done_with_errors', $result['status']);
        $final = $this->finalWrite();
        $this->assertSame(40, $final['state']);
        $this->assertSame(3, $final['log']['attempts']);
        $this->assertStringContainsString("unknown action 'renderBodyToPdf'", $final['log']['results'][0]['note']);
        $this->assertStringContainsString('kaboom', $final['log']['results'][1]['note']);
        $this->assertSame(PreprocessFailureCode::RULE_CONFIG, $final['log']['results'][0]['code']);
        $this->assertSame(PreprocessFailureCode::INTERNAL, $final['log']['results'][1]['code']);
    }

    public function testEmptyPlanEndsInStateForty(): void
    {
        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_log' => null])),
            $this->attachments(),
            new ActionRegistry(),
        );

        $result = $runner->run(42);

        $this->assertSame('done_with_errors', $result['status']);
        $this->assertSame(40, $this->finalWrite()['state']);
        $this->assertSame('plan', $this->finalWrite()['log']['results'][0]['action']);
        $this->assertSame(PreprocessFailureCode::INTERNAL, $this->finalWrite()['log']['results'][0]['code']);
    }

    public function testIsdocOnlyTriggerWithEmptyPlanEndsInStateThirty(): void
    {
        // Zpráva odložená jen kvůli ISDOC (#81 D1): prázdný plán je záměr,
        // běží jen import — žádný řádek `plan` v results, stav 30.
        $imported = [];
        $service = $this->createMock(IsdocImportService::class);
        $service->method('tryImport')->willReturnCallback(
            static function (int $messageId) use (&$imported): bool {
                $imported[] = $messageId;
                return true;
            },
        );
        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_log' => PreprocessRunner::encodeLog(PreprocessRunner::isdocOnlyLog())])),
            $this->attachments([['id' => 7, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml']]),
            new ActionRegistry(),
            static fn(): IsdocImportService => $service,
        );

        $result = $runner->run(42);

        $this->assertSame('done', $result['status']);
        $this->assertSame('imported', $result['isdoc']);
        $this->assertSame([42], $imported);
        $final = $this->finalWrite();
        $this->assertSame(30, $final['state']);
        $this->assertSame([], $final['log']['results']);
        $this->assertSame('isdoc', $final['log']['trigger']);
        $this->assertSame(1, $final['log']['attempts']);
    }

    public function testIsdocOnlyTriggerWithFailedImportStillOpensGate(): void
    {
        // Import se vzdal (prohraný závod, vadný soubor) → isdoc 'none',
        // stav 30 — gate AI fronty se otevře, zpráva jde k analyzeru.
        $service = $this->createMock(IsdocImportService::class);
        $service->method('tryImport')->willReturn(false);
        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_log' => PreprocessRunner::encodeLog(PreprocessRunner::isdocOnlyLog())])),
            $this->attachments([['id' => 7, 'name' => 'faktura.isdoc', 'mime_type' => 'application/xml']]),
            new ActionRegistry(),
            static fn(): IsdocImportService => $service,
        );

        $result = $runner->run(42);

        $this->assertSame('done', $result['status']);
        $this->assertSame('none', $result['isdoc']);
        $this->assertSame(30, $this->finalWrite()['state']);
    }

    public function testIsdocOnlyLogRoundTripsThroughEncodeDecode(): void
    {
        $log = PreprocessRunner::isdocOnlyLog();

        $this->assertSame([], $log['plan']);
        $this->assertSame(PreprocessRunner::TRIGGER_ISDOC, $log['trigger']);
        $this->assertSame([], $log['results']);
        $this->assertSame(0, $log['attempts']);
        $this->assertArrayHasKey('createdAt', $log);
        $this->assertSame($log, PreprocessRunner::decodeLog(PreprocessRunner::encodeLog($log)));
    }

    public function testLostRaceOnFinalWriteIsReported(): void
    {
        $this->affected = [1 => 0]; // claim ok, final write přepsán sweepem
        $runner = new PreprocessRunner(
            $this->db($this->message()),
            $this->attachments(),
            new ActionRegistry()->register('fetchLinkedDocument', new RecordingAction(ActionResult::success())),
        );

        $this->assertSame('lost_race', $runner->run(42)['status']);
    }

    // --- ISDOC -----------------------------------------------------------

    public function testIsdocRunsAfterActionsOverAllContentAttachments(): void
    {
        $sequence = [];
        $action = new class ($sequence) implements PreprocessAction {
            public function __construct(private array &$sequence)
            {
            }

            public function execute(array $message, string $ruleId, array $params): ActionResult
            {
                $this->sequence[] = 'action';
                return ActionResult::success('ok', [7]);
            }
        };
        $registry = new ActionRegistry()->register('fetchLinkedDocument', $action);

        $files = [
            ['id' => 5, 'name' => 'message.eml', 'file_name' => 'a.eml', 'mime_type' => 'message/rfc822', 'metadata' => null],
            ['id' => 6, 'name' => 'original.pdf', 'file_name' => 'b.pdf', 'mime_type' => 'application/pdf', 'metadata' => null],
            ['id' => 7, 'name' => 'invoice.pdf', 'file_name' => 'c.pdf', 'mime_type' => 'application/pdf',
                'metadata' => json_encode(['generatedBy' => 'preprocess'])],
        ];

        $isdoc = $this->createMock(IsdocImportService::class);
        $receivedIds = null;
        $isdoc->method('tryImport')->willReturnCallback(function (int $ndx, array $uploaded) use (&$sequence, &$receivedIds): bool {
            $sequence[] = 'isdoc';
            $receivedIds = array_column($uploaded, 'id');
            return true;
        });

        $runner = new PreprocessRunner(
            $this->db($this->message()),
            $this->attachments($files),
            $registry,
            static fn(): IsdocImportService => $isdoc,
        );
        $result = $runner->run(42);

        $this->assertSame(['action', 'isdoc'], $sequence);
        $this->assertSame([6, 7], $receivedIds, 'raw .eml vyloučen, původní i vygenerovaná příloha předány');
        $this->assertSame('imported', $result['isdoc']);
        $this->assertSame(30, $this->finalWrite()['state']);
    }

    public function testIsdocSkippedWhenNoCandidateAttachment(): void
    {
        $isdoc = $this->createMock(IsdocImportService::class);
        $isdoc->expects($this->never())->method('tryImport');
        $files = [['id' => 6, 'name' => 'photo.jpg', 'file_name' => 'x.jpg', 'mime_type' => 'image/jpeg', 'metadata' => null]];

        $runner = new PreprocessRunner(
            $this->db($this->message()),
            $this->attachments($files),
            new ActionRegistry()->register('fetchLinkedDocument', new RecordingAction(ActionResult::success())),
            static fn(): IsdocImportService => $isdoc,
        );

        $this->assertSame('none', $runner->run(42)['isdoc']);
    }

    // --- --force -----------------------------------------------------------

    public function testForceRematchesDeletesGeneratedAttachmentsAndRegenerates(): void
    {
        $action = new RecordingAction(ActionResult::success('regenerated', [99]));
        $registry = new ActionRegistry()->register('fetchLinkedDocument', $action);

        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $matcher->expects($this->once())->method('match')->willReturn([[
            'ruleId' => 'bolt-v2',
            'ruleNdx' => 3,
            'actions' => [['action' => 'fetchLinkedDocument', 'linkHrefRegex' => 'new-pattern']],
        ]]);

        $softDeleted = [];
        $files = [
            ['id' => 6, 'name' => 'original.pdf', 'file_name' => 'b.pdf', 'mime_type' => 'application/pdf', 'metadata' => null],
            ['id' => 7, 'name' => 'invoice.pdf', 'file_name' => 'c.pdf', 'mime_type' => 'application/pdf',
                'metadata' => json_encode(['generatedBy' => 'preprocess', 'ruleId' => 'bolt-invoice-link'])],
        ];

        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_state' => 30])),
            $this->attachments($files, $softDeleted),
            $registry,
            null,
            null,
            $matcher,
        );
        $result = $runner->run(42, true);

        $this->assertSame('done', $result['status']);
        $this->assertSame([7], $softDeleted, 'jen příloha s provenance předzpracování');
        $this->assertSame('bolt-v2', $action->calls[0]['ruleId']);
        $this->assertSame('new-pattern', $action->calls[0]['params']['linkHrefRegex']);

        // --force nejdřív zapíše stav 20 + nový plán přes updateWhere…
        $this->assertCount(1, $this->updates);
        $this->assertSame(20, $this->updates[0][1]['preprocess_state']);
        $forcedLog = json_decode((string) $this->updates[0][1]['preprocess_log'], true);
        $this->assertSame('bolt-v2', $forcedLog['plan'][0]['ruleId']);
        $this->assertSame(1, $forcedLog['deletedAttachments']);
        $this->assertArrayHasKey('forcedAt', $forcedLog);
        // …a pak výsledek jako běžný běh.
        $this->assertSame(30, $this->finalWrite()['state']);
    }

    public function testForceWorksOnStateZeroMessage(): void
    {
        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $matcher->method('match')->willReturn([['ruleId' => 'r', 'ruleNdx' => 1, 'actions' => [['action' => 'a']]]]);
        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_state' => 0, 'preprocess_log' => null])),
            $this->attachments(),
            new ActionRegistry()->register('a', new RecordingAction(ActionResult::success())),
            null,
            null,
            $matcher,
        );

        $this->assertSame('done', $runner->run(42, true)['status']);
    }

    public function testForceWithoutMatchLeavesMessageUntouched(): void
    {
        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $matcher->method('match')->willReturn(null);
        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_state' => 0])),
            $this->attachments(),
            new ActionRegistry(),
            null,
            null,
            $matcher,
        );

        $this->assertSame('no_match', $runner->run(42, true)['status']);
        $this->assertSame([], $this->executes);
        $this->assertSame([], $this->updates);
    }

    public function testForceRefusesMessageWithActiveAnalysisClaim(): void
    {
        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $matcher->expects($this->never())->method('match');
        $runner = new PreprocessRunner(
            $this->db($this->message(['analysis_state' => 20, 'preprocess_state' => 30])),
            $this->attachments(),
            new ActionRegistry(),
            null,
            null,
            $matcher,
        );

        $result = $runner->run(42, true);

        $this->assertSame('refused', $result['status']);
        $this->assertStringContainsString('analysis_state=20', $result['note']);
    }

    public function testForceRefusesRunningMessage(): void
    {
        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $runner = new PreprocessRunner(
            $this->db($this->message(['preprocess_state' => 20])),
            $this->attachments(),
            new ActionRegistry(),
            null,
            null,
            $matcher,
        );

        $this->assertSame('refused', $runner->run(42, true)['status']);
    }

    public function testNotFoundMessage(): void
    {
        $matcher = $this->createMock(PreprocessRuleMatcher::class);
        $runner = new PreprocessRunner($this->db(null), $this->attachments(), new ActionRegistry(), null, null, $matcher);

        $this->assertSame('not_found', $runner->run(42, true)['status']);
        $this->assertSame('not_found', $runner->run(42)['status'], 'claim prošel, řádek zmizel');
    }

    // --- sweep -----------------------------------------------------------

    public function testSweepRequeuesStuckMessagesAndSpawns(): void
    {
        $rows = [
            ['id' => 1, 'preprocess_state' => 10, 'preprocess_log' => json_encode(['plan' => [], 'attempts' => 0])],
            ['id' => 2, 'preprocess_state' => 20, 'preprocess_log' => json_encode(['plan' => [], 'attempts' => 1])],
        ];
        $spawned = [];
        $runner = new PreprocessRunner(
            $this->db(null, $rows),
            $this->attachments(),
            new ActionRegistry(),
            null,
            static function (int $id) use (&$spawned): void {
                $spawned[] = $id;
            },
        );

        $result = $runner->sweep(1_000_000);

        $this->assertSame([1, 2], $result['requeued']);
        $this->assertSame([], $result['failed']);
        $this->assertSame([1, 2], $spawned);

        // Výběr: stav 10 starší než 5 min, stav 20 starší než 15 min.
        $db = $this->createMock(DataSourceConnection::class);
        $captured = null;
        $db->method('fetchAll')->willReturnCallback(static function (...$args) use (&$captured): array {
            $captured = $args;
            return [];
        });
        new PreprocessRunner($db, $this->attachments(), new ActionRegistry())->sweep(1_000_000);
        $this->assertSame(date('Y-m-d H:i:s', 1_000_000 - 300), $captured[3]);
        $this->assertSame(date('Y-m-d H:i:s', 1_000_000 - 900), $captured[5]);

        // Requeue: stav 10, attempts++, podmíněno původním stavem.
        $this->assertSame(10, $this->executes[1][2]);
        $log = json_decode((string) $this->executes[1][3], true);
        $this->assertSame(2, $log['attempts']);
        $this->assertSame(20, $log['sweeps'][0]['fromState']);
        $this->assertSame(20, $this->executes[1][6]);
    }

    public function testSweepGivesUpAfterMaxAttempts(): void
    {
        $rows = [
            ['id' => 9, 'preprocess_state' => 20, 'preprocess_log' => json_encode(['plan' => [], 'attempts' => 3, 'results' => [['action' => 'x', 'ok' => true]]])],
        ];
        $spawned = [];
        $runner = new PreprocessRunner(
            $this->db(null, $rows),
            $this->attachments(),
            new ActionRegistry(),
            null,
            static function (int $id) use (&$spawned): void {
                $spawned[] = $id;
            },
        );

        $result = $runner->sweep();

        $this->assertSame([], $result['requeued']);
        $this->assertSame([9], $result['failed']);
        $this->assertSame([], $spawned);
        $this->assertSame(40, $this->executes[0][2]);
        $log = json_decode((string) $this->executes[0][3], true);
        $this->assertSame('sweep', $log['results'][1]['action']);
        $this->assertFalse($log['results'][1]['ok']);
        $this->assertSame(PreprocessFailureCode::INTERNAL, $log['results'][1]['code']);
    }

    public function testSweepSkipsRowsThatMovedMeanwhile(): void
    {
        $this->affected = [0 => 0];
        $rows = [['id' => 1, 'preprocess_state' => 10, 'preprocess_log' => null]];
        $spawned = [];
        $runner = new PreprocessRunner(
            $this->db(null, $rows),
            $this->attachments(),
            new ActionRegistry(),
            null,
            static function (int $id) use (&$spawned): void {
                $spawned[] = $id;
            },
        );

        $result = $runner->sweep();

        $this->assertSame([], $result['requeued']);
        $this->assertSame([], $spawned);
    }

    // --- provenance helper -------------------------------------------------

    public function testIsGeneratedAttachmentReadsMetadataJsonOrArray(): void
    {
        $this->assertTrue(PreprocessRunner::isGeneratedAttachment(['metadata' => '{"generatedBy":"preprocess"}']));
        $this->assertTrue(PreprocessRunner::isGeneratedAttachment(['metadata' => ['generatedBy' => 'preprocess']]));
        $this->assertFalse(PreprocessRunner::isGeneratedAttachment(['metadata' => '{"pages":3}']));
        $this->assertFalse(PreprocessRunner::isGeneratedAttachment(['metadata' => null]));
    }

    public function testSuccessfulActionRecordHasNoCode(): void
    {
        $registry = new ActionRegistry()->register('fetchLinkedDocument', new RecordingAction(ActionResult::success('fetched', [7])));
        $runner = new PreprocessRunner($this->db($this->message()), $this->attachments(), $registry);

        $runner->run(42);

        $record = $this->finalWrite()['log']['results'][0];
        $this->assertTrue($record['ok']);
        $this->assertArrayNotHasKey('code', $record);
    }
}
