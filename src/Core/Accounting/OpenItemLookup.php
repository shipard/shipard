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
     * Klíč případu = (partner, payment_reference, specific_symbol, currency)
     * v účetním období `$fiscalYear` (#69 D1, D11) — pravidlo 1 z D5 (přesná
     * shoda). Prázdný `payment_reference` nebo chybějící období je „bez
     * klíče“ → vždy null (pravidla 2–3 přijdou s efektivními symboly, T3).
     * Prázdný SS na úhradě sedí jen na prázdný SS předpisu. Porovnání je
     * necitlivé na okrajové mezery a velikost písmen měny (normalizace
     * klíče D10 na obou stranách).
     *
     * $direction: 1 = příjem, 2 = výdaj. Skupina s předpisem na straně
     * směru (příjem → MD, typicky pohledávky 311*; výdaj → DAL, závazky
     * 321* i 325/331/336/… podle nastavení) je pro směr přirozená —
     * otevřený = Σ předpisy − Σ úhrady > 0 (dluh se platí). Skupina
     * s předpisem na opačné straně je opačná — otevřený = reziduum < 0
     * (přeplatek, dobropis nebo platba bez faktury se vrací; #69 D19).
     * Přirozené skupiny se prohledávají první. `OpenItem::residual` nese
     * znaménko; účet a strana zápisu plynou z účtu předpisu a směru.
     *
     * $fiscalYear: období účetního data úhrady. Předpis z jiného období je
     * miss — zůstatky mezi obdobími přenáší otevírací doklad (D11).
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
        ?int $fiscalYear,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem;
}
