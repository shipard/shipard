<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Výsledek běhu periodické fakturace: řádek per zakázka × období
 * s výsledkem (`OUTCOME_*`), číslem dokladu a zprávou; počty pro CLI,
 * controller a testy.
 */
final class RunReport
{
    /** Doklad vznikl (nebo byl přegenerován) a období je vystavené. */
    public const OUTCOME_ISSUED = 'issued';
    /** Koncept vznikl, období čeká na podklady přispěvatele (D10). */
    public const OUTCOME_WAITING = 'waiting';
    /** Období by se vystavilo (dry-run). */
    public const OUTCOME_PLANNED = 'planned';
    /** Období se nepodařilo vystavit; `message` říká proč. */
    public const OUTCOME_FAILED = 'failed';
    /** Pojistka dohánění (Q4): víc než MAX_CATCHUP dlužných období. */
    public const OUTCOME_CATCHUP = 'catchup';
    /** Období mezitím vystavil jiný běh (zámek řádku). */
    public const OUTCOME_SKIPPED = 'skipped';

    /** @var list<RunLine> */
    public array $lines = [];

    public function __construct(public readonly RunOptions $options)
    {
    }

    public function add(RunLine $line): void
    {
        $this->lines[] = $line;
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->lines, static fn(RunLine $l): bool => $l->outcome === $outcome));
    }

    public function hasFailures(): bool
    {
        return $this->count(self::OUTCOME_FAILED) > 0;
    }

    /** @return array<string, int> výsledek → počet */
    public function counts(): array
    {
        $out = [];
        foreach ([self::OUTCOME_ISSUED, self::OUTCOME_WAITING, self::OUTCOME_PLANNED, self::OUTCOME_FAILED, self::OUTCOME_CATCHUP, self::OUTCOME_SKIPPED] as $outcome) {
            $out[$outcome] = $this->count($outcome);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'date'   => $this->options->date,
            'dryRun' => $this->options->dryRun,
            'counts' => $this->counts(),
            'lines'  => array_map(static fn(RunLine $l): array => $l->toArray(), $this->lines),
        ];
    }
}
