<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Prints\EscPos\EscPosConfigException;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\EscPos\PrinterTestReceipt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Zkušební účtenka profilu tiskárny jako soubor s bajty ESC/POS (#177
 * task 4a). Soubor jde poslat na tiskárnu (`nc <ip> 9100 < soubor`) nebo
 * ukázat v náhledu na stránce Most vývojářského dashboardu — ověří se tak
 * profil bez aplikace i bez tiskové úlohy.
 *
 * Profily a ovladač jsou v konfiguraci zdroje dat; bez `--profile` nebo
 * s neznámým profilem se použije záložní a příkaz to řekne na stderr.
 */
class PrintEscposTestCommand extends Command
{
    public function __construct(private readonly ?DataSourceConfig $dsConfig = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('print-escpos-test')
             ->setDescription('Zkušební účtenka profilu tiskárny jako bajty ESC/POS do souboru (--profile=<id> --output=<soubor>)')
             ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Id profilu tiskárny (escpos-80-cp852 | escpos-58-cp852); bez něj záložní profil')
             ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Cílový soubor (povinný)');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $outputFile = $input->getOption('output');
        if (!is_string($outputFile) || $outputFile === '') {
            $err->writeln('<error>--output=<file> is required</error>');
            return Command::INVALID;
        }
        $profileId = $input->getOption('profile');
        $profileId = is_string($profileId) && $profileId !== '' ? $profileId : null;

        $dsDir = $this->dsConfig?->getDataSourceDir() ?? $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);

        try {
            $config   = ConfigRuntime::load($dsDir, $dsConfig->getDefaultLanguage());
            $resolved = PrinterProfiles::fromConfig($config)->get($profileId);
            $receipt  = PrinterTestReceipt::fromConfig($config, fn (): \DateTimeImmutable => $this->now())
                ->render($resolved['profile']);
        } catch (EscPosConfigException | \RuntimeException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (@file_put_contents($outputFile, $receipt->bytes) === false) {
            $err->writeln("<error>Cannot write output file '{$outputFile}'</error>");
            return Command::FAILURE;
        }

        foreach ([$resolved['warning'], ...$receipt->warnings] as $warning) {
            if ($warning !== null) {
                $err->writeln(sprintf('<comment>Warning: %s (%s)</comment>', $warning->text, $warning->code));
            }
        }
        $output->writeln(sprintf(
            '%s: %d bytes, profile %s',
            $outputFile,
            strlen($receipt->bytes),
            $resolved['profile']->id,
        ));

        return Command::SUCCESS;
    }
}
