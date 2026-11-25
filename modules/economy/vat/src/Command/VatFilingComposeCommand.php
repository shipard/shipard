<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Command;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Vat\FilingComposer;
use Shipard\Module\Economy\Vat\FilingDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sestavení podání DPH z příkazové řádky (issue #55, Fáze 2) — E2E cesta
 * bez UI: nad instancí tvrzení založí podání zvoleného druhu (validace
 * a snapshot běží přes FilingDocument, stejně jako z formuláře), nebo
 * přepočítá snapshot existujícího konceptu.
 *
 * Testovatelné přes podtřídu (override getDataSourceDir + DI
 * dsConfig/dsConnection).
 */
class VatFilingComposeCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vat-filing-compose')
            ->setDescription('Sestaví podání DPH za instanci daňového tvrzení, nebo přepočítá snapshot konceptu')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Id instance daňového tvrzení — založí nové podání')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Druh podání (default regular)')
            ->addOption('date-found', null, InputOption::VALUE_REQUIRED, 'Datum zjištění důvodů YYYY-MM-DD (dodatečné, následné)')
            ->addOption('recompute', null, InputOption::VALUE_REQUIRED, 'Id existujícího konceptu — jen přepočítá snapshot');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
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
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $periodId  = (int) $input->getOption('period');
        $recompute = (int) $input->getOption('recompute');
        if (($periodId > 0) === ($recompute > 0)) {
            $output->writeln('<error>Zadejte právě jednu z voleb --period (nové podání) nebo --recompute (přepočet).</error>');
            return Command::INVALID;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $dibi         = $dsConnection->getDibiConnection();

        if (!in_array('economy_vat_filings', $dsConnection->getAllTableNames(), true)) {
            $output->writeln('<error>economy.vat není aktivní — tabulka podání neexistuje (spusťte ds-upgrade).</error>');
            return Command::FAILURE;
        }

        $language = $dsConfig->getDefaultLanguage();
        $config   = ConfigRuntime::load($dsDir, $language);

        if ($recompute > 0) {
            return $this->recompose($dibi, $config, $recompute, $output);
        }
        return $this->createFiling($input, $output, $dsConfig, $dsConnection, $config, $language, $periodId);
    }

    /** Přepočet snapshotu konceptu — transakci vlastní příkaz. */
    private function recompose(
        \Dibi\Connection $dibi,
        ConfigRuntime $config,
        int $filingId,
        OutputInterface $output,
    ): int {
        $dibi->begin();
        try {
            $summary = (new FilingComposer($dibi, $config))->compose($filingId);
            $dibi->commit();
        } catch (\Throwable $e) {
            $dibi->rollback();
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }
        $this->printSummary($output, $filingId, $summary);
        return Command::SUCCESS;
    }

    /**
     * Nové podání přes TableGateway — validace, pořadí, název i snapshot
     * (afterPersist) jdou stejnou cestou jako z formuláře.
     */
    private function createFiling(
        InputInterface $input,
        OutputInterface $output,
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        ConfigRuntime $config,
        string $language,
        int $periodId,
    ): int {
        $resolver = $this->buildResolver();
        $dibi     = $dsConnection->getDibiConnection();
        $tables   = TableLoader::load($dsConfig, $resolver, $language);
        $registry = DocumentLoader::load($dsConfig, $resolver);

        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher    = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);

        $definition = $tables[FilingDocument::TABLE] ?? null;
        if ($definition === null) {
            $output->writeln('<error>Definice tabulky economy_vat_filings nenalezena.</error>');
            return Command::FAILURE;
        }

        $data = [
            'report_period' => $periodId,
            'filing_kind'   => (string) ($input->getOption('kind') ?? '') !== ''
                ? (string) $input->getOption('kind')
                : FilingDocument::KIND_REGULAR,
            'docState'      => FilingDocument::DOC_STATE_COMPOSED,
            'docStateMain'  => 1,
        ];
        $dateFound = (string) ($input->getOption('date-found') ?? '');
        if ($dateFound !== '') {
            $data['date_found'] = $dateFound;
        }

        $gateway = new TableGateway(
            FilingDocument::TABLE,
            $dibi,
            $registry,
            $definition->childTables,
            $config,
            $dsConfig,
            $dispatcher,
            $definition->docStates,
        );
        $result = $gateway->saveDocument($data);

        if (!$result->isSuccess()) {
            $validation = $result->getValidation();
            if ($validation !== null) {
                foreach ($validation->toArray() as $error) {
                    $output->writeln("<error>{$error['column']}: {$error['message']}</error>");
                }
            } else {
                $output->writeln('<error>' . ($result->getErrorMessage() ?? 'Uložení podání selhalo') . '</error>');
            }
            return Command::FAILURE;
        }

        $saved    = $result->getData();
        $filingId = (int) ($saved['id'] ?? 0);
        $row      = $dibi->fetch('SELECT [name], [sequence] FROM %n WHERE [id] = %i', FilingDocument::TABLE, $filingId);
        $output->writeln(sprintf(
            'Podání #%d vytvořeno: %s (pořadí %d)',
            $filingId,
            (string) ($row['name'] ?? ''),
            (int) ($row['sequence'] ?? 0),
        ));

        $this->printSnapshotCounts($output, $dibi, $filingId);
        return Command::SUCCESS;
    }

    /** @param array{items: int, rows: int, isEmpty: bool} $summary */
    private function printSummary(OutputInterface $output, int $filingId, array $summary): void
    {
        $output->writeln(sprintf(
            'Podání #%d přepočítáno: %d dokladových řádků, %d výstupních řádků%s',
            $filingId,
            $summary['items'],
            $summary['rows'],
            $summary['isEmpty'] ? ' (prázdné podání)' : '',
        ));
    }

    private function printSnapshotCounts(OutputInterface $output, \Dibi\Connection $dibi, int $filingId): void
    {
        $items = (int) $dibi->fetchSingle(
            'SELECT COUNT(*) FROM [economy_vat_filing_items] WHERE [filing] = %i', $filingId,
        );
        $output->writeln("  dokladových řádků: {$items}");
        foreach ([
            'economy_vat_filing_return_rows' => 'řádky přiznání',
            'economy_vat_filing_cs_rows'     => 'řádky kontrolního hlášení',
            'economy_vat_filing_rs_rows'     => 'řádky souhrnného hlášení',
        ] as $table => $label) {
            $count = (int) $dibi->fetchSingle('SELECT COUNT(*) FROM %n WHERE [filing] = %i', $table, $filingId);
            if ($count > 0) {
                $output->writeln("  {$label}: {$count}");
            }
        }
        $result = $dibi->fetchSingle('SELECT [result] FROM %n WHERE [id] = %i', FilingDocument::TABLE, $filingId);
        if (is_string($result) && $result !== '') {
            $output->writeln('  výsledek: ' . $result);
        }
    }
}
