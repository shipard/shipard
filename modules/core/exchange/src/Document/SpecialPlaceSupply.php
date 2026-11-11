<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;

/**
 * Zvláštní místo plnění podle štítků řádků (#89 D3, D17): čtení
 * `crossBorderSupply` z taxonomie `core.exchange.contentTags` a predikát
 * „všechny položkové řádky mají štítek `special`“.
 *
 * Čisté funkce nad canonicalem a taxonomií — jediné místo pravidla pro
 * rozhodnutí applieru o dokladu mimo české DPH
 * ({@see DocumentApplier}, `specialPlace`) i pro strop pásma karty návrhu
 * (`AnalysisConfidenceResolver::capsSpecialPlace()`). Kdo pravidlo změní
 * tady, změní obojí; druhá kopie neexistuje.
 */
final class SpecialPlaceSupply
{
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
     * Má doklad aspoň jeden položkový řádek a nese každý z nich štítek se
     * zvláštním místem plnění? Položkový řádek = `rowKind` item bez kontace
     * (`accSide`); štítek řádku je výjimka řádku, jinak štítek dokladu
     * ({@see RowEnrichmentPipeline::rowContentTagOf()}). Doklad bez bloku
     * `_resolve.contentTag` nebo s prázdnou taxonomií = false.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $contentTags cfgItem `core.exchange.contentTags`
     */
    public static function allItemRowsSpecial(array $canonical, array $contentTags): bool
    {
        $found = false;
        foreach ((array) ($canonical['rows'] ?? []) as $idx => $row) {
            if (!is_array($row)
                || (string) ($row['rowKind'] ?? 'item') !== 'item'
                || isset($row['accSide'])) {
                continue;
            }
            $tag = RowEnrichmentPipeline::rowContentTagOf($canonical, (int) $idx);
            if ($tag === null || self::tagSupplyOf($contentTags, $tag) !== VatCodeDerivation::TAG_SUPPLY_SPECIAL) {
                return false;
            }
            $found = true;
        }
        return $found;
    }
}
