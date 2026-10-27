<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\DevUpdate;

use Shipard\Core\DevUpdate\Git;

/**
 * Atrapa gitu pro testy bez skutečného gitu a bez `PATH` z prostředí
 * (#103 D135): shell skript `git` v dočasném adresáři, který na dotaz
 * (`git -C <root> <args…>`) odpoví pevným stdout podle argumentů;
 * neznámý dotaz = exit 1 (jako selhání gitu). Prostředí {@see env()} je
 * jen `PATH` s tím adresářem — nic zděděného, jako pod PHP-FPM
 * s `clear_env`. {@see HEAD} = odpovědi platného HEAD na větvi `stable`.
 */
final class GitStub
{
    public const string FULL_HASH = 'e4ab901a848e88b0954ed13330abb928c971a2f3';
    public const string SHORT_HASH = 'e4ab901a';
    public const string BRANCH = 'stable';
    public const string COMMIT_DATE = '2026-10-17T10:17:42+00:00';

    public const string Q_HEAD = 'rev-parse HEAD';
    public const string Q_SHORT = 'rev-parse --short HEAD';
    public const string Q_BRANCH = 'rev-parse --abbrev-ref HEAD';
    public const string Q_DATE = 'log -1 --format=%cI';
    public const string Q_STATUS = 'status --porcelain --untracked-files=no';

    /** @var array<string, string> argumenty za `-C <root>` → stdout */
    public const array HEAD = [
        self::Q_HEAD => self::FULL_HASH,
        self::Q_SHORT => self::SHORT_HASH,
        self::Q_BRANCH => self::BRANCH,
        self::Q_DATE => self::COMMIT_DATE,
        self::Q_STATUS => '',
    ];

    private function __construct(public readonly string $dir)
    {
    }

    /** @param array<string, string> $answers argumenty (mezerou) → stdout; výchozí {@see HEAD} */
    public static function create(array $answers = self::HEAD): self
    {
        $dir = sys_get_temp_dir() . '/shpd_gitstub_' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $script = "#!/bin/sh\n# atrapa gitu pro testy: git -C <root> <args...>\nshift 2\ncase \"\$*\" in\n";
        foreach ($answers as $args => $stdout) {
            $script .= '  ' . self::quote($args) . ') printf \'%s\\n\' ' . self::quote($stdout) . ";;\n";
        }
        $script .= "  *) echo \"git stub: unknown query: \$*\" >&2; exit 1;;\nesac\n";
        file_put_contents($dir . '/git', $script);
        chmod($dir . '/git', 0755);
        return new self($dir);
    }

    /** Prázdné prostředí: jen `PATH` s adresářem atrapy. @return array<string, string> */
    public function env(): array
    {
        return ['PATH' => $this->dir];
    }

    /** Prostředí, v jehož `PATH` žádný git není. @return array<string, string> */
    public function envWithoutGit(): array
    {
        return ['PATH' => $this->dir . '/no-git-here'];
    }

    public function git(string $root): Git
    {
        return new Git($root, $this->env());
    }

    public function cleanup(): void
    {
        GitFixture::rrmdir($this->dir);
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
