<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Module\Core\Exchange\Dataset\E2e\CancellableProcess;

/**
 * Podproces generátoru demo sady (`python3 demo/datasets/build.py --story
 * <sada> --anchor <kotva> --out <adresář> --state <soubor>`, #131 D7,
 * tasks/testing-phase7b.md D120 / D128) s cwd v kořeni checkoutu. Zrušení
 * = SIGTERM (python skončí hned, rozestavěný adresář bez značky se příště
 * přestaví), po grace SIGKILL. Prostředí: `GOTENBERG_URL` ze `server.json`
 * (fallback v `render.py` se nikdy nepoužije) a `PYTHONUTF8=1`, protože
 * pevné prostředí nese `LC_ALL=C`.
 */
class DatasetBuildProcess extends CancellableProcess
{
    public const array ENV_PREFIXES = ['GOTENBERG_', 'PYTHON'];

    /**
     * @param list<string> $argv
     * @param \Closure(): bool $shouldCancel
     */
    public function __construct(
        private readonly array $argv,
        string $cwd,
        \Closure $shouldCancel,
        float $graceSeconds = 10.0,
        int $pollMicroseconds = 200_000,
    ) {
        parent::__construct($cwd, $shouldCancel, $graceSeconds, $pollMicroseconds, self::SIGTERM);
    }

    /** @return list<string> */
    public function argv(): array
    {
        return $this->argv;
    }

    /** @return array<string, string> */
    public static function environment(string $gotenbergUrl, ?string $home = null, string $path = UpdateEnvironment::DEFAULT_PATH): array
    {
        return self::environmentFor(['GOTENBERG_URL' => $gotenbergUrl, 'PYTHONUTF8' => '1'], self::ENV_PREFIXES, $home, $path);
    }

    protected function startFailureMessage(): string
    {
        return 'Generátor demo sady nejde spustit: ' . implode(' ', $this->argv());
    }
}
