<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Jazyk tisku (#90 D8). Ve v1 = výslovně vyžádaný jazyk, jinak výchozí
 * jazyk zdroje dat; osoby jazyk nemají. Jediné místo, kam později přibude
 * jazyk partnera (#94) — proto dostává i záznam a deklaraci.
 */
final class PrintLanguageResolver
{
    /** Jazyky, pro které existuje kompilovaná konfigurace i katalogy šablon. */
    public const LANGUAGES = ['cs', 'en'];

    private const FALLBACK = 'en';

    public function __construct(
        private readonly string $defaultLanguage,
    ) {}

    /**
     * @param array<string, mixed> $record
     * @throws \InvalidArgumentException Vyžádaný jazyk není podporovaný (→ 400).
     */
    public function resolve(?string $requested, PrintDefinition $definition, array $record): string
    {
        if ($requested !== null && $requested !== '') {
            if (!in_array($requested, self::LANGUAGES, true)) {
                throw new \InvalidArgumentException(
                    "Parameter 'language' must be one of " . implode('|', self::LANGUAGES),
                );
            }
            return $requested;
        }

        return in_array($this->defaultLanguage, self::LANGUAGES, true)
            ? $this->defaultLanguage
            : self::FALLBACK;
    }
}
