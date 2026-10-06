<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

use Shipard\Core\Alerts\AlertCheckRegistry;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Alerts\Feed\AlertsSource;
use Shipard\Module\Core\Exchange\Dashboard\ContentTagSuggestionsSource;
use Shipard\Module\Core\Mail\Feed\MailDigestSource;
use Shipard\Module\Core\Mail\Feed\MailSuggestionsSource;

/**
 * Sběr karet domovského feedu — „one calculation, N presentations".
 *
 * Extrahováno z `DashboardController` (UI shells Fáze 3): dashboard, AI shrnutí
 * i badge stavů sekcí stojí nad týmiž kartami. Zdroje zůstávají napevno
 * registrované (dashboard.md D10), registrace se řídí přítomností klíčových
 * tabulek na DS (D8). Bezstavová služba bez konstruktoru — wiring přes
 * `new FeedCollector()` v controlleru (v repu není DI kontejner).
 *
 * Sekce feedu (#101): každá karta dostane `feedSection` (explicitní ze
 * zdroje, jinak výchozí z `kind`), karty se řadí sekce → pásmo → čas
 * a stropují **per sekce** (`MAX_CARDS_PER_SECTION`). Zdroje dostávají jen
 * pojistný `SOURCE_LIMIT` pro své dotazy. Výsledek nese `FeedResult`.
 *
 * Detaily: `docs/dashboard.md`.
 */
final class FeedCollector
{
    /** Strop počtu karet **per sekce** feedu (#101 D3a); pravdivé počty nese `FeedResult::$sections`. */
    public const int MAX_CARDS_PER_SECTION = 30;

    /**
     * Pojistný limit dotazů zdrojů (`FeedContext::$sourceLimit`) — ne strop
     * feedu. Počty per sekce jsou pravdivé jen do tohoto limitu; nezvyšovat
     * bez měření (`MailSuggestionsSource` dekóduje `canonical_json` každého
     * řádku a sběr běží i při pollingu badge).
     */
    public const int SOURCE_LIMIT = 500;

    /** Pořadí sekcí feedu podle toku práce (#101 D8). */
    public const array SECTION_ORDER = [
        FeedSource::SECTION_NEW_ITEMS,
        FeedSource::SECTION_READY,
        FeedSource::SECTION_REVIEW,
        FeedSource::SECTION_FAILED,
        FeedSource::SECTION_ALERTS,
        FeedSource::SECTION_OTHER,
    ];

    /** Výchozí sekce karty bez `feedSection` dle `kind` (#101 D2b); neznámý kind → other. */
    public const array DEFAULT_SECTION_BY_KIND = [
        'urgent' => FeedSource::SECTION_FAILED,
        'review' => FeedSource::SECTION_REVIEW,
        'ready'  => FeedSource::SECTION_READY,
        'info'   => FeedSource::SECTION_OTHER,
    ];

    /**
     * Prioritní žebříček pásem karet uvnitř sekce (nižší = výše). V sekci
     * Upozornění odpovídá závažnosti alertu (D5), protože `AlertsSource`
     * mapuje severity na kind. Sekundárně timestamp DESC.
     */
    private const array KIND_ORDER = ['urgent' => 0, 'review' => 1, 'ready' => 2, 'info' => 3];

    /**
     * Posbírá karty ze zdrojů feedu, doplní sekce, seřadí a stropuje per
     * sekce (`sortAndCap`).
     *
     * Vrácené karty NESOU interní pole `amount`/`currency` (podklad pro
     * readySummary) — prezentační vrstva je před odesláním klientovi odstraní
     * přes `stripInternalFields()`.
     *
     * @param array<string, \Shipard\Core\Database\TableDefinition> $tables
     *        Runtime definice tabulek — řídí registraci zdrojů (D8).
     *        Prázdná mapa = fail-closed.
     */
    public function collect(
        DataSourceConnection $db,
        ?ConfigRuntime $config,
        string $lang,
        ?AlertCheckRegistry $alertRegistry = null,
        array $tables = [],
    ): FeedResult {
        $ctx = new FeedContext($db, $config, $lang, self::SOURCE_LIMIT);

        // Zdroje se registrují podle přítomnosti klíčové tabulky na DS (D8) —
        // feed nesmí padat na DS bez core.mail / core.alerts (hosting DS).
        // Mapování tabulka → zdroj drží collector; zdroje zůstávají napevno
        // registrované (dashboard.md D10), žádné nové rozhraní na FeedSource.
        /** @var list<FeedSource> $sources */
        $sources = [];
        if (isset($tables['core_mail_incoming_messages'])) {
            $sources[] = new MailSuggestionsSource();
            $sources[] = new MailDigestSource();
        }
        if (isset($tables['core_alerts_alerts'])) {
            $sources[] = new AlertsSource($alertRegistry);
        }
        // Karta položky k založení (content-tag-ui D25) — potřebuje analýzy
        // (štítky návrhů), položky (pokrytí štítků) i osnovu (volba účtu
        // goods.stock + materializace).
        if (isset($tables['core_mail_message_analyses'], $tables['economy_items'], $tables['economy_accounting_accounts'])) {
            $sources[] = new ContentTagSuggestionsSource();
        }

        $cards = [];
        foreach ($sources as $src) {
            // Per-source izolace (D8): výjimka jednoho zdroje se zaloguje
            // a feed pokračuje ostatními zdroji.
            try {
                foreach ($src->collectCards($ctx) as $card) {
                    $cards[] = $card;
                }
            } catch (\Throwable $e) {
                ErrorLogger::logException($e, 'Dashboard feed source failed: ' . $src::class);
            }
        }

        return $this->sortAndCap($cards);
    }

    /**
     * Odstraní interní pole `amount`/`currency` (podklad pro readySummary)
     * ze všech karet — do kartového kontraktu (docs/dashboard.md §4) nepatří.
     *
     * @param  list<array<string,mixed>> $cards
     * @return list<array<string,mixed>>
     */
    public function stripInternalFields(array $cards): array
    {
        foreach ($cards as &$card) {
            unset($card['amount'], $card['currency']);
        }
        return $cards;
    }

    /**
     * Sekce karty: explicitní `feedSection` ze zdroje, pokud je známá;
     * jinak výchozí dle `kind` (`DEFAULT_SECTION_BY_KIND`), neznámý kind →
     * Ostatní — stejná defenziva, jakou má frontend.
     *
     * @param array<string,mixed> $card
     */
    public static function resolveSection(array $card): string
    {
        $section = $card['feedSection'] ?? null;
        if (is_string($section) && in_array($section, self::SECTION_ORDER, true)) {
            return $section;
        }
        return self::DEFAULT_SECTION_BY_KIND[(string) ($card['kind'] ?? '')] ?? FeedSource::SECTION_OTHER;
    }

    /**
     * Doplní každé kartě `feedSection`, seřadí karty dle sekce
     * (`SECTION_ORDER`), uvnitř sekce dle pásma (`KIND_ORDER`) a `timestamp`
     * sestupně (nejnovější první; karty bez timestampu naspod), a ořízne
     * každou sekci na `$maxPerSection`. Počty `total`/`shown` per sekce
     * (jen neprázdné, v pořadí sekcí) nese výsledek.
     *
     * @param list<array<string,mixed>> $cards
     */
    public function sortAndCap(array $cards, int $maxPerSection = self::MAX_CARDS_PER_SECTION): FeedResult
    {
        foreach ($cards as &$card) {
            $card['feedSection'] = self::resolveSection($card);
        }
        unset($card);

        $sectionRank = array_flip(self::SECTION_ORDER);
        usort($cards, static function (array $a, array $b) use ($sectionRank): int {
            $sa = $sectionRank[$a['feedSection']];
            $sb = $sectionRank[$b['feedSection']];
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $oa = self::KIND_ORDER[$a['kind'] ?? ''] ?? 99;
            $ob = self::KIND_ORDER[$b['kind'] ?? ''] ?? 99;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            $ta = (string) ($a['timestamp'] ?? '');
            $tb = (string) ($b['timestamp'] ?? '');
            if ($ta === $tb) {
                return 0;
            }
            if ($ta === '') {
                return 1;
            }
            if ($tb === '') {
                return -1;
            }
            return strcmp($tb, $ta); // ATOM formát řadí lexikálně = chronologicky
        });

        /** @var array<string, array{total:int, shown:int}> $counts */
        $counts = [];
        $capped = [];
        foreach ($cards as $card) {
            $section = $card['feedSection'];
            $entry = $counts[$section] ?? ['total' => 0, 'shown' => 0];
            $entry['total']++;
            if ($entry['shown'] < $maxPerSection) {
                $entry['shown']++;
                $capped[] = $card;
            }
            $counts[$section] = $entry;
        }

        $sections = [];
        foreach (self::SECTION_ORDER as $id) {
            if (isset($counts[$id])) {
                $sections[] = ['id' => $id, 'total' => $counts[$id]['total'], 'shown' => $counts[$id]['shown']];
            }
        }

        return new FeedResult($capped, $cards, $sections);
    }

    /**
     * Agregace feedu per navigační sekce pro badge stavů sekcí (UI shells
     * Fáze 3, D2–D4). Čistá funkce nad kartami: počítají se jen pásma
     * `urgent` (→ severity `danger`) a `review` (→ `warning`) s neprázdným
     * `navSection` (opt-in, D1); `ready`/`info` ne — trvale svítící badge
     * není signál. Sekce = součet karet + max severity (danger > warning).
     * Volající předává `FeedResult::$allCards` — badge počítá ze všech karet,
     * ne jen z karet pod stropem (#101).
     *
     * @param  list<array<string,mixed>> $cards
     * @return array<string, array{count:int, severity:string}>
     *         klíč = id sekce (vč. sentinelu `_top`), jen neprázdné sekce
     */
    public function sectionBadges(array $cards): array
    {
        $sections = [];
        foreach ($cards as $card) {
            $kind = $card['kind'] ?? '';
            if ($kind !== 'urgent' && $kind !== 'review') {
                continue;
            }
            $section = $card['navSection'] ?? null;
            if (!is_string($section) || $section === '') {
                continue;
            }
            $entry = $sections[$section] ?? ['count' => 0, 'severity' => 'warning'];
            $entry['count']++;
            if ($kind === 'urgent') {
                $entry['severity'] = 'danger';
            }
            $sections[$section] = $entry;
        }
        return $sections;
    }

    /**
     * Počty karet dle kind (jen actionable pásma — urgent/review/ready).
     * Info karty se nezapočítávají. Volající předává `FeedResult::$allCards`
     * (pravdivé county nad stropem, #101 D3b).
     *
     * @param  list<array<string,mixed>> $cards
     * @return array{urgent:int, review:int, ready:int}
     */
    public function countByKind(array $cards): array
    {
        $counts = ['urgent' => 0, 'review' => 0, 'ready' => 0];
        foreach ($cards as $card) {
            $kind = (string) ($card['kind'] ?? '');
            if (isset($counts[$kind])) {
                $counts[$kind]++;
            }
        }
        return $counts;
    }
}
