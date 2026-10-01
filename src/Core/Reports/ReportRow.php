<?php

declare(strict_types=1);

namespace Shipard\Core\Reports;

/**
 * Řádek výsledku reportu. `values` jsou klíčované id sloupce; buňka money
 * sloupce nese `md`, `d` a `balance` (zůstatek dle stran účtu, ne prezentace
 * — D6), buňka text/date sloupce je prostý string (datum ISO `YYYY-MM-DD`).
 *
 * `key`, `link` a `cellLinks` jsou volitelné a v JSON jen když jsou
 * vyplněné — export a diff odkazy ignorují (docs/reports.md §16).
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
     * @param ?array{kind: string, target: array<string, mixed>} $link
     *        Drill-down z názvu řádku — akce stejného tvaru jako akce
     *        detailu vieweru (`open_detail`, `open_viewer`, `open_report`).
     * @param array<string, array{kind: string, target: array<string, mixed>}> $cellLinks
     *        Drill-down z textové buňky: id sloupce → akce.
     */
    public function __construct(
        public readonly ReportRowKind $kind,
        public readonly int $level,
        public readonly ?string $account,
        public readonly string $label,
        public readonly array $values,
        public readonly ?string $key = null,
        public readonly ?array $link = null,
        public readonly array $cellLinks = [],
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
        if ($this->link !== null) {
            $out['link'] = $this->link;
        }
        if ($this->cellLinks !== []) {
            $out['cellLinks'] = $this->cellLinks;
        }
        return $out;
    }
}
