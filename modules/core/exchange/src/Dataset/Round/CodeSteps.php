<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

use Shipard\Core\DevUpdate\UpdateEnvironment;

/**
 * Katalog kroků vrstvy kódu (D130) v pevném pořadí: celý PHPUnit, testy
 * generátoru korpusu, frontend (`npm test`, `check:i18n`), kontroly CSS,
 * video-runner, unit testy specu proklikání. Nástroje se hledají v pevném
 * `PATH` ({@see UpdateEnvironment::findTool()} — prostředí úlohy je prázdné);
 * chybějící nástroj, `vendor/` nebo `node_modules` dává krok s `problem`
 * (věta, co doinstalovat), ne výjimku — kolo pokračuje (D133).
 *
 * PHPUnit vždy s `memory_limit=1G`: bez capu nekonečná rekurze v testovaném
 * kódu sežere RAM celého stroje (OOM killer), s ním skončí čitelnou chybou.
 */
final class CodeSteps
{
    public const string PHPUNIT_MEMORY_LIMIT = '1G';

    public function __construct(
        private readonly string $repoRoot,
        private readonly string $path = UpdateEnvironment::DEFAULT_PATH,
        private readonly ?string $phpBinary = null,
    ) {}

    /** @return list<CodeStep> */
    public function plan(): array
    {
        $repo = rtrim($this->repoRoot, '/');
        $php = $this->phpBinary ?? self::phpBinary();
        $python = UpdateEnvironment::findTool('python3', $this->path);
        $npm = UpdateEnvironment::findTool('npm', $this->path);
        $node = UpdateEnvironment::findTool('node', $this->path);
        $pythonProblem = $python === null ? "python3 není v PATH ({$this->path}) — nainstaluj Python 3 do systémové cesty" : null;
        $npmProblem = $npm === null || $node === null
            ? "npm a node nejsou v PATH ({$this->path}) — nainstaluj Node 24+ do systémové cesty (nvm v neinteraktivním shellu nestačí)"
            : null;

        $phpunit = $repo . '/vendor/bin/phpunit';
        $steps = [];
        $steps[] = new CodeStep(
            'phpunit',
            'PHPUnit',
            [$php, '-d', 'memory_limit=' . self::PHPUNIT_MEMORY_LIMIT, $phpunit, '--colors=never'],
            $repo,
            CodeStep::PARSER_PHPUNIT,
            is_file($phpunit) ? null : 'PHPUnit chybí (vendor/bin/phpunit) — spusť composer install',
        );
        $steps[] = new CodeStep(
            'invoice-generator',
            'Generátor korpusu (unittest)',
            [$python ?? 'python3', '-m', 'unittest', 'discover', '-s', 'tests'],
            $repo . '/tools/invoice-generator',
            CodeStep::PARSER_UNITTEST,
            $pythonProblem ?? (is_dir($repo . '/tools/invoice-generator/tests') ? null : 'Testy generátoru chybí (tools/invoice-generator/tests) — checkout je neúplný'),
        );
        $steps[] = new CodeStep('frontend-test', 'Frontend (npm test)', [$npm ?? 'npm', 'test'], $repo . '/frontend', CodeStep::PARSER_NODE_TEST, $npmProblem ?? self::nodeModulesProblem($repo, 'frontend'));
        $steps[] = new CodeStep('frontend-i18n', 'Frontend (check:i18n)', [$npm ?? 'npm', 'run', 'check:i18n'], $repo . '/frontend', CodeStep::PARSER_EXIT_CODE, $npmProblem ?? self::nodeModulesProblem($repo, 'frontend'));
        $steps[] = new CodeStep('css-tokens', 'Kontrola CSS tokenů', [$python ?? 'python3', 'scripts/check-css-tokens.py'], $repo, CodeStep::PARSER_EXIT_CODE, $pythonProblem);
        $steps[] = new CodeStep('css-units', 'Kontrola CSS jednotek', [$python ?? 'python3', 'scripts/check-css-units.py'], $repo, CodeStep::PARSER_EXIT_CODE, $pythonProblem);
        $steps[] = new CodeStep('video-runner-test', 'Video-runner (npm test)', [$npm ?? 'npm', 'test'], $repo . '/tools/video-runner', CodeStep::PARSER_NODE_TEST, $npmProblem ?? self::nodeModulesProblem($repo, 'tools/video-runner'));
        $steps[] = new CodeStep('e2e-unit', 'Spec proklikání (npm run test:unit)', [$npm ?? 'npm', 'run', 'test:unit'], $repo . '/tests/e2e', CodeStep::PARSER_NODE_TEST, $npmProblem ?? self::nodeModulesProblem($repo, 'tests/e2e'));
        return $steps;
    }

    /** CLI interpret: pod php-fpm je `PHP_BINARY` samotný fpm (vzor `BinPaths`). */
    public static function phpBinary(): string
    {
        $php = PHP_BINDIR . '/php';
        return is_executable($php) ? $php : PHP_BINARY;
    }

    private static function nodeModulesProblem(string $repo, string $dir): ?string
    {
        return is_dir($repo . '/' . $dir . '/node_modules') ? null : "Závislosti chybí ({$dir}/node_modules) — spusť cd {$dir} && npm ci";
    }
}
