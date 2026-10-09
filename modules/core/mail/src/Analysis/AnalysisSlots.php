<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\CronProvisioner;

/**
 * Limit souběhu AI analýz per server (tasks/mail-analysis-inprocess.md D15):
 * `ai.analysis.maxConcurrent` v server.json (výchozí 2), slot = neblokující
 * `flock` na `ai-analysis-<n>.lock` v {@see CronProvisioner::RUN_DIR}.
 * Runner bez volného slotu končí bez claimu — zpráva zůstává ve frontě
 * pro sweep. Hodnota 0 = analýza v procesu vypnutá.
 *
 * Do adresáře zapisuje i PHP-FPM worker (spawn z requestu) — vlastní ho
 * shipard-user, pod kterým pool běží. Nezapisovatelný adresář analýzu
 * nesmí vypnout tiše: chyba do logu při každém volání.
 */
final class AnalysisSlots
{
    public const LOCK_PREFIX = 'ai-analysis-';

    public function __construct(
        private readonly int $maxConcurrent,
        private readonly string $runDir = CronProvisioner::RUN_DIR,
    ) {}

    /**
     * Limit ze server.json; nenačitatelný config = výchozí hodnota,
     * neplatná hodnota = výchozí hodnota + chyba v logu (nikdy tiché vypnutí).
     */
    public static function fromServerConfig(?ServerConfig $config, string $runDir = CronProvisioner::RUN_DIR): self
    {
        return new self(self::maxConcurrentOf($config), $runDir);
    }

    public static function maxConcurrentOf(?ServerConfig $config): int
    {
        if ($config === null) {
            try {
                $config = new ServerConfig();
                $config->load();
            } catch (\Throwable) {
                return ServerConfig::DEFAULT_AI_ANALYSIS_MAX_CONCURRENT;
            }
        }
        try {
            return $config->getAiAnalysisMaxConcurrent();
        } catch (\Throwable $e) {
            ErrorLogger::error('AnalysisSlots: invalid ai.analysis.maxConcurrent in server.json — using the default', [
                'error' => $e->getMessage(),
                'default' => ServerConfig::DEFAULT_AI_ANALYSIS_MAX_CONCURRENT,
            ]);
            return ServerConfig::DEFAULT_AI_ANALYSIS_MAX_CONCURRENT;
        }
    }

    public function maxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    public function isDisabled(): bool
    {
        return $this->maxConcurrent <= 0;
    }

    /** První volný slot, nebo null (vše obsazeno, limit 0, adresář nezapisovatelný). */
    public function tryAcquire(): ?AnalysisSlot
    {
        if ($this->isDisabled() || !$this->ensureRunDir()) {
            return null;
        }

        for ($n = 1; $n <= $this->maxConcurrent; $n++) {
            $handle = @fopen($this->lockPath($n), 'c');
            if ($handle === false) {
                ErrorLogger::error('AnalysisSlots: cannot open slot lock file — analysis cannot run', [
                    'path' => $this->lockPath($n),
                ]);
                return null;
            }
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new AnalysisSlot($n, $handle);
            }
            fclose($handle);
        }

        return null;
    }

    /** Kolik slotů je v tuto chvíli volných (sweep: kolik runnerů spustit). */
    public function freeCount(): int
    {
        $held = [];
        while (count($held) < $this->maxConcurrent) {
            $slot = $this->tryAcquire();
            if ($slot === null) {
                break;
            }
            $held[] = $slot;
        }
        $free = count($held);
        foreach ($held as $slot) {
            $slot->release();
        }
        return $free;
    }

    public function lockPath(int $n): string
    {
        return $this->runDir . '/' . self::LOCK_PREFIX . $n . '.lock';
    }

    private function ensureRunDir(): bool
    {
        if (is_dir($this->runDir)) {
            return true;
        }
        if (@mkdir($this->runDir, 0750, true) || is_dir($this->runDir)) {
            return true;
        }
        ErrorLogger::error('AnalysisSlots: cannot create the run directory — analysis cannot run', [
            'runDir' => $this->runDir,
        ]);
        return false;
    }
}
