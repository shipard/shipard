<?php

declare(strict_types=1);

namespace Shipard\Api\Controller;

use Shipard\Api\AuthContext;
use Shipard\Api\Response;
use Shipard\Core\Ai\Exception\LlmException;
use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Dashboard\DashboardSummaryService;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedCollector;
use Shipard\Core\Logging\ErrorLogger;

/**
 * Dashboard — prezentační vrstva feedu akčních karet (fáze 2).
 *
 * Sběr, sekce, řazení a strop per sekce řeší `FeedCollector` (sdílený
 * s badge stavů sekcí, UI shells Fáze 3) a vrací `FeedResult`; controller
 * nad ním staví odpovědi — dashboard feed (`cards` po stropu, `sections`
 * s pravdivými počty), AI shrnutí (SSE) a `readySummary`. County, badge,
 * digest i `readySummary` se počítají ze **všech** karet (`allCards`),
 * jen `cards` jsou stropnuté (#101 D3b).
 *
 * Detaily: `docs/dashboard.md`.
 */
class DashboardController
{
    /**
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     *        Runtime definice tabulek — řídí registraci zdrojů (D8)
     *        a capabilities (D9). Prázdná mapa = fail-closed.
     */
    public function dashboard(
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
        ?AuthContext $auth = null,
        ?string $section = null,
        bool $readOnly = false,
    ): Response {
        $lang = $language ?? 'en';

        $collector = new FeedCollector();
        $result    = $collector->collect($db, $config, $lang, $alertRegistry, $tables);

        // Sekční filtr `?section=` (UI shells Fáze 5, R4) — karty jedné sekce
        // NAVIGACE (`navSection`, ne `feedSection`) pro blok v scoped chat
        // konverzaci. Filtruje se po collect nad stropnutými kartami;
        // summary, sections, readySummary i capabilities jsou celofeedové →
        // při filtru se vynechají, odpověď nese jen karty. Nevalidní hodnota
        // → přirozeně prázdný seznam, ne chyba.
        if ($section !== null && $section !== '') {
            $filtered = array_values(array_filter(
                $result->cards,
                static fn (array $c): bool => ($c['navSection'] ?? null) === $section,
            ));
            return Response::success([
                'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'cards'       => $collector->stripInternalFields($filtered),
            ]);
        }
        // readySummary se počítá nad VŠEMI ready kartami (#101 D3b) — pruh
        // Připraveno mluví o celé sekci, `shown` říká, kolik z nich je
        // v `cards`. Interní pole `amount`/`currency` se hned poté odstraní —
        // do kontraktu nepatří.
        $readySummary = $this->buildReadySummary($result->allCards, $result->cards);

        $data = [
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'summary'     => ['aiText' => null, 'counts' => $collector->countByKind($result->allCards)],
            'sections'    => $result->sections,
            'cards'       => $collector->stripInternalFields($result->cards),
        ];
        // Souhrn ready pásma pro sbalený pruh (Issue #32/2, D8) — jen když
        // je aspoň jedna ready karta; jinak se pole vynechá.
        if ($readySummary !== null) {
            $data['readySummary'] = $readySummary;
        }
        // Capabilities (D9) — frontend podle nich skrývá upload a
        // ChatLauncher. `chat` musí zůstat identický s podmínkou Chat
        // root leafu v NavigationController (D5 + D10 + read-only #56 D5).
        // Upload na read-only DS server odmítá 403, tlačítko se skrývá taky.
        $data['capabilities'] = [
            'mailUpload' => isset($tables['core_mail_incoming_messages']) && !$readOnly,
            'chat'       => isset($tables['core_chat_conversations'])
                && (($auth?->isAdmin ?? false) || !isset($tables['hosting_core_data_sources']))
                && !$readOnly,
        ];

        return Response::success($data);
    }

    /**
     * GET /_ui/dashboard/summary — generované AI shrnutí feedu (SSE, fáze 2b).
     *
     * Sdílí `FeedCollector::collect()` s `dashboard()`; digest dostává
     * všechny karty (pravdivé county, #101) — top karty vybírá služba z čela
     * seznamu, tedy v pořadí sekcí. Události: `text {delta}` (jen při
     * cache miss), `done {text, cached}` (`text=null` = prázdný feed nebo
     * degradace — frontend ponechá statické county), `error {message}`.
     * Vzor streamu: ChatController. Detaily docs/dashboard.md §AI shrnutí.
     */
    public function summary(
        DataSourceConnection $db,
        DashboardSummaryService $service,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): Response {
        $lang = $language ?? 'en';

        $collector = new FeedCollector();
        $result    = $collector->collect($db, $config, $lang, $alertRegistry, $tables);
        // Čisté karty i pro AI shrnutí — digest cache se interními poli nemění.
        $cards = $collector->stripInternalFields($result->allCards);

        return Response::stream(
            function () use ($service, $cards, $lang): void {
                try {
                    $result = $service->stream($cards, $lang, function (string $delta): void {
                        $this->sse('text', ['delta' => $delta]);
                    });
                    $this->sse('done', ['text' => $result['text'], 'cached' => $result['cached']]);
                } catch (LlmException $e) {
                    $this->sse('error', ['message' => $e->getMessage()]);
                } catch (\Throwable $e) {
                    ErrorLogger::warn('DashboardController: summary stream failed', ['error' => $e->getMessage()]);
                    $this->sse('error', ['message' => 'Internal error during summary']);
                }
            },
            200,
            'text/event-stream; charset=utf-8',
        );
    }

    /**
     * GET /_ui/section-badges — badge stavů sekcí navigace (UI shells Fáze 3).
     *
     * Stejný sběr karet jako dashboard (plný FeedContext), jiná prezentace:
     * agregace per `navSection` (`FeedCollector::sectionBadges`, D2–D4) nad
     * všemi kartami, ne jen pod stropem (#101). Odpověď:
     * `{sections: {"<sectionId>": {count, severity}}}` — jen neprázdné
     * sekce, `_top` je platný klíč.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     */
    public function sectionBadges(
        DataSourceConnection $db,
        ?ConfigRuntime $config = null,
        ?string $language = null,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): Response {
        $lang = $language ?? 'en';

        $collector = new FeedCollector();
        $result    = $collector->collect($db, $config, $lang, $alertRegistry, $tables);

        // (object) — prázdná mapa musí být v JSON `{}`, ne `[]`.
        return Response::success(['sections' => (object) $collector->sectionBadges($result->allCards)]);
    }

    /**
     * Souhrn ready pásma pro sbalené pruhy feedu (Issue #32/2, D8 + D11;
     * #101 D3b). Počítá se ze **všech** ready karet (`$cards` =
     * `FeedResult::$allCards`) — titulek pruhu a součty mluví o celé sekci
     * Připraveno, ne jen o kartách pod stropem — a dělí se **per kategorie**:
     * `invoices` (přijaté faktury, defenzivní default) a `registry` (Spisovna)
     * mají každá vlastní pruh. `shown` = počet ready karet skupiny
     * v `$shownCards` (karty po stropu; bez argumentu = `count`), aby
     * frontend uměl odečíst optimisticky smazané karty.
     * Částky se agregují per měna, nikdy napříč měnami; karta bez
     * `amount`/`currency` se do `amounts` nezapočítá, do `count` ano
     * (registry karty částky nenesou → jejich `amounts` je vždy prázdné).
     * `confidencePct` u ready karet vždy existuje (pásmo se bez jistoty
     * nespočítá), kód je přesto defenzivní — bez hodnot zůstane min/max null.
     *
     * @param  list<array<string,mixed>>      $cards       všechny karty (bez stropu)
     * @param  list<array<string,mixed>>|null $shownCards  karty po stropu; null = `$cards`
     * @return array<string, array{count:int, shown:int,
     *               amounts:list<array{currency:string,total:float}>,
     *               confidenceMin:int|null, confidenceMax:int|null}>|null
     *         klíče `invoices`/`registry`, jen neprázdné skupiny;
     *         null = žádná ready karta (pole se v odpovědi vynechá)
     * @internal Public pro účely testů — čistá transformace bez business logiky.
     */
    public function buildReadySummary(array $cards, ?array $shownCards = null): ?array
    {
        $groups = [];
        foreach ($cards as $card) {
            if (($card['kind'] ?? '') !== 'ready') {
                continue;
            }
            $key = self::readyGroupKey($card);
            $g = $groups[$key] ?? ['count' => 0, 'totals' => [], 'confMin' => null, 'confMax' => null];
            $g['count']++;
            $amount   = $card['amount'] ?? null;
            $currency = $card['currency'] ?? null;
            if ((is_int($amount) || is_float($amount)) && is_string($currency) && $currency !== '') {
                $g['totals'][$currency] = ($g['totals'][$currency] ?? 0.0) + (float) $amount;
            }
            $conf = $card['confidencePct'] ?? null;
            if (is_int($conf)) {
                $g['confMin'] = $g['confMin'] === null ? $conf : min($g['confMin'], $conf);
                $g['confMax'] = $g['confMax'] === null ? $conf : max($g['confMax'], $conf);
            }
            $groups[$key] = $g;
        }
        if ($groups === []) {
            return null;
        }

        $shown = ['invoices' => 0, 'registry' => 0];
        foreach ($shownCards ?? $cards as $card) {
            if (($card['kind'] ?? '') === 'ready') {
                $shown[self::readyGroupKey($card)]++;
            }
        }

        $summary = [];
        foreach (['invoices', 'registry'] as $key) {
            if (!isset($groups[$key])) {
                continue;
            }
            $amounts = [];
            foreach ($groups[$key]['totals'] as $currency => $total) {
                $amounts[] = ['currency' => (string) $currency, 'total' => round($total, 2)];
            }
            $summary[$key] = [
                'count'         => $groups[$key]['count'],
                'shown'         => min($shown[$key], $groups[$key]['count']),
                'amounts'       => $amounts,
                'confidenceMin' => $groups[$key]['confMin'],
                'confidenceMax' => $groups[$key]['confMax'],
            ];
        }
        return $summary;
    }

    /** Skupina pruhu Připraveno: `registry` (Spisovna), jinak `invoices` — shodné s frontendem. */
    private static function readyGroupKey(array $card): string
    {
        return ($card['category'] ?? '') === 'registry' ? 'registry' : 'invoices';
    }

    /** Writes one SSE event frame and flushes it to the client. */
    private function sse(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }
}
