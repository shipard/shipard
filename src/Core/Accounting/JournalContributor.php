<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Příspěvek modulu do deníku zdroje (#79 D3b).
 *
 * Účtovací engine (dokladový i bankovní) po sestavení řádků zdroje, ale
 * **před** kontrolou vyrovnanosti a zápisem, předá contributorům kontext
 * zdroje a jeho řádky; contributor vrátí požadavky na další řádky. Engine
 * je doplní (dohledá účty dle kategorie nebo přesného čísla), znovu seskupí
 * a zapíše vše v jedné transakci s vlastními řádky. Reaccount tak zůstává
 * idempotentní (DELETE + INSERT) a příspěvek nemá vlastní paměť.
 *
 * Deklarace v core, implementace v modulu, registrace v `module.jsonc`
 * klíčem `journalContributors: ["FQCN", …]` (seznam — víc modulů smí
 * přispívat, pořadí = pořadí resolvovaných modulů × pořadí pole); sbírá
 * {@see \Shipard\Api\JournalContributorLoader} do {@see JournalContributorSet}.
 * Vzor `openItemLookup`: engine na přispívajícím modulu nezávisí.
 *
 * Chyby: výjimku contributoru engine zaloguje a spolkne — deník se zapíše
 * bez příspěvku a do zpráv účtování přibude varování `contributor_failed`
 * (stav účtování zůstává OK). Nevyrovnané požadavky skončí jako
 * `unbalanced` jako každá jiná nevyrovnanost.
 *
 * První konzument: `economy.accbal` → `CaseClosureContributor` (uzavření
 * případu zálohové faktury úhradou na přijatou zálohu, `docs/accbal.md` §5.8).
 */
interface JournalContributor
{
    /**
     * @param list<JournalLineView> $lines  řádky zdroje po seskupení, bez chybových
     * @return list<JournalLineRequest>     požadavky na další řádky (vyrovnané páry, Σ MD = Σ DAL)
     */
    public function contribute(JournalSourceContext $context, array $lines): array;
}
