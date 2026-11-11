<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Enrich;

use Dibi\Connection;

/**
 * Štítky položek podle kódu (#89 D19): pro sadu kódů položek vrátí jejich
 * `economy_items.content_tags` jedním dotazem. Jediné místo, které se na
 * štítky položky ptá přes kód — čte ho applier (jeden dotaz na doklad)
 * i strop pásma karty návrhu (jeden dotaz na sběr karet, jeden na detail
 * zprávy). Pravidlo, co štítky znamenají pro místo plnění, drží
 * {@see \Shipard\Module\Core\Exchange\Document\SpecialPlaceSupply}.
 *
 * Jen živé položky — stejný předpoklad jako
 * `ContentTagResolver::liveItemsForTag()`. Neexistující, neaktivní nebo
 * neotagovaná položka v mapě chybí; není to chyba.
 */
final class ItemContentTags
{
    /** Stejná trojice jako ContentTagResolver / ItemResolver. */
    private const ITEM_ACTIVE_STATES = [10, 40, 80];

    /**
     * Mapa {@see key()} kódu → štítky položky. Prázdná sada kódů = žádný
     * dotaz. Kód nesený víc živými položkami bere první otagovanou podle `id`.
     *
     * @param list<string> $codes kódy položek (`rows[].item.ourCode`)
     * @return array<string, list<string>>
     */
    public static function forCodes(Connection $db, array $codes): array
    {
        $wanted = [];
        foreach ($codes as $code) {
            $code = trim($code);
            if ($code !== '') {
                $wanted[self::key($code)] ??= $code;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $rows = $db->fetchAll(
            'SELECT [code], [content_tags] FROM [economy_items]'
            . ' WHERE [code] IN %in AND [docState] IN %in AND [content_tags] IS NOT NULL'
            . ' ORDER BY [id] ASC',
            array_values($wanted),
            self::ITEM_ACTIVE_STATES,
        );

        $map = [];
        foreach ($rows as $row) {
            $key = self::key((string) ($row['code'] ?? ''));
            if ($key === '' || isset($map[$key])) {
                continue;
            }
            $tags = json_decode((string) ($row['content_tags'] ?? ''), true);
            $tags = array_values(array_filter(
                is_array($tags) ? $tags : [],
                static fn (mixed $tag): bool => is_string($tag) && $tag !== '',
            ));
            if ($tags !== []) {
                $map[$key] = $tags;
            }
        }
        return $map;
    }

    /**
     * Klíč mapy: kód bez okrajových mezer malými písmeny — sloupec `code`
     * se v databázi porovnává bez rozlišení velikosti, mapa v PHP musí taky.
     */
    public static function key(string $code): string
    {
        return mb_strtolower(trim($code));
    }
}
