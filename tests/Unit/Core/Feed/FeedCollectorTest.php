<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Feed;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Feed\FeedCollector;
use Shipard\Core\Feed\FeedSource;

/**
 * Unit testy pro FeedCollector — čisté transformace nad kartami feedu
 * (sortAndCap / countByKind / stripInternalFields). Přestěhováno
 * z DashboardControllerTest při extrakci collectoru (UI shells Fáze 3).
 *
 * Sekce feedu (#101): výchozí `feedSection` z kind, explicitní hodnota ze
 * zdroje přebije výchozí, neznámá hodnota padá na výchozí; řazení sekce →
 * kind → čas; strop 30 **per sekce** s pravdivými počty v `sections`;
 * `allCards` bez stropu.
 *
 * Zapojení zdrojů, per-source izolaci a degradaci dle tabulek pokrývají
 * integrační testy v DashboardControllerTest (přes dashboard()).
 */
final class FeedCollectorTest extends TestCase
{
    /** @return array<string,mixed> */
    private function card(string $kind, ?string $timestamp, string $id = 'x'): array
    {
        return ['id' => $id, 'kind' => $kind, 'timestamp' => $timestamp];
    }

    /** @return array<string,mixed> */
    private function sectionedCard(string $kind, string $feedSection, string $id, ?string $timestamp = null): array
    {
        return [...$this->card($kind, $timestamp, $id), 'feedSection' => $feedSection];
    }

    // ── sortAndCap — sekce (#101 D2b) ────────────────────────────────────────

    public function testSortAndCapAssignsDefaultSectionFromKind(): void
    {
        $result = (new FeedCollector())->sortAndCap([
            $this->card('urgent', null, 'u'),
            $this->card('review', null, 'v'),
            $this->card('ready', null, 'r'),
            $this->card('info', null, 'i'),
            $this->card('weird', null, 'w'),   // neznámý kind → Ostatní
        ]);

        $sections = [];
        foreach ($result->cards as $card) {
            $sections[$card['id']] = $card['feedSection'];
        }
        $this->assertSame([
            'r' => FeedSource::SECTION_READY,
            'v' => FeedSource::SECTION_REVIEW,
            'u' => FeedSource::SECTION_FAILED,
            'i' => FeedSource::SECTION_OTHER,
            'w' => FeedSource::SECTION_OTHER,
        ], $sections);
    }

    public function testSortAndCapKeepsExplicitFeedSection(): void
    {
        // Zdroj smí výchozí mapování přepsat: karta položky (review) patří do
        // Položek k založení, warning alert (review) do Upozornění.
        $result = (new FeedCollector())->sortAndCap([
            $this->sectionedCard('review', FeedSource::SECTION_NEW_ITEMS, 'tag'),
            $this->sectionedCard('review', FeedSource::SECTION_ALERTS, 'alert'),
            $this->card('review', null, 'plain'),
        ]);

        $this->assertSame(['tag', 'plain', 'alert'], array_column($result->cards, 'id'));
        $this->assertSame(FeedSource::SECTION_NEW_ITEMS, $result->cards[0]['feedSection']);
        $this->assertSame(FeedSource::SECTION_REVIEW, $result->cards[1]['feedSection']);
        $this->assertSame(FeedSource::SECTION_ALERTS, $result->cards[2]['feedSection']);
    }

    public function testSortAndCapUnknownFeedSectionFallsBackToKind(): void
    {
        $result = (new FeedCollector())->sortAndCap([
            $this->sectionedCard('ready', 'bogus', 'r'),
            [...$this->card('urgent', null, 'u'), 'feedSection' => null],
        ]);

        $this->assertSame(FeedSource::SECTION_READY, $result->cards[0]['feedSection']);
        $this->assertSame(FeedSource::SECTION_FAILED, $result->cards[1]['feedSection']);
    }

    // ── sortAndCap — řazení sekce → kind → čas ───────────────────────────────

    public function testSortAndCapOrdersBySectionOrder(): void
    {
        $t = '2026-06-28T10:00:00+00:00';
        $result = (new FeedCollector())->sortAndCap([
            $this->card('info', $t, 'other'),
            $this->sectionedCard('review', FeedSource::SECTION_ALERTS, 'alerts', $t),
            $this->card('urgent', $t, 'failed'),
            $this->card('review', $t, 'review'),
            $this->card('ready', $t, 'ready'),
            $this->sectionedCard('review', FeedSource::SECTION_NEW_ITEMS, 'newItems', $t),
        ]);

        // D8: Položky k založení → Připraveno → Ke kontrole → Nepodařilo se
        // zpracovat → Upozornění → Ostatní (id karet = id sekcí).
        $this->assertSame(FeedCollector::SECTION_ORDER, array_column($result->cards, 'id'));
    }

    public function testSortAndCapOrdersByKindWithinSection(): void
    {
        // Upozornění: uvnitř sekce dle kind = dle závažnosti (D5).
        $t = '2026-06-28T10:00:00+00:00';
        $result = (new FeedCollector())->sortAndCap([
            $this->sectionedCard('info', FeedSource::SECTION_ALERTS, 'i', $t),
            $this->sectionedCard('urgent', FeedSource::SECTION_ALERTS, 'u', $t),
            $this->sectionedCard('review', FeedSource::SECTION_ALERTS, 'v', $t),
        ]);

        $this->assertSame(['u', 'v', 'i'], array_column($result->cards, 'id'));
    }

    public function testSortAndCapTimestampDescWithinKind(): void
    {
        $result = (new FeedCollector())->sortAndCap([
            $this->card('ready', '2026-06-01T10:00:00+00:00', 'old'),
            $this->card('ready', '2026-06-28T10:00:00+00:00', 'new'),
            $this->card('ready', null, 'notime'),
        ]);

        // Nejnovější první, karta bez timestampu naspod pásma.
        $this->assertSame(['new', 'old', 'notime'], array_column($result->cards, 'id'));
    }

    // ── sortAndCap — strop per sekce, počty (#101 D3a/D3b) ───────────────────

    public function testSortAndCapCapsPerSectionAndKeepsTruthfulCounts(): void
    {
        $input = [];
        for ($i = 0; $i < 31; $i++) {
            $input[] = $this->card('ready', '2026-06-28T10:00:00+00:00', "r$i");
        }
        $input[] = $this->card('review', null, 'v1');
        $input[] = $this->card('review', null, 'v2');

        $result = (new FeedCollector())->sortAndCap($input);

        // Strop 30 platí jen na přetékající sekci; ostatní nedotčené.
        $this->assertCount(32, $result->cards);
        $this->assertCount(33, $result->allCards);
        $this->assertSame(30, count(array_filter($result->cards, static fn (array $c): bool => $c['kind'] === 'ready')));
        $this->assertSame(['v1', 'v2'], array_column(array_slice($result->cards, 30), 'id'));
        $this->assertSame([
            ['id' => FeedSource::SECTION_READY,  'total' => 31, 'shown' => 30],
            ['id' => FeedSource::SECTION_REVIEW, 'total' => 2,  'shown' => 2],
        ], $result->sections);
        $this->assertTrue($result->hasMore());
        // allCards nesou sekci i pořadí stejně jako cards.
        $this->assertSame(FeedSource::SECTION_READY, $result->allCards[30]['feedSection']);
    }

    public function testSortAndCapSectionsOnlyNonEmptyInSectionOrder(): void
    {
        $result = (new FeedCollector())->sortAndCap([
            $this->card('info', null, 'i'),
            $this->sectionedCard('review', FeedSource::SECTION_NEW_ITEMS, 'tag'),
        ]);

        $this->assertSame(
            [FeedSource::SECTION_NEW_ITEMS, FeedSource::SECTION_OTHER],
            array_column($result->sections, 'id'),
        );
        $this->assertFalse($result->hasMore());
    }

    public function testSortAndCapEmptyInput(): void
    {
        $result = (new FeedCollector())->sortAndCap([]);

        $this->assertSame([], $result->cards);
        $this->assertSame([], $result->allCards);
        $this->assertSame([], $result->sections);
        $this->assertFalse($result->hasMore());
    }

    public function testSortAndCapCustomMaxPerSection(): void
    {
        $result = (new FeedCollector())->sortAndCap([
            $this->card('ready', null, 'a'),
            $this->card('ready', null, 'b'),
            $this->card('ready', null, 'c'),
        ], 2);

        $this->assertSame(['a', 'b'], array_column($result->cards, 'id'));
        $this->assertSame([['id' => 'ready', 'total' => 3, 'shown' => 2]], $result->sections);
    }

    // ── countByKind ──────────────────────────────────────────────────────────

    public function testCountByKindCountsOnlyActionable(): void
    {
        $collector = new FeedCollector();
        $cards = [
            $this->card('urgent', null),
            $this->card('urgent', null),
            $this->card('review', null),
            $this->card('ready', null),
            $this->card('info', null),   // nezapočítává se
        ];
        $this->assertSame(['urgent' => 2, 'review' => 1, 'ready' => 1], $collector->countByKind($cards));
    }

    // ── sectionBadges (UI shells Fáze 3, D2) ─────────────────────────────────

    /** @return array<string,mixed> */
    private function sectionCard(string $kind, ?string $navSection, string $id = 'x'): array
    {
        $card = ['id' => $id, 'kind' => $kind, 'timestamp' => null];
        if ($navSection !== null) {
            $card['navSection'] = $navSection;
        }
        return $card;
    }

    public function testSectionBadgesCountsOnlyUrgentAndReview(): void
    {
        $collector = new FeedCollector();
        $badges = $collector->sectionBadges([
            $this->sectionCard('urgent', 'accounting'),
            $this->sectionCard('review', 'accounting'),
            $this->sectionCard('ready', 'accounting'),   // nepočítá se (D2)
            $this->sectionCard('info', 'accounting'),    // nepočítá se (D2)
        ]);

        $this->assertSame(['accounting' => ['count' => 2, 'severity' => 'danger']], $badges);
    }

    public function testSectionBadgesIgnoresCardsWithoutNavSection(): void
    {
        $collector = new FeedCollector();
        $badges = $collector->sectionBadges([
            $this->sectionCard('urgent', null),
            [...$this->sectionCard('review', null), 'navSection' => null],
            $this->sectionCard('review', 'basic'),
        ]);

        $this->assertSame(['basic' => ['count' => 1, 'severity' => 'warning']], $badges);
    }

    public function testSectionBadgesMaxSeverityAndPerSectionSums(): void
    {
        $collector = new FeedCollector();
        $badges = $collector->sectionBadges([
            $this->sectionCard('review', 'accounting'),
            $this->sectionCard('urgent', 'accounting'),  // max severity vyhrává
            $this->sectionCard('review', 'accounting'),
            $this->sectionCard('review', '_top'),        // sentinel je platný klíč
            $this->sectionCard('review', '_top'),
        ]);

        $this->assertSame(3, $badges['accounting']['count']);
        $this->assertSame('danger', $badges['accounting']['severity']);
        $this->assertSame(['count' => 2, 'severity' => 'warning'], $badges['_top']);
    }

    public function testSectionBadgesEmptyFeedYieldsEmptyMap(): void
    {
        $this->assertSame([], (new FeedCollector())->sectionBadges([]));
    }

    // ── stripInternalFields ──────────────────────────────────────────────────

    public function testStripInternalFieldsRemovesAmountAndCurrency(): void
    {
        $collector = new FeedCollector();
        $stripped = $collector->stripInternalFields([
            ['id' => 'a', 'kind' => 'ready', 'amount' => 500.00, 'currency' => 'CZK', 'confidencePct' => 92],
            $this->card('info', null, 'i'),
        ]);

        foreach ($stripped as $card) {
            $this->assertArrayNotHasKey('amount', $card);
            $this->assertArrayNotHasKey('currency', $card);
        }
        // Ostatní pole zůstávají.
        $this->assertSame(92, $stripped[0]['confidencePct']);
    }
}
