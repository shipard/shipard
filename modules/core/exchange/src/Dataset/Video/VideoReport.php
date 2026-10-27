<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Video;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report `video-run` (tasks/testing-phase7b.md § 2): verb, scénář, sada
 * a kotva, zdroj dat, kroky (sestavení sady — i z mezipaměti —,
 * přeseedování, ověření nebo natáčení, skládání), výsledek runneru
 * (`result.json`: stav, u selhání krok a selektor) a u `build` video
 * (cesta, délka, velikost); text pro člověka a `--json` se stejným
 * obsahem.
 *
 * Nálezy (`problems`, stav `failed`): selhané sestavení sady, selhané
 * přeseedování, runner ukončený signálem nebo s neznámým kódem, chybějící
 * `result.json`, výsledek runneru jiný než `ok` (věta s krokem), `build`
 * bez videa. Zrušení má přednost: stav `cancelled`, exit 130.
 */
final class VideoReport
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';
    public const int EXIT_CANCELLED = 130;

    public const string VERB_CHECK = 'check';
    public const string VERB_BUILD = 'build';

    /** @var list<string> */
    private readonly array $problems;

    /**
     * @param array<string, mixed> $meta verb, scenario (toArray), anchor, fixedTime, dataset {name, cacheDir, cached, sourceHash}, dataSource {id, name}, appUrl
     * @param array<string, array{ran: bool, exitCode: ?int, termSignal: ?int, durationMs: ?int, cached: ?bool}> $steps
     * @param array<string, mixed>|null $result `result.json` runneru
     * @param array<string, mixed>|null $video meta hotového videa (jen `build`)
     */
    private function __construct(
        private readonly array $meta,
        private readonly array $steps,
        private readonly ?array $result,
        private readonly ?array $video,
        private readonly bool $cancelled,
    ) {
        $this->problems = $this->findings();
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, array<string, mixed>> $steps `build` / `reseed` / `check` | `record` / `compose` — chybějící klíče se doplní
     * @param array<string, mixed>|null $result
     * @param array<string, mixed>|null $video
     */
    public static function build(array $meta, array $steps, ?array $result, ?array $video, bool $cancelled): self
    {
        $names = $meta['verb'] === self::VERB_BUILD
            ? [VideoProgress::STEP_BUILD, VideoProgress::STEP_RESEED, VideoProgress::STEP_RECORD, VideoProgress::STEP_COMPOSE]
            : [VideoProgress::STEP_BUILD, VideoProgress::STEP_RESEED, VideoProgress::STEP_CHECK];
        $normalized = [];
        foreach ($names as $name) {
            $step = $steps[$name] ?? [];
            $normalized[$name] = [
                'ran' => (bool) ($step['ran'] ?? false),
                'exitCode' => self::intOrNull($step['exitCode'] ?? null),
                'termSignal' => self::intOrNull($step['termSignal'] ?? null),
                'durationMs' => self::intOrNull($step['durationMs'] ?? null),
                'cached' => $name === VideoProgress::STEP_BUILD ? (bool) ($step['cached'] ?? false) : null,
            ];
        }
        return new self($meta, $normalized, $result, $video, $cancelled);
    }

    public function status(): string
    {
        if ($this->cancelled) {
            return self::STATUS_CANCELLED;
        }
        return $this->problems === [] ? self::STATUS_OK : self::STATUS_FAILED;
    }

    public function exitCode(): int
    {
        return match ($this->status()) {
            self::STATUS_CANCELLED => self::EXIT_CANCELLED,
            self::STATUS_FAILED => 1,
            default => 0,
        };
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }

    public function verb(): string
    {
        return (string) $this->meta['verb'];
    }

    /** @return array<string, mixed>|null */
    public function video(): ?array
    {
        return $this->video;
    }

    /** Krok runneru, na kterém průchod selhal (`{index, verb, value}`); null bez selhání. */
    public function failedStep(): ?array
    {
        $step = $this->result['error']['step'] ?? null;
        return is_array($step) ? $step : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok'         => $this->status() === self::STATUS_OK,
            'status'     => $this->status(),
            'exitCode'   => $this->exitCode(),
            'problems'   => $this->problems,
            'verb'       => $this->meta['verb'],
            'scenario'   => $this->meta['scenario'],
            'anchor'     => $this->meta['anchor'],
            'fixedTime'  => $this->meta['fixedTime'],
            'dataset'    => $this->meta['dataset'],
            'dataSource' => $this->meta['dataSource'],
            'appUrl'     => $this->meta['appUrl'],
            'steps'      => $this->steps,
            'result'     => $this->result,
            'video'      => $this->video,
        ];
    }

    public function writeText(OutputInterface $output): void
    {
        $scenario = $this->meta['scenario'];
        $output->writeln(sprintf(
            '<comment>Scénář %s</comment> (%s) → sada %s, kotva %s, zdroj dat %s',
            $scenario['id'],
            $this->verb(),
            $this->meta['dataset']['name'],
            $this->meta['anchor'],
            $this->meta['dataSource']['id'],
        ));
        $output->writeln('Aplikace: ' . $this->meta['appUrl'] . ', čas v prohlížeči: ' . $this->meta['fixedTime']);
        $output->writeln('Kroky:');
        $labels = [
            VideoProgress::STEP_BUILD => 'sestavení sady',
            VideoProgress::STEP_RESEED => 'přeseedování ',
            VideoProgress::STEP_CHECK => 'ověření      ',
            VideoProgress::STEP_RECORD => 'natáčení     ',
            VideoProgress::STEP_COMPOSE => 'skládání     ',
        ];
        foreach ($this->steps as $name => $step) {
            $text = !$step['ran']
                ? 'neběželo'
                : ($name === VideoProgress::STEP_BUILD && $step['cached'] === true
                    ? 'z mezipaměti'
                    : self::exitText($step['exitCode'], $step['termSignal'], $step['durationMs']));
            $output->writeln('  ' . $labels[$name] . ' … ' . $text);
        }
        $output->writeln('');

        if ($this->result !== null) {
            $status = (string) ($this->result['status'] ?? '?');
            $output->writeln('<comment>Runner:</comment> ' . ($status === self::STATUS_OK ? '<info>ok</info>' : "<error>{$status}</error>"));
            $error = $this->result['error'] ?? null;
            if (is_array($error)) {
                $output->writeln('   ' . self::errorText($error));
            }
        }
        if ($this->video !== null) {
            $output->writeln(sprintf(
                '<comment>Video:</comment> %s (%s, %s)',
                (string) ($this->video['path'] ?? '?'),
                self::durationText(self::intOrNull($this->video['durationMs'] ?? null)),
                self::bytesText(self::intOrNull($this->video['bytes'] ?? null)),
            ));
        }
        $output->writeln('');

        $output->writeln(match ($this->status()) {
            self::STATUS_OK => '<info>Výsledek: v pořádku.</info>',
            self::STATUS_CANCELLED => '<comment>Výsledek: zrušeno.</comment>',
            default => sprintf('<error>Výsledek: nálezy (%d).</error>', count($this->problems)),
        });
        foreach ($this->problems as $problem) {
            $output->writeln('   - ' . $problem);
        }
    }

    // ── Nálezy ─────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function findings(): array
    {
        $problems = [];
        $build = $this->steps[VideoProgress::STEP_BUILD];
        if ($build['ran'] && $build['cached'] !== true && $build['exitCode'] !== 0) {
            $problems[] = 'sestavení demo sady selhalo (build.py ' . self::exitText($build['exitCode'], $build['termSignal'], null) . ')';
        }
        $reseed = $this->steps[VideoProgress::STEP_RESEED];
        if ($reseed['ran'] && $reseed['exitCode'] !== 0) {
            $problems[] = 'přeseedování selhalo (dataset-seed exit ' . ($reseed['exitCode'] ?? 'bez kódu') . ')';
        }
        $runName = $this->verb() === self::VERB_BUILD ? VideoProgress::STEP_RECORD : VideoProgress::STEP_CHECK;
        $run = $this->steps[$runName];
        $runLabel = $runName === VideoProgress::STEP_CHECK ? 'ověření' : 'natáčení';
        if (!$run['ran']) {
            if ($problems === []) {
                $problems[] = "{$runLabel} neběželo";
            }
            return $problems;
        }
        array_push($problems, ...$this->runnerFindings($run, $runLabel));
        if ($this->verb() === self::VERB_BUILD) {
            $compose = $this->steps[VideoProgress::STEP_COMPOSE];
            if (!$compose['ran']) {
                if ($problems === []) {
                    $problems[] = 'skládání neběželo';
                }
            } else {
                array_push($problems, ...$this->runnerFindings($compose, 'skládání'));
            }
            if ($this->video === null && $problems === []) {
                $problems[] = 'video nevzniklo — runner nezapsal složené video';
            }
        }
        return $problems;
    }

    /**
     * @param array{ran: bool, exitCode: ?int, termSignal: ?int, durationMs: ?int, cached: ?bool} $step
     * @return list<string>
     */
    private function runnerFindings(array $step, string $label): array
    {
        $problems = [];
        if ($step['termSignal'] !== null) {
            $problems[] = "{$label}: runner skončil signálem {$step['termSignal']}";
        } elseif ($step['exitCode'] !== 0 && $step['exitCode'] !== 1) {
            $problems[] = "{$label}: runner skončil s kódem " . ($step['exitCode'] ?? 'bez kódu');
        }
        if ($this->result === null) {
            if ($problems === []) {
                $problems[] = "{$label}: runner nezapsal result.json";
            }
            return $problems;
        }
        $status = $this->result['status'] ?? null;
        if ($status !== self::STATUS_OK) {
            $error = $this->result['error'] ?? null;
            $problems[] = "{$label}: " . (is_array($error) ? self::errorText($error) : 'runner hlásí stav ' . (is_scalar($status) ? (string) $status : '?'));
        }
        return $problems;
    }

    // ── Texty ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $error */
    private static function errorText(array $error): string
    {
        $text = strtok(trim((string) ($error['message'] ?? 'bez zprávy')), "\n") ?: 'bez zprávy';
        $step = $error['step'] ?? null;
        if (is_array($step)) {
            $text .= sprintf(' [krok #%s %s %s]', (string) ($step['index'] ?? '?'), (string) ($step['verb'] ?? '?'), (string) ($step['value'] ?? ''));
        }
        if (is_string($error['hint'] ?? null) && trim($error['hint']) !== '') {
            $text .= ' — ' . trim($error['hint']);
        }
        return $text;
    }

    private static function exitText(?int $exitCode, ?int $termSignal, ?int $durationMs): string
    {
        $text = $termSignal !== null ? "signál {$termSignal}" : 'exit ' . ($exitCode ?? '?');
        if ($durationMs !== null) {
            $text .= sprintf(' (%.1f s)', $durationMs / 1000);
        }
        return $text;
    }

    public static function durationText(?int $durationMs): string
    {
        if ($durationMs === null) {
            return 'délka ?';
        }
        $seconds = intdiv($durationMs, 1000);
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    public static function bytesText(?int $bytes): string
    {
        if ($bytes === null) {
            return 'velikost ?';
        }
        if ($bytes >= 1_048_576) {
            return sprintf('%.1f MiB', $bytes / 1_048_576);
        }
        return sprintf('%.0f KiB', $bytes / 1024);
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) || is_float($value) ? (int) $value : null;
    }
}
