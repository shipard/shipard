<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices\Command;

use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceRegistrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `shpd-ds device-revoke <deviceId> [--yes]` — zneplatnění zařízení bez UI
 * (#177 U22): ztracené zařízení, nedostupná aplikace. Konečné — stejná
 * služba jako akce Zneplatnit; `deviceId` vypíše `device-list`.
 */
class DeviceRevokeCommand extends DeviceCommand
{
    protected function configure(): void
    {
        $this->setName('device-revoke')
             ->setDescription('Revoke a registered device (final — the device must register again as a new one)')
             ->addArgument('deviceId', InputArgument::REQUIRED, 'Device ID (see device-list)')
             ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->connect($output);
        if ($db === null) {
            return Command::FAILURE;
        }

        $deviceId = (string) $input->getArgument('deviceId');
        $row = $db->fetchRow('SELECT * FROM core_devices_devices WHERE device_id = %s', $deviceId);
        if ($row === null) {
            $output->writeln("<error>Error: Device '{$deviceId}' not found — see shpd-ds device-list</error>");
            return Command::FAILURE;
        }
        if ((int) $row['docState'] !== DeviceDocument::STATE_ACTIVE) {
            $output->writeln("Device '{$row['name']}' is already revoked. No changes made.");
            return Command::SUCCESS;
        }

        $output->writeln('About to revoke device:');
        $output->writeln('  Name:         ' . $row['name']);
        $output->writeln('  Mode:         ' . $row['mode']);
        $output->writeln('  Platform:     ' . $row['platform']);
        $output->writeln('  Registered:   ' . (self::dateTime($row['registered_at'] ?? null) ?? '(unknown)'));
        $output->writeln('  Last contact: ' . (self::dateTime($row['last_seen_at'] ?? null) ?? '(never)'));
        $output->writeln('');
        $output->writeln('Revocation is final: the device has to be registered again as a new one.');

        if (!(bool) $input->getOption('yes')) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            if (!$helper->ask($input, $output, new ConfirmationQuestion('Proceed? [y/N]: ', false))) {
                $output->writeln('Aborted. No changes made.');
                return Command::SUCCESS;
            }
        }

        // Zneplatnění z příkazové řádky nemá uživatele (`revoked_by` NULL).
        if (!(new DeviceRegistrationService($db))->revoke((int) $row['id'], null)) {
            // Souběh: zařízení mezitím zneplatnil někdo jiný — výsledek je stejný.
            $output->writeln("Device '{$row['name']}' is already revoked. No changes made.");
            return Command::SUCCESS;
        }

        $output->writeln("<info>Device '{$row['name']}' revoked.</info>");
        return Command::SUCCESS;
    }
}
