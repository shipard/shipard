<?php

declare(strict_types=1);

namespace Shipard\Core\Accounting;

/**
 * Požadavek contributoru na řádek deníku ({@see JournalContributor}).
 * Datová třída bez logiky; účet je určen **buď** kategorií účtovacího
 * předpisu (`category`, engine dohledá masku ze sekce `accounts` →
 * `AccountMaskResolver`), **nebo** přesným číslem účtu (`accountNumber`,
 * engine ověří v rozvrhu). Právě jedno z nich — konstruktor to vynutí.
 *
 * `side` 0 = MD, 1 = DAL; `moneyDom` / `moneyCur` kladné částky strany.
 * Operace řádku se nepředává — engine zapíše NULL a saldo řádek zařadí
 * podle nastavení skupin (`docs/accbal.md` §4.2, krok 4). Identita
 * (partner, VS, SS) jde do řádku deníku; bankovní engine ji nemá odkud
 * vzít jinde než z transakce, takže odlišná identita je chyba contributoru.
 */
final readonly class JournalLineRequest
{
    public function __construct(
        public int $side,
        public ?string $category,
        public ?string $accountNumber,
        public ?int $partner,
        public ?string $paymentReference,
        public ?string $specificSymbol,
        public float $moneyDom,
        public float $moneyCur,
        public string $text,
    ) {
        if (($category === null || $category === '') === ($accountNumber === null || $accountNumber === '')) {
            throw new \InvalidArgumentException(
                'JournalLineRequest needs exactly one of category or accountNumber',
            );
        }
        if ($side !== 0 && $side !== 1) {
            throw new \InvalidArgumentException("JournalLineRequest side must be 0 (MD) or 1 (DAL), {$side} given");
        }
    }
}
