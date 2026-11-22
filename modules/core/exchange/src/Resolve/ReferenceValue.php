<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Shipard\Core\Database\TableDefinition;

/**
 * Hodnota reference na záznam, jak ji nese JSON (#166 D9, D15) — čistý
 * rozbor bez databáze:
 *
 *  - celé číslo > 0 → primární klíč (`id`);
 *  - `"@K00042"` → přirozený klíč cílové tabulky (`key`);
 *  - `"@company_id:12345678"` → jmenovaný sloupec (`column`), jen když je
 *    část před **první** dvojtečkou `exchangeKey` nebo sloupec
 *    z `exchangeLookupColumns` cílové tabulky; jinak je celý text za `@`
 *    hodnota přirozeného klíče (klíč smí dvojtečku obsahovat);
 *  - cokoli jiného (i číselný řetězec `"42"`) je chyba.
 *
 * Hodnota se neořezává a velikost písmen se nemění — `"0123"` a `"123"`
 * jsou různé klíče.
 */
final class ReferenceValue
{
    public const KIND_ID     = 'id';
    public const KIND_KEY    = 'key';
    public const KIND_COLUMN = 'column';

    /**
     * @param ?string    $column sloupec hledání: u `key` `exchangeKey` cílové
     *                           tabulky (null = tabulka klíč nemá), u `id` null
     * @param int|string $value  u `id` číslo, jinak text
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $column,
        public readonly int|string $value,
    ) {}

    /**
     * @throws ReferenceException `reference_invalid`
     */
    public static function parse(mixed $value, TableDefinition $target): self
    {
        if (is_int($value)) {
            if ($value <= 0) {
                throw ReferenceException::invalid('Id záznamu musí být kladné celé číslo.', $value);
            }
            return new self(self::KIND_ID, null, $value);
        }
        if (!is_string($value) || !str_starts_with($value, '@')) {
            throw ReferenceException::invalid(
                'Reference musí být id záznamu (kladné celé číslo) nebo text začínající „@“ (přirozený klíč).',
                $value,
            );
        }

        $text = substr($value, 1);
        if ($text === '') {
            throw ReferenceException::invalid('Za „@“ chybí hodnota přirozeného klíče.', $value);
        }

        $colon = strpos($text, ':');
        if ($colon !== false) {
            $column = substr($text, 0, $colon);
            if ($target->isExchangeLookupColumn($column)) {
                $rest = substr($text, $colon + 1);
                if ($rest === '') {
                    throw ReferenceException::invalid("Za „@{$column}:“ chybí hodnota.", $value);
                }
                return new self(self::KIND_COLUMN, $column, $rest);
            }
        }

        return new self(self::KIND_KEY, $target->exchangeKey, $text);
    }
}
