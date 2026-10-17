<?php

declare(strict_types=1);

namespace Shipard\Core\DevUpdate;

/**
 * Krok aktualizace jako podproces s živým výstupem (#129 D21): argv bez
 * shellu, stdout a stderr zděděné (terminál, nebo `output.log` úlohy),
 * stdin `/dev/null`, prostředí z {@see UpdateEnvironment}. Příkaz
 * `dev-update` ho načte už krokem 2 (`git merge`), takže kroky 3–5 po
 * změně kódu na disku žádnou novou třídu nepotřebují.
 */
final class UpdateStepRunner
{
    /**
     * @param list<string> $argv
     * @param array<string, string> $env
     * @return int exit kód; -1 = proces nešel spustit nebo skončil signálem
     */
    public function run(array $argv, string $cwd, array $env): int
    {
        if ($argv === []) {
            return -1;
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => \defined('STDOUT') ? \STDOUT : ['file', 'php://stdout', 'w'],
            2 => \defined('STDERR') ? \STDERR : ['file', 'php://stderr', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            return -1;
        }
        $exit = proc_close($process);
        return $exit < 0 ? -1 : $exit;
    }
}
