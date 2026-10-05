<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

use Shipard\Core\Prints\PrintDefinition;

/**
 * Zdroj uživatelských textů pro tisk (#90 D47). Jádro tisku zná jen toto
 * rozhraní — texty drží modul `core.prints` (`PrintTextResolver`), který na
 * zdroji dat být nemusí. `PrintRunner` bez zdroje textů tiskne bez nich.
 */
interface PrintTextProvider
{
    /**
     * Texty platné pro tisk záznamu v daném jazyce a dni — nevykreslené,
     * v pořadí, v jakém se mají ve slotu objevit.
     *
     * @param array<string, mixed> $record Tištěný záznam (řádek tabulky tisku).
     * @param \DateTimeImmutable $today Den tisku nebo odeslání (D52), ne datum dokladu.
     * @return array<string, list<array{id: int, text: string}>> slot → texty
     */
    public function resolve(
        PrintDefinition $definition,
        array $record,
        string $language,
        \DateTimeImmutable $today,
    ): array;
}
