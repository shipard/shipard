<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\E2e\CancellableProcess;

/**
 * Podproces video-runneru (tasks/testing-phase7b.md § 2): `node
 * tools/video-runner/bin/video-runner.mjs <verb> <scénář>` s cwd
 * v adresáři runneru. Verby: `check` (průchod bez záznamu), `record`
 * (natočení `raw.mp4` + `timeline.json`) a `compose` (složení videa);
 * `build` runneru je `record` + `compose`, které `video-run` pouští
 * zvlášť kvůli krokům průběhu. Zrušení = SIGINT (runner zavře Chromium),
 * po grace SIGKILL. Prostředí jen `SHPD_*` a `VIDEO_*`
 * ({@see environment()}) — vývojářův `.env` runneru má nižší prioritu,
 * proto `video-run` nastavuje i `VIDEO_HEADFUL=0`.
 */
class VideoRunnerProcess extends CancellableProcess
{
    public const string BIN_REL = 'bin/video-runner.mjs';
    public const string VERB_CHECK = 'check';
    public const string VERB_RECORD = 'record';
    public const string VERB_COMPOSE = 'compose';
    public const array VERBS = [self::VERB_CHECK, self::VERB_RECORD, self::VERB_COMPOSE];
    public const array ENV_PREFIXES = ['SHPD_', 'VIDEO_'];

    /**
     * @param \Closure(): bool $shouldCancel
     */
    public function __construct(
        private readonly string $node,
        private readonly string $runnerDir,
        private readonly string $verb,
        private readonly string $scenarioPath,
        \Closure $shouldCancel,
        float $graceSeconds = 20.0,
        int $pollMicroseconds = 200_000,
    ) {
        if (!in_array($verb, self::VERBS, true)) {
            throw new \InvalidArgumentException("Unknown video-runner verb: {$verb}");
        }
        parent::__construct(rtrim($runnerDir, '/'), $shouldCancel, $graceSeconds, $pollMicroseconds, self::SIGINT);
    }

    public function verb(): string
    {
        return $this->verb;
    }

    /** @return list<string> */
    public function argv(): array
    {
        return [$this->node, rtrim($this->runnerDir, '/') . '/' . self::BIN_REL, $this->verb, $this->scenarioPath];
    }

    /**
     * @param array<string, string> $vars jen `SHPD_*` a `VIDEO_*`
     * @return array<string, string>
     */
    public static function environment(array $vars, ?string $home = null, string $path = UpdateEnvironment::DEFAULT_PATH): array
    {
        return self::environmentFor($vars, self::ENV_PREFIXES, $home, $path);
    }

    protected function startFailureMessage(): string
    {
        return 'Video-runner nejde spustit: ' . implode(' ', $this->argv());
    }
}
