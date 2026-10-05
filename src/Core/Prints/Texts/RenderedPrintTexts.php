<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Texts;

use Shipard\Core\Prints\PrintMessage;

/** Výsledek `PrintTextRenderer`: obsah slotů a hlášení o vynechaných textech. */
final class RenderedPrintTexts
{
    /**
     * @param array<string, string> $texts slot → HTML (sloty stránky) nebo
     *        prostý text (e-mailové sloty); prázdný slot v mapě není.
     * @param list<PrintMessage> $messages
     */
    public function __construct(
        public readonly array $texts,
        public readonly array $messages,
    ) {}
}
