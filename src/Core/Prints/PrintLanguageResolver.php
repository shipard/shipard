<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\I18n\DocumentLanguageResolver;

/**
 * Jazyk tisku (#90 D8, #94 D2–D3) — jediné místo, kde se volí.
 *
 * 1. Výslovně vyžádaný jazyk (REST `language`, CLI `--language`); musí být
 *    mezi jazyky tisku.
 * 2. Jinak jazyk dokumentu z `DocumentLanguageResolver`: jazyk osoby →
 *    hlavní jazyk země strany → hlavní jazyk vlastní země. Interní tisk
 *    (`audience: internal`) stranu nehledá — tiskne se pro nás.
 * 3. Jazyk dokumentu, pro který tisk nemá katalogy (jazyk přidaný do
 *    `world.base.documentLanguages` dřív než překlady), se tiskne
 *    v záložním jazyce.
 */
final class PrintLanguageResolver
{
    /**
     * Jazyky tisku (#90 D29) — každý má katalogy všech šablon, popisky
     * konfigurace, kterou tisk čte, a formát čísel a dat
     * (`PrintTwigExtension`). Úplnost hlídají testy; musí být mezi jazyky
     * dokumentů, jinak se pro něj konfigurace nekompiluje.
     */
    public const LANGUAGES = ['cs', 'en', 'sk', 'de'];

    public const FALLBACK = 'en';

    /** @var \Closure(): DocumentLanguageResolver */
    private readonly \Closure $documentLanguages;

    /**
     * @param \Closure(): DocumentLanguageResolver $documentLanguages Líně —
     *        čte konfiguraci zdroje dat, a tisk s výslovným jazykem nebo
     *        render hotových dat ji nepotřebuje.
     */
    public function __construct(\Closure $documentLanguages)
    {
        $this->documentLanguages = $documentLanguages;
    }

    /** Má smysl ptát se builderu na stranu tisku? */
    public function needsParty(?string $requested, PrintDefinition $definition): bool
    {
        return ($requested === null || $requested === '')
            && $definition->audience !== 'internal';
    }

    /**
     * @param ?PrintParty $party Strana tisku, je-li známá (viz `needsParty()`).
     * @throws \InvalidArgumentException Vyžádaný jazyk není podporovaný (→ 400).
     */
    public function resolve(?string $requested, PrintDefinition $definition, ?PrintParty $party = null): PrintLanguageChoice
    {
        if ($requested !== null && $requested !== '') {
            if (!in_array($requested, self::LANGUAGES, true)) {
                throw new \InvalidArgumentException(
                    "Parameter 'language' must be one of " . implode('|', self::LANGUAGES),
                );
            }
            return new PrintLanguageChoice($requested);
        }

        if (!$this->needsParty($requested, $definition)) {
            $party = null;
        }
        $language = ($this->documentLanguages)()->resolve($party?->personLanguage, $party?->country);

        return in_array($language, self::LANGUAGES, true)
            ? new PrintLanguageChoice($language)
            : new PrintLanguageChoice(self::FALLBACK, unavailable: $language);
    }
}
