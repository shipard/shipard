<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Průběh běhu `ai-eval` jako JSON řádky (`--progress-file`, tasks/testing-phase6b.md
 * § 5, D61) pro úlohu vývojářského dashboardu: `started` (počet volání,
 * odhad, strop), `call` (zpráva, opakování, stav, cena, útrata, hotovo /
 * z kolika), `finished` (soubor výsledků, útrata, `stoppedBy`) nebo `error`.
 * Každý řádek nese `event` a `at` (UTC). Zápis `FILE_APPEND | LOCK_EX`;
 * selhání zápisu průběh tiše vynechá — průběh je informace, ne výsledek.
 * Bez cesty ({@see none()}) nic nedělá.
 */
final class EvalProgressWriter
{
    public const string STARTED = 'started';
    public const string CALL = 'call';
    public const string FINISHED = 'finished';
    public const string ERROR = 'error';

    private function __construct(private readonly ?string $path)
    {
    }

    public static function none(): self
    {
        return new self(null);
    }

    /** @throws EvalException složka souboru neexistuje nebo nejde zapisovat */
    public static function toFile(string $path): self
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir)) {
            throw new EvalException("--progress-file: složka {$dir} neexistuje nebo nejde zapisovat");
        }
        if (is_file($path) && !is_writable($path)) {
            throw new EvalException("--progress-file: {$path} nejde zapisovat");
        }
        return new self($path);
    }

    public function enabled(): bool
    {
        return $this->path !== null;
    }

    /** @param array<string, mixed> $data */
    public function event(string $type, array $data = []): void
    {
        if ($this->path === null) {
            return;
        }
        $line = json_encode(
            ['event' => $type, 'at' => gmdate('Y-m-d\TH:i:s\Z')] + $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
        if ($line === false) {
            return;
        }
        @file_put_contents($this->path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
}
