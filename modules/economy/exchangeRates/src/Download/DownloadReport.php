<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Výsledek běhu stahovače (#121 task B §4.4): per dotaz počty lístků
 * a výsledků importu, varování (konflikt, selhání, budoucí datum),
 * poznámky (přeskočené měny nově založených lístků) a chyba služby,
 * po které běh skončil. Logování nechává volajícímu (CLI).
 */
final class DownloadReport
{
    public const string KIND_LISTS = ExchangeRateServiceResponse::LISTS;
    public const string KIND_NONE = ExchangeRateServiceResponse::NONE;
    public const string KIND_ERROR = ExchangeRateServiceResponse::ERROR;

    public int $sent = 0;
    public int $created = 0;
    public int $skipped = 0;
    public int $ignored = 0;
    public int $conflict = 0;
    public int $failed = 0;

    /** @var list<array{query: DownloadQuery, kind: string, lists: int, created: int, skipped: int, ignored: int, conflict: int, failed: int}> */
    public array $results = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<string> */
    public array $notices = [];

    /** Chyba služby nebo sítě — běh skončil, další dotazy se neposlaly. */
    public ?string $error = null;

    public function __construct(public readonly DownloadPlan $plan)
    {
    }

    /** @param array{query: DownloadQuery, kind: string, lists: int, created: int, skipped: int, ignored: int, conflict: int, failed: int} $line */
    public function add(array $line): void
    {
        $this->results[] = $line;
        $this->created += $line['created'];
        $this->skipped += $line['skipped'];
        $this->ignored += $line['ignored'];
        $this->conflict += $line['conflict'];
        $this->failed += $line['failed'];
    }

    public function failed(): bool
    {
        return $this->error !== null || $this->conflict > 0 || $this->failed > 0;
    }

    /** Dotazy, na které v tomto běhu nedošlo (pauza, limit, chyba). */
    public function pending(): int
    {
        return max(0, count($this->plan->queries) - $this->sent);
    }
}
