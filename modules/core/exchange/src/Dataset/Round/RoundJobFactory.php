<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\DevJobs\JobRecord;
use Shipard\Core\DevJobs\JobStep;
use Shipard\Core\DevJobs\JobStore;

/**
 * Záznam úlohy `round` (D130, N1): jeden krok `shpd-server test-round-run
 * <adresář úlohy>` s cwd v kořeni repa, výběr v `params`, bez zdroje dat.
 * Totéž pro CLI (`test-round`) i dashboard (`DevRoundController`), takže
 * kolo z terminálu je v dashboardu k nerozeznání od kola z dashboardu.
 *
 * Grace zrušení = nejdelší grace dílčí úlohy (evaluace 900 s) + rezerva:
 * orchestrátor na zrušené dítě čeká, obal by ho jinak zabil dřív.
 */
final class RoundJobFactory
{
    public const int CANCEL_GRACE_SECONDS = 960;
    public const string STEP_LABEL = 'test-round-run';
    public const string ORIGIN_CLI = 'cli';
    public const string ORIGIN_DASHBOARD = 'dashboard';

    private function __construct()
    {
    }

    /** @param list<string> $serverCommand argv prefix `shpd-server` */
    public static function record(RoundSelection $selection, array $serverCommand, string $repoRoot, JobStore $jobs, \DateTimeImmutable $now, ?string $clientIp, string $origin): JobRecord
    {
        $id = JobRecord::newId($now);
        return new JobRecord(
            $id,
            JobRecord::TYPE_ROUND,
            '',
            '',
            null,
            $selection->eval?->variant,
            $selection->toArray() + ['origin' => $origin],
            [new JobStep(self::STEP_LABEL, [...$serverCommand, 'test-round-run', $jobs->jobDir($id)], $repoRoot)],
            JobStore::formatTime($now),
            $clientIp,
            self::CANCEL_GRACE_SECONDS,
        );
    }
}
