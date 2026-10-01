<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Řádek výsledku reportu. `values` jsou klíčované id sloupce; buňka money
 * sloupce nese `md`, `d` a `balance` (zůstatek dle stran účtu, ne prezentace
 * — D6), buňka text/date sloupce je prostý string (datum ISO `YYYY-MM-DD`).
 */
final class ReportRow
{
    /**
     * @param ?string $account Číslo účtu (u error řádků chybová maska,
     *                         u total/computed null).
     * @param array<string, array{md: float, d: float, balance: float}|string> $values
     * @param ?string $key Stabilní identita řádku, který není účet (např.
     *                     `asset:68`, `event:412`) — klíč párování v
     *                     `ReportDiff`; bez něj se páruje podle `account`.
     */
    public function __construct(
        public readonly ReportRowKind $kind,
        public readonly int $level,
        public readonly ?string $account,
        public readonly string $label,
        public readonly array $values,
        public readonly ?string $key = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'kind'    => $this->kind->value,
            'level'   => $this->level,
            'account' => $this->account,
            'label'   => $this->label,
            'values'  => $this->values,
        ];
        if ($this->key !== null) {
            $out['key'] = $this->key;
        }
        return $out;
    }
}
