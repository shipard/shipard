<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval\Browse;

use Shipard\Module\Core\Exchange\Dataset\DatasetReader;

/**
 * Sada v katalogu prohlížeče výsledků: název z manifestu (= název složky
 * výsledků `tests/eval/<name>`), titulek, země, profil, počet zpráv
 * a absolutní adresář sady v checkoutu.
 */
final readonly class EvalDatasetInfo
{
    public function __construct(
        public string $name,
        public string $title,
        public ?string $country,
        public ?string $profile,
        public int $messages,
        public string $dir,
    ) {}

    public function reader(): DatasetReader
    {
        return DatasetReader::open($this->dir);
    }

    /** @return array{name: string, title: string, country: ?string, profile: ?string, messages: int} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'country' => $this->country,
            'profile' => $this->profile,
            'messages' => $this->messages,
        ];
    }
}
