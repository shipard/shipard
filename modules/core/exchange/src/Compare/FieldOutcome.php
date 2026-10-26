<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Výsledek porovnání jednoho listu projekce v režimu evaluace
 * ({@see CanonicalComparator::evaluate()}): cesta hodnoty, stav, obě
 * strany po projekci (surové — co model skutečně vydal) a u volného textu
 * podobnost 0–1. U pole různé délky nese cestu pole a obě strany jako
 * počty prvků. `reason` jen u stavu `notCarried` (proč zdroj pole nenese,
 * #103 D76). `informative` (#103 D114): list informativního uzlu projekce
 * ({@see Projection::isInformativeNode()}) — stav platí, ale do shody polí
 * ani čistých dokladů se nepočítá, vykazuje se zvlášť; `notCarried` se
 * neznačí.
 */
final readonly class FieldOutcome
{
    public function __construct(
        public string $path,
        public FieldState $state,
        public mixed $expected,
        public mixed $actual,
        public ?float $similarity = null,
        public ?string $reason = null,
        public bool $informative = false,
    ) {}

    /** Táž hodnota označená jako informativní (#103 D114). */
    public function asInformative(): self
    {
        return new self($this->path, $this->state, $this->expected, $this->actual, $this->similarity, $this->reason, true);
    }

    /** Cesta uzlu projekce (indexy nahrazené `[]`). */

    public function nodePath(): string
    {
        return Projection::nodePath($this->path);
    }

    /** @return array{path: string, state: string, expected: mixed, actual: mixed, similarity?: float, reason?: string, informative?: true} */
    public function toArray(): array
    {
        $out = ['path' => $this->path, 'state' => $this->state->value, 'expected' => $this->expected, 'actual' => $this->actual];
        if ($this->similarity !== null) {
            $out['similarity'] = $this->similarity;
        }
        if ($this->reason !== null) {
            $out['reason'] = $this->reason;
        }
        if ($this->informative) {
            $out['informative'] = true;
        }
        return $out;
    }
}

