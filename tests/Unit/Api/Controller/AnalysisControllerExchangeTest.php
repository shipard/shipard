<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Unit testy AnalysisController po message-centric refaktoru — plná
 * applyMessage pipeline (delegace na DocumentApplier přes
 * MessageProposalApplier), idempotentní / guard větve. Validaci canonicalu
 * výsledku kryje AnalysisResultWriterTest.
 */
class AnalysisControllerExchangeTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_axc_test_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    private function controller(
        DataSourceConnection $db,
        ?DocumentApplier $applier = null,
        ?RowEnrichmentPipeline $enricher = null,
        ?ConfigRuntime $configRuntime = null,
    ): AnalysisController {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            $applier,
            $configRuntime, null,
            $enricher,
        );
    }

    /** ConfigRuntime s primaryTypes: insurance jako registry target. */
    private function configRuntimeWithRegistryTargets(): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['core.mail.primaryTypes', [
                'invoiceReceived' => ['target' => 'docs'],
                'insurance'       => ['target' => 'registry', 'docKind' => 'insurance'],
            ]],
        ]);
        return $config;
    }

    /**
     * Real enricher nad mockovanou DB (RowHistoryEnricher je final — nelze
     * mockovat) — partner vždy matched, historie dle parametru.
     *
     * @param list<array<string, mixed>> $history
     */
    private function enricher(array $history): RowEnrichmentPipeline
    {
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('fetchAll')->willReturn(array_map(
            static fn(array $row) => new \Dibi\Row($row),
            $history,
        ));
        $party = $this->createMock(PartyResolver::class);
        $party->method('resolve')->willReturn(ResolveResult::matched(42, 'companyId'));
        return new RowEnrichmentPipeline(new RowHistoryEnricher($dibi, $party), new ContentTagResolver($dibi));
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function request(string $method, string $path, array $body = []): Request
    {
        $rawBody = $body === [] ? '' : (string) json_encode($body);
        return Request::fromArray($method, $path, [], $rawBody, ['HTTP_HOST' => 'test']);
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    /**
     * DB mock pro message-centrické akce: fetchRow routuje podle názvu
     * tabulky (první %n argument) na řádek zprávy / poslední úspěšné analýzy.
     */
    private function dbForProposal(?array $message, ?array $analysis): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, ...$args) => match ($args[0] ?? null) {
                'core_mail_incoming_messages' => $message,
                'core_mail_message_analyses'  => $analysis,
                default                       => null,
            },
        );
        return $db;
    }

    /** Zpráva připravená na apply (mimo Archiv/Koš, analysis_state=30). */
    private function openMessage(int $ndx = 100): array
    {
        return ['id' => $ndx, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null];
    }

    /** Otevřený návrh (resolution NULL) s daným canonicalem. */
    private function openAnalysis(?string $canonicalJson, string $proposedType = 'invoiceReceived'): array
    {
        return [
            'id' => 11, 'resolution' => null,
            'canonical_json' => $canonicalJson, 'proposed_type' => $proposedType,
        ];
    }

    /** Dibi mock pro zápis verdiktu (writeResolution) po úspěšném apply. */
    private function wireResolutionDibi(DataSourceConnection $db): void
    {
        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willReturn($fluent);
        $db->method('getDibiConnection')->willReturn($dibi);
    }

    // ── applyMessage branches ───────────────────────────────────────────────

    public function testApplyMessageReturns401WhenUnauthenticated(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $ctrl = $this->controller($db);

        $auth = new AuthContext(false, null, null);
        $resp = $ctrl->applyMessage($auth, $this->request('POST', '/_mail/messages/1/apply'), 1);
        $this->assertSame(401, $this->statusOf($resp));
    }

    public function testApplyMessageReturns404WhenMessageMissing(): void
    {
        $db = $this->dbForProposal(null, null);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 999,
        );
        $this->assertSame(404, $this->statusOf($resp));
    }

    public function testApplyMessageReturns422WhenCanonicalIsAiFailedWrapper(): void
    {
        // Forenzní wrapper z /result → nelze aplikovat, jen reanalyzovat.
        $wrapper = json_encode([
            '_validationError' => 'Canonical schema validation failed',
            '_validationIssues' => [],
            '_rawOutput' => ['garbage' => true],
        ]);
        $db = $this->dbForProposal($this->openMessage(), $this->openAnalysis($wrapper));

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('AI_OUTPUT_INVALID', $resp->getPayload()['error']['code']);
    }

    public function testApplyMessageReturns409WhenProposalRejected(): void
    {
        $analysis = $this->openAnalysis('{}');
        $analysis['resolution'] = 50;
        $db = $this->dbForProposal($this->openMessage(), $analysis);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('INVALID_STATE', $resp->getPayload()['error']['code']);
    }

    public function testApplyMessageReturns422WhenNoProposal(): void
    {
        // Poslední úspěšná analýza bez dokumentového návrhu (canonical NULL).
        $db = $this->dbForProposal($this->openMessage(), $this->openAnalysis(null));

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('NO_PROPOSAL', $resp->getPayload()['error']['code']);
    }

    public function testApplyMessageIdempotentWhenAlreadyApplied(): void
    {
        // target_row obsazený + resolution=40 → idempotentní úspěch bez apply.
        $message = $this->openMessage();
        $message['target_row'] = 1234;
        $analysis = $this->openAnalysis('{}');
        $analysis['resolution'] = 40;
        $db = $this->dbForProposal($message, $analysis);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(200, $this->statusOf($resp));
        $payload = $resp->getPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame(1234, $payload['data']['savedDocId']);
        $this->assertTrue($payload['data']['idempotent']);
    }

    public function testApplyMessageRecoversWhenResolutionWriteLagged(): void
    {
        // target_row obsazený, ale verdikt ještě nezapsaný → recovery cesta
        // doběhne resolution a hlásí recovered.
        $message = $this->openMessage();
        $message['target_row'] = 1234;
        $db = $this->dbForProposal($message, $this->openAnalysis('{}'));
        $this->wireResolutionDibi($db);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(200, $this->statusOf($resp));
        $payload = $resp->getPayload();
        $this->assertSame(1234, $payload['data']['savedDocId']);
        $this->assertTrue($payload['data']['recovered']);
    }

    public function testApplyMessageForwardsUnresolvedRequiredFromApplier(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbForProposal(
            $this->openMessage(),
            $this->openAnalysis((string) json_encode($canonical)),
        );

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->willReturn(ApplyResult::error('unresolved_required', 'Doplň userAction', ['x' => 1], 422));

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(422, $this->statusOf($resp));
        $this->assertSame('unresolved_required', $resp->getPayload()['error']['code']);
    }

    public function testApplyMessageForwardsCorruptedJson(): void
    {
        $db = $this->dbForProposal($this->openMessage(), $this->openAnalysis('not-json'));

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->never())->method('apply');

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(500, $this->statusOf($resp));
        $this->assertSame('CORRUPTED_DATA', $resp->getPayload()['error']['code']);
    }

    public function testApplyMessageInjectsSourceAndApplyOptionsForApplier(): void
    {
        $canonical = $this->happyCanonical();
        // Strip source.message so we can verify server-side injection.
        unset($canonical['source']['message']);

        $db = $this->dbForProposal(
            $this->openMessage(42),
            $this->openAnalysis((string) json_encode($canonical)),
        );

        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->willReturnCallback(function (array $passed) use (&$captured) {
                $captured = $passed;
                return ApplyResult::error('unresolved_required', 'X', [], 422);
            });

        $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 42,
        );

        $this->assertNotNull($captured);
        $this->assertSame(42, $captured['source']['message']);
        $this->assertSame('aiExtraction', $captured['source']['kind']);
        $this->assertSame('safe', $captured['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $captured['applyOptions']['targetDocState']);
    }

    public function testApplyMessageSuccessWritesResolutionAndReturnsSavedDocId(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbForProposal(
            $this->openMessage(),
            $this->openAnalysis((string) json_encode($canonical)),
        );
        $this->wireResolutionDibi($db);

        $applier = $this->createMock(DocumentApplier::class);
        $applier->expects($this->once())
            ->method('apply')
            ->willReturn(ApplyResult::ok($canonical, savedId: 777));

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->request('POST', '/x'), 100,
        );
        $this->assertSame(200, $this->statusOf($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame(777, $data['savedDocId']);
        $this->assertSame(100, $data['messageNdx']);
        $this->assertSame(11, $data['analysisNdx']);
        $this->assertArrayHasKey('canonical', $data);
    }

    public function testApplyMessageWithoutApplierReturnsInternalError(): void
    {
        // Fallback „bez applieru jen status update" zanikl — docs apply bez
        // DocumentApplieru je INTERNAL_ERROR 500.
        $db = $this->dbForProposal(
            $this->openMessage(),
            $this->openAnalysis((string) json_encode($this->happyCanonical())),
        );

        $ctrl = new AnalysisController(
            $db, $this->config, $this->tmpDir, [], new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            null,
        );

        $resp = $ctrl->applyMessage($this->authed(), $this->request('POST', '/x'), 100);
        $this->assertSame(500, $this->statusOf($resp));
        $this->assertSame('INTERNAL_ERROR', $resp->getPayload()['error']['code']);
    }
}
