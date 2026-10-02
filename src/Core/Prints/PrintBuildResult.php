<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Výstup builderu: sekce `data` specifická pro tisk, titulek a název
 * souboru pro `meta` a měkká hlášení. `version` je verze kontraktu `data`
 * (#90 D15) — builder ji zvyšuje při nekompatibilní změně.
 */
final class PrintBuildResult
{
    /**
     * @param array<string, mixed> $data
     * @param list<PrintMessage> $messages
     */
    public function __construct(
        public readonly array $data,
        public readonly string $title,
        public readonly string $fileName,
        public readonly array $messages = [],
        public readonly int $version = 1,
    ) {}
}
