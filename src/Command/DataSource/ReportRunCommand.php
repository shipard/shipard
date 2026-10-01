<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Core\Reports\Export\ReportExportContextFactory;
use Shipard\Core\Reports\Export\ReportExporter;
use Shipard\Core\Reports\Export\ReportExportFormat;
use Shipard\Core\Reports\ReportNotFoundException;
use Shipard\Core\Reports\ReportRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spustí report a vypíše `ReportResult::toArray()` jako čistý JSON na stdout
 * (žádné dekorace — pipe-friendly, vstupní materiál pro `report-diff`
 * a skripty). Wiring `ReportRunner` shodný s `dispatchReports`.
 *
 * `--format=xlsx|csv` místo JSON vyrobí soubor exportu (docs/reports.md
 * §15): xlsx vyžaduje `--output`, csv bez něj jde na stdout.
 *
 * D15: výsledek se `status: errors|warnings` je legitimní výstup — poznámka
 * jde na stderr, exit code zůstává 0. Nenulový exit = nevalidní vstup
 * (neznámý report, špatné parametry) nebo chyba prostředí.
 */
class ReportRunCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('report-run')
             ->setDescription('Spustí report a vypíše ReportResult jako JSON na stdout (--format=xlsx|csv = export do souboru)')
             ->addArgument('reportId', InputArgument::REQUIRED, 'Id reportu (např. economy.accounting.generalLedger)')
             ->addOption('fiscal-year', null, InputOption::VALUE_REQUIRED, 'Název fiskálního roku (např. 2026) — reporty s fiskálním obdobím')
             ->addOption('month-from', null, InputOption::VALUE_REQUIRED, 'První fiskální měsíc intervalu (1–N)')
             ->addOption('month-to', null, InputOption::VALUE_REQUIRED, 'Poslední fiskální měsíc intervalu (1–N)')
             ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Id instance daňového tvrzení (economy_vat_report_periods) — reporty s obdobím DPH')
             ->addOption('detail', null, InputOption::VALUE_REQUIRED, 'Úroveň detailu: analytic | synthetic', 'analytic')
             ->addOption('param', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Další parametr reportu jako id=hodnota (opakovatelné; nabídku ukáže deklarace reportu)')
             ->addOption('pretty', null, InputOption::VALUE_NONE, 'Formátovaný JSON (odsazení)')
             ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Výstupní formát: json | xlsx | csv', 'json')
             ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Cílový soubor (u xlsx povinný; json a csv bez něj na stdout)');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Formát se ověřuje před čímkoli dalším — překlep nemá stát běh reportu.
        $formatRaw    = (string) $input->getOption('format');
        $exportFormat = $formatRaw === 'json' ? null : ReportExportFormat::tryFrom($formatRaw);
        if ($formatRaw !== 'json' && $exportFormat === null) {
            $err->writeln("<error>Invalid --format '{$formatRaw}' (json | xlsx | csv)</error>");
            return Command::INVALID;
        }
        $outputFile = $input->getOption('output');
        $outputFile = is_string($outputFile) && $outputFile !== '' ? $outputFile : null;
        if ($exportFormat === ReportExportFormat::Xlsx && $outputFile === null) {
            $err->writeln('<error>--format=xlsx requires --output=<file></error>');
            return Command::INVALID;
        }

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $language     = $dsConfig->getDefaultLanguage();

        try {
            $configRuntime = ConfigRuntime::load($dsConfig->getDataSourceDir(), $language);
        } catch (\Throwable $e) {
            $err->writeln('<error>Failed to load compiled config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $registry = ReportDefinitionLoader::load($dsConfig, $this->getModulePathResolver(), $language);
        $runner   = new ReportRunner(
            $registry,
            $dsConnection,
            $configRuntime,
            $dsConfig->getId(),
            $language,
            country: $dsConfig->getCountry(),
        );

        $reportId   = (string) $input->getArgument('reportId');
        $definition = $registry->get($reportId);

        // Povinné volby dle periodSource deklarace. Neznámý report propadá
        // s prázdnými parametry na runner — ten vypíše dostupné reporty.
        $rawParams = [];
        if ($definition !== null && $definition->periodSource === 'vatPeriod') {
            if ($input->getOption('period') === null) {
                $err->writeln('<error>Missing required option --period</error>');
                return Command::INVALID;
            }
            $rawParams = ['period' => (string) $input->getOption('period')];
        } elseif ($definition !== null) {
            foreach (['fiscal-year', 'month-from', 'month-to'] as $option) {
                if ($input->getOption($option) === null) {
                    $err->writeln("<error>Missing required option --{$option}</error>");
                    return Command::INVALID;
                }
            }
            $rawParams = [
                'fiscalYear' => (string) $input->getOption('fiscal-year'),
                'monthFrom'  => (string) $input->getOption('month-from'),
                'monthTo'    => (string) $input->getOption('month-to'),
            ];
        }
        // `detail` je per-report parametr — reportu, který ho nedeklaruje,
        // se default nepodsouvá (validátor by ho odmítl jako neznámý).
        if ($definition !== null) {
            foreach ($definition->params as $param) {
                if ($param['id'] === 'detail') {
                    $rawParams['detail'] = (string) $input->getOption('detail');
                    break;
                }
            }
        }

        // Ostatní parametry deklarace (`--param id=hodnota`); platnost id
        // i hodnoty hlídá validátor runneru. `--param detail=…` má přednost
        // před `--detail`.
        foreach ((array) $input->getOption('param') as $pair) {
            $parts = explode('=', (string) $pair, 2);
            if (count($parts) !== 2 || $parts[0] === '') {
                $err->writeln("<error>Invalid --param '{$pair}' (expected id=value)</error>");
                return Command::INVALID;
            }
            $rawParams[$parts[0]] = $parts[1];
        }

        try {
            $result = $runner->run($reportId, $rawParams);
        } catch (ReportNotFoundException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            $err->writeln('Available reports: ' . implode(', ', array_map(
                static fn ($d): string => $d->id,
                $registry->getAll(),
            )));
            return Command::INVALID;
        } catch (\InvalidArgumentException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        if ($exportFormat !== null) {
            // $definition tady existuje — neznámý report skončil výjimkou runneru.
            $contextFactory = new ReportExportContextFactory(
                ReportExportContextFactory::resolveDataSourceName($dsConnection, $dsConfig),
                $configRuntime,
                $language,
                new DbFiscalPeriodProvider($dsConnection),
            );
            $body = (new ReportExporter())
                ->export($result, $exportFormat, $contextFactory->create($definition, $result))
                ->body;
        } else {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
            if ((bool) $input->getOption('pretty')) {
                $flags |= JSON_PRETTY_PRINT;
            }
            $body = (string) json_encode($result->toArray(), $flags) . "\n";
        }

        if ($outputFile !== null) {
            if (@file_put_contents($outputFile, $body) === false) {
                $err->writeln("<error>Cannot write output file '{$outputFile}'</error>");
                return Command::FAILURE;
            }
        } else {
            $output->write($body, false, OutputInterface::OUTPUT_RAW);
        }

        // D15: errors/warnings nejsou chyba requestu — jen zřetelná stopa na stderr.
        if ($result->status->value !== 'ok') {
            $where = match ($exportFormat) {
                null                     => 'see "messages" in the output',
                ReportExportFormat::Xlsx => 'see the messages sheet of the workbook',
                ReportExportFormat::Csv  => 'CSV carries no messages, run with --format=json to see them',
            };
            $err->writeln(sprintf(
                '<comment>Note: report finished with status "%s" (%d message(s)) — %s.</comment>',
                $result->status->value,
                count($result->messages),
                $where,
            ));
        }

        return Command::SUCCESS;
    }
}
