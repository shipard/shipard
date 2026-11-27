<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Společný základ příkazů modulu core.devices: připojení ke zdroji dat
 * z aktuálního adresáře (v testech vložené konstruktorem).
 */
abstract class DeviceCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    /** Připojení ke zdroji dat, nebo null s chybou na výstupu. */
    protected function connect(OutputInterface $output): ?DataSourceConnection
    {
        if ($this->dsConnection !== null) {
            return $this->dsConnection;
        }
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return null;
        }
        return new DataSourceConnection($this->dsConfig ?? new DataSourceConfig($dsDir));
    }

    /** Hodnota data z databáze (`Y-m-d\TH:i:s` nebo DateTime) jako `Y-m-d H:i`. */
    protected static function dateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        return substr(str_replace('T', ' ', $value), 0, 16);
    }
}
