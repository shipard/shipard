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
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

/**
 * Unit testy AnalysisController::saveDecisions — průběžné ukládání
 * rozhodnutí z review modalu (`POST /_mail/messages/{ndx}/decisions`, #76,
 * tasks/mail-review-decisions-persist.md). Kontrakt body (`_resolve`
 * povinný objekt), sanitizace před uložením i v odpovědi, `{}` pro prázdnou
 * mapu, propagace guardů z applieru. Guardy samotné pokrývá
 * MessageProposalApplierTest::testSaveUserActions*.
 */
class AnalysisControllerSaveDecisionsTest extends TestCase
{
    private string $tmpDir;
    private DataSourceConfig $config;

    /** @var list<array{0: string, 1: array<string, mixed>, 2: string, 3: array<int, mixed>}> */
    private array $updates = [];

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->updates = [];
        $this->tmpDir = sys_get_temp_dir() . '/shpd_dec_' . bin2hex(random_bytes(8));
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

    private function controller(DataSourceConnection $db): AnalysisController
    {
        return new AnalysisController(
            $db, $this->config, $this->tmpDir, [],
            new DocumentRegistry(),
            new SchemaValidator(SchemaLoader::default()),
            null,
        );
    }

    private function authed(int $userId = 7): AuthContext
    {
        return new AuthContext(true, $userId, 'tester');
    }

    /** `null` = prázdné tělo requestu (bez JSON). */
    private function requestWithBody(?array $body): Request
    {
        return Request::fromArray(
            'POST',
            '/x',
            [],
            $body === null ? '' : (string) json_encode($body),
            ['HTTP_HOST' => 'test'],
        );
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        return (int) $ref->getProperty('status')->getValue($response);
    }

    /**
     * DB mock: fetchRow routuje podle názvu tabulky (první %n argument) na
     * řádek zprávy / poslední úspěšné analýzy; updateWhere se zachytí do
     * $this->updates.
     */
    private function db(?array $message, ?array $analysis): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static fn(string $sql, ...$args) => match ($args[0] ?? null) {
                'core_mail_incoming_messages' => $message,
                'core_mail_message_analyses'  => $analysis,
                default                       => null,
            },
        );
        $db->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, mixed ...$params): void {
                $this->updates[] = [$table, $data, $where, $params];
            },
        );
        return $db;
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return ['id' => 100, 'docState' => 20, 'analysis_state' => 30, 'target_row' => null];
    }

    /** @return array<string, mixed> */
    private function analysis(?int $resolution = null): array
    {
        return [
            'id' => 11, 'resolution' => $resolution,
            'canonical_json' => '{}', 'proposed_type' => 'invoiceReceived',
        ];
    }

    public function testSaveDecisionsReturns401WhenUnauthenticated(): void
    {
        $db = $this->db($this->message(), $this->analysis());
        $resp = $this->controller($db)->saveDecisions(
            new AuthContext(false, null, null),
            $this->requestWithBody(['_resolve' => ['supplier' => 'create']]),
            100,
        );
        $this->assertSame(401, $this->statusOf($resp));
        $this->assertSame([], $this->updates);
    }

    public function testSaveDecisionsReturns422WithoutResolveObject(): void
    {
        // Chybějící klíč, prázdné tělo i `_resolve` jiného typu než objekt
        // → 422 VALIDATION_ERROR s field `_resolve`; nic se nezapisuje.
        $db = $this->db($this->message(), $this->analysis());
        $ctrl = $this->controller($db);

        foreach ([[], null, ['_resolve' => 'useExisting:1'], ['_resolve' => null]] as $body) {
            $resp = $ctrl->saveDecisions($this->authed(), $this->requestWithBody($body), 100);
            $this->assertSame(422, $this->statusOf($resp));
            $this->assertSame('VALIDATION_ERROR', $resp->getPayload()['error']['code']);
        }
        $this->assertSame([], $this->updates);
    }

    public function testSaveDecisionsStoresSanitizedMapAndEchoesIt(): void
    {
        $db = $this->db($this->message(), $this->analysis());
        $resp = $this->controller($db)->saveDecisions(
            $this->authed(),
            $this->requestWithBody(['_resolve' => [
                'supplier'     => 'useExisting:42',
                'bogus'        => 'create',
                'rows[0].item' => 'skip',
                'customer'     => '',
            ]]),
            100,
        );

        $this->assertSame(200, $this->statusOf($resp));
        $data = $resp->getPayload()['data'];
        $this->assertSame(100, $data['messageNdx']);
        $this->assertSame(11, $data['analysisNdx']);
        // Odpověď = sanitizovaná mapa, ne echo requestu.
        $this->assertSame(['supplier' => 'useExisting:42', 'rows[0].item' => 'skip'], $data['userActions']);

        // Jeden UPDATE na řádek analýzy s JSON téže mapy.
        $this->assertCount(1, $this->updates);
        [$table, $update, $where, $params] = $this->updates[0];
        $this->assertSame('core_mail_message_analyses', $table);
        $this->assertSame(['user_actions_json' => '{"supplier":"useExisting:42","rows[0].item":"skip"}'], $update);
        $this->assertSame('id = %i', $where);
        $this->assertSame([11], $params);
    }

    public function testSaveDecisionsEmptyMapWritesNullAndReturnsEmptyObject(): void
    {
        // `{"_resolve": {}}` (klient po „Zrušit výběr" posledního rozhodnutí)
        // → sloupec NULL, v odpovědi `{}` (objekt, ne `[]`).
        $db = $this->db($this->message(), $this->analysis());
        $resp = $this->controller($db)->saveDecisions(
            $this->authed(),
            $this->requestWithBody(['_resolve' => []]),
            100,
        );

        $this->assertSame(200, $this->statusOf($resp));
        $userActions = $resp->getPayload()['data']['userActions'];
        $this->assertInstanceOf(\stdClass::class, $userActions);
        $this->assertSame('{}', json_encode($userActions));

        $this->assertCount(1, $this->updates);
        $this->assertSame(['user_actions_json' => null], $this->updates[0][1]);
    }

    public function testSaveDecisionsPropagates409FromApplier(): void
    {
        // Návrh už má verdikt → applier vrátí INVALID_STATE, controller ho
        // předá 1:1 (kód, zpráva, status); nic se nezapisuje.
        $db = $this->db($this->message(), $this->analysis(resolution: 40));
        $resp = $this->controller($db)->saveDecisions(
            $this->authed(),
            $this->requestWithBody(['_resolve' => ['supplier' => 'create']]),
            100,
        );

        $this->assertSame(409, $this->statusOf($resp));
        $this->assertSame('INVALID_STATE', $resp->getPayload()['error']['code']);
        $this->assertSame([], $this->updates);
    }

    public function testSaveDecisionsPropagates404WhenMessageMissing(): void
    {
        $db = $this->db(null, null);
        $resp = $this->controller($db)->saveDecisions(
            $this->authed(),
            $this->requestWithBody(['_resolve' => ['supplier' => 'create']]),
            999,
        );

        $this->assertSame(404, $this->statusOf($resp));
        $this->assertSame('NOT_FOUND', $resp->getPayload()['error']['code']);
        $this->assertSame([], $this->updates);
    }

    /** Volby DPH (tasks/exchange-preview-vat-choices.md D8): hlavičkové cesty `vat.*` projdou, neznámé ne. */
    public function testSaveDecisionsKeepsVatPathsAndDropsUnknownOnes(): void
    {
        $db = $this->db($this->message(), $this->analysis());
        $resp = $this->controller($db)->saveDecisions(
            $this->authed(),
            $this->requestWithBody(['_resolve' => [
                'vat.place'       => 'useValue:intracom',
                'vat.mode'        => 'useValue:none',
                'vat.bogus'       => 'useValue:x',
                'rows[0].vatCode' => 'useCode:cz-218',
            ]]),
            100,
        );

        $this->assertSame(200, $this->statusOf($resp));
        $expected = ['vat.place' => 'useValue:intracom', 'vat.mode' => 'useValue:none', 'rows[0].vatCode' => 'useCode:cz-218'];
        $this->assertSame($expected, $resp->getPayload()['data']['userActions']);
        $this->assertCount(1, $this->updates);
        $this->assertSame(['user_actions_json' => json_encode($expected)], $this->updates[0][1]);
    }
}
