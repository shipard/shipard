<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Plán běhu (#121 task B §4, `--dry-run`): okno, per typ očekáváno /
 * přítomno / chybí, varování konfigurace a dotazy v pořadí od nejnovějšího
 * měsíce. `queriesToSend()` = nepozastavené dotazy do limitu.
 */
final class DownloadPlan
{
    /** @var list<TypeDownloadPlan> */
    public array $types = [];

    /** @var list<DownloadQuery> */
    public array $queries = [];

    /** @var list<string> typ se nestahuje — neplatný `download`, neznámý kalendář */
    public array $configWarnings = [];

    /** @var array<string, array<string, true>> typ → přítomná data v rozsahu očekávaných */
    public array $present = [];

    /** Unix čas, do kdy je po chybě služby pozastaven celý běh (jen cron mód). */
    public ?int $pausedUntil = null;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $limit,
    ) {
    }

    /** @return list<DownloadQuery> */
    public function queriesToSend(): array
    {
        $due = array_values(array_filter($this->queries, static fn(DownloadQuery $q): bool => !$q->paused()));
        return $this->limit === null ? $due : array_slice($due, 0, $this->limit);
    }

    public function missingTotal(): int
    {
        return array_sum(array_map(static fn(TypeDownloadPlan $t): int => count($t->missing), $this->types));
    }
}
