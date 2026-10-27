<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/** Scénář ve vrstvě videí; `problem` ≠ null = neplatný soubor nebo neznámá sada → krok `failed` s větou, kolo pokračuje (D133). */
final readonly class RoundScenarioPlan
{
    public function __construct(
        public string $id,
        public string $path,
        public ?string $dataset,
        public ?string $problem = null,
    ) {}
}
