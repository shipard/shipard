<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Shipard\Module\World\Validations\DomesticAccount;
use Shipard\Module\World\Validations\Iban;
use Shipard\Module\World\Validations\ValueRulesRegistry;

/**
 * Klíč bankovního účtu pro párování (#118 D16): tvar podle hodnoty, ne
 * podle sloupce — u starých dat nese `account_number` často IBAN.
 *
 *  - IBAN státu s tuzemským tvarem účtu (cz, sk) → `bban:<20 číslic>`,
 *    stejně jako tuzemské číslo `[předčíslí-]číslo/kód` (BBAN je u cz
 *    i sk stejný, země není potřeba) — IBAN z dokladu se tak napáruje
 *    na uložené číslo účtu a naopak;
 *  - jiný IBAN → `iban:<IBAN>`;
 *  - cokoli jiného → `raw:<hodnota bez mezer, velkými>`.
 *
 * Klíč nezávisí na platnosti kontrolních součtů — uložený účet s chybou
 * se musí napárovat stejně jako správný.
 */
final class BankAccountKey
{
    public static function of(string $value): ?string
    {
        $s = strtoupper((string) preg_replace('/\s+/u', '', trim($value)));
        if ($s === '') {
            return null;
        }
        if (Iban::hasShape($s)) {
            $rules = ValueRulesRegistry::forIban($s);
            $account = $rules->supportsDomesticAccount() ? $rules->domesticAccountFromIban($s) : null;
            return $account !== null ? 'bban:' . $account->toBban() : 'iban:' . $s;
        }
        $account = DomesticAccount::parse($s);
        return $account !== null ? 'bban:' . $account->toBban() : 'raw:' . $s;
    }
}
