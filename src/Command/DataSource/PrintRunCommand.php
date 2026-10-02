<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintRunnerFactory;
use Shipard\Core\Render\RenderClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Spustí tisk nad jedním záznamem. `--format=json` vypíše `PrintData`
 * (ladění, testy kontraktu bez renderu), `--format=pdf` vyrobí soubor.
 * Wiring `PrintRunner` shodný s REST (`PrintRunnerFactory`).
 *
 * Měkká hlášení builderu (QR nevznikl) jdou na stderr, exit code zůstává 0.
 * Nenulový exit = neznámý tisk nebo záznam, tisk pro záznam není dostupný,
 * nebo PDF nevzniklo.
 */
class PrintRunCommand extends Command
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
        $this->setName('print-run')
             ->setDescription('Spustí tisk nad záznamem: PrintData jako JSON na stdout, nebo PDF do souboru (--format=pdf --output=<soubor>)')
             ->addArgument('printId', InputArgument::REQUIRED, 'Id tisku (např. docs.invoicesOut.invoice)')
             ->addArgument('recordId', InputArgument::REQUIRED, 'Id záznamu v tabulce tisku')
             ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Výstupní formát: json | pdf', 'json')
             ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Jazyk tisku (cs | en); výchozí je jazyk zdroje dat')
             ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Cílový soubor (u pdf povinný; json bez něj na stdout)');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function getServerConfig(): ServerConfig
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return $cfg;
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        return ModulePathResolver::fromServerConfig($this->getServerConfig(), dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        // Vstupy se ověřují před čímkoli dalším — překlep nemá stát běh tisku.
        $formatRaw = (string) $input->getOption('format');
        $format    = PrintFormat::tryFrom($formatRaw);
        if ($format === null) {
            $err->writeln("<error>Invalid --format '{$formatRaw}' (json | pdf)</error>");
            return Command::INVALID;
        }
        $outputFile = $input->getOption('output');
        $outputFile = is_string($outputFile) && $outputFile !== '' ? $outputFile : null;
        if ($format === PrintFormat::Pdf && $outputFile === null) {
            $err->writeln('<error>--format=pdf requires --output=<file></error>');
            return Command::INVALID;
        }
        $recordRaw = (string) $input->getArgument('recordId');
        if (!ctype_digit($recordRaw)) {
            $err->writeln("<error>Invalid recordId '{$recordRaw}' (expected a positive integer)</error>");
            return Command::INVALID;
        }
        $language = $input->getOption('language');
        $language = is_string($language) && $language !== '' ? $language : null;

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $modules      = $this->getModulePathResolver();

        $registry = PrintDefinitionLoader::load($dsConfig, $modules, $dsConfig->getDefaultLanguage());
        // Render klient jen pro PDF — JSON nepotřebuje server.json ani službu.
        $runner   = PrintRunnerFactory::create(
            $registry,
            $dsConfig,
            $dsConnection,
            $modules,
            $format === PrintFormat::Pdf ? RenderClient::fromServerConfig($this->getServerConfig()) : null,
        );

        $printId = (string) $input->getArgument('printId');
        try {
            $result = $runner->run($printId, (int) $recordRaw, $format, $language);
        } catch (PrintNotFoundException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            $err->writeln('Available prints: ' . implode(', ', array_map(
                static fn ($d): string => $d->id,
                $registry->getAll(),
            )));
            return Command::INVALID;
        } catch (\InvalidArgumentException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (PrintRecordNotFoundException | PrintNotAvailableException | PrintBuildException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (PrintRenderException $e) {
            $err->writeln("<error>PDF render failed ({$e->errorKind->value}): {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $body = $format === PrintFormat::Pdf
            ? (string) $result->pdfContent
            : (string) json_encode(
                $result->printData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ) . "\n";

        if ($outputFile !== null) {
            if (@file_put_contents($outputFile, $body) === false) {
                $err->writeln("<error>Cannot write output file '{$outputFile}'</error>");
                return Command::FAILURE;
            }
        } else {
            $output->write($body, false, OutputInterface::OUTPUT_RAW);
        }

        foreach ($result->printData->messages as $message) {
            $err->writeln(sprintf(
                '<comment>%s: %s (%s)</comment>',
                ucfirst($message->severity->value),
                $message->text,
                $message->code,
            ));
        }

        return Command::SUCCESS;
    }
}
