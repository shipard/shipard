<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\World\Trade\TradeUnionResolver;

/**
 * Deterministické odvození místa plnění (`vat.place`) **přijatého** dokladu
 * z prefixu DIČ dodavatele (tasks/exchange-received-vat-place.md, D1/D2).
 *
 * Model čte pravidlo „intracom = dodavatel z jiného státu EU“ podle adresy
 * — dodavatel se sídlem mimo EU, který fakturuje pod DIČ jiného členského
 * státu (americký SaaS s irskou registrací), pak dostal `thirdCountry`
 * a kód `cz-417` (ř. 12) místo `cz-217` (ř. 5). Pro ř. 5 přiznání
 * rozhoduje registrace k dani v jiném členském státě, tj. prefix DIČ,
 * ne sídlo. Sídlo dodavatele (`supplier.country`) se nemění.
 *
 * Algoritmus:
 *   1. normalizace DIČ (uppercase, jen `[A-Z0-9]`), prefix = první dva
 *      znaky, jen když jsou písmena;
 *   2. DIČ dodavatele = DIČ odběratele → null (model dal naše DIČ
 *      k dodavateli; odvození by dalo chybně tuzemsko);
 *   3. unie, jejichž členem je země naší registrace k datu; z nich ta,
 *      jejíž `taxPrefixes` prefix zná (žádná / víc → null);
 *   4. prefix mimo platnost k datu (`GB` po Brexitu) → `thirdCountry`;
 *   5. `supplyKinds` na záznamu prefixu (Severní Irsko `XI` jen zboží):
 *      kterýkoli položkový řádek mimo seznam → `thirdCountry`, kterýkoli
 *      bez druhu plnění → null;
 *   6. země prefixu = naše země → `domestic`, jinak `intracom`.
 *
 * Prefix, který unie vůbec nezná (`US`, `CHE`, `EU…` z režimu OSS mimo
 * Unii), nebo doklad bez DIČ → null = platí hodnota z AI. Čistá funkce nad
 * {@see TradeUnionResolver}; canonical se nemění, efektivní místo drží
 * `DocumentApplier::vatContext()` (vzor {@see VatModeDerivation}).
 */
final class VatPlaceDerivation
{
    public function __construct(
        private readonly TradeUnionResolver $unions,
    ) {}

    /**
     * @param string             $ownCountry     Země naší registrace DPH (ISO alpha-2).
     * @param string|null        $date           DUZP (fallback datum vystavení), `Y-m-d`.
     * @param string|null        $supplierVatId  Canonical `supplier.vatId`.
     * @param string|null        $customerVatId  Canonical `customer.vatId`.
     * @param list<string|null>  $rowSupplyKinds `rows[].vat.supplyKind` položkových řádků
     *                                           (null = řádek bez druhu plnění).
     * @return array{place: ?string, prefix: ?string, reason: ?string}
     *         `place` = canonical hodnota (`domestic` / `intracom` / `thirdCountry`),
     *         null s důvodem, když derivace nemá z čeho rozhodnout.
     */
    public function derive(
        string $ownCountry,
        ?string $date,
        ?string $supplierVatId,
        ?string $customerVatId,
        array $rowSupplyKinds,
    ): array {
        $supplier = self::normalize($supplierVatId);
        if ($supplier === '') {
            return self::fail('doklad nemá DIČ dodavatele');
        }
        $prefix = substr($supplier, 0, 2);
        if (!ctype_alpha($prefix) || strlen($prefix) !== 2) {
            return self::fail("DIČ dodavatele „{$supplier}“ nemá písmenný prefix");
        }

        if ($supplier === self::normalize($customerVatId)) {
            return self::fail('DIČ dodavatele je shodné s DIČ odběratele (prohozené strany)');
        }

        if ($date === null || $date === '') {
            return self::fail('doklad nemá DUZP ani datum vystavení');
        }

        $ownCountry = strtolower(trim($ownCountry));
        $unionKeys = $this->unions->unionsOf($ownCountry, $date);
        if ($unionKeys === []) {
            return self::fail('země naší registrace ' . strtoupper($ownCountry) . " není k {$date} členem žádné unie");
        }

        $matches = [];
        foreach ($unionKeys as $unionKey) {
            $entry = $this->unions->taxPrefix($unionKey, $prefix);
            if ($entry !== null) {
                $matches[$unionKey] = $entry;
            }
        }
        if ($matches === []) {
            return self::fail("prefix DIČ {$prefix} žádná unie naší země nezná", $prefix);
        }
        if (count($matches) > 1) {
            return self::fail(
                "prefix DIČ {$prefix} zná víc unií naší země: " . implode(', ', array_keys($matches)),
                $prefix,
            );
        }
        $entry = reset($matches);

        if (!$this->unions->isPrefixValid($entry, $date)) {
            return self::ok('thirdCountry', $prefix);
        }

        $allowedKinds = $entry['supplyKinds'] ?? null;
        if (is_array($allowedKinds) && $allowedKinds !== []) {
            $hasUnknown = $rowSupplyKinds === [];
            foreach ($rowSupplyKinds as $kind) {
                if ($kind === null || trim($kind) === '') {
                    $hasUnknown = true;
                    continue;
                }
                if (!in_array(trim($kind), $allowedKinds, true)) {
                    return self::ok('thirdCountry', $prefix);
                }
            }
            if ($hasUnknown) {
                return self::fail(
                    "prefix DIČ {$prefix} platí jen pro " . implode(', ', $allowedKinds)
                        . ' a některý řádek nemá druh plnění',
                    $prefix,
                );
            }
        }

        $country = strtolower(trim((string) ($entry['country'] ?? '')));
        return self::ok($country === $ownCountry ? 'domestic' : 'intracom', $prefix);
    }

    /** Uppercase, jen `[A-Z0-9]` — `DE 123 456 789`, `CHE-123.456.789` i `cz12345678`. */
    public static function normalize(?string $vatId): string
    {
        if ($vatId === null) {
            return '';
        }
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($vatId));
    }

    /** @return array{place: string, prefix: string, reason: null} */
    private static function ok(string $place, string $prefix): array
    {
        return ['place' => $place, 'prefix' => $prefix, 'reason' => null];
    }

    /** @return array{place: null, prefix: ?string, reason: string} */
    private static function fail(string $reason, ?string $prefix = null): array
    {
        return ['place' => null, 'prefix' => $prefix, 'reason' => $reason];
    }
}
