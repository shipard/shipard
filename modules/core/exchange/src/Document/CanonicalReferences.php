<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Tvar referencí v kanonickém dokladu (#166 D30–D33, D39) — čisté funkce
 * bez databáze. Hodnotu reference rozebírá `ReferenceValue` a hledá
 * `ReferenceResolver`; tady je jen to, co je vlastní formátu dokladu:
 *
 *  - strana a položka smí být reference sama, nebo objekt s `ref`
 *    ({@see expand()} sjednotí na objekt, aby zbytek kódu četl jedno);
 *  - které údaje strany a položky „identifikují“ (objekt bez `ref` s nimi
 *    je v pokynu chyba `reference_missing`);
 *  - které volby `_resolve` pokyn nečte ({@see withoutReferencePins()});
 *  - účet řádku holým číslem je signál návrhu, ne reference.
 */
final class CanonicalReferences
{
    public const PARTIES = ['supplier', 'customer', 'balanceParty'];

    /** Údaje strany, podle kterých hledá návrh — bez `ref` jsou v pokynu chybou. */
    private const PARTY_IDENTITY = ['name', 'companyId', 'vatId', 'taxId'];

    /** Údaje položky, podle kterých hledá návrh (`ourCode` hlídá validátor jako signál). */
    private const ITEM_IDENTITY = ['name', 'supplierCode', 'ean', 'sku'];

    /** Volby `_resolve`, které určují referenci — pokyn je nečte (D32). */
    private const REFERENCE_PINS = ['supplier', 'customer', 'balanceParty', 'supplierBank', 'correction'];

    /**
     * Strana a položka zadané jen referencí (`"supplier": 42`,
     * `"item": "@KOD"`) → objekt `{"ref": …}`. Idempotentní.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function expand(array $canonical): array
    {
        foreach (self::PARTIES as $key) {
            if (self::isReference($canonical[$key] ?? null)) {
                $canonical[$key] = ['ref' => $canonical[$key]];
            }
        }
        if (is_array($canonical['rows'] ?? null)) {
            foreach ($canonical['rows'] as $i => $row) {
                if (is_array($row) && self::isReference($row['item'] ?? null)) {
                    $canonical['rows'][$i]['item'] = ['ref' => $row['item']];
                }
            }
        }
        return $canonical;
    }

    /** Hodnota, kterou má smysl předat resolveru (číslo nebo text; tvar posoudí `ReferenceValue`). */
    public static function isReference(mixed $value): bool
    {
        return is_int($value) || is_string($value);
    }

    /** Je pole s referencí vyplněné? Prázdný text se bere jako nevyplněno. */
    public static function isSet(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && $value !== '');
    }

    /**
     * Účet řádku holým číslem (`"518100"`) — dočasný signál návrhu (D39);
     * reference je číslo (id) nebo text začínající `@`.
     */
    public static function isAccountSignal(mixed $value): bool
    {
        return is_string($value) && $value !== '' && !str_starts_with($value, '@');
    }

    /** @param array<string, mixed> $party */
    public static function partyIdentifies(array $party): bool
    {
        return self::hasAny($party, self::PARTY_IDENTITY);
    }

    /** @param array<string, mixed> $item */
    public static function itemIdentifies(array $item): bool
    {
        return self::hasAny($item, self::ITEM_IDENTITY);
    }

    /**
     * Nese strana vedle `ref` i data (dobový snapshot importního módu)?
     *
     * @param array<string, mixed> $party
     */
    public static function partyHasData(array $party): bool
    {
        unset($party['ref']);
        foreach ($party as $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * `_resolve` bez voleb, které určují referenci (strany, účet partnera,
     * původní doklad, položky řádků) — pokyn je nečte (D32). Dočasně
     * zůstávají `partner` a `rows[].partner`
     * (tasks/doc-format-partner-fields.md); ostatní bloky (volby DPH, které
     * pokyn hlásí jako `vat_pin_ignored`, auditní bloky) se nemění.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function withoutReferencePins(array $canonical): array
    {
        if (!is_array($canonical['_resolve'] ?? null)) {
            return $canonical;
        }
        foreach (self::REFERENCE_PINS as $key) {
            unset($canonical['_resolve'][$key]);
        }
        if (is_array($canonical['_resolve']['rows'] ?? null)) {
            foreach ($canonical['_resolve']['rows'] as $i => $row) {
                if (is_array($row)) {
                    unset($canonical['_resolve']['rows'][$i]['item']);
                }
            }
        }
        return $canonical;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private static function hasAny(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }
}
