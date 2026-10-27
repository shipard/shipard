<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Jedna sada ve vrstvě sad: odkud (adresář s manifestem), kam (zdroj dat
 * kola, nebo `create` = chybí a kolo ho založí — O1) a zda se nad ní má
 * běžet evaluace.
 */
final readonly class RoundDatasetPlan
{
    public function __construct(
        public string $name,
        public string $title,
        public ?string $country,
        public string $dir,
        public ?string $dataSourceId,
        public ?string $dataSourceName,
    ) {}

    public function needsCreate(): bool
    {
        return $this->dataSourceId === null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['name' => $this->name, 'title' => $this->title, 'country' => $this->country, 'dir' => $this->dir, 'dataSource' => $this->dataSourceId === null ? null : ['id' => $this->dataSourceId, 'name' => $this->dataSourceName], 'create' => $this->needsCreate()];
    }
}
