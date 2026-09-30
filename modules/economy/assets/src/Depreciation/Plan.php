<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Depreciation;

/**
 * Plán odpisů jednoho okruhu (daňového nebo účetního).
 *
 * Souhrn (`entryPrice`, `accumulated`, `residual`) je skutečný stav
 * z potvrzených událostí; `currentYearAmount` je odpis (potvrzený
 * i plánovaný) účetního roku, do kterého patří datum `asOf`.
 */
final readonly class Plan
{
    /**
     * @param list<PlanRow> $rows
     * @param list<PlanMessage> $messages hlášení k okruhu jako celku
     */
    public function __construct(
        public string $circuit,
        public array $rows,
        public float $entryPrice,
        public float $accumulated,
        public float $residual,
        public float $currentYearAmount,
        public array $messages = [],
    ) {
    }

    /** @return list<PlanRow> */
    public function plannedRows(): array
    {
        return array_values(array_filter($this->rows, static fn(PlanRow $r): bool => $r->isPlanned()));
    }

    /**
     * Hlášení okruhu i všech řádků.
     *
     * @return list<PlanMessage>
     */
    public function allMessages(): array
    {
        $all = $this->messages;
        foreach ($this->rows as $row) {
            array_push($all, ...$row->messages);
        }
        return $all;
    }

    public function hasErrors(): bool
    {
        foreach ($this->allMessages() as $message) {
            if ($message->isError()) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'circuit' => $this->circuit,
            'rows' => array_map(static fn(PlanRow $r): array => $r->toArray(), $this->rows),
            'entryPrice' => $this->entryPrice,
            'accumulated' => $this->accumulated,
            'residual' => $this->residual,
            'currentYearAmount' => $this->currentYearAmount,
            'messages' => array_map(static fn(PlanMessage $m): array => $m->toArray(), $this->messages),
        ];
    }
}
