<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Dashboard;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\ResolvedAiCall;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Unit testy pro DashboardSummaryService (dashboard fáze 2b).
 *
 * Pokrývají:
 *   - stabilitu digestu/hashe (stejné vstupy → stejný hash; změněná karta,
 *     jiné datum, jiný jazyk → jiný hash; info karty bez vlivu)
 *   - cache hit (LLM se nevolá, vrací uložený text)
 *   - cache miss (LLM se volá, delty tečou přes $onDelta, upsert insert/update)
 *   - prázdný feed → text=null, žádné LLM, žádný zápis
 *   - degradace: žádný backend / backend bez klíče / selhání dešifrování
 */
final class DashboardSummaryServiceTest extends TestCase
{
    private const TODAY = '2026-07-02';

    /** @return list<array<string, mixed>> */
    private function sampleCards(): array
    {
        return [
            [
                'id' => 'alert_1', 'source' => 'alerts', 'kind' => 'urgent',
                'title' => 'Faktura po splatnosti', 'subtitle' => 'ABC s.r.o. · 12 100 Kč',
            ],
            [
                'id' => 'mail_5', 'source' => 'mail', 'kind' => 'review',
                'title' => 'Došlá faktura', 'subtitle' => 'XYZ a.s.',
            ],
            [
                'id' => 'mail_7', 'source' => 'mail', 'kind' => 'ready',
                'title' => 'Faktura připravena', 'subtitle' => 'DEF spol.',
            ],
        ];
    }

    private function service(
        ?DataSourceConnection $db = null,
        ?LlmClient $llm = null,
        ?AiFunctionResolver $functions = null,
    ): DashboardSummaryService {
        return new DashboardSummaryService(
            $db ?? $this->createMock(DataSourceConnection::class),
            $llm ?? $this->createMock(LlmClient::class),
            $functions ?? $this->functions(),
        );
    }

    /** Resolver AI funkce `dashboard.summary`: hotové rozhodnutí, nebo nenastavená funkce. */
    private function functions(?ResolvedAiCall $call = null, ?AiFunctionException $failure = null): AiFunctionResolver
    {
        $functions = $this->createMock(AiFunctionResolver::class);
        if ($failure !== null) {
            $functions->method('resolve')->willThrowException($failure);
        } else {
            $functions->method('resolve')->willReturn($call ?? $this->call());
        }
        return $functions;
    }

    /** @param array<string, mixed> $overrides */
    private function call(array $overrides = []): ResolvedAiCall
    {
        $values = $overrides + [
            'functionId' => 'dashboard.summary', 'platform' => 'anthropic', 'baseUrl' => '', 'apiKey' => 'sk-test',
            'model' => 'claude-x', 'shapeAs' => null, 'catalogModel' => null, 'maxTokens' => 2000,
            'temperature' => null, 'thinking' => null, 'effort' => null, 'connectionId' => 1, 'connectionCode' => 'default',
        ];
        return new ResolvedAiCall(...$values);
    }

    private function hashDigest(array $digest, string $model = 'claude-x'): string
    {
        return DashboardSummaryService::cacheKey($digest, $model);
    }

    // -------------------------------------------------------------------------
    // Digest / hash
    // -------------------------------------------------------------------------

    public function testDigestIsStableForSameInputs(): void
    {
        $svc = $this->service();
        $a = $svc->buildDigest($this->sampleCards(), 'cs', self::TODAY);
        $b = $svc->buildDigest($this->sampleCards(), 'cs', self::TODAY);
        $this->assertSame($this->hashDigest($a), $this->hashDigest($b));
    }

    public function testDigestChangesWithChangedCard(): void
    {
        $svc = $this->service();
        $a = $svc->buildDigest($this->sampleCards(), 'cs', self::TODAY);

        $cards = $this->sampleCards();
        $cards[0]['title'] = 'Jiný titulek';
        $b = $svc->buildDigest($cards, 'cs', self::TODAY);

        $this->assertNotSame($this->hashDigest($a), $this->hashDigest($b));
    }

    public function testDigestChangesWithDate(): void
    {
        $svc = $this->service();
        $a = $svc->buildDigest($this->sampleCards(), 'cs', self::TODAY);
        $b = $svc->buildDigest($this->sampleCards(), 'cs', '2026-07-03');
        $this->assertNotSame($this->hashDigest($a), $this->hashDigest($b));
    }

    public function testDigestChangesWithLanguage(): void
    {
        $svc = $this->service();
        $a = $svc->buildDigest($this->sampleCards(), 'cs', self::TODAY);
        $b = $svc->buildDigest($this->sampleCards(), 'en', self::TODAY);
        $this->assertNotSame($this->hashDigest($a), $this->hashDigest($b));
    }

    public function testDigestIgnoresInfoCards(): void
    {
        $svc = $this->service();
        $a = $svc->buildDigest($this->sampleCards(), 'cs', self::TODAY);

        $cards   = $this->sampleCards();
        $cards[] = ['id' => 'mail_notinvoice:9', 'kind' => 'info', 'title' => 'Neobsahuje doklad ani dokument'];
        $b = $svc->buildDigest($cards, 'cs', self::TODAY);

        $this->assertSame($this->hashDigest($a), $this->hashDigest($b));
    }

    public function testDigestCapsTopCardsButCountsAll(): void
    {
        $cards = [];
        for ($i = 0; $i < 10; $i++) {
            $cards[] = ['id' => "mail_{$i}", 'kind' => 'review', 'title' => "Karta {$i}", 'subtitle' => ''];
        }
        $digest = $this->service()->buildDigest($cards, 'cs', self::TODAY);
        $this->assertSame(10, $digest['counts']['review']);
        $this->assertCount(6, $digest['topCards']);
    }

    // -------------------------------------------------------------------------
    // stream() — cache & degradace
    // -------------------------------------------------------------------------

    public function testEmptyFeedReturnsNullWithoutLlmOrDb(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchRow');
        $db->expects($this->never())->method('insertRow');

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $result = $this->service($db, $llm)->stream([], 'cs', static fn (string $d) => null);
        $this->assertSame(['text' => null, 'cached' => false], $result);
    }

    public function testCacheHitReturnsStoredTextWithoutLlm(): void
    {
        $svc  = $this->service(); // jen na výpočet očekávaného hashe
        $hash = $this->hashDigest($svc->buildDigest($this->sampleCards(), 'cs', date('Y-m-d')));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'language' => 'cs', 'input_hash' => $hash, 'text' => 'Uložené shrnutí.',
        ]);
        $db->expects($this->never())->method('insertRow');
        $db->expects($this->never())->method('updateWhere');

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->never())->method('streamChat');

        $result = $this->service($db, $llm)->stream($this->sampleCards(), 'cs', static fn (string $d) => null);
        $this->assertSame(['text' => 'Uložené shrnutí.', 'cached' => true], $result);
    }

    public function testCacheMissStreamsAndInserts(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // žádná cache
        $db->expects($this->once())->method('insertRow')
            ->with('core_ai_dashboard_summary', $this->callback(
                static fn (array $row): bool => $row['text'] === 'Dnes máte tři věci.'
                    && $row['language'] === 'cs'
                    && $row['input_tokens'] === 120
                    && $row['output_tokens'] === 40
                    && $row['input_hash'] !== '',
            ));

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->once())->method('streamChat')
            ->willReturnCallback(static function (LlmChatParams $params, callable $onDelta): LlmChatResult {
                $onDelta('Dnes máte ');
                $onDelta('tři věci.');
                return new LlmChatResult('Dnes máte tři věci.', 120, 40, 'end_turn', 'claude-x');
            });

        $deltas = [];
        $result = $this->service($db, $llm)->stream(
            $this->sampleCards(), 'cs',
            static function (string $d) use (&$deltas): void { $deltas[] = $d; },
        );

        $this->assertSame(['text' => 'Dnes máte tři věci.', 'cached' => false], $result);
        $this->assertSame(['Dnes máte ', 'tři věci.'], $deltas);
    }

    public function testCacheMissWithExistingRowUpdates(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'language' => 'cs', 'input_hash' => 'stale-hash', 'text' => 'Staré shrnutí.',
        ]);
        $db->expects($this->never())->method('insertRow');
        $db->expects($this->once())->method('updateWhere')
            ->with('core_ai_dashboard_summary', $this->callback(
                static fn (array $row): bool => $row['text'] === 'Nové shrnutí.',
            ), '`language` = %s', 'cs');

        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturn(new LlmChatResult('Nové shrnutí.', 10, 5, 'end_turn', 'm'));

        $result = $this->service($db, $llm)->stream($this->sampleCards(), 'cs', static fn (string $d) => null);
        $this->assertSame(['text' => 'Nové shrnutí.', 'cached' => false], $result);
    }

    /** Změna modelu funkce (D48) = jiný klíč cache: uložené shrnutí se neservíruje. */
    public function testCacheKeyIncludesTheModel(): void
    {
        $digest = $this->service()->buildDigest($this->sampleCards(), 'cs', date('Y-m-d'));
        $this->assertNotSame($this->hashDigest($digest, 'claude-x'), $this->hashDigest($digest, 'claude-haiku-5-5'));

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn([
            'language' => 'cs', 'input_hash' => $this->hashDigest($digest, 'claude-x'), 'text' => 'Uložené shrnutí.',
        ]);
        $db->expects($this->once())->method('updateWhere');

        $llm = $this->createMock(LlmClient::class);
        $llm->expects($this->once())->method('streamChat')
            ->willReturn(new LlmChatResult('Nové shrnutí.', 10, 5, 'end_turn', 'claude-haiku-5-5'));

        $result = $this->service($db, $llm, $this->functions($this->call(['model' => 'claude-haiku-5-5'])))
            ->stream($this->sampleCards(), 'cs', static fn (string $d) => null);
        $this->assertSame(['text' => 'Nové shrnutí.', 'cached' => false], $result);
    }

    /** Nenastavená AI funkce (bez připojení, klíče, model mimo platformu) = bez shrnutí, ne chyba. */
    public function testUnresolvedFunctionDegradesToNull(): void
    {
        foreach ([
            AiFunctionException::NO_CONNECTION,
            AiFunctionException::KEY_MISSING,
            AiFunctionException::KEY_CORRUPTED,
            AiFunctionException::MODEL_NOT_ON_PLATFORM,
        ] as $code) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchRow')->willReturn(null);
            $db->expects($this->never())->method('insertRow');

            $llm = $this->createMock(LlmClient::class);
            $llm->expects($this->never())->method('streamChat');

            $functions = $this->functions(failure: new AiFunctionException($code, 'x'));
            $result = $this->service($db, $llm, $functions)->stream($this->sampleCards(), 'cs', static fn (string $d) => null);
            $this->assertSame(['text' => null, 'cached' => false], $result, $code);
        }
    }

    /** Useknuté / odmítnuté shrnutí (F0-D4) se necachuje a vrací text null. */
    public function testIncompleteOutputIsNotCached(): void
    {
        foreach (['max_tokens', 'refusal'] as $stop) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchRow')->willReturn(null);
            $db->expects($this->never())->method('insertRow');
            $db->expects($this->never())->method('updateWhere');

            $llm = $this->createMock(LlmClient::class);
            $llm->method('streamChat')->willReturn(new LlmChatResult('Dnes máte tři věci a', 10, 5, $stop, 'm'));

            $result = $this->service($db, $llm)->stream($this->sampleCards(), 'cs', static fn (string $d) => null);
            $this->assertSame(['text' => null, 'cached' => false], $result, $stop);
        }
    }

    /** Přepsané ladění z rozhodnutí resolveru projde beze změny (teplotu funkce nedeklaruje). */
    public function testTuningComesFromTheResolvedCall(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta) use (&$captured): LlmChatResult {
                $captured = $params;
                return new LlmChatResult('Text.', 1, 1, 'end_turn', 'claude-x');
            },
        );

        $functions = $this->functions($this->call(['thinking' => 'adaptive', 'effort' => 'high', 'shapeAs' => 'claude-sonnet-5-5']));
        $this->service($db, $llm, $functions)->stream($this->sampleCards(), 'cs', static fn (string $d) => null);

        $this->assertNull($captured->temperature);
        $this->assertSame('adaptive', $captured->thinking);
        $this->assertSame('high', $captured->effort);
        $this->assertSame('claude-sonnet-5-5', $captured->shapeAs);
    }

    /** Výchozí rozhodnutí (D36): 2000 tokenů, bez teploty, ladění a nástrojů — stejné tělo jako před změnou. */
    public function testLlmParamsCarryPromptAndLimits(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('streamChat')->willReturnCallback(
            static function (LlmChatParams $params, callable $onDelta) use (&$captured): LlmChatResult {
                $captured = $params;
                return new LlmChatResult('Text.', 1, 1, 'end_turn', 'claude-x');
            },
        );

        $functions = $this->functions($this->call(['baseUrl' => 'https://api.example.test']));
        $this->service($db, $llm, $functions)->stream($this->sampleCards(), 'cs', static fn (string $d) => null);

        $this->assertInstanceOf(LlmChatParams::class, $captured);
        $this->assertSame('claude-x', $captured->model);
        $this->assertSame('https://api.example.test', $captured->baseUrl);
        $this->assertSame(2000, $captured->maxTokens);
        $this->assertNull($captured->temperature);
        $this->assertNull($captured->thinking);
        $this->assertNull($captured->effort);
        $this->assertNull($captured->tools);
        $this->assertStringContainsString('2 až 4 věty', (string) $captured->system);
        $userContent = (string) $captured->messages[0]['content'];
        $this->assertStringContainsString('Faktura po splatnosti', $userContent);
        $this->assertStringContainsString(date('Y-m-d'), $userContent);
    }
}
