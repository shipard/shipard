<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Nahrání kurzovních lístků ze souboru nebo složky (#121 §5,
 * docs/exchange-rates.md): každý `.jsonc` / `.json` ve formátu
 * shpd.exchangeRates.list.v1 projde ExchangeRateListImporter se zdrojem
 * „soubor“. Existující lístek se shodným obsahem = beze změny; s jiným
 * obsahem = konflikt, který přepíše jen --replace. Měny mimo
 * world.base.currencies importer vyřadí a příkaz je vypíše jako varování
 * u souboru (U1) — výsledek zůstává ok.
 *
 * Exit 0; 1 při jakémkoli selhání (neplatný lístek, konflikt, chyba
 * zápisu, chybějící modul); INVALID při neexistující cestě.
 */
class ExchangeRatesImportCommand extends Command
{
    private const array OUTCOME_LABELS = [
        ExchangeRateListImportResult::CREATED  => 'založen',
        ExchangeRateListImportResult::SKIPPED  => 'beze změny',
        ExchangeRateListImportResult::REPLACED => 'nahrazen',
        ExchangeRateListImportResult::CONFLICT => 'jiný obsah',
        ExchangeRateListImportResult::INVALID  => 'neplatný',
        ExchangeRateListImportResult::FAILED   => 'selhal',
    ];

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('exchange-rates-import')
            ->setDescription('Import exchange rate lists (shpd.exchangeRates.list.v1) from a file or a folder; --replace overwrites lists with different content')
            ->addArgument('path', InputArgument::REQUIRED, 'JSONC/JSON file with one list, or a folder with such files')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replace existing lists whose content differs (default: conflict)');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $path = (string) $input->getArgument('path');
        $files = $this->collectFiles($path);
        if ($files === null) {
            $output->writeln("<error>Cesta '{$path}' neexistuje.</error>");
            return Command::INVALID;
        }
        if ($files === []) {
            $output->writeln("<error>Ve složce '{$path}' není žádný soubor .jsonc / .json.</error>");
            return Command::INVALID;
        }
        $replace = (bool) $input->getOption('replace');

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if (!in_array(ExchangeRateListImporter::TABLE_LISTS, $dsConnection->getAllTableNames(), true)) {
            $output->writeln('<error>economy.exchangeRates není aktivní — spusť ds-upgrade.</error>');
            return Command::FAILURE;
        }

        $importer = $this->createImporter($dsDir, $dsConfig, $dsConnection);

        $counts = array_fill_keys(array_keys(self::OUTCOME_LABELS), 0);
        $failed = 0;
        foreach ($files as $file) {
            $name = basename($file);
            try {
                $list = JsoncParser::parseFile($file);
            } catch (\RuntimeException $e) {
                $failed++;
                $counts[ExchangeRateListImportResult::INVALID]++;
                $output->writeln(sprintf('  <error>%s</error> %s', self::pad(self::OUTCOME_LABELS[ExchangeRateListImportResult::INVALID]), $name));
                $output->writeln('    - ' . $e->getMessage());
                continue;
            }
            if (!is_array($list)) {
                $list = [];
            }
            $result = $importer->import($list, ExchangeRateListImporter::SOURCE_FILE, $replace);
            $counts[$result->outcome] = ($counts[$result->outcome] ?? 0) + 1;
            $label = self::OUTCOME_LABELS[$result->outcome] ?? $result->outcome;
            $tag = $result->ok() ? 'info' : 'error';
            $output->writeln(sprintf('  <%s>%s</%s> %s — %s', $tag, self::pad($label), $tag, $name, $result->label() !== '' ? $result->label() : '(bez typu)'));
            foreach ($result->errors as $error) {
                $output->writeln('    - ' . $error);
            }
            if ($result->skippedCurrencies !== []) {
                $output->writeln('    <comment>přeskočené měny: ' . implode(', ', $result->skippedCurrencies) . '</comment>');
            }
            if (!$result->ok()) {
                $failed++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Lístky: %d založeno, %d beze změny, %d nahrazeno, %d selhalo (%d jiný obsah, %d neplatných)',
            $counts[ExchangeRateListImportResult::CREATED],
            $counts[ExchangeRateListImportResult::SKIPPED],
            $counts[ExchangeRateListImportResult::REPLACED],
            $failed,
            $counts[ExchangeRateListImportResult::CONFLICT],
            $counts[ExchangeRateListImportResult::INVALID],
        ));
        if ($counts[ExchangeRateListImportResult::CONFLICT] > 0 && !$replace) {
            $output->writeln('<comment>Lístky s jiným obsahem přepíše --replace.</comment>');
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** Zarovnání výsledku na 11 znaků — sprintf počítá bajty, ne znaky s diakritikou. */
    private static function pad(string $label): string
    {
        return $label . str_repeat(' ', max(0, 11 - mb_strlen($label)));
    }

    /**
     * Soubor → [soubor]; složka → všechny .jsonc / .json setříděné podle
     * jména; neexistující cesta → null.
     *
     * @return list<string>|null
     */
    protected function collectFiles(string $path): ?array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return null;
        }
        $files = array_merge(glob(rtrim($path, '/') . '/*.jsonc') ?: [], glob(rtrim($path, '/') . '/*.json') ?: []);
        $files = array_values(array_filter($files, 'is_file'));
        sort($files, SORT_STRING);
        return $files;
    }

    /** Produkční wiring (přepsatelné v testech). */
    protected function createImporter(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): ExchangeRateListImporter
    {
        $language = $dsConfig->getDefaultLanguage();
        $config   = ConfigRuntime::load($dsDir, $language);
        $resolver = $this->buildResolver();
        $tables   = TableLoader::load($dsConfig, $resolver, $language);
        $registry = DocumentLoader::load($dsConfig, $resolver);

        return ExchangeRateListImporter::create($dsConnection->getDibiConnection(), $config, $dsConfig, $registry, $tables);
    }

    private function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }
}
