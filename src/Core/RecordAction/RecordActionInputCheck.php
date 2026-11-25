<?php

declare(strict_types=1);

namespace Shipard\Core\RecordAction;

/**
 * Výsledek obecné validace vstupu akce záznamu
 * ({@see RecordActionInputValidator}): právě jedno z
 *
 *  - `unknownField` — vstup nese pole, které dialog nedeklaruje (400),
 *  - `errors` — chyby polí `{field, code, message}` (422),
 *  - `input` — zvalidovaný vstup pro `RecordAction::execute()`.
 */
final class RecordActionInputCheck
{
    /** @param list<array{field: string, code: string, message: string}> $errors */
    private function __construct(
        public readonly ?RecordActionInput $input = null,
        public readonly array $errors = [],
        public readonly ?string $unknownField = null,
    ) {
    }

    public static function valid(RecordActionInput $input): self
    {
        return new self(input: $input);
    }

    /** @param non-empty-list<array{field: string, code: string, message: string}> $errors */
    public static function invalid(array $errors): self
    {
        return new self(errors: $errors);
    }

    public static function unknownField(string $field): self
    {
        return new self(unknownField: $field);
    }
}
