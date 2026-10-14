<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

/**
 * Výsledek jedné kontroly: stav, důvod přeskočení, seznam rozdílů
 * (hotové věty pro člověka i JSON) a poznámky (informace, které nejsou
 * rozdíl — varování reportu, počty).
 */
final class CheckResult
{
    /**
     * @param list<string> $differences
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly CheckStatus $status,
        public readonly ?string $reason = null,
        public readonly array $differences = [],
        public readonly array $notes = [],
    ) {}

    /** @param list<string> $notes */
    public static function ok(string $id, string $label, array $notes = []): self
    {
        return new self($id, $label, CheckStatus::Ok, null, [], $notes);
    }

    /**
     * Rozdíl, když je co hlásit; prázdný seznam = ok.
     *
     * @param list<string> $differences
     * @param list<string> $notes
     */
    public static function fromDifferences(string $id, string $label, array $differences, array $notes = []): self
    {
        return $differences === []
            ? self::ok($id, $label, $notes)
            : new self($id, $label, CheckStatus::Diff, null, array_values($differences), $notes);
    }

    /** @param list<string> $notes */
    public static function skipped(string $id, string $label, string $reason, array $notes = []): self
    {
        return new self($id, $label, CheckStatus::Skipped, $reason, [], $notes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'status'      => $this->status->value,
            'reason'      => $this->reason,
            'differences' => $this->differences,
            'notes'       => $this->notes,
        ];
    }
}
