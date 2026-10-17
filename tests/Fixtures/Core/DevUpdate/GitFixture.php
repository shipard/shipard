<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\DevUpdate;

use Shipard\Core\DevUpdate\UpdateEnvironment;

/**
 * Dočasné git repozitáře pro testy aktualizace (#129 fáze 3): bare
 * „upstream“ s větví `stable`, autorský klon (do něj se commituje a pushuje,
 * aby upstream rostl) a `checkout` = klon, který hraje vývojový checkout.
 * `origin` checkoutu je cesta k bare repozitáři (= fork, ne kanonické URL).
 *
 * Prostředí ({@see env()}): vlastní `bin/` se symlinkem na skutečný `git`
 * a stuby `composer` / `npm` (pojistka `missingTool` tak nezávisí na stroji),
 * `GIT_CONFIG_GLOBAL=/dev/null` + `GIT_CONFIG_NOSYSTEM=1` (žádné podpisy
 * ani hooky z konfigurace vývojáře), pevný autor. Bez binárky `git` test
 * přeskočí ({@see available()}).
 */
final class GitFixture
{
    public readonly string $base;
    public readonly string $bin;
    public readonly string $upstream;
    public readonly string $author;
    public readonly string $checkout;
    private int $commitCounter = 0;

    private function __construct(string $base)
    {
        $this->base = $base;
        $this->bin = $base . '/bin';
        $this->upstream = $base . '/upstream.git';
        $this->author = $base . '/author';
        $this->checkout = $base . '/checkout';
    }

    public static function available(): bool
    {
        return UpdateEnvironment::findTool('git') !== null;
    }

    /** Založí upstream s jedním commitem na `stable` a klon `checkout`. */
    public static function create(): self
    {
        $base = sys_get_temp_dir() . '/shpd_gitfx_' . bin2hex(random_bytes(6));
        mkdir($base . '/bin', 0700, true);
        $fx = new self($base);

        $git = UpdateEnvironment::findTool('git');
        if ($git === null) {
            throw new \RuntimeException('git is not available');
        }
        symlink($git, $fx->bin . '/git');
        foreach (['composer', 'npm'] as $stub) {
            file_put_contents($fx->bin . '/' . $stub, "#!/bin/sh\nexit 0\n");
            chmod($fx->bin . '/' . $stub, 0755);
        }

        $fx->git($base, ['init', '--bare', '--initial-branch=stable', $fx->upstream]);
        $fx->git($base, ['clone', '--quiet', $fx->upstream, $fx->author]);
        $fx->git($fx->author, ['checkout', '--quiet', '-b', 'stable']);
        $fx->commitUpstream('initial', '2026-01-01T10:00:00+00:00');
        $fx->git($base, ['clone', '--quiet', '--branch', 'stable', $fx->upstream, $fx->checkout]);
        return $fx;
    }

    /**
     * Prostředí pro {@see \Shipard\Core\DevUpdate\Git} i pro příkazy testu.
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        $env = UpdateEnvironment::env($this->bin . ':/usr/bin:/bin');
        $env['HOME'] = $this->base;
        $env['GIT_CONFIG_GLOBAL'] = '/dev/null';
        $env['GIT_CONFIG_NOSYSTEM'] = '1';
        $env['GIT_AUTHOR_NAME'] = 'Test';
        $env['GIT_AUTHOR_EMAIL'] = 'test@example.com';
        $env['GIT_COMMITTER_NAME'] = 'Test';
        $env['GIT_COMMITTER_EMAIL'] = 'test@example.com';
        unset($env['GIT_DIR'], $env['GIT_WORK_TREE'], $env['GIT_INDEX_FILE']);
        return $env;
    }

    /** Commit do upstreamu (přes autorský klon + push); vrací plný hash. */
    public function commitUpstream(string $message, ?string $date = null): string
    {
        $hash = $this->commitIn($this->author, $message, $date);
        $this->git($this->author, ['push', '--quiet', 'origin', 'HEAD:stable']);
        return $hash;
    }

    /** Lokální commit v checkoutu (checkout napřed); vrací plný hash. */
    public function commitLocal(string $message, ?string $date = null): string
    {
        return $this->commitIn($this->checkout, $message, $date);
    }

    public function headOf(string $repo, string $ref = 'HEAD'): string
    {
        return trim($this->git($repo, ['rev-parse', $ref]));
    }

    /**
     * Spustí git v `$repo`; při selhání výjimka s stderr.
     *
     * @param list<string> $args
     */
    public function git(string $repo, array $args, ?string $date = null): string
    {
        $env = $this->env();
        if ($date !== null) {
            $env['GIT_AUTHOR_DATE'] = $date;
            $env['GIT_COMMITTER_DATE'] = $date;
        }
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open([$this->bin . '/git', '-C', $repo, ...$args], $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException('proc_open git failed');
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . " failed ({$exit}): {$stderr}");
        }
        return $stdout;
    }

    public function cleanup(): void
    {
        self::rrmdir($this->base);
    }

    private function commitIn(string $repo, string $message, ?string $date): string
    {
        $this->commitCounter++;
        $file = $repo . '/file-' . $this->commitCounter . '.txt';
        file_put_contents($file, $message . "\n");
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['commit', '--quiet', '--no-verify', '-m', $message], $date);
        return $this->headOf($repo);
    }

    public static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
