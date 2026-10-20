<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Isdoc;

use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\FieldOutcome;
use Shipard\Module\Core\Exchange\Compare\FieldState;

/**
 * Pole canonicalu, která ISDOC z principu nenese (#119 D8, #103 D76) —
 * jediný zdroj pro round-trip test generátor → čtečka
 * (`IsdocCorpusRoundTripTest`) i pro evaluaci zpráv s ISDOC
 * (`EvalRunner`: u cesty `isdoc` dostanou tyhle listy stav
 * {@see FieldState::NotCarried} s důvodem a nepočítají se do shody ani
 * do odchylek). Ověřeno proti XSD 6.0.1 / 6.0.2; pole, které ISDOC nese
 * a čtečka ho nevrací, sem nepatří — patří do čtečky. Tabulka
 * v docs/einvoice.md § 8 je opis.
 */
final class IsdocFieldCoverage
{
    /** @var array<string, string> cesta uzlu projekce → důvod */
    public const array NOT_CARRIED = [
        'vat.place'               => 'ISDOC nemá místo plnění; applier ho odvodí z prefixu DIČ dodavatele (docs/exchange-format.md § 8.4)',
        'rows[].vat.supplyKind'   => 'ISDOC nemá druh plnění (zboží / služba)',
        'rows[].priceCalcMode'    => 'ISDOC neříká, zda je autoritativní jednotková, nebo celková cena — čtečka dává fromUnitPrice',
        'supplier.contact.web'    => 'Contact má jen Name / Telephone / ElectronicMail',
        'customer.contact.web'    => 'Contact má jen Name / Telephone / ElectronicMail',
        'dates.periodFrom'        => 'hlavička ISDOC nemá období plnění',
        'dates.periodTo'          => 'hlavička ISDOC nemá období plnění',
        'rows[].discountPct'      => 'ISDOC nese jen částku před slevou (LineExtensionAmountBeforeDiscount), ne procento — částku hlídá testDiscountSurvivesAsAmount',
        'rows[].discountAmount'   => 'korpus vyjadřuje slevu procentem (discountAmount null), čtečka vrací částku z LineExtensionAmountBeforeDiscount — hlídá testDiscountSurvivesAsAmount',
    ];

    private function __construct()
    {
    }

    /** @return list<string> cesty uzlů (bez indexů) */
    public static function paths(): array
    {
        return array_keys(self::NOT_CARRIED);
    }

    /**
     * Důvod, proč ISDOC uzel nenese; null = nese (uzel ani žádný jeho
     * předek v seznamu není). Cesta hodnoty s indexy (`rows[2].discountPct`)
     * i cesta uzlu (`rows[].discountPct`).
     */
    public static function reasonFor(string $path): ?string
    {
        $nodePath = (string) preg_replace('/\[[0-9]+\]/', '[]', $path);
        foreach (self::NOT_CARRIED as $ignored => $reason) {
            if (CanonicalComparator::isIgnored($nodePath, [$ignored])) {
                return $reason;
            }
        }
        return null;
    }

    /**
     * Výsledky porovnání cesty `isdoc`: list, který ISDOC nenese, dostane
     * stav `notCarried` s důvodem (hodnoty obou stran zůstávají pro
     * prohlížeč), ostatní beze změny.
     *
     * @param list<FieldOutcome> $outcomes
     * @return list<FieldOutcome>
     */
    public static function markNotCarried(array $outcomes): array
    {
        $out = [];
        foreach ($outcomes as $o) {
            $reason = self::reasonFor($o->nodePath());
            $out[] = $reason === null ? $o : new FieldOutcome($o->path, FieldState::NotCarried, $o->expected, $o->actual, null, $reason);
        }
        return $out;
    }
}
