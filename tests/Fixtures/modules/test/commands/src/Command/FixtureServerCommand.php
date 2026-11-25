<?php

declare(strict_types=1);

namespace Shipard\Module\Test\Commands\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fixture příkaz pro testy příkazů z modulů (#172). */
class FixtureServerCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('fixture-server')
             ->setDescription('Fixture: server-level command');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('fixture-server ran');
        return Command::SUCCESS;
    }
}
