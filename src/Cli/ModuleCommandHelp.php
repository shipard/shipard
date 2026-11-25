<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generovaná část nápovědy `shpd-ds help` / `shpd-server help` (#172 D5):
 * příkazy modulů po modulech — nadpis `<id modulu> — <název modulu>`
 * a řádky `<jméno>  <popis>`, popis je `getDescription()` příkazu. Příkazy
 * jádra zůstávají v nápovědě ručně psané.
 */
final class ModuleCommandHelp
{
    private function __construct()
    {
    }

    /**
     * Vypíše sekci „Modules:“; bez skupin nevypíše nic. Skryté příkazy
     * se vynechávají, modul jen se skrytými příkazy také.
     *
     * @param list<ModuleCommandGroup> $groups
     */
    public static function write(OutputInterface $output, array $groups): void
    {
        $visible = [];
        $width = 0;
        foreach ($groups as $group) {
            $commands = [];
            foreach ($group->commands as $command) {
                if ($command->isHidden()) {
                    continue;
                }
                $commands[(string) $command->getName()] = $command->getDescription();
                $width = max($width, strlen((string) $command->getName()));
            }
            if ($commands !== []) {
                $visible[] = [$group, $commands];
            }
        }
        if ($visible === []) {
            return;
        }

        $output->writeln('<comment>Modules:</comment>');
        foreach ($visible as [$group, $commands]) {
            $output->writeln(sprintf('  <comment>%s — %s</comment>', $group->moduleId, $group->moduleName));
            foreach ($commands as $name => $description) {
                $output->writeln(sprintf(
                    '    <info>%s</info>%s  %s',
                    $name,
                    str_repeat(' ', $width - strlen($name)),
                    $description,
                ));
            }
        }
        $output->writeln('');
    }
}
