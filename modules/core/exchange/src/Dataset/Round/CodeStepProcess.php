<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\E2e\CancellableProcess;

/**
 * Podproces kroku vrstvy kódu ({@see CancellableProcess}): argv z kroku,
 * zrušení SIGTERM (phpunit, node i python na něj končí) a po grace SIGKILL.
 * Prostředí skládá {@see environment()}: pevný `PATH`, `HOME` (cache npm
 * a Playwrightu), UTF-8 locale (`UpdateEnvironment::env()` vnucuje
 * `LC_ALL=C`, PHPUnit i python by pak mrzačily diakritiku) a `PYTHONUTF8`.
 * Nic dalšího: `CI=1` by zdědil celý PHPUnit a testy, které skládání
 * prostředí hlídají (`CancellableProcessTest`, `VideoRunCommandTest`),
 * by padaly jen pod kolem; `node --test` dává mimo TTY TAP i bez `CI`,
 * barvy mimo TTY nikdo nepíše.
 */
final class CodeStepProcess extends CancellableProcess
{
    public const float DEFAULT_GRACE_SECONDS = 20.0;

    /** @param list<string> $argv @param \Closure(): bool $shouldCancel */
    public function __construct(
        private readonly array $argv,
        string $cwd,
        \Closure $shouldCancel,
        float $graceSeconds = self::DEFAULT_GRACE_SECONDS,
        int $pollMicroseconds = 200_000,
    ) {
        parent::__construct($cwd, $shouldCancel, $graceSeconds, $pollMicroseconds, self::SIGTERM);
    }

    public function argv(): array
    {
        return $this->argv;
    }

    /** @return array<string, string> */
    public static function environment(?string $home, string $path = UpdateEnvironment::DEFAULT_PATH): array
    {
        return self::environmentFor(
            ['LC_ALL' => 'C.UTF-8', 'LANG' => 'C.UTF-8', 'PYTHONUTF8' => '1'],
            ['LC_ALL', 'LANG', 'PYTHON'],
            $home,
            $path,
        );
    }
}
