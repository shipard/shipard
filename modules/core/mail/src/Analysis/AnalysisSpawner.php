<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Cli\BinPaths;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Process\DetachedProcess;

/**
 * Detached spuštění runneru `shpd-ds mail-analyze --message <id>`
 * (tasks/mail-analysis-inprocess.md D14) — po commitu příjmu nebo nahrání,
 * na konci běhu předzpracování, po reanalýze a ze sweepu. Fire-and-forget
 * po vzoru {@see \Shipard\Module\Core\Mail\Preprocess\PreprocessSpawner}:
 * stdout/stderr potomka do `analysis.log` vedle serverového logu, selhání
 * spawnu se jen zaloguje — zprávu ve frontě dohledá `mail-analyze --sweep`.
 * Při `ai.analysis.maxConcurrent = 0` nespouští nic (server s démonem).
 * Spawn předává jen id zprávy — klíč backendu si runner dešifruje sám.
 */
final class AnalysisSpawner
{
    /**
     * @param list<string>|null $command Argv prefix pro shpd-ds (test seam);
     *        null = BinPaths::shpdDsCommand().
     * @param int|null $maxConcurrent Limit ze server.json; null = načíst.
     */
    public function __construct(
        private readonly string $dsPath,
        private readonly ?array $command = null,
        private readonly ?string $logFile = null,
        private readonly ?int $maxConcurrent = null,
    ) {
    }

    public function spawn(int $messageId): bool
    {
        if (($this->maxConcurrent ?? AnalysisSlots::maxConcurrentOf(null)) === 0) {
            return false;
        }

        $argv = [...($this->command ?? BinPaths::shpdDsCommand()), 'mail-analyze', '--message', (string) $messageId];

        $ok = DetachedProcess::spawn($argv, $this->dsPath, $this->logFile ?? self::defaultLogFile());
        if (!$ok) {
            ErrorLogger::warn('Analysis runner spawn failed — message stays queued for the sweep', [
                'message' => $messageId,
                'argv' => $argv,
            ]);
        }

        return $ok;
    }

    public static function defaultLogFile(): ?string
    {
        try {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
            $dir = dirname($serverConfig->getLogFile());
            return is_dir($dir) && is_writable($dir) ? $dir . '/analysis.log' : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
