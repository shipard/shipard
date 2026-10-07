<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

/**
 * Výsledek sběru feedu (`FeedCollector::collect()`, #101 D3a/D3b): karty po
 * stropu per sekce, karty bez stropu (pravdivé county, badge, AI digest,
 * `readySummary`) a souhrn sekcí s počty.
 *
 * Obě sady karet nesou interní pole (`amount`/`currency`) — prezentační
 * vrstva je před odesláním klientovi odstraní přes
 * `FeedCollector::stripInternalFields()`. Každá karta má po sběru vyplněné
 * `feedSection`.
 */
final readonly class FeedResult
{
    /**
     * @param list<array<string,mixed>> $cards     seřazené, strop per sekce, s interními poli
     * @param list<array<string,mixed>> $allCards  seřazené, bez stropu, s interními poli
     * @param list<array{id:string, total:int, shown:int, archivable?:int}> $sections
     *        jen neprázdné sekce, v pořadí FeedCollector::SECTION_ORDER;
     *        `archivable` jen u sekce s kartami, které odklidí Archivovat vše
     */
    public function __construct(
        public array $cards,
        public array $allCards,
        public array $sections,
    ) {}

    /** Některá sekce má víc karet, než se vešlo pod strop. */
    public function hasMore(): bool
    {
        foreach ($this->sections as $section) {
            if ($section['total'] > $section['shown']) {
                return true;
            }
        }
        return false;
    }
}
