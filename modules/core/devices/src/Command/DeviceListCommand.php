<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices\Command;

use Shipard\Module\Core\Devices\DeviceDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds device-list [--json]` — registrovaná zařízení zdroje dat (#177
 * U22): název, režim, platforma, stav a poslední kontakt. `deviceId` je
 * argument příkazu `device-revoke`. Veřejný klíč příkaz nevypisuje.
 */
class DeviceListCommand extends DeviceCommand
{
    protected function configure(): void
    {
        $this->setName('device-list')
             ->setDescription('List registered devices of the native app')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->connect($output);
        if ($db === null) {
            return Command::FAILURE;
        }

        $devices = array_map(static fn (array $row): array => [
            'deviceId'     => (string) $row['device_id'],
            'name'         => (string) $row['name'],
            'mode'         => (string) $row['mode'],
            'platform'     => (string) $row['platform'],
            'appVersion'   => $row['app_version'] ?? null,
            'state'        => (int) $row['docState'] === DeviceDocument::STATE_ACTIVE ? 'active' : 'revoked',
            'registeredAt' => self::dateTime($row['registered_at'] ?? null),
            'lastSeenAt'   => self::dateTime($row['last_seen_at'] ?? null),
        ], $db->fetchAll(
            'SELECT device_id, name, mode, platform, app_version, docState, registered_at, last_seen_at
             FROM core_devices_devices ORDER BY docStateMain ASC, name ASC, id ASC',
        ));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(
                ['devices' => $devices],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));
            return Command::SUCCESS;
        }

        if ($devices === []) {
            $output->writeln('No devices registered.');
            return Command::SUCCESS;
        }

        (new Table($output))
            ->setHeaders(['Name', 'Mode', 'Platform', 'State', 'Last contact', 'Device ID'])
            ->setRows(array_map(static fn (array $d): array => [
                $d['name'], $d['mode'], $d['platform'], $d['state'], $d['lastSeenAt'] ?? '—', $d['deviceId'],
            ], $devices))
            ->render();

        return Command::SUCCESS;
    }
}
