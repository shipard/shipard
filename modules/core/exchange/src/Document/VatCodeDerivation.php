<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Deterministické odvození kódu DPH položkového řádku **přijatého** dokladu
 * ze sémantických signálů (tasks/exchange-received-reverse-charge.md, D1/D4).
 *
 * AI extrakce ani ISDOC neznají per-country klíče číselníku (`cz-217`) —
 * model vracel vymyšlené kódy (`reverse-charge`, `eu-reverse`) a návrh
 * končil `vat_code_unknown`. Kód proto určuje systém: z hlavičky
 * `vat.place` + `vat.reverseCharge`, z řádku `vat.pct`, `vat.supplyKind`
 * a `vat.reverseChargeCode`, vždy v číselníku země **naší** registrace
 * DPH (D2) k DUZP.
 *
 * Kandidáti = vstupní kódy pro místo plnění bez `hidden` a bez
 * `reducedDeduction` (krácený odpočet se nikdy neodvozuje — má stejnou
 * sazbu, místo i kategorii jako plný nárok, bez příznaku by výběr nebyl
 * jednoznačný):
 *
 *   - samovyměření (`reverseCharge === true`, nebo místo ≠ tuzemsko):
 *     kódy s `reverseVatCode` kategorie `standard`; mimo tuzemsko navíc
 *     `supplyKind` řádku, v tuzemsku `reverseChargeCode` řádku;
 *   - tuzemsko bez samovyměření: kódy bez `reverseVatCode`, jejichž sazba
 *     k datu = `pct` řádku. Nerozhoduje název kategorie — `reduced1` /
 *     `reduced2` platily jen 2015–2023.
 *
 * Výsledek je kód jen tehdy, když zbyde právě jeden kandidát; jinak `null`
 * s důvodem pro zprávu issue. Snížená sazba u samovyměření, zahraniční
 * DPH naúčtovaná dodavatelem a smíšené doklady jsou mimo rozsah (D7) —
 * derivace u nich vrací `null`, applier hlásí `vat_code_unknown`.
 *
 * Čistá funkce nad `VatRateResolver`; canonical se nemění, korekce jde do
 * `_resolve` (vzor {@see VatModeDerivation}).
 */
final class VatCodeDerivation
{
    /**
     * Canonical `vat.place` → `place` v číselníku world.vat. Jediné místo
     * mapování — canonical říká `thirdCountry`, číselník `foreign`.
     */
    public const PLACE_MAP = [
        'domestic'     => 'domestic',
        'intracom'     => 'intracom',
        'thirdCountry' => 'foreign',
    ];

    /** Samovyměření se odvozuje jen v základní sazbě (D4). */
    private const REVERSE_CHARGE_CATEGORY = 'standard';

    public function __construct(
        private readonly VatRateResolver $vat,
    ) {}

    /**
     * @param string      $country           Země naší registrace DPH (ISO alpha-2).
     * @param string      $date              DUZP (fallback datum vystavení), `Y-m-d`.
     * @param string|null $place             Canonical `vat.place`; null = tuzemsko.
     * @param bool|null   $reverseCharge     Canonical `vat.reverseCharge`.
     * @param float|null  $pct               Sazba z řádku dokladu (`rows[].vat.pct`).
     * @param string|null $supplyKind        `rows[].vat.supplyKind` (goods / services).
     * @param string|null $reverseChargeCode `rows[].vat.reverseChargeCode` (tuzemské PDP).
     * @return array{code: ?string, reason: ?string}
     */
    public function derive(
        string $country,
        string $date,
        ?string $place,
        ?bool $reverseCharge,
        ?float $pct,
        ?string $supplyKind,
        ?string $reverseChargeCode,
    ): array {
        $country = strtolower(trim($country));
        $placeKey = $place ?? 'domestic';
        $cfgPlace = self::PLACE_MAP[$placeKey] ?? null;
        if ($cfgPlace === null) {
            return self::fail("neznámé místo plnění „{$placeKey}“");
        }

        try {
            $candidates = $this->vat->getVatCodes($country, 'input', $cfgPlace);
        } catch (\LogicException) {
            return self::fail('číselník DPH země ' . strtoupper($country) . ' není k dispozici');
        }
        $candidates = array_filter(
            $candidates,
            static fn (array $def): bool => empty($def['reducedDeduction']),
        );

        $selfAssessed = $reverseCharge === true || $cfgPlace !== 'domestic';
        if (!$selfAssessed) {
            return $this->deriveDomestic($country, $date, $candidates, $pct);
        }

        // Dodavatel mimo tuzemsko, který DPH účtuje (hotel, PHM v cizině):
        // není to samovyměření a číselník na zahraniční DPH kód nemá (D7
        // otevřené). Tiché samovyměření by základ zdanilo podruhé.
        if ($cfgPlace !== 'domestic' && $reverseCharge !== true && $pct !== null && abs($pct) > 0.001) {
            return self::fail(
                'dodavatel mimo tuzemsko účtuje DPH (zahraniční DPH) — to není přenesení daňové povinnosti',
            );
        }

        $candidates = array_filter(
            $candidates,
            static fn (array $def): bool => !empty($def['reverseVatCode'])
                && ($def['category'] ?? null) === self::REVERSE_CHARGE_CATEGORY,
        );

        if ($cfgPlace !== 'domestic') {
            if ($supplyKind === null || trim($supplyKind) === '') {
                return self::fail('u plnění mimo tuzemsko chybí druh plnění (zboží / služba)');
            }
            $kind = trim($supplyKind);
            $candidates = array_filter(
                $candidates,
                static fn (array $def): bool => ($def['supplyKind'] ?? null) === $kind,
            );
            return self::pick($candidates, sprintf(
                'přenesení daňové povinnosti, %s, %s',
                self::placeLabel($cfgPlace),
                self::supplyKindLabel($kind),
            ));
        }

        if ($reverseChargeCode === null || trim($reverseChargeCode) === '') {
            return self::fail('u tuzemského přenesení daňové povinnosti chybí kód předmětu plnění');
        }
        // Číselník nese kód předmětu plnění jako int, canonical jako string.
        $rc = trim($reverseChargeCode);
        $candidates = array_filter(
            $candidates,
            static fn (array $def): bool => isset($def['reverseChargeCode'])
                && (string) $def['reverseChargeCode'] === $rc,
        );
        return self::pick($candidates, "tuzemské přenesení daňové povinnosti, předmět plnění {$rc}");
    }

    /**
     * Rozpor **existujícího** kódu (z canonicalu nebo z historie řádků,
     * {@see \Shipard\Module\Core\Exchange\Enrich\RowHistoryEnricher}) se
     * signály dokladu. `null` = v souladu; jinak důvod pro zprávu issue.
     * Null signál se nekontroluje. Kód, který v číselníku není, tu projde —
     * to hlásí resolver jako `notFound`.
     *
     * „V souladu“ = místo kódu odpovídá místu z hlavičky; má `reverseVatCode`
     * ⇔ `reverseCharge`; `supplyKind` sedí, když je na obou stranách;
     * u tuzemska bez samovyměření sedí sazba k datu.
     */
    public function conflict(
        string $country,
        string $code,
        string $date,
        ?string $place,
        ?bool $reverseCharge,
        ?float $pct,
        ?string $supplyKind,
    ): ?string {
        $country = strtolower(trim($country));
        try {
            $def = $this->vat->getVatCode($country, $code);
        } catch (\LogicException) {
            return null;
        }
        if ($def === null) {
            return null;
        }

        $defPlace = (string) ($def['place'] ?? 'domestic');
        if ($place !== null) {
            $cfgPlace = self::PLACE_MAP[$place] ?? null;
            if ($cfgPlace !== null && $cfgPlace !== $defPlace) {
                return sprintf(
                    'kód je pro místo plnění %s, doklad uvádí %s',
                    self::placeLabel($defPlace),
                    self::placeLabel($cfgPlace),
                );
            }
        }

        $isReverse = !empty($def['reverseVatCode']);
        if ($reverseCharge !== null && $isReverse !== $reverseCharge) {
            return $isReverse
                ? 'kód je samovyměření, doklad přenesení daňové povinnosti neuvádí'
                : 'doklad uvádí přenesení daňové povinnosti, kód není samovyměření';
        }

        if ($supplyKind !== null && isset($def['supplyKind']) && (string) $def['supplyKind'] !== $supplyKind) {
            return sprintf(
                'kód je pro %s, řádek je %s',
                self::supplyKindLabel((string) $def['supplyKind']),
                self::supplyKindLabel($supplyKind),
            );
        }

        if ($defPlace === 'domestic' && !$isReverse && $pct !== null) {
            try {
                $rate = $this->vat->resolveVatPct($country, $code, $date);
            } catch (\LogicException) {
                return "kód nemá sazbu k datu {$date}";
            }
            if (abs($rate - $pct) > 0.001) {
                return sprintf(
                    'sazba kódu %s %% neodpovídá sazbě řádku %s %%',
                    self::formatPct($rate),
                    self::formatPct($pct),
                );
            }
        }

        return null;
    }

    /**
     * Tuzemsko bez samovyměření: jediný kód bez `reverseVatCode`, jehož
     * sazba k datu odpovídá sazbě řádku (±0,001).
     *
     * @param array<string, array<string, mixed>> $candidates
     * @return array{code: ?string, reason: ?string}
     */
    private function deriveDomestic(string $country, string $date, array $candidates, ?float $pct): array
    {
        if ($pct === null) {
            return self::fail('řádek nemá sazbu DPH');
        }
        $matching = [];
        foreach ($candidates as $key => $def) {
            if (!empty($def['reverseVatCode'])) {
                continue;
            }
            try {
                $rate = $this->vat->resolveVatPct($country, (string) $key, $date);
            } catch (\LogicException) {
                continue; // kód nemá sazbu k datu (reduced1/2 mimo 2015–2023)
            }
            if (abs($rate - $pct) <= 0.001) {
                $matching[(string) $key] = $def;
            }
        }
        return self::pick($matching, sprintf('tuzemsko, sazba %s %% k %s', self::formatPct($pct), $date));
    }

    /**
     * @param array<string, array<string, mixed>> $candidates
     * @return array{code: ?string, reason: ?string}
     */
    private static function pick(array $candidates, string $what): array
    {
        if (count($candidates) === 1) {
            return ['code' => (string) array_key_first($candidates), 'reason' => null];
        }
        if ($candidates === []) {
            return self::fail("pro {$what} není v číselníku žádný kód");
        }
        return self::fail("pro {$what} je v číselníku víc kódů: " . implode(', ', array_keys($candidates)));
    }

    /** @return array{code: null, reason: string} */
    private static function fail(string $reason): array
    {
        return ['code' => null, 'reason' => $reason];
    }

    private static function placeLabel(string $cfgPlace): string
    {
        return match ($cfgPlace) {
            'intracom' => 'EU',
            'foreign'  => 'třetí země',
            default    => 'tuzemsko',
        };
    }

    private static function supplyKindLabel(string $kind): string
    {
        return $kind === 'goods' ? 'zboží' : 'služby';
    }

    private static function formatPct(float $pct): string
    {
        $s = number_format($pct, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.');
    }
}
