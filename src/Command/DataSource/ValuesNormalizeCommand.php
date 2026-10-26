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
 *
 * `--only=<druhy>` (D27) omezí běh na druhy hodnot (`phone`, `email`,
 * `web`, `zip`, `ids`, `accounts`); neznámý druh = chyba volání.
 * `-v` / `--verbose` (vestavěná volba Symfony Console) přidá k výpisu
 * i JSONu každou změnu (osoba, sloupec, před → po; přesun IBAN jako jedna
 * položka) a u nálezů hodnotu, důvod a návrh opravy — výstup s osobními
 * údaji, jen na obrazovku, nikdy do issue.
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
            ->addOption('json', null, InputOption::VALUE_NONE, 'Výstup jako JSON')
            ->addOption(
                'only',
                null,
                InputOption::VALUE_REQUIRED,
                'Jen vybrané druhy hodnot, čárkami: ' . implode(', ', DirectoryValuesNormalizer::ONLY_KINDS)
                . ' (zip = PSČ a země adresy, ids = IČO, DIČ a daňové číslo, accounts = číslo účtu, IBAN a BIC vč. přesunu IBAN)',
            )
            ->setHelp(
                "S <info>-v</info> (<info>--verbose</info>) vypíše každou změnu s osobou a hodnotami před → po\n"
                . "a u nevalidních hodnot hodnotu, důvod a návrh opravy (nic se neopravuje).\n"
                . 'Podrobný výpis obsahuje osobní údaje — jen na obrazovku, nikdy do issue.',
            );
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /**
     * Šev pro testy — runner nad reálným zdrojem dat.
     *
     * @param list<string>|null $only
     */
    protected function createNormalizer(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, bool $ownAccounts, ?array $only, bool $verbose): DirectoryValuesNormalizer
    {
        return new DirectoryValuesNormalizer(
            $dsConnection->getDibiConnection(),
            $dsConfig->getCountry(),
            $ownAccounts,
            DirectoryValuesNormalizer::DEFAULT_BATCH,
            $only,
            $verbose,
        );
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
        $verbose = $output->isVerbose();

        $only = null;
        $onlyOption = $input->getOption('only');
        if ($onlyOption !== null) {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $onlyOption)), static fn (string $kind): bool => $kind !== ''));
            $unknown = array_values(array_diff($only, DirectoryValuesNormalizer::ONLY_KINDS));
            if ($only === [] || $unknown !== []) {
                $output->writeln(sprintf(
                    '<error>Neznámý druh hodnot „%s“ — povolené: %s.</error>',
                    implode(', ', $unknown),
                    implode(', ', DirectoryValuesNormalizer::ONLY_KINDS),
                ));
                return Command::INVALID;
            }
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $ownAccounts = ($this->modules ?? new DataSourceModules())->isActive($dsDir, 'economy.codebooks');

        try {
            $report = $this->createNormalizer($dsConfig, $dsConnection, $ownAccounts, $only, $verbose)->run($dryRun);
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
        if (isset($report['only'])) {
            $output->writeln('Jen druhy: ' . implode(', ', $report['only']));
        }
        foreach ($report['tables'] as $table => $stats) {
            if (isset($stats['skipped'])) {
                $reason = match ($stats['skipped']) {
                    DirectoryValuesNormalizer::SKIPPED_MODULE_INACTIVE => 'modul economy.codebooks není aktivní',
                    DirectoryValuesNormalizer::SKIPPED_NOT_SELECTED    => 'mimo --only',
                    default                                            => (string) $stats['skipped'],
                };
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

        if (isset($report['changes'])) {
            $output->writeln(sprintf('Změny (%d):', count($report['changes'])));
            foreach ($report['changes'] as $change) {
                $parts = [];
                foreach ($change['columns'] as $column => $diff) {
                    $parts[] = sprintf('%s „%s“ → „%s“', $column, (string) ($diff['from'] ?? ''), (string) ($diff['to'] ?? ''));
                }
                $output->writeln(sprintf(
                    '  %s · %d · %s · %s%s',
                    $change['table'],
                    $change['id'],
                    self::ownerLabel($change),
                    $change['ibanMoved'] ? 'IBAN přesunut z čísla účtu: ' : '',
                    implode('; ', $parts),
                ));
            }
        }

        $output->writeln(sprintf('Nevalidní hodnoty (k ruční opravě): %d', $report['totals']['invalid']));
        foreach ($report['invalid'] as $row) {
            $line = sprintf('  %s · %d · %s · %s', $row['table'], $row['id'], $row['column'], $row['code']);
            if (array_key_exists('value', $row)) {
                $line = sprintf(
                    '  %s · %d · %s · %s · %s · „%s“ — %s',
                    $row['table'],
                    $row['id'],
                    self::ownerLabel($row),
                    $row['column'],
                    $row['code'],
                    (string) ($row['value'] ?? ''),
                    (string) ($row['reason'] ?? ''),
                );
                if (($row['suggestion'] ?? null) !== null) {
                    $line .= sprintf(' · návrh: „%s“', $row['suggestion']);
                }
            }
            $output->writeln($line);
        }
        if ($report['dryRun']) {
            $output->writeln('(dry-run, nic se nezapsalo)');
        }
    }

    /**
     * Osoba (id, kód, název) nebo vlastní účet (kód, název) položky podrobného výpisu.
     *
     * @param array<string, mixed> $entry
     */
    private static function ownerLabel(array $entry): string
    {
        if (is_array($entry['account'] ?? null)) {
            return sprintf('účet %s „%s“', $entry['account']['code'], $entry['account']['name']);
        }
        if (is_array($entry['person'] ?? null)) {
            return sprintf('osoba %d %s „%s“', $entry['person']['id'], $entry['person']['code'], $entry['person']['name']);
        }
        return '—';
    }
}
