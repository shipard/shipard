<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Dibi\Connection;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Registry\RegistryExporter;
use Shipard\Module\Core\Exchange\Dataset\DatasetDumper;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetManifest;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Export\DocumentExporter;
use Shipard\Module\Core\Exchange\Export\ItemExporter;
use Shipard\Module\Core\Exchange\Export\PersonExporter;
use Shipard\Module\Economy\Assets\AssetDocument;
use Shipard\Module\Economy\Assets\Dataset\AssetExporter;
use Shipard\Module\Economy\Bank\Dataset\BankStatementExporter;
use Shipard\Module\Economy\ExchangeRates\Dataset\ExchangeRatesExporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\Dataset\ProvisionedItemsDetector;
use Shipard\Module\Economy\Items\Dataset\ProvisionedItemsResult;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderExporter;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Core\Exchange\Export\SetupExporter;
use Shipard\Module\Core\Mail\Dataset\MailExporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds dataset-dump <dir>` — export celého DS do přenosné datové sady
 * (`shpd.dataset.v1`, tasks/dataset-phase1.md, #40).
 *
 * Bere všechny nearchivované záznamy (`docState != 90`) sekcí setup,
 * persons, items, workOrders, assets, docs, bank, registry, mail; exportery pro moduly, které na DS
 * nejsou aktivní, se vynechají. Nezměněné účetní položky z nabídky osnovy
 * do `items/` nejdou — manifest je nese jako `provision.accountingItems`
 * a `country` zdroje dat, aby dump naseedované sady vrátil tytéž soubory
 * ({@see ProvisionedItemsDetector}, #131 krok 0c). Testovatelné přes
 * podtřídu (seamy getDataSourceDir / buildResolver / loadTables /
 * createDetector / createExporters).
 */
class DatasetDumpCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dataset-dump')
            ->setDescription('Export celého DS do přenosné datové sady (složka, volitelně zip)')
            ->addArgument('dir', InputArgument::REQUIRED, 'Cílová složka sady')
            ->addOption('zip', null, InputOption::VALUE_OPTIONAL, 'Zabalit i do zipu (cesta; bez hodnoty = <dir>.zip)', false)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Přepsat obsah existující neprázdné složky')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Identifikátor sady (default: název složky)')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Titulek sady (default: název DS)')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Popis sady');
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
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    /**
     * @return array<string, TableDefinition>
     */
    protected function loadTables(DataSourceConfig $dsConfig, ModulePathResolver $resolver, string $lang): array
    {
        return TableLoader::load($dsConfig, $resolver, $lang);
    }

    /**
     * Detektor nezměněných položek z nabídky osnovy; null = DS položky nemá.
     *
     * @param array<string, TableDefinition> $tables
     */
    protected function createDetector(
        DataSourceConnection $dsConnection,
        array $tables,
        string $lang,
        ModulePathResolver $resolver,
    ): ?ProvisionedItemsDetector {
        if (!isset($tables['economy_items'])) {
            return null;
        }
        return new ProvisionedItemsDetector(
            $dsConnection,
            new AccountingItemsOffer($dsConnection, $resolver),
            $lang,
            $tables,
        );
    }

    /**
     * Exportery podle aktivních tabulek DS, v pořadí sekcí sady.
     *
     * @param array<string, TableDefinition> $tables
     * @param list<int> $excludedItemIds položky, které manifest pokrývá
     *        přes `provision.accountingItems` — `ItemExporter` je vynechá
     * @return array{setup: ?SetupExporter, records: list<RecordExporter>}
     */
    protected function createExporters(
        Connection $db,
        array $tables,
        DataSourceConfig $dsConfig,
        string $dsDir,
        ?ConfigRuntime $config = null,
        array $excludedItemIds = [],
    ): array {
        $country = $dsConfig->hasCountry() ? strtolower($dsConfig->getCountry()) : 'cz';
        $records = [];
        // Kurzovní lístky (#121 D10, U7) — jen ty, které resolver použije pro
        // doklady a návrhy v cizí měně; bez kompilované konfigurace (typy
        // lístků) se sekce nedumpne.
        if (isset($tables[ExchangeRateResolver::TABLE_LISTS]) && $config !== null) {
            $records[] = new ExchangeRatesExporter(
                $db,
                ExchangeRateResolver::create($db, $config, $dsConfig),
                isset($tables['docs_core_heads']),
                isset($tables['core_mail_incoming_messages']),
            );
        }
        if (isset($tables['base_persons_persons'])) {
            $records[] = new PersonExporter($db, $country);
        }
        if (isset($tables['economy_items'])) {
            $records[] = new ItemExporter($db, $excludedItemIds);
        }
        // Zakázky (#131 fáze 2b) před doklady: doklady je odkazují číslem
        // v `dimensions`, proto je dimenze zakázky přenosná jen s touto sekcí.
        $hasWorkOrders = isset($tables[WorkOrderDocument::TABLE]);
        if ($hasWorkOrders) {
            $records[] = new WorkOrderExporter($db, $config, $tables);
        }
        // Majetek (#131 fáze 2c) před doklady: řádky pořízení odkazují kartu
        // inventárním číslem v `dimensions`, dimenze je přenosná jen s touto sekcí.
        $hasAssets = isset($tables[AssetDocument::TABLE]);
        if ($hasAssets) {
            $records[] = new AssetExporter($db, $config, $tables);
        }
        if (isset($tables['docs_core_heads'])) {
            // Konfigurace kvůli dimenzím deníku na dokladech (#110 T2); přenosné
            // dimenze s vlastní sekcí (zakázky, majetek) podle aktivních tabulek.
            $sectionTables = [];
            if ($hasWorkOrders) {
                $sectionTables[] = WorkOrderDocument::TABLE;
            }
            if ($hasAssets) {
                $sectionTables[] = AssetDocument::TABLE;
            }
            $records[] = new DocumentExporter($db, $config, $sectionTables);
        }
        // Bankovní výpisy (#131 fáze 2a): pořadí za doklady = pořadí seedu i klíčů counts.
        if (isset($tables['economy_bank_statements'])) {
            $records[] = new BankStatementExporter($db);
        }
        if (isset($tables['base_registry_documents'])) {
            $records[] = new RegistryExporter($db, $dsDir);
        }
        if (isset($tables['core_mail_incoming_messages'])) {
            $records[] = new MailExporter($db, $dsDir);
        }
        return ['setup' => new SetupExporter($db, $tables), 'records' => $records];
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Not a data source directory (config/main.json missing).</error>');
            return Command::FAILURE;
        }

        $targetDir = rtrim((string) $input->getArgument('dir'), '/');
        if ($targetDir === '') {
            $output->writeln('<error>Target directory is required.</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $lang = $dsConfig->getDefaultLanguage();
        $resolver = $this->buildResolver();
        $tables = $this->loadTables($dsConfig, $resolver, $lang);
        $db = $dsConnection->getDibiConnection();

        $name = (string) ($input->getOption('name') ?? '');
        $name = $name !== '' ? $name : DatasetWriter::slug(basename($targetDir));
        $title = (string) ($input->getOption('title') ?? '');
        $title = $title !== '' ? $title : $dsConfig->getName();
        $description = $input->getOption('description');

        try {
            // Nezměněné položky z nabídky osnovy nese manifest, ne `items/`
            // (#131 krok 0c); `country` kvůli pojistce seedu proti DS jiné země.
            $provisioned = $this->createDetector($dsConnection, $tables, $lang, $resolver)?->detect()
                ?? ProvisionedItemsResult::none();

            $manifest = new DatasetManifest(
                name: $name,
                title: $title,
                description: is_string($description) && $description !== '' ? $description : null,
                dateMode: DatasetManifest::DATE_MODE_FIXED,
                created: gmdate('Y-m-d\TH:i:s\Z'),
                country: $dsConfig->hasCountry() ? strtolower($dsConfig->getCountry()) : null,
                provision: $provisioned->manifestProvision(),
            );

            $writer = DatasetWriter::create($targetDir, overwrite: (bool) $input->getOption('force'));
            // Kompilovaná konfigurace kvůli dimenzím deníku na dokladech; DS
            // bez ní (před ds-upgrade) se dumpne bez dimenzí.
            $config = is_file($dsDir . '/config/configuration/compiled.' . $lang . '.json')
                ? ConfigRuntime::load($dsDir, $lang)
                : null;
            $exporters = $this->createExporters($db, $tables, $dsConfig, $dsDir, $config, $provisioned->itemIds);

            $output->writeln("<info>Dataset dump</info> → {$writer->getRootDir()}");
            $result = (new DatasetDumper($writer))->dump($manifest, $exporters['setup'], $exporters['records']);

            foreach ($result->counts as $section => $count) {
                $output->writeln(sprintf('  %-10s %d', $section . ':', $count));
            }
            if ($provisioned->accountingItems !== null) {
                $output->writeln(sprintf(
                    '  %-10s accountingItems=%s (%d items not written to items/)',
                    'provision:',
                    $provisioned->isAll() ? 'all' : count($provisioned->accountingItems) . ' codes',
                    count($provisioned->itemIds),
                ));
            }
            foreach ($result->warnings as $w) {
                $output->writeln("  <comment>warning:</comment> {$w}");
            }

            $zip = $input->getOption('zip');
            if ($zip !== false) {
                $zipPath = is_string($zip) && $zip !== '' ? $zip : $targetDir . '.zip';
                $writer->zip($zipPath);
                $output->writeln("  zip:       {$zipPath}");
            }
        } catch (DatasetException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Done.</info> %d warning(s).',
            count($result->warnings),
        ));
        return Command::SUCCESS;
    }
}
