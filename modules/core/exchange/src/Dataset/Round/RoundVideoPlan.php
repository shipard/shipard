<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/** Demo sada ve vrstvě videí: zdroj dat pro videa (`videa — <sada>`, D126; null = kolo ho založí) a scénáře nad ní. */
final readonly class RoundVideoPlan
{
    /** @param list<RoundScenarioPlan> $scenarios */
    public function __construct(
        public string $dataset,
        public string $title,
        public ?string $country,
        public ?string $dataSourceId,
        public ?string $dataSourceName,
        public array $scenarios,
    ) {}

    public function needsCreate(): bool
    {
        return $this->dataSourceId === null;
    }
}
