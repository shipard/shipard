<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Config\ServerConfigSection;

/**
 * Sekce `ai.analysis` v `/etc/shipard/server.json`
 * (tasks/mail-analysis-inprocess.md D15; sekce modulu #172 D10) — limit
 * souběhu AI analýz pošty per server `ai.analysis.maxConcurrent`:
 *
 *  - sekce nebo klíč chybí → {@see self::DEFAULT_MAX_CONCURRENT};
 *  - `0` → analýza pošty na tomto serveru vypnutá (vypínač, žádný runner
 *    se nespustí);
 *  - záporná nebo nečíselná hodnota → výjimka.
 *
 * Čte ji {@see AnalysisSlots::maxConcurrentOf()}, která chybnou hodnotu
 * zaloguje a běží s výchozí.
 */
final readonly class MailAnalysisServerConfig implements ServerConfigSection
{
    public const DEFAULT_MAX_CONCURRENT = 2;

    private function __construct(private int $maxConcurrent)
    {
    }

    public static function key(): string
    {
        return 'ai.analysis';
    }

    public static function fromServerConfig(ServerConfig $config): static
    {
        $section = $config->getSection(self::key());
        $value = is_array($section) ? ($section['maxConcurrent'] ?? null) : null;
        if ($value === null) {
            return new self(self::DEFAULT_MAX_CONCURRENT);
        }
        if (!is_int($value) || $value < 0) {
            throw new \RuntimeException("Server config 'ai.analysis.maxConcurrent' must be a non-negative integer");
        }
        return new self($value);
    }

    /** Limit souběhu; `0` = analýza pošty vypnutá. */
    public function maxConcurrent(): int
    {
        return $this->maxConcurrent;
    }
}
