<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Bank\Command;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Bank\Import\ImportException;
use Shipard\Module\Economy\Bank\Import\StatementImportService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Import bankovního výpisu ze souboru (CAMT / GPC / FIO). Zdrojový soubor se
 * uloží jako příloha výpisu (provenience). Testovatelné přes podtřídu
 * (override getDataSourceDir + DI dsConfig/dsConnection).
 */
class BankImportStatementCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bank-import-statement')
            ->setDescription('Import bankovního výpisu ze souboru (CAMT/GPC/FIO)')
            ->addArgument('file', InputArgument::REQUIRED, 'Cesta k souboru výpisu')
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Vlastní bankovní spojení (kód nebo id) — override detekce účtu');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 5) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 5) . '/modules']);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        if (!is_file($file)) {
            $output->writeln("<error>Soubor nenalezen: {$file}</error>");
            return Command::FAILURE;
        }
        $raw = file_get_contents($file);
        if ($raw === false || $raw === '') {
            $output->writeln('<error>Soubor je prázdný nebo nečitelný.</error>');
            return Command::FAILURE;
        }

        $dsDir = $this->getDataSourceDir();
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $lang = $dsConfig->getDefaultLanguage();
        $resolver = $this->buildResolver();

        $config = ConfigRuntime::load($dsDir, $lang);
        $tables = TableLoader::load($dsConfig, $resolver, $lang);
        $attachments = new AttachmentService($dsConnection, $dsDir, $tables);
        $dibi = $dsConnection->getDibiConnection();
        $registry = DocumentLoader::load($dsConfig, $resolver);
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);
        $service = StatementImportService::create(
            $dibi,
            $config,
            $dsConfig,
            $registry,
            $tables,
            $dispatcher,
            $attachments,
        );

        // Kopie do tempu — upload přílohy nesmí spotřebovat uživatelův originál.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'bankimp_');
        @copy($file, $tmp);

        try {
            $summary = $service->import($raw, $input->getOption('account'), $tmp, basename($file), null);
        } catch (ImportException $e) {
            @unlink($tmp);
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }

        $this->printSummary($output, $summary);
        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $s */
    private function printSummary(OutputInterface $output, array $s): void
    {
        $output->writeln("Formát: {$s['format']}");
        $output->writeln(sprintf(
            'Celkem: vytvořeno %d, přeskočeno %d, nespárovaný partner %d',
            $s['created'],
            $s['skipped'],
            $s['unmatchedPartner'],
        ));
        foreach ($s['statements'] as $i => $st) {
            $n = $i + 1;
            if (isset($st['error'])) {
                $output->writeln("  <error>[výpis {$n}] {$st['error']}</error>");
                continue;
            }
            $rec = match ((int) $st['reconciliation']) {
                1 => 'souhlasí',
                2 => 'NESOUHLASÍ',
                default => 'nezkontrolováno',
            };
            $output->writeln(sprintf(
                '  [výpis %d] účet %s (id %d): +%d / přeskočeno %d, reconciliace: %s',
                $n,
                $st['bankAccountRef'],
                $st['statementId'],
                $st['created'],
                $st['skipped'],
                $rec,
            ));
            if (!empty($st['currencyWarning'])) {
                $output->writeln("    <comment>{$st['currencyWarning']}</comment>");
            }
        }
    }
}
