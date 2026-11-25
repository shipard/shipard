<?php

declare(strict_types=1);

namespace Shipard\Module\Test\Commands\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fixture příkaz pro testy příkazů z modulů (#172). */
class FixtureEchoCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('fixture-echo')
             ->setDescription('Fixture: print one line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('fixture-echo ran');
        return Command::SUCCESS;
    }
}
