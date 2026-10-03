<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Výsledek volby jazyka tisku. `unavailable` nese jazyk dokumentu, pro
 * který tisk zatím nemá katalogy (#94 D3) — tiskne se pak v záložním jazyce
 * a tisk o tom dá vědět měkkým hlášením.
 */
final readonly class PrintLanguageChoice
{
    public function __construct(
        public string $language,
        public ?string $unavailable = null,
    ) {}

    /**
     * Hlášení je anglicky — vzniká jen při tisku v záložním jazyce, a text
     * hlášení je v jazyce tisku. Záměrně bez názvu partnera.
     *
     * @return list<PrintMessage>
     */
    public function messages(): array
    {
        if ($this->unavailable === null) {
            return [];
        }
        return [PrintMessage::warning(
            'language.unavailable',
            "Prints are not available in '{$this->unavailable}' yet — printed in English.",
        )];
    }
}
