<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset;

/**
 * Sada k seedu z katalogu checkoutu ({@see DatasetCatalog}): název
 * z manifestu — tentýž, který zdroj dat po `dataset-seed` drží v `main.json`
 * (`dataset.name`, původ `dataset-seed <název>`), proto je to klíč výběru
 * i v dashboardu — titulek, popis, druh (`test` / `demo`), země, profil
 * a absolutní adresář sady. Do příkazu `dataset-seed` jde jen `dir`
 * z tohoto záznamu, nikdy text z požadavku.
 */
final readonly class DatasetCatalogEntry
{
    public function __construct(
        public string $name,
        public string $title,
        public ?string $description,
        public string $kind,
        public ?string $country,
        public ?string $profile,
        public string $dir,
    ) {}

    /** @return array{name: string, title: string, kind: string, country: ?string, profile: ?string, description: ?string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'kind' => $this->kind,
            'country' => $this->country,
            'profile' => $this->profile,
            'description' => $this->description,
        ];
    }
}
