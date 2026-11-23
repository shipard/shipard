<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Tvar referencí v kanonickém dokladu (#166 D30–D33, D39) — čisté funkce
 * bez databáze. Hodnotu reference rozebírá `ReferenceValue` a hledá
 * `ReferenceResolver`; tady je jen to, co je vlastní formátu dokladu:
 *
 *  - strana, partner (hlavičky i řádku) a položka smí být reference
 *    sama, nebo objekt s `ref` ({@see expand()} sjednotí na objekt, aby
 *    zbytek kódu četl jedno);
 *  - které údaje strany a položky „identifikují“ (objekt bez `ref` s nimi
 *    je v pokynu chyba `reference_missing`);
 *  - pokyn `_resolve` nečte vůbec ({@see withoutResolve()}, D32);
 *  - účet řádku holým číslem je signál návrhu, ne reference;
 *  - položka a účet řádku, jak je čte a zapisuje obohacení návrhu
 *    ({@see rowItemIsSet()}, {@see suggestItem()} …): nově reference
 *    `"@kód"`, uložené návrhy starým tvarem (`item.ourCode`, holé číslo
 *    účtu — D39) se čtou dál.
 */
final class CanonicalReferences
{
    public const PARTIES = ['supplier', 'customer', 'balanceParty'];

    /**
     * Partner hlavičky účetního dokladu a dokladu vázaného na pokladnu
     * a partner řádku (#166 D30) — stejný tvar jako strana.
     */
    public const PARTNER = 'partner';

    /**
     * Typy dokladu, jejichž hlavička nese pole `partner`: účetní doklad
     * a typy vázané na pokladnu. U faktur je partnerem strana.
     */
    public const HEAD_PARTNER_DOC_TYPES = ['accountingDocument', 'cashDocument', 'cashRegisterDocument'];

    /** Údaje strany, podle kterých hledá návrh — bez `ref` jsou v pokynu chybou. */
    private const PARTY_IDENTITY = ['name', 'companyId', 'vatId', 'taxId'];

    /** Údaje položky, podle kterých hledá návrh (`ourCode` hlídá validátor jako signál). */
    private const ITEM_IDENTITY = ['name', 'supplierCode', 'ean', 'sku'];

    /**
     * Strana, partner a položka zadané jen referencí (`"supplier": 42`,
     * `"partner": "@K00042"`, `"item": "@KOD"`) → objekt `{"ref": …}`.
     * Idempotentní.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function expand(array $canonical): array
    {
        foreach ([...self::PARTIES, self::PARTNER] as $key) {
            if (self::isReference($canonical[$key] ?? null)) {
                $canonical[$key] = ['ref' => $canonical[$key]];
            }
        }
        if (is_array($canonical['rows'] ?? null)) {
            foreach ($canonical['rows'] as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }
                foreach (['item', self::PARTNER] as $key) {
                    if (self::isReference($row[$key] ?? null)) {
                        $canonical['rows'][$i][$key] = ['ref' => $row[$key]];
                    }
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
     * Je pole partnera vyplněné — referencí, nebo údaji osoby? Čte tvar
     * po {@see expand()}.
     */
    public static function partnerIsSet(mixed $partner): bool
    {
        return is_array($partner) && (self::isSet($partner['ref'] ?? null) || self::partyHasData($partner));
    }

    /**
     * Nese doklad partnera hlavičky v poli `partner` a smí ho jeho typ nést?
     *
     * @param array<string, mixed> $canonical
     */
    public static function carriesHeadPartner(array $canonical): bool
    {
        return self::partnerIsSet($canonical[self::PARTNER] ?? null)
            && in_array($canonical['docType'] ?? null, self::HEAD_PARTNER_DOC_TYPES, true);
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

    // ── Položka a účet řádku v návrhu (obohacení, D33, D39) ─────────────────

    /**
     * Je položka řádku určená — referencí, nebo signálem `item.ourCode`
     * (uložené návrhy)? Takový řádek obohacení nechává být.
     */
    public static function rowItemIsSet(mixed $row): bool
    {
        $item = is_array($row) && is_array($row['item'] ?? null) ? $row['item'] : [];
        return self::isSet($item['ref'] ?? null) || trim((string) ($item['ourCode'] ?? '')) !== '';
    }

    /**
     * Kód položky řádku: z reference `"@kód"` (tvar, jaký píše obohacení),
     * jinak ze signálu `item.ourCode`. Reference id nebo jmenovaným
     * sloupcem (`"@ean:…"`) kód nedává — null.
     */
    public static function rowItemCode(mixed $row): ?string
    {
        $item = is_array($row) && is_array($row['item'] ?? null) ? $row['item'] : [];
        $ref = $item['ref'] ?? null;
        if (is_string($ref) && str_starts_with($ref, '@')) {
            $key = substr($ref, 1);
            if (str_starts_with($key, 'code:')) {
                $key = substr($key, 5);
            } elseif (preg_match('/^(ean|sku):/', $key) === 1) {
                return null;
            }
            return $key !== '' ? $key : null;
        }
        if (self::isSet($ref)) {
            return null;
        }
        $code = is_scalar($item['ourCode'] ?? null) ? trim((string) $item['ourCode']) : '';
        return $code !== '' ? $code : null;
    }

    /**
     * Položka navržená obohacením → `item.ref = "@kód"`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function suggestItem(array $row, string $code): array
    {
        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $item['ref'] = '@' . $code;
        $row['item'] = $item;
        return $row;
    }

    /**
     * Odvolá položku navrženou obohacením, pokud ji od té doby nikdo
     * nezměnil — nový tvar (`ref`) i starý (`ourCode`).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function revertSuggestedItem(array $row, string $code): array
    {
        if (!is_array($row['item'] ?? null)) {
            return $row;
        }
        if (($row['item']['ref'] ?? null) === '@' . $code) {
            $row['item']['ref'] = null;
        }
        if (($row['item']['ourCode'] ?? null) === $code) {
            $row['item']['ourCode'] = null;
        }
        return $row;
    }

    /** Je na řádku účet (reference i holé číslo)? */
    public static function rowAccountIsSet(mixed $row): bool
    {
        return is_array($row) && self::isSet($row['account'] ?? null);
    }

    /**
     * Účet navržený obohacením → reference `"@číslo"`.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function suggestAccount(array $row, string $number): array
    {
        $row['account'] = '@' . $number;
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function revertSuggestedAccount(array $row, string $number): array
    {
        if (in_array($row['account'] ?? null, ['@' . $number, $number], true)) {
            $row['account'] = null;
        }
        return $row;
    }

    /**
     * Nese payload blok `_resolve`? V pokynu se celý ignoruje s jedním info
     * nálezem `resolve_ignored` (D32).
     *
     * @param array<string, mixed> $canonical
     */
    public static function carriesResolve(array $canonical): bool
    {
        return is_array($canonical['_resolve'] ?? null) && $canonical['_resolve'] !== [];
    }

    /**
     * Canonical bez bloku `_resolve` — pokyn ho nečte vůbec (D32): volby
     * uživatele patří návrhu, reference určuje payload.
     *
     * @param array<string, mixed> $canonical
     * @return array<string, mixed>
     */
    public static function withoutResolve(array $canonical): array
    {
        unset($canonical['_resolve']);
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
