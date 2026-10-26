<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Server\DataSourceModules;
use Shipard\Module\Base\Persons\Values\DirectoryValuesNormalizer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `values-normalize` — jednorázová, opakovatelná normalizace existujících
 * údajů adresáře (#118 D22): osoby, adresy, kontakty, účty osob a vlastní
 * účty stejnými pravidly jako uložení ({@see DirectoryValuesNormalizer}).
 * Vypíše počty záznamů a změn po tabulce a sloupci, přesuny IBAN ze sloupce
 * čísla účtu (D20) a nevalidní hodnoty po opravě tvaru jako tabulka · id ·
 * sloupec · kód — bez hodnot, výpis smí do issue. `--dry-run` spočítá totéž
 * a nic nezapíše. Tabulka vlastních účtů jen s aktivním modulem
 * `economy.codebooks`. Na kopiích reálných dat ostrý běh pouští člověk po
 * `--dry-run`. Exit 0 i s nevalidními hodnotami (jsou k ruční opravě, ne
 * chyba příkazu); nenulový jen při chybě volání nebo infrastruktury.
 */
class ValuesNormalizeCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?DataSourceModules $modules = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('values-normalize')
            ->setDescription('Normalizuje existující údaje adresáře (osoby, adresy, kontakty, účty) stejnými pravidly jako uložení; vypíše změny a nevalidní hodnoty')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen spočítej a vypiš změny, nic nezapisuj')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Výstup jako JSON');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /** Šev pro testy — runner nad reálným zdrojem dat. */
    protected function createNormalizer(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, bool $ownAccounts): DirectoryValuesNormalizer
    {
        return new DirectoryValuesNormalizer($dsConnection->getDibiConnection(), $dsConfig->getCountry(), $ownAccounts);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');
        $json = (bool) $input->getOption('json');

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $ownAccounts = ($this->modules ?? new DataSourceModules())->isActive($dsDir, 'economy.codebooks');

        try {
            $report = $this->createNormalizer($dsConfig, $dsConnection, $ownAccounts)->run($dryRun);
        } catch (\Throwable $e) {
            $output->writeln('<error>Normalizace selhala, nic se nezapsalo: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }
        $this->printText($output, $report);
        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $report */
    private function printText(OutputInterface $output, array $report): void
    {
        foreach ($report['tables'] as $table => $stats) {
            if (isset($stats['skipped'])) {
                $reason = $stats['skipped'] === DirectoryValuesNormalizer::SKIPPED_MODULE_INACTIVE
                    ? 'modul economy.codebooks není aktivní'
                    : (string) $stats['skipped'];
                $output->writeln(sprintf('%s: přeskočeno — %s', $table, $reason));
                continue;
            }
            $columns = [];
            foreach ($stats['columns'] as $column => $count) {
                $columns[] = "{$column} {$count}";
            }
            $line = sprintf(
                '%s: %d záznamů, %d změněno%s',
                $table,
                $stats['rows'],
                $stats['changed'],
                $columns !== [] ? ' (' . implode(', ', $columns) . ')' : '',
            );
            if (isset($stats['ibanMoved'])) {
                $line .= sprintf('; IBAN přesunut z čísla účtu: %d', $stats['ibanMoved']);
            }
            $output->writeln($line);
        }
        $output->writeln(sprintf('Celkem: %d záznamů, %d změněno', $report['totals']['rows'], $report['totals']['changed']));
        $output->writeln(sprintf('Nevalidní hodnoty (k ruční opravě): %d', $report['totals']['invalid']));
        foreach ($report['invalid'] as $row) {
            $output->writeln(sprintf('  %s · %d · %s · %s', $row['table'], $row['id'], $row['column'], $row['code']));
        }
        if ($report['dryRun']) {
            $output->writeln('(dry-run, nic se nezapsalo)');
        }
    }
}
