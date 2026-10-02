<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\PrintDefinitionLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintDocument;
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
 * (ladění, testy kontraktu bez renderu), `--format=pdf` vyrobí soubor,
 * `--format=html` zapíše do adresáře přesně to, co jde do render služby.
 * Wiring `PrintRunner` shodný s REST (`PrintRunnerFactory`).
 *
 * `--data=<PrintData.json>` přeskočí databázi i builder a renderuje hotová
 * data — vývoj šablon bez záznamu (#90 D28).
 *
 * Měkká hlášení builderu (QR nevznikl) jdou na stderr, exit code zůstává 0.
 * Nenulový exit = neznámý tisk nebo záznam, tisk pro záznam není dostupný,
 * nebo PDF nevzniklo.
 */
class PrintRunCommand extends Command
{
    /** Soubory HTML výstupu; assety leží vedle pod svými jmény. */
    public const HTML_PAGE_FILE   = 'index.html';
    public const HTML_HEADER_FILE = 'header.html';
    public const HTML_FOOTER_FILE = 'footer.html';

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
             ->setDescription('Spustí tisk nad záznamem: PrintData jako JSON na stdout, PDF do souboru (--format=pdf --output=<soubor>), nebo HTML do adresáře (--format=html --output=<adresář>)')
             ->addArgument('printId', InputArgument::REQUIRED, 'Id tisku (např. docs.invoicesOut.invoice)')
             ->addArgument('recordId', InputArgument::OPTIONAL, 'Id záznamu v tabulce tisku (s --data se nezadává)')
             ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Výstupní formát: json | pdf | html', 'json')
             ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Jazyk tisku (cs | en); výchozí je jazyk zdroje dat, s --data jazyk z dat')
             ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Cílový soubor (u pdf povinný; json bez něj na stdout), u html cílový adresář (povinný)')
             ->addOption('data', null, InputOption::VALUE_REQUIRED, 'Soubor s hotovým PrintData (JSON) — render bez databáze a builderu; jen html | pdf');
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
            $err->writeln("<error>Invalid --format '{$formatRaw}' (json | pdf | html)</error>");
            return Command::INVALID;
        }
        $outputFile = $input->getOption('output');
        $outputFile = is_string($outputFile) && $outputFile !== '' ? $outputFile : null;
        if ($format === PrintFormat::Pdf && $outputFile === null) {
            $err->writeln('<error>--format=pdf requires --output=<file></error>');
            return Command::INVALID;
        }
        if ($format === PrintFormat::Html && $outputFile === null) {
            $err->writeln('<error>--format=html requires --output=<directory></error>');
            return Command::INVALID;
        }
        $language = $input->getOption('language');
        $language = is_string($language) && $language !== '' ? $language : null;

        $dataFile  = $input->getOption('data');
        $dataFile  = is_string($dataFile) && $dataFile !== '' ? $dataFile : null;
        $recordRaw = $input->getArgument('recordId');
        $envelope  = null;
        if ($dataFile !== null) {
            if ($recordRaw !== null) {
                $err->writeln('<error>recordId is not used with --data</error>');
                return Command::INVALID;
            }
            if ($format === PrintFormat::Json) {
                $err->writeln('<error>--data renders ready print data — use --format=html or --format=pdf</error>');
                return Command::INVALID;
            }
            $json = @file_get_contents($dataFile);
            if ($json === false) {
                $err->writeln("<error>Cannot read --data file '{$dataFile}'</error>");
                return Command::INVALID;
            }
            $envelope = json_decode($json, true);
            if (!is_array($envelope)) {
                $err->writeln("<error>--data file '{$dataFile}' is not a JSON object</error>");
                return Command::INVALID;
            }
        } elseif (!is_string($recordRaw) || !ctype_digit($recordRaw)) {
            $err->writeln(sprintf(
                "<error>Invalid recordId '%s' (expected a positive integer)</error>",
                is_string($recordRaw) ? $recordRaw : '',
            ));
            return Command::INVALID;
        }

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        // Hotová data se renderují bez databáze — spojení se ani nenavazuje.
        $dsConnection = $envelope !== null
            ? null
            : ($this->dsConnection ?? new DataSourceConnection($dsConfig));
        $modules      = $this->getModulePathResolver();

        $registry = PrintDefinitionLoader::load($dsConfig, $modules, $dsConfig->getDefaultLanguage());
        // Render klient jen pro PDF — JSON a HTML nepotřebují server.json ani službu.
        $runner   = PrintRunnerFactory::create(
            $registry,
            $dsConfig,
            $dsConnection,
            $modules,
            $format === PrintFormat::Pdf ? RenderClient::fromServerConfig($this->getServerConfig()) : null,
        );

        $printId = (string) $input->getArgument('printId');
        try {
            $result = $envelope !== null
                ? $runner->renderData($printId, $envelope, $format, $language)
                : $runner->run($printId, (int) $recordRaw, $format, $language);
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

        if ($result->document !== null) {
            $failed = $this->writeDocument((string) $outputFile, $result->document);
            if ($failed !== null) {
                $err->writeln("<error>Cannot write '{$failed}'</error>");
                return Command::FAILURE;
            }
        } else {
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

    /**
     * Zapíše tisk do adresáře tak, jak jde do render služby: stránka,
     * záhlaví, zápatí a assety pod svými jmény. Adresář vytvoří, soubory
     * stejného jména přepíše, jiné nechá být.
     *
     * @return ?string Cesta, kterou se nepodařilo zapsat; null = hotovo.
     */
    private function writeDocument(string $dir, PrintDocument $document): ?string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $dir;
        }

        $files = [];
        foreach ($document->assets as $name => $content) {
            $files[basename($name)] = $content;
        }
        $files[self::HTML_PAGE_FILE] = $document->html;
        if ($document->header !== null) {
            $files[self::HTML_HEADER_FILE] = $document->header;
        }
        if ($document->footer !== null) {
            $files[self::HTML_FOOTER_FILE] = $document->footer;
        }

        foreach ($files as $name => $content) {
            $file = rtrim($dir, '/') . '/' . $name;
            if (@file_put_contents($file, $content) === false) {
                return $file;
            }
        }
        return null;
    }
}
