<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

/**
 * Chyba reference na záznam (#166 D9, D10): špatný tvar hodnoty, tabulka
 * bez přirozeného klíče, nenalezený nebo nejednoznačný záznam. Vedle
 * zprávy pro člověka nese kód a části, ze kterých si volající složí
 * vlastní text (datové sady, dimenze dokladů).
 */
final class ReferenceException extends \RuntimeException
{
    public const INVALID            = 'reference_invalid';
    public const TABLE_UNKNOWN      = 'reference_table_unknown';
    public const NO_KEY             = 'reference_no_key';
    public const COLUMN_NOT_ALLOWED = 'reference_column_not_allowed';
    public const NOT_FOUND          = 'reference_not_found';
    public const AMBIGUOUS          = 'reference_ambiguous';

    /**
     * @param list<int> $ids id nalezených záznamů (jen `reference_ambiguous`)
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $table = '',
        public readonly ?string $column = null,
        public readonly mixed $value = null,
        public readonly array $ids = [],
    ) {
        parent::__construct($message);
    }

    public static function invalid(string $message, mixed $value, string $table = ''): self
    {
        return new self(self::INVALID, $message, $table, null, $value);
    }

    public static function tableUnknown(string $table): self
    {
        return new self(self::TABLE_UNKNOWN, "Tabulka „{$table}“ na tomto zdroji dat není.", $table);
    }

    public static function noKey(string $table): self
    {
        return new self(
            self::NO_KEY,
            "Tabulka „{$table}“ nemá přirozený klíč — odkaž záznam jeho id.",
            $table,
        );
    }

    public static function columnNotAllowed(string $table, string $column): self
    {
        return new self(
            self::COLUMN_NOT_ALLOWED,
            "Přes sloupec „{$column}“ se v tabulce „{$table}“ hledat nedá.",
            $table,
            $column,
        );
    }

    public static function notFound(string $table, ?string $column, int|string $value): self
    {
        $message = $column === null
            ? "Záznam #{$value} v tabulce „{$table}“ není."
            : "Záznam „{$value}“ ({$table}.{$column}) nebyl nalezen.";
        return new self(self::NOT_FOUND, $message, $table, $column, $value);
    }

    /**
     * @param list<int> $ids
     */
    public static function ambiguous(string $table, string $column, string $value, array $ids): self
    {
        return new self(
            self::AMBIGUOUS,
            "Hodnota „{$value}“ ({$table}.{$column}) není jednoznačná — záznamů: "
                . count($ids) . ' (#' . implode(', #', $ids) . ').',
            $table,
            $column,
            $value,
            $ids,
        );
    }

    /** Táž chyba se jménem tabulky — parser hodnoty tabulku nezná. */
    public function forTable(string $table): self
    {
        return new self($this->errorCode, $this->getMessage(), $table, $this->column, $this->value, $this->ids);
    }
}
