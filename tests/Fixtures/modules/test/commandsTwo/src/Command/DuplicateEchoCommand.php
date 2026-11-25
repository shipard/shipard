<?php

declare(strict_types=1);

namespace Shipard\Module\Test\CommandsTwo\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Fixture příkaz pro testy příkazů z modulů (#172). */
class DuplicateEchoCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('fixture-echo')
             ->setDescription('Fixture: same name as the command of test.commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('fixture-echo ran');
        return Command::SUCCESS;
    }
}
