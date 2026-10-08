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
 * persons, items, docs, registry, mail; exportery pro moduly, které na DS
 * nejsou aktivní, se vynechají. Testovatelné přes podtřídu (seamy
 * getDataSourceDir / buildResolver / loadTables / createExporters).
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
     * Exportery podle aktivních tabulek DS, v pořadí sekcí sady.
     *
     * @param array<string, TableDefinition> $tables
     * @return array{setup: ?SetupExporter, records: list<RecordExporter>}
     */
    protected function createExporters(
        Connection $db,
        array $tables,
        DataSourceConfig $dsConfig,
        string $dsDir,
        ?ConfigRuntime $config = null,
    ): array {
        $country = $dsConfig->hasCountry() ? strtolower($dsConfig->getCountry()) : 'cz';
        $records = [];
        if (isset($tables['base_persons_persons'])) {
            $records[] = new PersonExporter($db, $country);
        }
        if (isset($tables['economy_items'])) {
            $records[] = new ItemExporter($db);
        }
        if (isset($tables['docs_core_heads'])) {
            // Konfigurace kvůli dimenzím deníku na dokladech (#110 T2).
            $records[] = new DocumentExporter($db, $config);
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
        $tables = $this->loadTables($dsConfig, $this->buildResolver(), $lang);
        $db = $dsConnection->getDibiConnection();

        $name = (string) ($input->getOption('name') ?? '');
        $name = $name !== '' ? $name : DatasetWriter::slug(basename($targetDir));
        $title = (string) ($input->getOption('title') ?? '');
        $title = $title !== '' ? $title : $dsConfig->getName();
        $description = $input->getOption('description');

        try {
            $manifest = new DatasetManifest(
                name: $name,
                title: $title,
                description: is_string($description) && $description !== '' ? $description : null,
                dateMode: DatasetManifest::DATE_MODE_FIXED,
                created: gmdate('Y-m-d\TH:i:s\Z'),
            );

            $writer = DatasetWriter::create($targetDir, overwrite: (bool) $input->getOption('force'));
            // Kompilovaná konfigurace kvůli dimenzím deníku na dokladech; DS
            // bez ní (před ds-upgrade) se dumpne bez dimenzí.
            $config = is_file($dsDir . '/config/configuration/compiled.' . $lang . '.json')
                ? ConfigRuntime::load($dsDir, $lang)
                : null;
            $exporters = $this->createExporters($db, $tables, $dsConfig, $dsDir, $config);

            $output->writeln("<info>Dataset dump</info> → {$writer->getRootDir()}");
            $result = (new DatasetDumper($writer))->dump($manifest, $exporters['setup'], $exporters['records']);

            foreach ($result->counts as $section => $count) {
                $output->writeln(sprintf('  %-10s %d', $section . ':', $count));
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
