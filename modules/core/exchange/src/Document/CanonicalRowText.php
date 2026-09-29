<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

/**
 * Text řádku dokladu z kanonického řádku `shpd.docs.document.v1` — jeden
 * zdroj pravdy pro `docs_core_rows.description` (#84 D1/D2).
 *
 * Skladba:
 *   1. neprázdný top-level `row.description` má přednost (účetní doklad
 *      `acc.record` item fragment nemá, export ho píše sem);
 *   2. jinak `item.name`, za oddělovačem `item.description`, pokud je po
 *      trimu neprázdný a není v názvu obsažený (case-insensitive) — AI
 *      extrakce do popisu občas opíše záhlaví sloupců nebo jednotku, občas
 *      užitečný detail (fakturované období, číslo služby);
 *   3. chybí-li název, samotný popis; nic → `null`.
 *
 * Výsledek je oříznutý na délku sloupce. Používá ho applier (zápis řádku),
 * náhled (`_resolve.rows[i].rowText`), poziční guard dodavatelských kódů,
 * klasifikátor obsahových štítků i enricher z historie — skládat text
 * řádku jinde než tady znamená, že si tyto vrstvy přestanou odpovídat
 * (viz tasks/exchange-row-text.md → Pasti).
 */
final class CanonicalRowText
{
    public const SEPARATOR = ' — ';

    /** Délka `docs_core_rows.description` (varchar(500)). */
    public const MAX_LENGTH = 500;

    /**
     * @param array<string, mixed> $row kanonický řádek
     */
    public static function compose(array $row): ?string
    {
        $top = self::clean($row['description'] ?? null);
        if ($top !== null) {
            return self::cap($top);
        }

        $item = is_array($row['item'] ?? null) ? $row['item'] : [];
        $name = self::clean($item['name'] ?? null);
        $desc = self::clean($item['description'] ?? null);

        if ($name === null) {
            return $desc === null ? null : self::cap($desc);
        }
        if ($desc === null || mb_stripos($name, $desc, 0, 'UTF-8') !== false) {
            return self::cap($name);
        }

        return self::cap($name . self::SEPARATOR . $desc);
    }

    /** Trim; prázdný řetězec i ne-string hodnota → null. */
    private static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function cap(string $text): string
    {
        return mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8');
    }
}
