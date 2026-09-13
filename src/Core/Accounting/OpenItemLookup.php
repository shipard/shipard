<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Dohledání otevřeného předpisu saldokonta pro klíč úhrady (#69 D3/D8).
 *
 * Deklarace v core, implementace v modulu (`economy.accbal` →
 * `LedgerOpenItemLookup`), registrace v `module.jsonc` klíčem
 * `openItemLookup: "FQCN"` — jeden poskytovatel per DS, sbírá
 * `OpenItemLookupLoader`. DS bez poskytovatele dostane {@see NullOpenItemLookup}
 * (všechno na clearing). Vzor `journalEventHandlers`: účtovací engine na
 * modulu saldokonta nezávisí.
 *
 * Konzumenti: `BankTransactionAccountingEngine` (účet úhrady = účet
 * předpisu při zásahu, jinak clearing), `ClearingRouter` (přeúčtování
 * čekajících clearingových úhrad), později dashboard přijatých faktur (#49).
 * Další metody (nespárované úhrady pro klíč faktury) přidá až T6.
 */
interface OpenItemLookup
{
    /**
     * Otevřený předpis pro klíč úhrady, nebo null.
     *
     * Klíč = (partner, payment_reference, specific_symbol, currency) —
     * pravidlo 1 z D5 (přesná shoda). Prázdný `payment_reference` je „bez
     * klíče“ → vždy null (pravidla 2–3 přijdou s efektivními symboly, T3).
     * Prázdný SS na úhradě sedí jen na prázdný SS předpisu. Porovnání je
     * necitlivé na okrajové mezery a velikost písmen měny.
     *
     * $direction: 1 = příjem → předpis v pohledávkách (přirozený účet 311*),
     * 2 = výdaj → závazky (321*). Otevřený = Σ předpisy − Σ úhrady pro klíč
     * > 0 v měně dokladu.
     *
     * $excludeSourceKind/$excludeSourceId: zdroj, jehož pohyby se do Σ úhrad
     * nepočítají — engine předá právě účtovanou transakci, aby reaccount už
     * routované úhrady neviděl své vlastní reziduum jako nulu a byl
     * idempotentní (bez paměti). Konzument bez vlastního pohybu předá null.
     */
    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem;
}
