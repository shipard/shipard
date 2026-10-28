<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\AnalysisController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Unit testy AnalysisController::applyMessage — body handling klientských
 * `_resolve` overrides a odvození `autoCreateMode`. Expand/merge helpery
 * žijí v MessageProposalApplier; tady se testuje body→canonical kontrakt
 * end-to-end přes applyMessage.
 */
class AnalysisControllerResolveBodyTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd_3b_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
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
    ): AnalysisController {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            $applier,
        );
    }

    private function happyCanonical(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    private function requestWithBody(array $body): Request
    {
        return Request::fromArray(
            'POST',
            '/x',
            [],
            (string) json_encode($body),
            ['HTTP_HOST' => 'test'],
        );
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    /**
     * DB mock: zpráva s otevřeným návrhem poslední úspěšné analýzy
     * (canonical = fixture) + dibi pro zápis verdiktu po úspěšném apply.
     *
     * @param array<string, mixed> $canonical
     */
    private function dbWithOpenProposal(array $canonical): DataSourceConnection
    {
        $message  = ['id' => 100, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null];
        $analysis = [
            'id' => 11, 'resolution' => null,
            'canonical_json' => (string) json_encode($canonical),
            'proposed_type' => 'invoiceReceived',
        ];

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, ...$args) => match ($args[0] ?? null) {
                'core_mail_incoming_messages' => $message,
                'core_mail_message_analyses'  => $analysis,
                default                       => null,
            },
        );

        $fluent = $this->createMock(\Dibi\Fluent::class);
        $fluent->method('__call')->willReturnSelf();
        $fluent->method('execute');
        $dibi = $this->createMock(\Dibi\Connection::class);
        $dibi->method('update')->willReturn($fluent);
        $db->method('getDibiConnection')->willReturn($dibi);

        return $db;
    }

    /**
     * Applier mock, který zachytí canonical předaný do apply().
     *
     * @param array<string, mixed> $canonical
     * @return array{0: DocumentApplier, 1: \Closure}
     */
    private function captureApplier(array $canonical): array
    {
        $captured = null;
        $applier = $this->createMock(DocumentApplier::class);
        $applier->method('apply')->willReturnCallback(
            function (array $passed) use (&$captured) {
                $captured = $passed;
                return ApplyResult::ok($passed, savedId: 9999);
            },
        );
        return [$applier, function () use (&$captured): ?array { return $captured; }];
    }

    public function testEmptyBodyPreservesSafeMode(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);

        $resp = $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->requestWithBody([]), 100,
        );
        $this->assertSame(200, $this->statusOf($resp));
        $c = $captured();
        $this->assertNotNull($c, 'Applier was invoked');
        $this->assertSame('safe', $c['applyOptions']['autoCreateMode']);
        $this->assertSame(10, $c['applyOptions']['targetDocState']);
    }

    public function testEmptyResolveObjectSwitchesToStrictMode(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(), $this->requestWithBody(['_resolve' => []]), 100,
        );
        $c = $captured();
        $this->assertSame('strict', $c['applyOptions']['autoCreateMode']);
    }

    public function testSupplierUserActionPropagatedToCanonical(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(),
            $this->requestWithBody(['_resolve' => ['supplier' => 'useExisting:42']]),
            100,
        );
        $c = $captured();
        $this->assertSame('strict', $c['applyOptions']['autoCreateMode']);
        $this->assertSame('useExisting:42', $c['_resolve']['supplier']['userAction']);
    }

    public function testRowItemSkipExpandsToNestedShape(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(),
            $this->requestWithBody(['_resolve' => ['rows[0].item' => 'skip']]),
            100,
        );
        $c = $captured();
        $this->assertSame('skip', $c['_resolve']['rows'][0]['item']['userAction']);
    }

    public function testExplicitAutoCreateModeOverrideWins(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(),
            $this->requestWithBody([
                'applyOptions' => ['autoCreateMode' => 'liberal'],
            ]),
            100,
        );
        $c = $captured();
        $this->assertSame('liberal', $c['applyOptions']['autoCreateMode']);
    }

    public function testTargetDocStateOverrideRespected(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(),
            $this->requestWithBody(['applyOptions' => ['targetDocState' => 40]]),
            100,
        );
        $c = $captured();
        $this->assertSame(40, $c['applyOptions']['targetDocState']);
    }

    public function testInvalidResolveValueGracefullySkipped(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(),
            $this->requestWithBody([
                '_resolve' => [
                    'supplier' => 'useExisting:42',
                    'customer' => ['not', 'a', 'string'], // garbage → ignored
                ],
            ]),
            100,
        );
        $c = $captured();
        // supplier got through, customer was silently dropped
        $this->assertSame('useExisting:42', $c['_resolve']['supplier']['userAction']);
        $this->assertArrayNotHasKey('customer', $c['_resolve']);
    }

    public function testUnknownPathInResolveIsIgnored(): void
    {
        $canonical = $this->happyCanonical();
        $db = $this->dbWithOpenProposal($canonical);

        [$applier, $captured] = $this->captureApplier($canonical);
        $this->controller($db, $applier)->applyMessage(
            $this->authed(),
            $this->requestWithBody([
                '_resolve' => [
                    'mystery.field' => 'create',
                    'rows[99].item' => 'skip',  // valid shape, but row index doesn't exist
                ],
            ]),
            100,
        );
        $c = $captured();
        $this->assertArrayNotHasKey('mystery.field', $c['_resolve'] ?? []);
        // rows[99].item gets expanded structurally — applier ignores indices
        // that don't correspond to real rows in canonical.rows
        $this->assertSame('skip', $c['_resolve']['rows'][99]['item']['userAction']);
    }

    // Direct unit tests for the expand/merge helpers live with the apply
    // core in MessageProposalApplier. The body→canonical contract (above)
    // still exercises them end-to-end through applyMessage.
}
