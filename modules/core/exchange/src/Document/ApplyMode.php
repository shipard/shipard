<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Režim volání {@see DocumentApplier} (#166 D18): parametr `validate()`,
 * `preview()` a `apply()`, ne obsah payloadu.
 *
 *  - **Pokyn** (výchozí) — doklad se uloží tak, jak ho payload říká: reference,
 *    výchozí hodnoty, validace a výpočet `DocDocument`. Nic se neodvozuje ze
 *    signálů (D19); hodnoty DPH jsou záměr (D20). REST, datové sady, generátory
 *    dokladů, import.
 *  - **Návrh** — interpretace papírové podoby dokladu: místo plnění z DIČ,
 *    kód a režim DPH, doklad neplátce a mimo české DPH, převzetí rekapitulace,
 *    odhad zaokrouhlení, volby uživatele v `_resolve`. Pošta a ISDOC.
 *
 * Viz `docs/exchange-format.md` § 3 a § 10.
 */
enum ApplyMode: string
{
    case Instruction = 'instruction';
    case Proposal = 'proposal';

    /** Interpretace ze signálů běží jen v návrhu. */
    public function derives(): bool
    {
        return $this === self::Proposal;
    }
}
