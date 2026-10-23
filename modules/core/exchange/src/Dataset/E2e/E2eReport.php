<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\E2e;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Report `dataset-e2e` (tasks/testing-phase7a.md § 3, D95, I11): souhrn
 * tří kroků (přeseedování, proklikání, `dataset-verify --no-apply`), karty
 * použité / selhané z `walkthrough.json` specu, chyby prohlížeče, odpovědi
 * API ≥ 400 a JSON `dataset-verify` beze změny tvaru; text pro člověka
 * a `--json` se stejným obsahem.
 *
 * Nálezy (`problems`, stav `failed`): selhaná karta, chyba prohlížeče
 * (`pageerror`, `console.error`), odpověď API 5xx (4xx se jen zapisují),
 * kontrola `dataset-verify` se stavem jiným než `ok` (i `skipped` — D95
 * doslova), nesoulad počtu použitých karet s očekávaným, karta zbylá na
 * dashboardu, neúplný nebo chybějící `walkthrough.json`, selhání
 * přeseedování, Playwright ukončený signálem nebo s neznámým kódem,
 * odmítnutý nebo neproběhlý `dataset-verify`. Zrušení (SIGTERM) má přednost
 * před vším: stav `cancelled`, exit 130.
 */
final class E2eReport
{
    public const string STATUS_OK = 'ok';
    public const string STATUS_FAILED = 'failed';
    public const string STATUS_CANCELLED = 'cancelled';
    public const int EXIT_CANCELLED = 130;

    public const string WALKTHROUGH_FILE = 'walkthrough.json';
    public const string PLAYWRIGHT_REPORT_FILE = 'report.json';
    public const string HTML_REPORT_DIR = 'playwright-report';
    public const string TRACES_DIR = 'traces';

    /** @var list<string> */
    private readonly array $problems;

    /**
     * @param array{dataset: array{name: string, profile: ?string, country: ?string}, dataSource: string, appUrl: string, outputDir: string, expected: ?int} $meta
     * @param array<string, mixed>|null $walkthrough obsah `walkthrough.json`
     * @param array<string, mixed>|null $playwright obsah `report.json` (JSON reportér Playwrightu)
     * @param array<string, mixed>|null $verify JSON `dataset-verify`
     * @param array{reseed: array{ran: bool, exitCode: ?int, durationMs: ?int}, walkthrough: array{ran: bool, exitCode: ?int, termSignal: ?int, durationMs: ?int}, verify: array{ran: bool, exitCode: ?int, refused: ?string}} $steps
     */
    private function __construct(
        private readonly array $meta,
        private readonly ?array $walkthrough,
        private readonly ?array $playwright,
        private readonly ?array $verify,
        private readonly array $steps,
        private readonly bool $cancelled,
    ) {
        $this->problems = $this->findings();
    }

    /**
     * @param array{dataset: array{name: string, profile: ?string, country: ?string}, dataSource: string, appUrl: string, outputDir: string, expected: ?int} $meta
     * @param array<string, mixed>|null $walkthrough
     * @param array<string, mixed>|null $playwright
     * @param array<string, mixed>|null $verify
     * @param ?string $verifyRefusal text odmítnutí `dataset-verify` (exit 2 / jiný výstup než JSON)
     * @param array<string, array<string, mixed>> $steps `reseed` / `walkthrough` / `verify` — chybějící klíče se doplní
     */
    public static function build(
        array $meta,
        ?array $walkthrough,
        ?array $playwright,
        ?array $verify,
        ?string $verifyRefusal,
        array $steps,
        bool $cancelled,
    ): self {
        $reseed = $steps['reseed'] ?? [];
        $walk = $steps['walkthrough'] ?? [];
        $ver = $steps['verify'] ?? [];
        $normalized = [
            'reseed' => [
                'ran' => (bool) ($reseed['ran'] ?? false),
                'exitCode' => self::intOrNull($reseed['exitCode'] ?? null),
                'durationMs' => self::intOrNull($reseed['durationMs'] ?? null),
            ],
            'walkthrough' => [
                'ran' => (bool) ($walk['ran'] ?? false),
                'exitCode' => self::intOrNull($walk['exitCode'] ?? null),
                'termSignal' => self::intOrNull($walk['termSignal'] ?? null),
                'durationMs' => self::intOrNull($walk['durationMs'] ?? null),
            ],
            'verify' => [
                'ran' => (bool) ($ver['ran'] ?? false),
                'exitCode' => self::intOrNull($ver['exitCode'] ?? null),
                'refused' => $verifyRefusal !== null && trim($verifyRefusal) !== '' ? trim($verifyRefusal) : null,
            ],
        ];
        return new self($meta, $walkthrough, $playwright, $verify, $normalized, $cancelled);
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

    public function expected(): ?int
    {
        $fromWalkthrough = self::intOrNull($this->walkthrough['expected'] ?? null);
        return $fromWalkthrough ?? self::intOrNull($this->meta['expected'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function applied(): array
    {
        return self::listOf($this->walkthrough['applied'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function failed(): array
    {
        return self::listOf($this->walkthrough['failed'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function browserErrors(): array
    {
        return self::listOf($this->walkthrough['browserErrors'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function apiResponses(): array
    {
        return self::listOf($this->walkthrough['apiResponses'] ?? null);
    }

    /**
     * Cesty k výstupům běhu; `traces[].file` je **basename** v `traces/`
     * (klíč pro stahování z dashboardu — endpoint vydá jen soubor, který
     * report jmenuje), `path` absolutní cesta.
     *
     * @return array{htmlReport: ?string, playwrightReport: ?string, walkthrough: ?string, traces: list<array{cardId: ?string, file: string, path: string}>}
     */
    public function artifacts(): array
    {
        $out = rtrim((string) $this->meta['outputDir'], '/');
        $html = $out . '/' . self::HTML_REPORT_DIR . '/index.html';
        $report = $out . '/' . self::PLAYWRIGHT_REPORT_FILE;
        $walkthrough = $out . '/' . self::WALKTHROUGH_FILE;
        $traces = [];
        foreach ($this->failed() as $failure) {
            $trace = $failure['trace'] ?? null;
            if (!is_string($trace) || $trace === '' || basename($trace) !== $trace) {
                continue;
            }
            $file = $out . '/' . self::TRACES_DIR . '/' . $trace;
            if (is_file($file)) {
                $traces[] = ['cardId' => is_string($failure['cardId'] ?? null) ? $failure['cardId'] : null, 'file' => $trace, 'path' => $file];
            }
        }
        return [
            'htmlReport' => is_file($html) ? $html : null,
            'playwrightReport' => is_file($report) ? $report : null,
            'walkthrough' => is_file($walkthrough) ? $walkthrough : null,
            'traces' => $traces,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status'        => $this->status(),
            'exitCode'      => $this->exitCode(),
            'problems'      => $this->problems,
            'dataset'       => $this->meta['dataset'],
            'dataSource'    => $this->meta['dataSource'],
            'appUrl'        => $this->meta['appUrl'],
            'outputDir'     => $this->meta['outputDir'],
            'steps'         => $this->steps,
            'cards'         => [
                'expected' => $this->expected(),
                'applied'  => $this->applied(),
                'failed'   => $this->failed(),
            ],
            'browserErrors' => $this->browserErrors(),
            'apiResponses'  => $this->apiResponses(),
            'verify'        => $this->verify,
            'artifacts'     => $this->artifacts(),
        ];
    }

    public function writeText(OutputInterface $output): void
    {
        $dataset = $this->meta['dataset'];
        $output->writeln(sprintf(
            '<comment>Sada %s</comment> (profil %s, země %s) → zdroj dat %s',
            $dataset['name'],
            $dataset['profile'] ?? '—',
            $dataset['country'] ?? '—',
            $this->meta['dataSource'],
        ));
        $output->writeln('Aplikace: ' . $this->meta['appUrl']);
        $output->writeln('Adresář běhu: ' . $this->meta['outputDir']);

        $reseed = $this->steps['reseed'];
        $walk = $this->steps['walkthrough'];
        $ver = $this->steps['verify'];
        $output->writeln('Kroky:');
        $output->writeln('  přeseedování … ' . ($reseed['ran'] ? self::exitText($reseed['exitCode'], null, $reseed['durationMs']) : 'vynecháno (--no-reseed)'));
        $output->writeln('  proklikání   … ' . ($walk['ran'] ? self::exitText($walk['exitCode'], $walk['termSignal'], $walk['durationMs']) : 'neběželo'));
        $output->writeln('  ověření      … ' . ($ver['refused'] !== null ? 'odmítnuto' : ($ver['ran'] ? self::exitText($ver['exitCode'], null, null) : 'neběželo')));
        $output->writeln('');

        $expected = $this->expected();
        $output->writeln(sprintf(
            '<comment>Karty:</comment> použito %d z %s očekávaných, selhalo %d',
            count($this->applied()),
            $expected === null ? '?' : (string) $expected,
            count($this->failed()),
        ));
        foreach ($this->failed() as $failure) {
            $output->writeln('   <error>' . self::failureText($failure) . '</error>'
                . (is_string($failure['trace'] ?? null) ? ' [trace ' . $failure['trace'] . ']' : ''));
        }
        $errors = $this->browserErrors();
        $output->writeln(sprintf('<comment>Chyby prohlížeče:</comment> %d', count($errors)));
        foreach ($errors as $error) {
            $output->writeln('   - ' . self::browserErrorText($error));
        }
        $responses = $this->apiResponses();
        $serverErrors = array_values(array_filter($responses, static fn (array $r): bool => (int) ($r['status'] ?? 0) >= 500));
        $output->writeln(sprintf('<comment>Odpovědi API ≥ 400:</comment> %d (5xx: %d)', count($responses), count($serverErrors)));
        foreach ($responses as $response) {
            $output->writeln('   - ' . self::responseText($response));
        }
        $output->writeln('');

        if ($ver['refused'] !== null) {
            $output->writeln('<comment>dataset-verify:</comment> <error>odmítnuto</error>');
            foreach (explode("\n", $ver['refused']) as $line) {
                $output->writeln('   ' . $line);
            }
        } elseif ($this->verify === null) {
            $output->writeln('<comment>dataset-verify:</comment> neběžel');
        } else {
            $summary = is_array($this->verify['summary'] ?? null) ? $this->verify['summary'] : [];
            $output->writeln(sprintf(
                '<comment>dataset-verify:</comment> ok %d, rozdíl %d, přeskočeno %d',
                (int) ($summary['ok'] ?? 0),
                (int) ($summary['diff'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
            ));
            foreach (self::listOf($this->verify['checks'] ?? null) as $n => $check) {
                $status = (string) ($check['status'] ?? '?');
                $label = match ($status) {
                    'ok' => '<info>ok</info>',
                    'diff' => '<error>rozdíl</error> (' . self::countOf($check['differences'] ?? null) . ')',
                    'skipped' => '<error>přeskočeno</error> — ' . (string) ($check['reason'] ?? ''),
                    default => $status,
                };
                $output->writeln(sprintf('   %d. %s … %s', $n + 1, (string) ($check['label'] ?? $check['id'] ?? '?'), $label));
                foreach (is_array($check['differences'] ?? null) ? $check['differences'] : [] as $line) {
                    $output->writeln('      - ' . (is_scalar($line) ? (string) $line : json_encode($line, JSON_UNESCAPED_UNICODE)));
                }
            }
        }
        $output->writeln('');

        $artifacts = $this->artifacts();
        $output->writeln('<comment>Artefakty:</comment>');
        $output->writeln('   HTML report:      ' . ($artifacts['htmlReport'] ?? '—'));
        $output->writeln('   report.json:      ' . ($artifacts['playwrightReport'] ?? '—'));
        $output->writeln('   walkthrough.json: ' . ($artifacts['walkthrough'] ?? '—'));
        foreach ($artifacts['traces'] as $trace) {
            $output->writeln('   trace ' . ($trace['cardId'] ?? '?') . ': ' . $trace['path']);
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

        $reseed = $this->steps['reseed'];
        if ($reseed['ran'] && $reseed['exitCode'] !== 0) {
            $problems[] = 'přeseedování selhalo (dataset-seed exit ' . ($reseed['exitCode'] ?? 'bez kódu') . ')';
        }

        $walk = $this->steps['walkthrough'];
        if (!$walk['ran']) {
            $problems[] = 'proklikání neběželo';
        } else {
            if ($walk['termSignal'] !== null) {
                $problems[] = "Playwright skončil signálem {$walk['termSignal']}";
            } elseif ($walk['exitCode'] !== 0 && $walk['exitCode'] !== 1) {
                $problems[] = 'Playwright skončil s kódem ' . ($walk['exitCode'] ?? 'bez kódu');
            }
            $before = count($problems);
            if ($this->walkthrough === null) {
                $problems[] = self::WALKTHROUGH_FILE . ' chybí — Playwright nedoběhl do zápisu průchodu';
            } else {
                array_push($problems, ...$this->walkthroughFindings());
            }
            array_push($problems, ...$this->playwrightFindings(count($problems) > $before));
        }

        array_push($problems, ...$this->verifyFindings());
        return $problems;
    }

    /** @return list<string> */
    private function walkthroughFindings(): array
    {
        $problems = [];
        $w = $this->walkthrough ?? [];
        if (!(bool) ($w['complete'] ?? false)) {
            $problems[] = 'proklikání neúplné (' . self::WALKTHROUGH_FILE . ': complete = false)';
        }
        $expected = $this->expected();
        $applied = count($this->applied());
        if ($expected !== null && $applied !== $expected) {
            $problems[] = "použito {$applied} karet z {$expected} očekávaných";
        }
        $remaining = self::intOrNull($w['remainingCards'] ?? null);
        if ($remaining !== null && $remaining > 0) {
            $problems[] = "na dashboardu zbylo {$remaining} karet návrhů";
        }
        foreach ($this->failed() as $failure) {
            $problems[] = self::failureText($failure);
        }
        foreach ($this->browserErrors() as $error) {
            $problems[] = 'prohlížeč: ' . self::browserErrorText($error);
        }
        foreach ($this->apiResponses() as $response) {
            if ((int) ($response['status'] ?? 0) >= 500) {
                $problems[] = 'API 5xx: ' . self::responseText($response);
            }
        }
        return $problems;
    }

    /**
     * Chyby Playwrightu mimo spec (konfigurace, sběr) a neúspěšný test,
     * který už nevysvětlil žádný nález z průchodu.
     *
     * @return list<string>
     */
    private function playwrightFindings(bool $explained): array
    {
        $problems = [];
        if ($this->playwright === null) {
            return $problems;
        }
        foreach (self::listOf($this->playwright['errors'] ?? null) as $error) {
            $message = is_array($error) ? ($error['message'] ?? null) : $error;
            if (is_string($message) && trim($message) !== '') {
                $problems[] = 'Playwright: ' . strtok(trim($message), "\n");
            }
        }
        $unexpected = self::intOrNull($this->playwright['stats']['unexpected'] ?? null) ?? 0;
        if ($unexpected > 0 && !$explained && $problems === []) {
            $problems[] = "Playwright hlásí {$unexpected} neúspěšný test bez nálezu v průchodu — viz " . self::PLAYWRIGHT_REPORT_FILE;
        }
        return $problems;
    }

    /** @return list<string> */
    private function verifyFindings(): array
    {
        $ver = $this->steps['verify'];
        if ($ver['refused'] !== null) {
            $text = (string) preg_replace('/^dataset-verify odmítnuto:\s*/u', '', $ver['refused']);
            $lines = array_values(array_filter(array_map(static fn (string $l): string => ltrim(trim($l), "- \t"), explode("\n", $text)), static fn (string $l): bool => $l !== ''));
            return ['dataset-verify odmítnuto: ' . ($lines === [] ? '(bez důvodu)' : implode('; ', $lines))];
        }
        if (!$ver['ran'] || $this->verify === null) {
            return ['dataset-verify neběžel'];
        }
        $problems = [];
        foreach (self::listOf($this->verify['apply']['failed'] ?? null) as $failure) {
            $problems[] = 'dataset-verify apply selhalo: ' . (string) ($failure['messageId'] ?? '?') . ' ' . (string) ($failure['code'] ?? '');
        }
        foreach (self::listOf($this->verify['checks'] ?? null) as $check) {
            $status = (string) ($check['status'] ?? '?');
            if ($status === 'ok') {
                continue;
            }
            $label = (string) ($check['label'] ?? $check['id'] ?? '?');
            $problems[] = match ($status) {
                'diff' => "kontrola {$label}: rozdíl (" . self::countOf($check['differences'] ?? null) . ')',
                'skipped' => "kontrola {$label}: přeskočeno — " . (string) ($check['reason'] ?? ''),
                default => "kontrola {$label}: {$status}",
            };
        }
        if ($problems === [] && ($ver['exitCode'] ?? 0) !== 0) {
            $problems[] = 'dataset-verify skončil s kódem ' . ($ver['exitCode'] ?? 'bez kódu');
        }
        return $problems;
    }

    // ── Texty ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $failure */
    private static function failureText(array $failure): string
    {
        $text = 'karta ' . (string) ($failure['cardId'] ?? '?') . ': ' . (string) ($failure['step'] ?? '?') . '/' . (string) ($failure['reason'] ?? '?');
        if (is_string($failure['path'] ?? null) && $failure['path'] !== '') {
            $text .= ' (' . $failure['path'] . ')';
        }
        if (is_string($failure['message'] ?? null) && trim($failure['message']) !== '') {
            $text .= ' — ' . strtok(trim($failure['message']), "\n");
        }
        return $text;
    }

    /** @param array<string, mixed> $error */
    private static function browserErrorText(array $error): string
    {
        return '[' . (string) ($error['cardId'] ?? '-') . '] ' . (string) ($error['type'] ?? '?') . ': ' . strtok((string) ($error['message'] ?? ''), "\n");
    }

    /** @param array<string, mixed> $response */
    private static function responseText(array $response): string
    {
        return '[' . (string) ($response['cardId'] ?? '-') . '] ' . (string) ($response['status'] ?? '?') . ' ' . (string) ($response['method'] ?? '') . ' ' . (string) ($response['url'] ?? '');
    }

    private static function exitText(?int $exitCode, ?int $termSignal, ?int $durationMs): string
    {
        $text = $termSignal !== null ? "signál {$termSignal}" : 'exit ' . ($exitCode ?? '?');
        if ($durationMs !== null) {
            $text .= sprintf(' (%.1f s)', $durationMs / 1000);
        }
        return $text;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) || is_float($value) ? (int) $value : null;
    }

    private static function countOf(mixed $value): int
    {
        return is_array($value) ? count($value) : 0;
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, is_array(...)));
    }
}
