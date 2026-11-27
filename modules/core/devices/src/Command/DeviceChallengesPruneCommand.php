<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices\Command;

use Shipard\Module\Core\Devices\ChallengeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds device-challenges-prune` — smaže použité a prošlé výzvy zařízení
 * (#177 U20). Úloha cronu modulu ve slotu `five-minutes`; výzva žije 60 s,
 * takže tabulka zůstává malá. Když není co mazat, příkaz mlčí.
 */
class DeviceChallengesPruneCommand extends DeviceCommand
{
    protected function configure(): void
    {
        $this->setName('device-challenges-prune')
             ->setDescription('Remove used and expired device challenges');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->connect($output);
        if ($db === null) {
            return Command::FAILURE;
        }

        $removed = (new ChallengeService($db))->prune();
        if ($removed > 0 || $output->isVerbose()) {
            $output->writeln("Removed {$removed} device challenges.");
        }

        return Command::SUCCESS;
    }
}
