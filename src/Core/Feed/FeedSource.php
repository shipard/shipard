<?php

declare(strict_types=1);

namespace Shipard\Core\Feed;

/**
 * Zdroj karet domovského feedu. Bezstavový, napevno registrovaný ve
 * `FeedCollector` (D10 — module-driven registrace odložena).
 *
 * Karty vrací v kontraktu popsaném v `docs/dashboard.md` (kartový kontrakt):
 * `{id, source, kind, feedSection?, icon, stateStyle, category?, navSection?,
 * title, subtitle, timestamp, context, actions[]}`. Řazení a strop per sekce
 * řeší `FeedCollector` (`sortAndCap`), zdroj karty jen emituje.
 *
 * Tři ortogonální pole karty:
 * - `feedSection` (SECTION_*) — sekce feedu podle toku práce (#101 D2b).
 *   Volitelné: bez pole (nebo s neznámou hodnotou) ho collector odvodí
 *   z `kind` (`FeedCollector::DEFAULT_SECTION_BY_KIND`); zdroj ho nastavuje
 *   jen tam, kde se má karta od výchozí sekce lišit.
 * - `category` (CATEGORY_*) — klientský filtr feedu; karta bez pole se
 *   zobrazuje jen v záložce Vše.
 * - `navSection` (id sekce navigace, či NAV_SECTION_TOP) — opt-in atribuce
 *   pro badge stavů sekcí (UI shells Fáze 3, D1) — počítají se jen karty
 *   pásem urgent/review (D2); karta bez pole se do badge nezapočítá.
 */
interface FeedSource
{
    /** Kategorie karet pro filtr feedu (docs/dashboard.md §4). */
    public const string CATEGORY_INVOICES = 'invoices';
    public const string CATEGORY_REGISTRY = 'registry';
    public const string CATEGORY_OTHER    = 'other';

    /** Sekce feedu (docs/dashboard.md §4, #101 D8). Pořadí řídí FeedCollector::SECTION_ORDER. */
    public const string SECTION_NEW_ITEMS = 'newItems';
    public const string SECTION_READY     = 'ready';
    public const string SECTION_REVIEW    = 'review';
    public const string SECTION_FAILED    = 'failed';
    public const string SECTION_ALERTS    = 'alerts';
    public const string SECTION_OTHER     = 'other';

    /** Sentinel `navSection` pro root-level položky nad sekcemi (pošta, úkoly). */
    public const string NAV_SECTION_TOP = '_top';

    /** @return list<array<string,mixed>> karty dle kontraktu */
    public function collectCards(FeedContext $ctx): array;
}
