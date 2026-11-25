<?php

declare(strict_types=1);

namespace Shipard\Cli;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Aplikace `shpd-ds` — Symfony `Application` se dvěma doplňky pro příkazy
 * z modulů (#172):
 *
 *  - **D4:** jméno příkazu, který patří modulu neaktivnímu ve zdroji dat,
 *    skončí chybou, která modul jmenuje (exit 1), ne obecným „command not
 *    found“. Mimo zdroj dat chyba říká, odkud příkaz pustit.
 *  - Varování, že moduly zdroje dat nejde určit (nabízí se příkazy všech
 *    modulů), jde jednou na chybový výstup před během příkazu.
 */
final class ShipardApplication extends Application
{
    private ?ModuleCommands $moduleCommands = null;

    private bool $insideDataSource = true;

    private ?string $startupWarning = null;

    /** Zdroj mapy jméno → modul pro chybu D4. */
    public function setModuleCommands(ModuleCommands $moduleCommands, bool $insideDataSource): void
    {
        $this->moduleCommands = $moduleCommands;
        $this->insideDataSource = $insideDataSource;
    }

    public function setStartupWarning(?string $warning): void
    {
        $this->startupWarning = $warning;
    }

    public function find(string $name): Command
    {
        // Přesné jméno příkazu modulu, který tu registrovaný není — dřív,
        // než ho Symfony zkusí doplnit na jiný příkaz se stejným začátkem.
        if ($this->moduleCommands !== null && !$this->has($name)) {
            $owner = $this->moduleCommands->dsOwner($name);
            if ($owner !== null) {
                throw new CommandNotFoundException($this->insideDataSource
                    ? sprintf('Command "%s" belongs to module %s, which is not active in this data source.', $name, $owner)
                    : sprintf('Command "%s" belongs to module %s; run it from a data source directory where the module is active.', $name, $owner));
            }
        }
        return parent::find($name);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        if ($this->startupWarning !== null) {
            $error = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $error->writeln($this->startupWarning);
            $this->startupWarning = null;
        }
        return parent::doRun($input, $output);
    }
}
