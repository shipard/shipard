<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalProgressWriter;

/**
 * Průběh `video-run` jako JSON řádky (`--progress-file`, tasks/testing-phase7b.md
 * § 2) — stejný tvar jako `dataset-e2e` ({@see \Shipard\Module\Core\Exchange\Dataset\E2e\E2eProgress}):
 * `started` (pid, verb, scénář, sada, kotva, zdroj dat, adresa aplikace),
 * `step` (`build` / `reseed` / `check` / `record` / `compose` × `started`
 * / `finished` / `failed`, u konce `exitCode`; `build finished` nese
 * `cached`), `finished` (stav, exit, video) a `error`. Dashboard čte
 * události stejným reduktorem jako u proklikání.
 */
final class VideoProgress
{
    public const string STARTED = EvalProgressWriter::STARTED;
    public const string STEP = 'step';
    public const string FINISHED = EvalProgressWriter::FINISHED;
    public const string ERROR = EvalProgressWriter::ERROR;

    public const string STEP_BUILD = 'build';
    public const string STEP_RESEED = 'reseed';
    public const string STEP_CHECK = 'check';
    public const string STEP_RECORD = 'record';
    public const string STEP_COMPOSE = 'compose';

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

    /** @throws VideoException složka souboru neexistuje nebo nejde zapisovat */
    public static function toFile(string $path): self
    {
        try {
            return new self(EvalProgressWriter::toFile($path), $path);
        } catch (EvalException $e) {
            throw new VideoException($e->getMessage(), $e);
        }
    }

    public function enabled(): bool
    {
        return $this->path !== null;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    /** @param array<string, mixed> $data */
    public function started(array $data): void
    {
        $this->writer->event(self::STARTED, $data);
    }

    /** @param array<string, mixed> $extra */
    public function step(string $step, string $status, ?int $exitCode = null, array $extra = []): void
    {
        $data = ['step' => $step, 'status' => $status];
        if ($status !== self::STATUS_STARTED) {
            $data['exitCode'] = $exitCode;
        }
        $this->writer->event(self::STEP, $data + $extra);
    }

    /** @param array<string, mixed>|null $video */
    public function finished(string $status, int $exitCode, ?array $video): void
    {
        $this->writer->event(self::FINISHED, ['status' => $status, 'exitCode' => $exitCode, 'video' => $video]);
    }

    public function error(string $message): void
    {
        $this->writer->event(self::ERROR, ['message' => $message]);
    }
}
