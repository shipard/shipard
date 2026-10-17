<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

use Shipard\Core\Process\CapturedProcess;

/**
 * Tenký wrapper nad gitem checkoutu pro kontrolu aktualizací: argv bez
 * shellu, prostředí z {@see UpdateEnvironment}, timeout. Dotazy jsou
 * lokální (10 s) kromě {@see fetch()} (30 s). Nikdy `git pull`, nikdy
 * `fetch origin` — jen pevná HTTPS URL z {@see Upstream}.
 */
final class Git
{
    /** @var array<string, string> */
    private readonly array $env;
    private readonly string $binary;

    /** @param array<string, string>|null $env výchozí {@see UpdateEnvironment::env()} */
    public function __construct(
        private readonly string $root,
        ?array $env = null,
        ?string $binary = null,
    ) {
        $this->env = $env ?? UpdateEnvironment::env();
        $this->binary = $binary ?? UpdateEnvironment::findTool('git', $this->env['PATH'] ?? UpdateEnvironment::DEFAULT_PATH) ?? 'git';
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return array<string, string> */
    public function env(): array
    {
        return $this->env;
    }

    public function binary(): string
    {
        return $this->binary;
    }

    /**
     * @param list<string> $args
     * @return array{exitCode: ?int, stdout: string, stderr: string, timedOut: bool}
     */
    public function run(array $args, float $timeoutSeconds = UpdateEnvironment::LOCAL_TIMEOUT_SECONDS): array
    {
        $r = CapturedProcess::run([$this->binary, '-C', $this->root, ...$args], null, $timeoutSeconds, env: $this->env);
        return [
            'exitCode' => $r['exitCode'],
            'stdout' => $r['stdout'],
            'stderr' => $r['stderr'],
            'timedOut' => $r['timedOut'],
        ];
    }

    /**
     * První řádek stdout úspěšného příkazu, jinak null.
     *
     * @param list<string> $args
     */
    public function line(array $args, float $timeoutSeconds = UpdateEnvironment::LOCAL_TIMEOUT_SECONDS): ?string
    {
        $r = $this->run($args, $timeoutSeconds);
        if ($r['exitCode'] !== 0) {
            return null;
        }
        $line = strtok($r['stdout'], "\n");
        return $line === false ? '' : trim($line);
    }

    /**
     * Všechny řádky stdout úspěšného příkazu (bez prázdných), jinak null.
     *
     * @param list<string> $args
     * @return list<string>|null
     */
    public function lines(array $args, float $timeoutSeconds = UpdateEnvironment::LOCAL_TIMEOUT_SECONDS): ?array
    {
        $r = $this->run($args, $timeoutSeconds);
        if ($r['exitCode'] !== 0) {
            return null;
        }
        $lines = [];
        foreach (explode("\n", $r['stdout']) as $line) {
            $line = rtrim($line, "\r");
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    public function isRepository(): bool
    {
        return $this->line(['rev-parse', '--is-inside-work-tree']) === 'true';
    }

    /** Fetch URL `origin`; null = bez origin. */
    public function originUrl(): ?string
    {
        $url = $this->line(['remote', 'get-url', 'origin']);
        return $url === null || $url === '' ? null : $url;
    }

    public function headHash(): ?string
    {
        $hash = $this->line(['rev-parse', 'HEAD']);
        return $hash !== null && preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? $hash : null;
    }

    /** Název větve; null = odpojená HEAD nebo chyba. */
    public function branch(): ?string
    {
        $branch = $this->line(['rev-parse', '--abbrev-ref', 'HEAD']);
        return $branch === null || $branch === '' || $branch === 'HEAD' ? null : $branch;
    }

    /** Plný hash reference; null = neexistuje. */
    public function refHash(string $ref): ?string
    {
        $hash = $this->line(['rev-parse', '--verify', '--quiet', $ref . '^{commit}']);
        return $hash !== null && preg_match('/^[0-9a-f]{40}$/', $hash) === 1 ? $hash : null;
    }

    /** Necommitnuté změny sledovaných souborů (`--untracked-files=no`, U6); null = git selhal. */
    public function isDirty(): ?bool
    {
        $r = $this->run(['status', '--porcelain', '--untracked-files=no']);
        return $r['exitCode'] === 0 ? trim($r['stdout']) !== '' : null;
    }

    public function revListCount(string $range): ?int
    {
        $count = $this->line(['rev-list', '--count', $range]);
        return $count !== null && ctype_digit($count) ? (int) $count : null;
    }

    /**
     * Commity rozsahu, nejnovější první: krátký hash, plný hash, předmět, datum commitu (ISO 8601).
     *
     * @return list<array{hash: string, fullHash: string, subject: string, date: string}>|null
     */
    public function log(string $range, int $limit): ?array
    {
        $lines = $this->lines(['log', '--format=%h%x09%H%x09%cI%x09%s', '-n', (string) $limit, $range]);
        if ($lines === null) {
            return null;
        }
        $commits = [];
        foreach ($lines as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) !== 4) {
                continue;
            }
            $commits[] = ['hash' => $parts[0], 'fullHash' => $parts[1], 'date' => $parts[2], 'subject' => $parts[3]];
        }
        return $commits;
    }

    /**
     * Data commitů rozsahu (ISO 8601) — pro nejstarší chybějící změnu.
     *
     * @return list<string>|null
     */
    public function commitDates(string $range): ?array
    {
        return $this->lines(['log', '--format=%cI', $range]);
    }

    /**
     * `git fetch --quiet --no-tags <url> <refspec>` s timeoutem fetche.
     *
     * @return array{exitCode: ?int, stdout: string, stderr: string, timedOut: bool}
     */
    public function fetch(string $url, string $refspec): array
    {
        return $this->run(['fetch', '--quiet', '--no-tags', $url, $refspec], UpdateEnvironment::FETCH_TIMEOUT_SECONDS);
    }
}
