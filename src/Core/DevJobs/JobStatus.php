<?php

declare(strict_types=1);

namespace Shipard\Core\DevJobs;

/**
 * Stavy úlohy vývojářského dashboardu (tasks/testing-phase6b.md § 1, D58).
 * `starting` / `running` / `cancelling` jsou aktivní (obal se rozbíhá nebo
 * běží), ostatní konečné. Stav se nikdy neukládá jako celek — odvozuje ho
 * {@see JobStore::status()} ze `state.json` obalu, značky zrušení
 * (`cancel.json`) a živosti obalu (`wrapper.lock`).
 */
final class JobStatus
{
    public const string STARTING = 'starting';
    public const string RUNNING = 'running';
    public const string CANCELLING = 'cancelling';
    public const string FINISHED = 'finished';
    public const string FAILED = 'failed';
    public const string CANCELLED = 'cancelled';

    public const array ACTIVE = [self::STARTING, self::RUNNING, self::CANCELLING];
    public const array ALL = [self::STARTING, self::RUNNING, self::CANCELLING, self::FINISHED, self::FAILED, self::CANCELLED];

    private function __construct()
    {
    }

    public static function isActive(string $status): bool
    {
        return in_array($status, self::ACTIVE, true);
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
