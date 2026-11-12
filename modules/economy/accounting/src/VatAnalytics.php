<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

/**
 * Analytika 343 pro řádek rekapitulace DPH mimo `AccountingEngine` —
 * zaúčtování přiznání (uzávěrka analytik) a křížová kontrola přiznání proti
 * deníku. Obě místa musí dojít ke stejnému účtu jako engine při účtování
 * dokladu, proto čtou tatáž pravidla `cat: vat` ze sekce `accounts` předpisu
 * stejným vyhodnocením `query` ({@see RuleQuery}) a pravidlem „první
 * odpovídající vyhrává“.
 *
 * Výchozí je konvence `343{NNN}` (docs/accounting.md → Konvence DPH analytik);
 * pravidlo smí kód dál rozlišit podle předmětu plnění tuzemského přenesení
 * (`query.reverse_charge_code` — odpočet u předmětu 5 na 343117, #89 D9).
 */
final class VatAnalytics
{
    /** Oddělovač klíče `kód|předmět` ({@see key()}). */
    private const KEY_SEPARATOR = '|';

    /**
     * Masky účtu pro řádek rekapitulace (kód DPH + předmět plnění): první
     * pravidlo `cat: vat` s `query.vat_code`, jehož query záznamu vyhovuje;
     * bez pravidla konvence `343{NNN}` / `343{CC}{NNN}`.
     *
     * @param list<array<string, mixed>> $accountRules sekce `accounts` efektivního předpisu
     * @return list<string>
     */
    public static function masks(array $accountRules, string $vatCode, ?string $subject): array
    {
        $record = [
            'vat_code'            => $vatCode,
            'reverse_charge_code' => $subject !== null && $subject !== '' ? $subject : null,
            'vat_code_country'    => self::country($vatCode),
        ];
        foreach ($accountRules as $rule) {
            if (!is_array($rule) || ($rule['cat'] ?? null) !== 'vat' || !isset($rule['query']['vat_code'])) {
                continue;
            }
            if (!RuleQuery::matches($rule['query'], $record)) {
                continue;
            }
            $masks = [];
            foreach ((array) ($rule['accountMask'] ?? []) as $mask) {
                if ((string) $mask !== '') {
                    $masks[] = (string) $mask;
                }
            }
            if ($masks !== []) {
                return $masks;
            }
        }

        $dash    = strpos($vatCode, '-');
        $country = self::country($vatCode);
        $number  = $dash !== false ? substr($vatCode, $dash + 1) : $vatCode;
        return [$country === 'cz' ? '343' . $number : '343' . strtoupper($country) . $number];
    }

    /**
     * Předměty plnění, které předpis u kódu rozlišuje vlastním pravidlem
     * (`query: {vat_code, reverse_charge_code}`) — jen pro ně má smysl dělit
     * součty daně podle předmětu.
     *
     * @param list<array<string, mixed>> $accountRules
     * @return array<string, list<string>> kód DPH → předměty
     */
    public static function distinguishedSubjects(array $accountRules): array
    {
        $out = [];
        foreach ($accountRules as $rule) {
            if (!is_array($rule) || ($rule['cat'] ?? null) !== 'vat') {
                continue;
            }
            $code    = $rule['query']['vat_code'] ?? null;
            $subject = $rule['query']['reverse_charge_code'] ?? null;
            if (!is_string($code) || !is_scalar($subject) || (string) $subject === '') {
                continue;
            }
            $out[$code][] = (string) $subject;
        }
        return array_map(static fn (array $subjects): array => array_values(array_unique($subjects)), $out);
    }

    /**
     * Klíč součtu daně: kód DPH, u rozlišovaného předmětu `kód|předmět`.
     *
     * @param array<string, list<string>> $distinguished {@see distinguishedSubjects()}
     */
    public static function key(string $vatCode, ?string $subject, array $distinguished): string
    {
        return $subject !== null && in_array($subject, $distinguished[$vatCode] ?? [], true)
            ? $vatCode . self::KEY_SEPARATOR . $subject
            : $vatCode;
    }

    /**
     * Rozklad klíče {@see key()} na kód DPH a předmět (null = bez rozlišení).
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitKey(string $key): array
    {
        $pos = strpos($key, self::KEY_SEPARATOR);
        return $pos === false
            ? [$key, null]
            : [substr($key, 0, $pos), substr($key, $pos + 1)];
    }

    private static function country(string $vatCode): string
    {
        $dash = strpos($vatCode, '-');
        return strtolower($dash === false ? 'cz' : substr($vatCode, 0, $dash));
    }
}
