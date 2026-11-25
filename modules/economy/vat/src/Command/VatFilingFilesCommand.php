<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Command;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\Xml\FilingFilesFactory;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Shipard\Module\Economy\Vat\Xml\FilingXmlValidationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Soubory podání DPH pro daňový portál z příkazové řádky (issue #55,
 * Fáze 3, X8).
 *
 * Bez `--out` se soubory uloží jako přílohy podání — totéž, co dělá akce
 * „Vytvořit soubory" a přechod do stavu Podáno. S `--out` se jen zapíšou
 * do adresáře a databáze se nedotknou; to je cesta pro E2E a pro zlatý
 * test (porovnání se skutečně podanými soubory).
 *
 * Testovatelné přes podtřídu (override getDataSourceDir + DI
 * dsConfig/dsConnection).
 */
class VatFilingFilesCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vat-filing-files')
            ->setDescription('Vyrobí soubory podání DPH pro daňový portál (XML, PDF opis)')
            ->addOption('filing', null, InputOption::VALUE_REQUIRED, 'Id podání DPH')
            ->addOption(
                'out',
                null,
                InputOption::VALUE_REQUIRED,
                'Adresář pro zápis souborů; bez něj se uloží jako přílohy podání',
            )
            ->addOption('xml-only', null, InputOption::VALUE_NONE, 'Jen XML, bez PDF');
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

        $filingId = (int) $input->getOption('filing');
        if ($filingId <= 0) {
            $output->writeln('<error>Zadejte --filing s id podání.</error>');
            return Command::INVALID;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if (!in_array('economy_vat_filings', $dsConnection->getAllTableNames(), true)) {
            $output->writeln('<error>economy.vat není aktivní — tabulka podání neexistuje (spusťte ds-upgrade).</error>');
            return Command::FAILURE;
        }

        $config  = ConfigRuntime::load($dsDir, $dsConfig->getDefaultLanguage());
        $outDir  = $input->getOption('out');
        $xmlOnly = (bool) $input->getOption('xml-only');
        $service = FilingFilesFactory::create($dsConnection->getDibiConnection(), $config, $dsConfig);

        try {
            $result = $outDir === null
                ? $service->generate($filingId, $xmlOnly)
                : $service->build($filingId, $xmlOnly);
        } catch (FilingXmlValidationException $e) {
            $output->writeln('<error>Podání nelze vygenerovat:</error>');
            foreach ($e->getErrors() as $error) {
                $output->writeln("  <error>{$error->column}: {$error->message}</error>");
            }
            return Command::FAILURE;
        } catch (\DomainException | \RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        if ($outDir !== null && !$this->writeFiles($output, (string) $outDir, $result->files)) {
            return Command::FAILURE;
        }

        foreach ($result->files as $index => $file) {
            $target = $outDir !== null
                ? rtrim((string) $outDir, '/') . '/' . $file->name
                : $file->name . ' (příloha #' . ($result->attachmentIds[$index] ?? '?') . ')';
            $output->writeln(sprintf('  %-13s %s', $file->kind, $target));
        }
        foreach ($result->warnings as $warning) {
            $output->writeln("<comment>  {$warning}</comment>");
        }

        return Command::SUCCESS;
    }

    /** @param list<\Shipard\Module\Economy\Vat\Xml\FilingFile> $files */
    private function writeFiles(OutputInterface $output, string $outDir, array $files): bool
    {
        if (!is_dir($outDir) && !@mkdir($outDir, 0o750, true) && !is_dir($outDir)) {
            $output->writeln("<error>Adresář '{$outDir}' nelze vytvořit.</error>");
            return false;
        }
        foreach ($files as $file) {
            $path = rtrim($outDir, '/') . '/' . $file->name;
            if (file_put_contents($path, $file->content) === false) {
                $output->writeln("<error>Soubor '{$path}' nelze zapsat.</error>");
                return false;
            }
        }
        return true;
    }
}
