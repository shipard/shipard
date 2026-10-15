<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Výsledek porovnání jednoho listu projekce v režimu evaluace
 * ({@see CanonicalComparator::evaluate()}): cesta hodnoty, stav, obě
 * strany po projekci (surové — co model skutečně vydal) a u volného textu
 * podobnost 0–1. U pole různé délky nese cestu pole a obě strany jako
 * počty prvků.
 */
final readonly class FieldOutcome
{
    public function __construct(
        public string $path,
        public FieldState $state,
        public mixed $expected,
        public mixed $actual,
        public ?float $similarity = null,
    ) {}

    /** Cesta uzlu projekce (indexy nahrazené `[]`). */
    public function nodePath(): string
    {
        return Projection::nodePath($this->path);
    }

    /** @return array{path: string, state: string, expected: mixed, actual: mixed, similarity?: float} */
    public function toArray(): array
    {
        $out = ['path' => $this->path, 'state' => $this->state->value, 'expected' => $this->expected, 'actual' => $this->actual];
        if ($this->similarity !== null) {
            $out['similarity'] = $this->similarity;
        }
        return $out;
    }
}
