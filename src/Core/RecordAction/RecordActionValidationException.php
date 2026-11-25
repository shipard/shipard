<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Chyba vstupu, kterou pozná až akce při provedení (#172 D14) — heslo
 * nesedí k certifikátu, datum je mimo otevřené období. Nese chyby polí
 * `{<id pole>: <text>}`; endpoint z nich udělá 422 stejného tvaru jako
 * u obecné validace a dialog je vykreslí u polí. Chyba bez vazby na pole
 * má klíč {@see self::FORM} (pruh nad poli dialogu; u akce bez dialogu
 * hláška).
 *
 * Texty jsou pro uživatele — z katalogu modulu, v jazyce kontextu.
 */
final class RecordActionValidationException extends \RuntimeException
{
    /** Klíč chyby bez vazby na pole — shodný s `ValidationError::FIELD_FORM`. */
    public const string FORM = '_form';

    /** @var array<string, string> */
    private array $fieldErrors;

    /** @param array<string, string> $fieldErrors id pole (nebo `_form`) → text */
    public function __construct(array $fieldErrors)
    {
        if ($fieldErrors === []) {
            throw new \InvalidArgumentException('Record action validation exception needs at least one error');
        }
        foreach ($fieldErrors as $field => $message) {
            if (!is_string($field) || $field === '' || !is_string($message) || trim($message) === '') {
                throw new \InvalidArgumentException('Record action field errors must be {field: message}');
            }
        }
        $this->fieldErrors = $fieldErrors;
        parent::__construct('Record action input is not valid');
    }

    public static function field(string $field, string $message): self
    {
        return new self([$field => $message]);
    }

    public static function form(string $message): self
    {
        return new self([self::FORM => $message]);
    }

    /** @return array<string, string> */
    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
