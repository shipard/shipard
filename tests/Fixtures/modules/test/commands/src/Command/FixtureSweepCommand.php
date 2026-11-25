<?php

declare(strict_types=1);

namespace Shipard\Module\Test\Commands\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fixture příkaz pro testy příkazů z modulů (#172). */
class FixtureSweepCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('fixture-sweep')
             ->setDescription('Fixture: sweep job for the daily slot')
             ->addOption('all', null, InputOption::VALUE_NONE, 'Sweep everything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('fixture-sweep ran');
        return Command::SUCCESS;
    }
}
