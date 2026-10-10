<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api\Controller;

use PHPUnit\Framework\TestCase;
use Shipard\Api\AuthContext;
use Shipard\Api\Controller\ChatController;
use Shipard\Api\Request;
use Shipard\Api\Response;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Api\Mcp\McpInvocationContext;
use Shipard\Api\Mcp\McpTool;
use Shipard\Api\Mcp\McpToolRegistry;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;

/**
 * Unit testy pro ChatController (Fáze 1 — CRUD konverzací).
 *
 * DB mockujeme; kontrolujeme auth gate, user-scoping (vlastní vs cizí
 * konverzace), soft-delete (docState=90) a že na zprávy není zápisový
 * endpoint (testuje se nepřímo — controller žádnou takovou metodu nemá).
 * Plná SQL cesta proti reálné DB je mimo rozsah unit testů.
 */
class ChatControllerTest extends TestCase
{
    private const USER_ID = 100;

    private function request(string $method, string $path, array $body = [], array $query = []): Request
    {
        $rawBody = $body === [] ? '' : (string) json_encode($body);
        $server = ['HTTP_HOST' => 'test', 'REMOTE_ADDR' => '127.0.0.1'];
        return Request::fromArray($method, $path, $query, $rawBody, $server);
    }

    private function auth(): AuthContext
    {
        return new AuthContext(true, self::USER_ID, 'session', 'shpd_st_xxx');
    }

    private function statusOf(Response $response): int
    {
        $ref = new \ReflectionClass($response);
        $prop = $ref->getProperty('status');
        return (int) $prop->getValue($response);
    }

    /**
     * ConfigRuntime s reálnými navSections + sectionContexts JSONC,
     * lokalizovanými jako v compiled configu (vzor NavigationControllerTest).
     */
    private function configRuntime(string $language = 'cs'): ConfigRuntime
    {
        $root         = dirname(__DIR__, 4);
        $navSections  = ConfigLocalizer::localize(
            JsoncParser::parseFile($root . '/modules/install/base/config/navSections.jsonc'),
            $language,
        );
        $sectionCtx   = ConfigLocalizer::localize(
            JsoncParser::parseFile($root . '/modules/core/chat/config/sectionContexts.jsonc'),
            $language,
        );

        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(fn (string $id) => match ($id) {
            'global.navSections'        => $navSections,
            'core.chat.sectionContexts' => $sectionCtx,
            default                     => null,
        });
        return $cfg;
    }

    // -------------------------------------------------------------------
    // Auth gate — každý endpoint odmítne neauthnutého (401)
    // -------------------------------------------------------------------

    public function testListRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->list(AuthContext::anonymous(), $this->request('GET', '/_chat/conversations'));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testCreateRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->create(AuthContext::anonymous(), $this->request('POST', '/_chat/conversations'));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testShowRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->show(AuthContext::anonymous(), 1);
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testRenameRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->rename(AuthContext::anonymous(), 1, $this->request('PATCH', '/_chat/conversations/1', ['title' => 'x']));
        $this->assertSame(401, $this->statusOf($response));
    }

    public function testDeleteRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class));
        $response = $ctrl->delete(AuthContext::anonymous(), 1);
        $this->assertSame(401, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // create
    // -------------------------------------------------------------------

    public function testCreateInsertsOwnedConversation(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = ['table' => $table, 'data' => $data];
                return 42;
            });

        $ctrl = new ChatController($db);
        $response = $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['title' => '  Hello  ']));

        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame(42, $response->getPayload()['data']['id']);

        $this->assertSame('core_chat_conversations', $captured['table']);
        $this->assertSame(self::USER_ID, $captured['data']['user']);
        $this->assertSame(self::USER_ID, $captured['data']['created_by']);
        $this->assertSame('Hello', $captured['data']['title']);
        $this->assertSame(10, $captured['data']['docState']);
    }

    public function testCreateBlankTitleStoredAsNull(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $ctrl = new ChatController($db);
        $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['title' => '   ']));

        $this->assertNull($captured['title']);
    }

    // -------------------------------------------------------------------
    // create — section scope (UI shells Fáze 5)
    // -------------------------------------------------------------------

    public function testCreateWithValidSectionStoresIt(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $ctrl = new ChatController($db, $this->configRuntime());
        $response = $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['section' => 'accounting']));

        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame('accounting', $captured['section']);
    }

    public function testCreateValidatesSectionAgainstFallbackIdsWithoutConfig(): void
    {
        // Bez ConfigRuntime (chybějící compiled config) validace degraduje
        // na statický seznam id — známá sekce projde.
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['section' => 'sales']));

        $this->assertSame(201, $this->statusOf($response));
        $this->assertSame('sales', $captured['section']);
    }

    public function testCreateWithInvalidSectionReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('insertRow');

        $ctrl = new ChatController($db, $this->configRuntime());
        $response = $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['section' => 'bogus']));

        $this->assertSame(422, $this->statusOf($response));
        $this->assertSame('INVALID_VALUE', $response->getPayload()['error']['code']);
    }

    public function testCreateRejectsTopSentinelAsSection(): void
    {
        // `_top` je sentinel root-level leafů, ne sekce — scope na něj neexistuje.
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class), $this->configRuntime());
        $response = $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['section' => '_top']));

        $this->assertSame(422, $this->statusOf($response));
    }

    public function testCreateMissingOrBlankSectionStoredAsNull(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('insertRow')->willReturnCallback(function (string $table, array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $ctrl = new ChatController($db, $this->configRuntime());
        $ctrl->create($this->auth(), $this->request('POST', '/_chat/conversations', ['section' => '  ']));

        $this->assertNull($captured['section']);
    }

    // -------------------------------------------------------------------
    // list — jen vlastní a nesmazané
    // -------------------------------------------------------------------

    public function testListScopesToUserAndExcludesDeleted(): void
    {
        $args = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$a) use (&$args): array {
            $args = $a;
            return [
                ['id' => 1, 'title' => 'A', 'backend' => null, 'model_snapshot' => null, 'tokens_input' => 0, 'tokens_output' => 0, 'cost' => 0, 'created' => '2026-06-01 10:00:00', 'modified' => '2026-06-02 10:00:00'],
            ];
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->list($this->auth(), $this->request('GET', '/_chat/conversations'));

        $this->assertSame(200, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertCount(1, $payload['data']);
        $this->assertSame(1, $payload['data'][0]['id']);

        // SQL musí scopovat na uživatele a vyloučit smazané (docState=90).
        $sql = (string) $args[0];
        $this->assertStringContainsString('`user` = %i', $sql);
        $this->assertStringContainsString('`docState` <> %i', $sql);
        $this->assertContains(self::USER_ID, $args);
        $this->assertContains(90, $args);
    }

    public function testListReturnsSectionField(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$a): array {
            $this->assertStringContainsString('`section`', (string) $a[0]);
            return [
                ['id' => 1, 'title' => 'A', 'section' => 'accounting', 'backend' => null, 'model_snapshot' => null, 'tokens_input' => 0, 'tokens_output' => 0, 'cost' => 0, 'created' => '2026-06-01 10:00:00', 'modified' => '2026-06-02 10:00:00'],
                ['id' => 2, 'title' => 'B', 'section' => null, 'backend' => null, 'model_snapshot' => null, 'tokens_input' => 0, 'tokens_output' => 0, 'cost' => 0, 'created' => '2026-06-01 10:00:00', 'modified' => '2026-06-01 10:00:00'],
            ];
        });

        $ctrl = new ChatController($db);
        $payload = $ctrl->list($this->auth(), $this->request('GET', '/_chat/conversations'))->getPayload();

        $this->assertSame('accounting', $payload['data'][0]['section']);
        $this->assertNull($payload['data'][1]['section']);
    }

    // -------------------------------------------------------------------
    // show — cizí/neexistující → 404
    // -------------------------------------------------------------------

    public function testShowForeignConversationReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // not owned / not found

        $ctrl = new ChatController($db);
        $response = $ctrl->show($this->auth(), 999);

        $this->assertSame(404, $this->statusOf($response));
        $this->assertSame('NOT_FOUND', $response->getPayload()['error']['code']);
    }

    public function testShowReturnsConversationWithMessages(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'id' => 5, 'user' => self::USER_ID, 'title' => 'Chat', 'backend' => null,
            'model_snapshot' => null, 'tokens_input' => 0, 'tokens_output' => 0,
            'cost' => 0, 'created' => '2026-06-01 10:00:00', 'modified' => '2026-06-01 10:00:00',
            'docState' => 10,
        ]);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'seq' => 0, 'role' => 'user', 'kind' => 'user_text', 'content' => '[{"type":"text","text":"hi"}]', 'tokens_input' => null, 'tokens_output' => null, 'cost' => null, 'model_name' => null, 'created' => '2026-06-01 10:00:00'],
        ]);

        $ctrl = new ChatController($db);
        $response = $ctrl->show($this->auth(), 5);

        $this->assertSame(200, $this->statusOf($response));
        $payload = $response->getPayload();
        $this->assertSame(5, $payload['data']['conversation']['id']);
        $this->assertCount(1, $payload['data']['messages']);
        // content se vrací jako dekódované pole bloků, ne řetězec
        $this->assertSame([['type' => 'text', 'text' => 'hi']], $payload['data']['messages'][0]['content']);
    }

    // -------------------------------------------------------------------
    // rename
    // -------------------------------------------------------------------

    public function testRenameUpdatesTitle(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'docState' => 10]);
        $db->method('updateWhere')->willReturnCallback(function (string $table, array $data) use (&$captured): void {
            $captured = $data;
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->rename($this->auth(), 5, $this->request('PATCH', '/_chat/conversations/5', ['title' => 'Renamed']));

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame('Renamed', $captured['title']);
    }

    public function testRenameMissingTitleReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'docState' => 10]);

        $ctrl = new ChatController($db);
        $response = $ctrl->rename($this->auth(), 5, $this->request('PATCH', '/_chat/conversations/5', []));

        $this->assertSame(422, $this->statusOf($response));
    }

    public function testRenameForeignReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = new ChatController($db);
        $response = $ctrl->rename($this->auth(), 5, $this->request('PATCH', '/_chat/conversations/5', ['title' => 'x']));

        $this->assertSame(404, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // delete — soft-delete (docState=90), ne fyzické smazání
    // -------------------------------------------------------------------

    public function testDeleteSoftDeletesViaDocState(): void
    {
        $captured = null;
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'docState' => 10]);
        $db->expects($this->never())->method('deleteWhere');
        $db->method('updateWhere')->willReturnCallback(function (string $table, array $data) use (&$captured): void {
            $captured = $data;
        });

        $ctrl = new ChatController($db);
        $response = $ctrl->delete($this->auth(), 5);

        $this->assertSame(200, $this->statusOf($response));
        $this->assertSame(90, $captured['docState']);
    }

    public function testDeleteForeignReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = new ChatController($db);
        $response = $ctrl->delete($this->auth(), 5);

        $this->assertSame(404, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // sendMessage — streamed chat turn (Fáze 2a), fake LlmClient
    // -------------------------------------------------------------------

    private const BACKEND = [
        'id' => 1, 'provider' => 'anthropic', 'model' => 'claude-test',
        'api_key' => null, 'base_url' => null, 'max_tokens' => 4096,
    ];

    /**
     * DB mock for the streaming path. fetchRow returns the owned conversation
     * for the conversations query and $backend for the backends query;
     * insertRow appends to $inserts.
     *
     * @param array<int, array<string, mixed>> $inserts
     * @param array<string, mixed>|null        $backend
     */
    private function streamDb(array &$inserts, ?array $backend, ?string $section = null): DataSourceConnection
    {
        $conv = ['id' => 5, 'user' => self::USER_ID, 'backend' => null, 'docState' => 10, 'section' => $section];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            fn ($sql, ...$a) => str_contains((string) $sql, 'core_ai_backends') ? $backend : $conv,
        );
        $db->method('fetchSingle')->willReturn(null);
        $db->method('fetchAll')->willReturn([
            ['role' => 'user', 'content' => '[{"type":"text","text":"Ahoj"}]'],
        ]);
        $db->method('insertRow')->willReturnCallback(function ($table, array $data) use (&$inserts): int {
            $inserts[] = $data;
            return count($inserts);
        });
        return $db;
    }

    /**
     * Resolver funkce `chat.default` (tasks/ai-functions.md D40) mimo DB
     * a šifrování: připojení z $backend (null = žádné), klíč `sk-test`,
     * model z řádku funkce ($model) nebo z mapy úrovní (Sonnet 4.6).
     */
    private function functions(?array $backend, ?string $model = null, ?ConfigRuntime $config = null): AiFunctionResolver
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($model !== null ? ['function_id' => 'chat.default', 'model' => $model] : null);
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn($backend);
        $backends->method('backendByNdx')->willReturn($backend);
        $backends->method('apiKey')->willReturn('sk-test');
        return new AiFunctionResolver($db, $config ?? $this->aiConfig(), null, null, null, $backends);
    }

    /** ConfigRuntime s deklarací chat.default a katalogem, mapou úrovní a platformami ze skutečných souborů v repu. */
    private function aiConfig(): ConfigRuntime
    {
        $configDir = dirname(__DIR__, 4) . '/modules/core/ai/config';
        $items = [
            AiFunctionRegistry::CFG_ITEM => [
                'chat.default' => ['name' => 'Chat', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
            ],
            ModelCatalog::CFG_ITEM => JsoncParser::parseFile($configDir . '/models.jsonc'),
            ModelCatalog::TIERS_CFG_ITEM => JsoncParser::parseFile($configDir . '/tiers.jsonc'),
            AiFunctionResolver::PLATFORMS_CFG_ITEM => JsoncParser::parseFile($configDir . '/platforms.jsonc'),
        ];
        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        return $cfg;
    }

    private function runProducer(Response $response): string
    {
        $ref = new \ReflectionClass($response);
        $producer = $ref->getProperty('streamProducer')->getValue($response);
        $this->assertIsCallable($producer);

        ob_start();
        try {
            $producer();
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }

    public function testSendMessageStreamsAndPersistsBothMessages(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['Ahoj', ', světe']), [], null, $this->functions(self::BACKEND));

        $response = $ctrl->sendMessage(
            $this->auth(),
            5,
            $this->request('POST', '/_chat/conversations/5/messages', ['text' => 'Ahoj']),
        );
        $this->assertSame(200, $this->statusOf($response));

        $out = $this->runProducer($response);
        $this->assertStringContainsString('event: text-delta', $out);
        $this->assertStringContainsString('event: message-complete', $out);

        // user message persisted before the stream, assistant after completion
        $this->assertCount(2, $inserts);
        $this->assertSame('user', $inserts[0]['role']);
        $this->assertSame('user_text', $inserts[0]['kind']);
        $this->assertSame('assistant', $inserts[1]['role']);
        $this->assertSame('assistant', $inserts[1]['kind']);
        $assistant = json_decode((string) $inserts[1]['content'], true);
        $this->assertSame('Ahoj, světe', $assistant[0]['text']);
        $this->assertSame(7, $inserts[1]['tokens_output']);
    }

    public function testParamsComeFromTheAiFunction(): void
    {
        // Deklarace chat.default (tasks/ai-functions.md D36): strop 4096,
        // bez teploty a ladění, model z mapy úrovní (Sonnet 4.6) — stejné
        // tělo jako před resolverem.
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $llm = new ScriptedLlmClient([$this->finalResult('Ahoj.')]);
        $ctrl = new ChatController($db, null, null, $llm, [], null, $this->functions(self::BACKEND));

        $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj'])));

        $this->assertNotNull($llm->lastParams);
        $this->assertSame(4096, $llm->lastParams->maxTokens);
        $this->assertSame('claude-sonnet-4-6', $llm->lastParams->model);
        $this->assertSame('sk-test', $llm->lastParams->apiKey);
        $this->assertNull($llm->lastParams->temperature);
        $this->assertNull($llm->lastParams->thinking);
        $this->assertNull($llm->lastParams->effort);
    }

    // -------------------------------------------------------------------
    // sendMessage — sekční blok system promptu (UI shells Fáze 5)
    // -------------------------------------------------------------------

    public function testSystemPromptContainsSectionBlockWithPrompt(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND, 'accounting');
        $llm = new ScriptedLlmClient([$this->finalResult('Ahoj.')]);
        $ctrl = new ChatController($db, $this->configRuntime(), null, $llm, [], null, $this->functions(self::BACKEND));

        $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj'])));

        $system = $llm->lastParams->system;
        $this->assertStringContainsString('Kontext: uživatel konverzuje v oddělení «Účtárna».', $system);
        $this->assertStringContainsString('Jsi asistent Účtárny.', $system);
        // Sekční blok patří před datumový dovětek.
        $this->assertLessThan(
            strpos($system, 'Aktuální datum'),
            strpos($system, 'Kontext: uživatel konverzuje'),
        );
    }

    public function testSystemPromptSectionWithoutCfgRecordDegradesToLabelOnly(): void
    {
        // Sekce `system` nemá záznam v core.chat.sectionContexts — připojí se
        // jen věta s labelem, žádný sekční prompt.
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND, 'system');
        $llm = new ScriptedLlmClient([$this->finalResult('Ahoj.')]);
        $ctrl = new ChatController($db, $this->configRuntime(), null, $llm, [], null, $this->functions(self::BACKEND));

        $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj'])));

        $this->assertStringContainsString('Kontext: uživatel konverzuje v oddělení «Systém».', $llm->lastParams->system);
        $this->assertStringNotContainsString('Jsi asistent', $llm->lastParams->system);
    }

    public function testSystemPromptWithoutSectionHasNoSectionBlock(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $llm = new ScriptedLlmClient([$this->finalResult('Ahoj.')]);
        $ctrl = new ChatController($db, $this->configRuntime(), null, $llm, [], null, $this->functions(self::BACKEND));

        $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj'])));

        $this->assertStringNotContainsString('Kontext: uživatel konverzuje', $llm->lastParams->system);
    }

    public function testSendMessageEmitsErrorEventAndKeepsUserMessage(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        // Throws before emitting any delta → no partial assistant text.
        $fake = new FakeLlmClient([], new LlmApiException(500, 'api_error', 'boom'));
        $ctrl = new ChatController($db, null, null, $fake, [], null, $this->functions(self::BACKEND));

        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));
        $out = $this->runProducer($response);

        $this->assertStringContainsString('event: error', $out);
        $this->assertStringContainsString('LLM_ERROR', $out);
        // user message survives; no assistant message written
        $this->assertCount(1, $inserts);
        $this->assertSame('user', $inserts[0]['role']);
    }

    // -------------------------------------------------------------------
    // Pojistka katalogu modelů (tasks/ai-model-catalog.md D35)
    // -------------------------------------------------------------------

    /** @return array<string, mixed> JSON payload odpovědi */
    private function payloadOf(Response $response): array
    {
        $ref = new \ReflectionClass($response);
        return (array) $ref->getProperty('payload')->getValue($response);
    }

    public function testSendMessageRefusesModelWithoutChatToolLoop(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        // Kdyby se model zavolal, fake vyhodí výjimku — ale k volání nesmí dojít.
        $llm = new FakeLlmClient([], new \LogicException('model must not be called'));
        // Model Sonnet 5.5 nastavený u funkce chat.default (řádek funkce).
        $ctrl = new ChatController($db, null, null, $llm, [], null, $this->functions(self::BACKEND, 'claude-sonnet-5-5'));

        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));

        $this->assertSame(503, $this->statusOf($response));
        $payload = $this->payloadOf($response);
        $this->assertSame('AI_MODEL_NOT_SUPPORTED_FOR_CHAT', $payload['error']['code']);
        $this->assertStringContainsString('claude-sonnet-5-5', $payload['error']['message']);
        $this->assertSame([['field' => 'model', 'value' => 'claude-sonnet-5-5']], $payload['error']['details']);
        // Uživatelská zpráva zůstává uložená (jako u NO_BACKEND), asistent nic nenapsal.
        $this->assertCount(1, $inserts);
        $this->assertSame('user', $inserts[0]['role']);
    }

    public function testSendMessageAllowsModelsWithChatToolLoopUnknownOrOutsideCatalog(): void
    {
        foreach ([
            'claude-sonnet-4-6'          => 'chatToolLoop true',
            'claude-sonnet-4-6-20260217' => 'datová přípona',
            'claude-opus-4-7'            => 'položka jen s cenami (chatToolLoop null)',
        ] as $model => $why) {
            $inserts = [];
            $db = $this->streamDb($inserts, self::BACKEND);
            $ctrl = new ChatController($db, null, null, new FakeLlmClient(['Ahoj']), [], null, $this->functions(self::BACKEND, $model));

            $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));

            $this->assertSame(200, $this->statusOf($response), $why);
            $this->assertStringContainsString('event: message-complete', $this->runProducer($response), $why);
        }

        // Model mimo katalog bez model_like (D43) nejde sestavit — AI_NOT_CONFIGURED,
        // uživatelská zpráva zůstává uložená.
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['Ahoj']), [], null, $this->functions(self::BACKEND, 'claude-test-x'));
        \Shipard\Core\Logging\ErrorLogger::setLogLevel('error');
        try {
            $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));
        } finally {
            \Shipard\Core\Logging\ErrorLogger::resetForTesting();
        }
        $this->assertSame(503, $this->statusOf($response));
        $this->assertSame('AI_NOT_CONFIGURED', $this->payloadOf($response)['error']['code']);
        $this->assertCount(1, $inserts);
    }

    public function testSendMessageWithoutCompiledConfigIsNotConfigured(): void
    {
        // Bez compiled configu (registr funkcí prázdný) chat volání nesestaví —
        // srozumitelná chyba místo tichého výchozího modelu.
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $empty = $this->createMock(ConfigRuntime::class);
        $empty->method('cfgItem')->willReturn(null);
        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['Ahoj']), [], null, $this->functions(self::BACKEND, null, $empty));

        \Shipard\Core\Logging\ErrorLogger::setLogLevel('error');
        try {
            $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));
        } finally {
            \Shipard\Core\Logging\ErrorLogger::resetForTesting();
        }

        $this->assertSame(503, $this->statusOf($response));
        $this->assertSame('AI_NOT_CONFIGURED', $this->payloadOf($response)['error']['code']);
    }

    public function testSendMessageWithoutLlmReturns503(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'backend' => null, 'docState' => 10]);

        $ctrl = new ChatController($db); // llm = null
        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'hi']));

        $this->assertSame(503, $this->statusOf($response));
    }

    public function testSendMessageNoActiveBackendReturns503(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, null);
        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['x']), [], null, $this->functions(null)); // žádné připojení

        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));

        $this->assertSame(503, $this->statusOf($response));
        $this->assertSame('NO_BACKEND', $this->payloadOf($response)['error']['code']);
        $this->assertCount(1, $inserts); // user message persisted before backend resolution
    }

    public function testSendMessageForeignReturns404(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['x']));
        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Ahoj']));

        $this->assertSame(404, $this->statusOf($response));
    }

    public function testSendMessageRejectsAnonymous(): void
    {
        $ctrl = new ChatController($this->createMock(DataSourceConnection::class), null, null, new FakeLlmClient(['x']));
        $response = $ctrl->sendMessage(AuthContext::anonymous(), 5, $this->request('POST', '/x', ['text' => 'hi']));

        $this->assertSame(401, $this->statusOf($response));
    }

    public function testSendMessageEmptyTextReturns422(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'user' => self::USER_ID, 'backend' => null, 'docState' => 10]);

        $ctrl = new ChatController($db, null, null, new FakeLlmClient(['x']));
        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => '   ']));

        $this->assertSame(422, $this->statusOf($response));
    }

    // -------------------------------------------------------------------
    // sendMessage — agentic tool-use loop (Fáze 2b)
    // -------------------------------------------------------------------

    private function toolUseResult(string $id, string $name, array $input): LlmChatResult
    {
        $block = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input];
        return new LlmChatResult('', 10, 5, 'tool_use', 'claude-test', [['id' => $id, 'name' => $name, 'input' => $input]], [$block]);
    }

    private function finalResult(string $text): LlmChatResult
    {
        return new LlmChatResult($text, 8, 4, 'end_turn', 'claude-test', [], [['type' => 'text', 'text' => $text]]);
    }

    private function registry(McpTool ...$tools): McpToolRegistry
    {
        $reg = new McpToolRegistry();
        foreach ($tools as $tool) {
            $reg->register($tool);
        }
        return $reg;
    }

    public function testLoopRunsReadToolThenFinalAnswer(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $read = new FakeReadTool('persons_search');
        $write = new FakeWriteTool();
        $llm = new ScriptedLlmClient([
            $this->toolUseResult('tu1', 'persons_search', ['query' => 'Acme']),
            $this->finalResult('Našel jsem Acme.'),
        ]);
        $ctrl = new ChatController($db, null, null, $llm, [], $this->registry($read, $write), $this->functions(self::BACKEND));

        $response = $ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'Najdi Acme']));
        $out = $this->runProducer($response);

        $this->assertStringContainsString('event: tool-call', $out);
        $this->assertStringContainsString('persons_search', $out);
        $this->assertStringContainsString('event: message-complete', $out);
        $this->assertSame(1, $read->calls);

        // user, assistant(tool_use), tool_results, assistant(final)
        $this->assertCount(4, $inserts);
        $this->assertSame('assistant', $inserts[1]['role']);
        $turn1 = json_decode((string) $inserts[1]['content'], true);
        $this->assertSame('tool_use', $turn1[0]['type']);
        $this->assertSame('tool_results', $inserts[2]['kind']);
        $results = json_decode((string) $inserts[2]['content'], true);
        $this->assertSame('tool_result', $results[0]['type']);
        $this->assertArrayNotHasKey('is_error', $results[0]);
        $final = json_decode((string) $inserts[3]['content'], true);
        $this->assertSame('Našel jsem Acme.', $final[0]['text']);

        // only read-only tools are offered to the model
        $this->assertNotNull($llm->lastParams?->tools);
        $names = array_column($llm->lastParams->tools, 'name');
        $this->assertSame(['persons_search'], $names);
    }

    public function testLoopRunsMultipleToolsInOneTurn(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $a = new FakeReadTool('persons_search');
        $b = new FakeReadTool('documents_search');
        $multi = new LlmChatResult('', 10, 5, 'tool_use', 'claude-test', [
            ['id' => 't1', 'name' => 'persons_search', 'input' => []],
            ['id' => 't2', 'name' => 'documents_search', 'input' => []],
        ], [
            ['type' => 'tool_use', 'id' => 't1', 'name' => 'persons_search', 'input' => []],
            ['type' => 'tool_use', 'id' => 't2', 'name' => 'documents_search', 'input' => []],
        ]);
        $llm = new ScriptedLlmClient([$multi, $this->finalResult('Hotovo.')]);
        $ctrl = new ChatController($db, null, null, $llm, [], $this->registry($a, $b), $this->functions(self::BACKEND));

        $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'x'])));

        $this->assertSame(1, $a->calls);
        $this->assertSame(1, $b->calls);
        $results = json_decode((string) $inserts[2]['content'], true);
        $this->assertCount(2, $results);
    }

    public function testLoopRejectsNonReadOnlyToolRequest(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $read = new FakeReadTool('persons_search');
        $write = new FakeWriteTool();
        // Model asks for the write tool even though it was never offered.
        $llm = new ScriptedLlmClient([
            $this->toolUseResult('tu1', 'fake_write', []),
            $this->finalResult('ok'),
        ]);
        $ctrl = new ChatController($db, null, null, $llm, [], $this->registry($read, $write), $this->functions(self::BACKEND));

        $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'x'])));

        $this->assertSame(0, $write->calls); // never executed
        $results = json_decode((string) $inserts[2]['content'], true);
        $this->assertTrue($results[0]['is_error']);
    }

    public function testLoopToolExceptionBecomesErrorResultAndContinues(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $read = new FakeReadTool('persons_search', throw: true);
        $llm = new ScriptedLlmClient([
            $this->toolUseResult('tu1', 'persons_search', []),
            $this->finalResult('omlouvám se'),
        ]);
        $ctrl = new ChatController($db, null, null, $llm, [], $this->registry($read), $this->functions(self::BACKEND));

        $out = $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'x'])));

        $this->assertSame(1, $read->calls);
        $results = json_decode((string) $inserts[2]['content'], true);
        $this->assertTrue($results[0]['is_error']);
        $this->assertStringContainsString('Nástroj selhal', (string) $results[0]['content']);
        $this->assertStringContainsString('event: message-complete', $out); // loop recovered
    }

    public function testLoopIterationCapEndsGracefully(): void
    {
        $inserts = [];
        $db = $this->streamDb($inserts, self::BACKEND);
        $read = new FakeReadTool('persons_search');
        // Every turn requests a tool → never converges.
        $llm = new ScriptedLlmClient([], $this->toolUseResult('tu', 'persons_search', []));
        $ctrl = new ChatController($db, null, null, $llm, [], $this->registry($read), $this->functions(self::BACKEND));

        $out = $this->runProducer($ctrl->sendMessage($this->auth(), 5, $this->request('POST', '/x', ['text' => 'x'])));

        $this->assertSame(8, $read->calls); // capped at MAX_TOOL_ITERATIONS
        $this->assertStringContainsString('iteration_limit', $out);
        // 1 user + 8×(assistant + tool_results) + 1 cap assistant
        $this->assertCount(18, $inserts);
        $cap = json_decode((string) $inserts[17]['content'], true);
        $this->assertStringContainsString('limitu', $cap[0]['text']);
    }
}

/**
 * In-memory LlmClient stub: emits the given deltas, then either throws or
 * returns a fixed result.
 */
final class FakeLlmClient implements LlmClient
{
    /** @param string[] $deltas */
    public function __construct(
        private array $deltas,
        private ?\Throwable $throw = null,
        private ?LlmChatResult $result = null,
    ) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        foreach ($this->deltas as $delta) {
            $onTextDelta($delta);
        }
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->result
            ?? new LlmChatResult(implode('', $this->deltas), 42, 7, 'end_turn', 'claude-test');
    }
}

/**
 * Scripted LlmClient: returns queued per-turn results in order; once the queue
 * is exhausted, repeats $whenExhausted (used for the iteration-cap test).
 * Emits each result's text as a single delta and records the last params seen.
 */
final class ScriptedLlmClient implements LlmClient
{
    private int $turn = 0;
    public ?LlmChatParams $lastParams = null;

    /** @param LlmChatResult[] $turns */
    public function __construct(private array $turns, private ?LlmChatResult $whenExhausted = null) {}

    public function streamChat(LlmChatParams $params, callable $onTextDelta): LlmChatResult
    {
        $this->lastParams = $params;
        $result = $this->turns[$this->turn] ?? $this->whenExhausted;
        $this->turn++;
        if ($result === null) {
            throw new \RuntimeException('ScriptedLlmClient: no scripted turn');
        }
        if ($result->text !== '') {
            $onTextDelta($result->text);
        }
        return $result;
    }
}

/** Read-only fake tool; records call count and can simulate a failure. */
final class FakeReadTool implements McpTool
{
    public int $calls = 0;

    public function __construct(private string $toolName, private bool $throw = false) {}

    public function name(): string { return $this->toolName; }
    public function description(): string { return 'fake read tool'; }
    public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function isReadOnly(): bool { return true; }

    public function call(array $arguments, McpInvocationContext $ctx): array
    {
        $this->calls++;
        if ($this->throw) {
            throw new \RuntimeException('tool boom');
        }
        return ['summary' => '1 výsledek', 'items' => [['ref' => ['id' => 1]]]];
    }
}

/** Write (non-read-only) fake tool; must never be executed by the chat loop. */
final class FakeWriteTool implements McpTool
{
    public int $calls = 0;

    public function name(): string { return 'fake_write'; }
    public function description(): string { return 'fake write tool'; }
    public function inputSchema(): array { return ['type' => 'object', 'properties' => []]; }
    public function isReadOnly(): bool { return false; }

    public function call(array $arguments, McpInvocationContext $ctx): array
    {
        $this->calls++;
        return [];
    }
}
