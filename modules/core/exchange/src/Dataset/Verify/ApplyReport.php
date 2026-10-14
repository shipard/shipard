<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

/** Výsledek kroku apply: co se použilo, co selhalo, počet položek před a po. */
final class ApplyReport
{
    /**
     * @param list<string> $applied messageId
     * @param list<array{messageId: string, code: string, message: string}> $failed
     */
    public function __construct(
        public readonly bool $ran,
        public readonly array $applied = [],
        public readonly array $failed = [],
        public readonly ?int $itemsBefore = null,
        public readonly ?int $itemsAfter = null,
    ) {}

    public static function notRun(): self
    {
        return new self(false);
    }

    public function withItemCounts(?int $before, ?int $after): self
    {
        return new self($this->ran, $this->applied, $this->failed, $before, $after);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ran'         => $this->ran,
            'applied'     => $this->applied,
            'failed'      => $this->failed,
            'itemsBefore' => $this->itemsBefore,
            'itemsAfter'  => $this->itemsAfter,
        ];
    }
}
