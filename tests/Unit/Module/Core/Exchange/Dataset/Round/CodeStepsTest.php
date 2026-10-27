<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset\Round;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStep;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeStepProcess;
use Shipard\Module\Core\Exchange\Dataset\Round\CodeSteps;

/** Katalog kroků vrstvy kódu (D130): pevné pořadí a argv, chybějící nástroj / závislosti = krok s větou, ne výjimka. */
final class CodeStepsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd_code_steps_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/bin', 0700, true);
        mkdir($this->dir . '/repo', 0700, true);
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->dir);
    }

    private function tool(string $name): void
    {
        file_put_contents($this->dir . '/bin/' . $name, "#!/bin/sh\nexit 0\n");
        chmod($this->dir . '/bin/' . $name, 0700);
    }

    public function testFullRepoGivesEightRunnableStepsInOrder(): void
    {
        foreach (['node', 'npm', 'python3'] as $name) {
            $this->tool($name);
        }
        $repo = $this->dir . '/repo';
        foreach (['vendor/bin', 'tools/invoice-generator/tests', 'frontend/node_modules', 'tools/video-runner/node_modules', 'tests/e2e/node_modules'] as $sub) {
            mkdir($repo . '/' . $sub, 0700, true);
        }
        touch($repo . '/vendor/bin/phpunit');

        $steps = (new CodeSteps($repo, $this->dir . '/bin', '/usr/bin/php'))->plan();

        $this->assertSame(['phpunit', 'invoice-generator', 'frontend-test', 'frontend-i18n', 'css-tokens', 'css-units', 'video-runner-test', 'e2e-unit'], array_map(static fn (CodeStep $s) => $s->id, $steps));
        $this->assertSame([], array_filter(array_map(static fn (CodeStep $s) => $s->problem, $steps)), 'nothing missing');
        $this->assertSame(['/usr/bin/php', '-d', 'memory_limit=1G', $repo . '/vendor/bin/phpunit', '--colors=never'], $steps[0]->argv);
        $this->assertSame($repo, $steps[0]->cwd);
        $this->assertSame(CodeStep::PARSER_PHPUNIT, $steps[0]->parser);
        $this->assertSame([$this->dir . '/bin/python3', '-m', 'unittest', 'discover', '-s', 'tests'], $steps[1]->argv);
        $this->assertSame($repo . '/tools/invoice-generator', $steps[1]->cwd);
        $this->assertSame(CodeStep::PARSER_UNITTEST, $steps[1]->parser);
        $this->assertSame([$this->dir . '/bin/npm', 'test'], $steps[2]->argv);
        $this->assertSame($repo . '/frontend', $steps[2]->cwd);
        $this->assertSame(CodeStep::PARSER_NODE_TEST, $steps[2]->parser);
        $this->assertSame([$this->dir . '/bin/npm', 'run', 'check:i18n'], $steps[3]->argv);
        $this->assertSame(CodeStep::PARSER_EXIT_CODE, $steps[3]->parser);
        $this->assertSame([$this->dir . '/bin/python3', 'scripts/check-css-tokens.py'], $steps[4]->argv);
        $this->assertSame([$this->dir . '/bin/python3', 'scripts/check-css-units.py'], $steps[5]->argv);
        $this->assertSame($repo . '/tools/video-runner', $steps[6]->cwd);
        $this->assertSame([$this->dir . '/bin/npm', 'run', 'test:unit'], $steps[7]->argv);
        $this->assertSame($repo . '/tests/e2e', $steps[7]->cwd);
        $this->assertSame('phpunit', $steps[0]->toArray()['id']);
    }

    public function testMissingToolsAndDependenciesGiveProblemSentences(): void
    {
        $repo = $this->dir . '/repo';
        mkdir($repo . '/tools/invoice-generator/tests', 0700, true);
        mkdir($repo . '/frontend', 0700, true);

        $steps = (new CodeSteps($repo, $this->dir . '/bin', '/usr/bin/php'))->plan();
        $byId = [];
        foreach ($steps as $step) {
            $byId[$step->id] = $step;
        }

        $this->assertStringContainsString('composer install', (string) $byId['phpunit']->problem);
        $this->assertStringContainsString('python3 není v PATH', (string) $byId['invoice-generator']->problem);
        $this->assertStringContainsString('npm a node nejsou v PATH', (string) $byId['frontend-test']->problem);
        $this->assertStringContainsString('python3 není v PATH', (string) $byId['css-tokens']->problem);

        // Nástroje jsou, závislosti ne.
        foreach (['node', 'npm', 'python3'] as $name) {
            $this->tool($name);
        }
        $steps = (new CodeSteps($repo, $this->dir . '/bin', '/usr/bin/php'))->plan();
        $byId = [];
        foreach ($steps as $step) {
            $byId[$step->id] = $step;
        }
        $this->assertNull($byId['invoice-generator']->problem);
        $this->assertNull($byId['css-tokens']->problem);
        $this->assertSame('Závislosti chybí (frontend/node_modules) — spusť cd frontend && npm ci', $byId['frontend-test']->problem);
        $this->assertSame($byId['frontend-test']->problem, $byId['frontend-i18n']->problem);
        $this->assertStringContainsString('cd tools/video-runner && npm ci', (string) $byId['video-runner-test']->problem);
        $this->assertStringContainsString('cd tests/e2e && npm ci', (string) $byId['e2e-unit']->problem);
    }

    public function testEnvironmentIsFixedAndUtf8(): void
    {
        $env = CodeStepProcess::environment('/home/pool', $this->dir . '/bin');

        $this->assertSame($this->dir . '/bin', $env['PATH']);
        $this->assertSame('C.UTF-8', $env['LC_ALL']);
        $this->assertSame('C.UTF-8', $env['LANG']);
        $this->assertSame('1', $env['PYTHONUTF8']);
        $this->assertArrayHasKey('HOME', $env);
        // Nic navíc: `CI` / `NO_COLOR` by zdědil celý PHPUnit a testy skládání prostředí by padaly jen pod kolem.
        $this->assertArrayNotHasKey('NO_COLOR', $env);
        $this->assertSame(getenv('CI') === false ? false : true, array_key_exists('CI', $env), 'CI jen zděděné z procesu, nikdy přidané');
    }
}
