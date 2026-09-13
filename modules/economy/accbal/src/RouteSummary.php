<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

/**
 * Agregát dávkového běhu {@see ClearingRouter}: počty přeúčtováno /
 * naplánováno (dry-run) / přeskočeno per důvod a Σ částky přeúčtovaných
 * (v dry-runu naplánovaných) úhrad v domácí měně. Tvar odpovědi
 * `POST /_accbal/match` (docs/accbal.md §5.7, verze kontraktu 2).
 */
final class RouteSummary
{
    /** @var list<RouteResult> */
    public array $results = [];

    public int $routed = 0;
    public int $planned = 0;

    /** @var array<string, int> reason → count */
    public array $skipped = [];

    /** Σ amount_hc přeúčtovaných (dry-run: naplánovaných) úhrad. */
    public float $routedAmount = 0.0;

    public function add(RouteResult $r): void
    {
        $this->results[] = $r;
        switch ($r->status) {
            case RouteResult::STATUS_ROUTED:
                $this->routed++;
                $this->routedAmount = round($this->routedAmount + $r->amountHc, 2);
                break;
            case RouteResult::STATUS_PLANNED:
                $this->planned++;
                $this->routedAmount = round($this->routedAmount + $r->amountHc, 2);
                break;
            case RouteResult::STATUS_SKIPPED:
                $reason = $r->reason ?? 'unknown';
                $this->skipped[$reason] = ($this->skipped[$reason] ?? 0) + 1;
                break;
        }
    }

    public function candidates(): int
    {
        return count($this->results);
    }
}
