<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalProgressWriter;

/**
 * Průběh `dataset-e2e` jako JSON řádky (`--progress-file`, tasks/testing-phase7a.md
 * § 3, I10) — stejný tvar a zápis jako `ai-eval` ({@see EvalProgressWriter}:
 * `event`, `at` UTC, `FILE_APPEND | LOCK_EX`, selhání zápisu průběh tiše
 * vynechá). Události PHP: `started` (pid, sada, zdroj dat, adresa aplikace,
 * adresář běhu, `expected` null — počet karet je znám až po přeseedování
 * a nese ho `step walkthrough started`), `step` (`reseed` / `walkthrough`
 * / `verify` × `started` / `finished` / `failed`, u konce `exitCode`),
 * `finished` (stav, použité / selhané karty, exit) a `error`. Události
 * `card` píše **spec** sám do téhož souboru (`SHPD_E2E_PROGRESS_FILE`).
 */
final class E2eProgress
{
    public const string STARTED = EvalProgressWriter::STARTED;
    public const string STEP = 'step';
    public const string CARD = 'card';
    public const string FINISHED = EvalProgressWriter::FINISHED;
    public const string ERROR = EvalProgressWriter::ERROR;

    public const string STEP_RESEED = 'reseed';
    public const string STEP_WALKTHROUGH = 'walkthrough';
    public const string STEP_VERIFY = 'verify';

    public const string STATUS_STARTED = 'started';
    public const string STATUS_FINISHED = 'finished';
    public const string STATUS_FAILED = 'failed';

    private function __construct(
        private readonly EvalProgressWriter $writer,
        private readonly ?string $path,
    ) {}

    public static function none(): self
    {
        return new self(EvalProgressWriter::none(), null);
    }

    /** @throws E2eException složka souboru neexistuje nebo nejde zapisovat */
    public static function toFile(string $path): self
    {
        try {
            return new self(EvalProgressWriter::toFile($path), $path);
        } catch (EvalException $e) {
            throw new E2eException($e->getMessage(), $e);
        }
    }

    public function enabled(): bool
    {
        return $this->path !== null;
    }

    /** Cesta souboru — předává se specu jako `SHPD_E2E_PROGRESS_FILE`; null bez průběhu. */
    public function path(): ?string
    {
        return $this->path;
    }

    /** @param array{pid: ?int, dataset: string, dataSource: string, appUrl: string, outputDir: string, expected: ?int} $data */
    public function started(array $data): void
    {
        $this->writer->event(self::STARTED, $data);
    }

    /** @param array<string, mixed> $extra další pole události (např. `expected` u začátku proklikání) */
    public function step(string $step, string $status, ?int $exitCode = null, array $extra = []): void
    {
        $data = ['step' => $step, 'status' => $status];
        if ($status !== self::STATUS_STARTED) {
            $data['exitCode'] = $exitCode;
        }
        $this->writer->event(self::STEP, $data + $extra);
    }

    public function finished(string $status, int $applied, int $failed, int $exitCode): void
    {
        $this->writer->event(self::FINISHED, [
            'status' => $status,
            'applied' => $applied,
            'failed' => $failed,
            'exitCode' => $exitCode,
        ]);
    }

    public function error(string $message): void
    {
        $this->writer->event(self::ERROR, ['message' => $message]);
    }
}
