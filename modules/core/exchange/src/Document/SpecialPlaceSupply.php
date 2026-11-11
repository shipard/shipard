<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Enrich\ItemContentTags;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;

/**
 * Zvláštní místo plnění podle štítků řádků (#89 D3, D17, D19–D21): čtení
 * `crossBorderSupply` z taxonomie `core.exchange.contentTags`, klasifikace
 * řádku ({@see classifyRow()}) a predikát „všechny položkové řádky jsou
 * `special`“.
 *
 * Štítek řádku je štítek z bloku `_resolve.contentTag` (výjimka řádku,
 * jinak štítek dokladu); řádek bez něj, pokrytý položkou
 * (`item.ourCode`), se klasifikuje podle štítků své položky (D19).
 *
 * Čisté funkce nad canonicalem, taxonomií a mapou kód položky → štítky
 * ({@see ItemContentTags}) — jediné místo pravidla pro rozhodnutí applieru
 * o dokladu mimo české DPH ({@see DocumentApplier}, `specialPlace`) i pro
 * strop pásma karty návrhu (`AnalysisConfidenceResolver`). Kdo pravidlo
 * změní tady, změní obojí; druhá kopie neexistuje.
 */
final class SpecialPlaceSupply
{
    /** Řádek se zvláštním místem plnění. */
    public const ROW_SPECIAL = 'special';

    /** Řádek pokrytý položkou, jejíž štítky místo plnění neurčují jednoznačně (D21). */
    public const ROW_AMBIGUOUS = 'ambiguous';

    /** Klasifikace ze štítku bloku `_resolve.contentTag`. */
    public const SOURCE_TAG = 'tag';

    /** Klasifikace ze štítků položky řádku. */
    public const SOURCE_ITEM = 'item';

    /**
     * `crossBorderSupply` štítku z taxonomie; neznámá hodnota nebo štítek = null.
     *
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     */
    public static function tagSupplyOf(array $contentTags, string $tag): ?string
    {
        $value = $contentTags[$tag]['crossBorderSupply'] ?? null;
        return is_string($value) && in_array($value, VatCodeDerivation::TAG_SUPPLY_VALUES, true) ? $value : null;
    }

    /**
     * Klasifikace jednoho řádku (D19):
     *
     *  - řádek má štítek z bloku `_resolve.contentTag`
     *    ({@see RowEnrichmentPipeline::rowContentTagOf()}) → `special`, když
     *    to říká štítek, jinak nic; zdroj `tag`. Štítek z bloku má přednost
     *    vždy — i když sám místo plnění neurčuje;
     *  - jinak řádek s `item.ourCode` → ze štítků položky se počítají jen
     *    ty, které mají v taxonomii `crossBorderSupply`: všechny `special`
     *    → `special`; `special` i jiná hodnota → `ambiguous`; žádný
     *    `special` (položka bez štítků, neexistující, neaktivní) → nic;
     *    zdroj `item`;
     *  - řádek s volbou položky od uživatele
     *    (`_resolve.rows[i].item.userAction`) se z položky neklasifikuje —
     *    na dokladu skončí jiná položka, než nese canonical.
     *
     * Druh plnění (`goods` / `services`) se z položky neodvozuje.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param array<string, list<string>> $itemTags {@see ItemContentTags::forCodes()}
     * @return array{state: ?string, source: ?string, tag: ?string, item: ?string}
     *         state special | ambiguous | null; `tag` = štítek z bloku;
     *         `item` = kód položky, ze které klasifikace plyne
     */
    public static function classifyRow(array $canonical, int $rowIndex, array $contentTags, array $itemTags): array
    {
        $tag = RowEnrichmentPipeline::rowContentTagOf($canonical, $rowIndex);
        if ($tag !== null) {
            return [
                'state'  => self::tagSupplyOf($contentTags, $tag) === VatCodeDerivation::TAG_SUPPLY_SPECIAL
                    ? self::ROW_SPECIAL
                    : null,
                'source' => self::SOURCE_TAG,
                'tag'    => $tag,
                'item'   => null,
            ];
        }

        $none = ['state' => null, 'source' => null, 'tag' => null, 'item' => null];
        $code = self::classifiableItemCode($canonical, $rowIndex);
        if ($code === null) {
            return $none;
        }
        $supplies = [];
        foreach ($itemTags[ItemContentTags::key($code)] ?? [] as $itemTag) {
            $supply = is_string($itemTag) ? self::tagSupplyOf($contentTags, $itemTag) : null;
            if ($supply !== null) {
                $supplies[$supply] = true;
            }
        }
        if (!isset($supplies[VatCodeDerivation::TAG_SUPPLY_SPECIAL])) {
            return $none;
        }
        return [
            'state'  => count($supplies) === 1 ? self::ROW_SPECIAL : self::ROW_AMBIGUOUS,
            'source' => self::SOURCE_ITEM,
            'tag'    => null,
            'item'   => $code,
        ];
    }

    /**
     * Má doklad aspoň jeden položkový řádek a je každý z nich `special`
     * ({@see classifyRow()} — štítkem z bloku nebo štítky položky, i
     * v kombinaci)? Položkový řádek = `rowKind` item bez kontace
     * (`accSide`). Prázdná taxonomie = false.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     * @param array<string, list<string>> $itemTags {@see ItemContentTags::forCodes()}
     */
    public static function allItemRowsSpecial(array $canonical, array $contentTags, array $itemTags): bool
    {
        $found = false;
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!self::isItemRow($row)) {
                continue;
            }
            if (self::classifyRow($canonical, (int) $idx, $contentTags, $itemTags)['state'] !== self::ROW_SPECIAL) {
                return false;
            }
            $found = true;
        }
        return $found;
    }

    /**
     * Kódy položek, na jejichž štítky se {@see classifyRow()} zeptá —
     * položkové řádky bez štítku z bloku a bez volby položky. Volající
     * podle nich jedním dotazem načte mapu ({@see ItemContentTags::forCodes()}).
     *
     * @param array<string, mixed> $canonical
     * @return list<string>
     */
    public static function itemCodes(array $canonical): array
    {
        $codes = [];
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!self::isItemRow($row) || RowEnrichmentPipeline::rowContentTagOf($canonical, (int) $idx) !== null) {
                continue;
            }
            $code = self::classifiableItemCode($canonical, (int) $idx);
            if ($code !== null) {
                $codes[ItemContentTags::key($code)] ??= $code;
            }
        }
        return array_values($codes);
    }

    /** Položkový řádek: `rowKind` item bez kontace (`accSide`). */
    private static function isItemRow(mixed $row): bool
    {
        return is_array($row)
            && (string) ($row['rowKind'] ?? 'item') === 'item'
            && !isset($row['accSide']);
    }

    /**
     * `item.ourCode` řádku, pokud se podle něj smí klasifikovat — řádek
     * s volbou položky od uživatele kód nedává.
     *
     * @param array<string, mixed> $canonical
     */
    private static function classifiableItemCode(array $canonical, int $rowIndex): ?string
    {
        $row = $canonical['rows'][$rowIndex] ?? null;
        $code = is_array($row) && is_scalar($row['item']['ourCode'] ?? null) ? trim((string) $row['item']['ourCode']) : '';
        if ($code === '') {
            return null;
        }
        $action = $canonical['_resolve']['rows'][$rowIndex]['item']['userAction'] ?? null;
        return is_string($action) && $action !== '' ? null : $code;
    }
}
