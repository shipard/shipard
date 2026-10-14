<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Registry\Dataset\RegistrySeeder;
use Shipard\Module\Base\Registry\RegistryApplier;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetPreflight;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\DatasetSeeder;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\Seed\DocumentSeeder;
use Shipard\Module\Core\Exchange\Dataset\Seed\ItemSeeder;
use Shipard\Module\Core\Exchange\Dataset\Seed\PersonSeeder;
use Shipard\Module\Core\Exchange\Dataset\Seed\SetupSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Item\ItemApplier;
use Shipard\Module\Core\Exchange\Person\PersonApplier;
use Shipard\Module\Core\Exchange\Resolve\PartyResolver;
use Shipard\Module\Core\Mail\Dataset\MailSeeder;
use Shipard\Module\Docs\Core\OwnCompanyResolver;
use Shipard\Module\Economy\Codebooks\Dataset\FiscalYearsSeeder;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\Dataset\AccountingItemsSeeder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `shpd-ds dataset-seed <dir|zip>` — reset DS a naplnění obsahem datové sady
 * (tasks/dataset-phase1.md, #40).
 *
 * Průběh: otevření sady → preflight (schémata, validátory, přílohy, země
 * sady vs. DS — nic se ještě nemění) → potvrzení → na čerstvém DS bez
 * schématu `ds-upgrade` → nastavení ze sady → `ds-reset` (delegace,
 * respektuje `enableReset`; `--no-reset` přeskočí a doplňuje do
 * existujícího DS) → setup → fiskální roky → účetní položky → persons →
 * items → docs → registry → mail. Nenulový exit code při jakékoli chybě
 * záznamu. Testovatelné přes podtřídu (seamy getDataSourceDir /
 * buildResolver / runReset / runUpgrade / hasSettingsTable /
 * applySettingsBeforeReset / createContext / createSeeders / createPreflight).
 */
class DatasetSeedCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dataset-seed')
            ->setDescription('Reset DS a naplnění obsahem datové sady (složka nebo zip)')
            ->addArgument('path', InputArgument::REQUIRED, 'Složka sady nebo .zip')
            ->addOption('no-reset', null, InputOption::VALUE_NONE, 'Nemazat DS — doplnit sadu do existujícího obsahu')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Bez potvrzení');
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

    protected function createPreflight(): DatasetPreflight
    {
        return new DatasetPreflight();
    }

    /**
     * Delegace na `ds-reset --yes` ve stejném procesu (vzor DsResetCommand →
     * ds-upgrade). Produkční guard `enableReset` řeší ds-reset sám.
     */
    protected function runReset(OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Cannot run ds-reset: no application context</error>');
            return Command::FAILURE;
        }
        return $app->find('ds-reset')->run(new ArrayInput(['--yes' => true]), $output);
    }

    /**
     * `ds-upgrade` ve stejném procesu — čerstvý DS po `ds-create` nemá
     * schéma, takže by zápis nastavení před resetem neměl kam (#103 F2).
     */
    protected function runUpgrade(OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln('<error>Cannot run ds-upgrade: no application context</error>');
            return Command::FAILURE;
        }
        return $app->find('ds-upgrade')->run(new ArrayInput([]), $output);
    }

    /** Existuje `core_system_settings`? Ne = DS bez schématu. */
    protected function hasSettingsTable(DataSourceConnection $dsConnection): bool
    {
        return in_array('core_system_settings', $dsConnection->getAllTableNames(), true);
    }

    /**
     * `setup/settings.jsonc` → `core_system_settings` ještě před resetem:
     * tabulka reset přežije (keepOnReset) a provisionery v ds-upgrade podle
     * ní seedují účtový rozvrh a fiskální roky.
     */
    protected function applySettingsBeforeReset(DatasetReader $reader, DataSourceConfig $dsConfig): int
    {
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        return SetupSeeder::applySettings($reader, $dsConnection);
    }

    /**
     * Wiring až po resetu — tabulky i compiled config vznikají v ds-upgrade.
     */
    protected function createContext(
        DatasetReader $reader,
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        string $dsDir,
        bool $merge,
    ): SeedContext {
        $lang = $dsConfig->getDefaultLanguage();
        $resolver = $this->buildResolver();
        $config = ConfigRuntime::load($dsDir, $lang);
        $tables = TableLoader::load($dsConfig, $resolver, $lang);
        $dibi = $dsConnection->getDibiConnection();
        $registry = DocumentLoader::load($dsConfig, $resolver);
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);
        $attachments = new AttachmentService($dsConnection, $dsDir, $tables);

        return new SeedContext(
            reader: $reader,
            db: $dibi,
            dsConnection: $dsConnection,
            config: $config,
            dsConfig: $dsConfig,
            registry: $registry,
            tables: $tables,
            dsDir: $dsDir,
            attachments: $attachments,
            dispatcher: $dispatcher,
            merge: $merge,
        );
    }

    /**
     * Seedery podle aktivních tabulek, v pořadí sekcí sady.
     *
     * @return list<SectionSeeder>
     */
    protected function createSeeders(SeedContext $ctx): array
    {
        $db = $ctx->db;
        $party = new PartyResolver($db, new OwnCompanyResolver($db));
        $seeders = [new SetupSeeder()];

        // #103 D22/D23 — hned po setup/ (nastavení už platí), před záznamy.
        if (isset($ctx->tables['economy_codebooks_fiscal_years'])) {
            $seeders[] = new FiscalYearsSeeder();
        }
        // Podle manifestu, ne podle tabulky: DS bez účetnictví má skončit
        // chybou accounting_inactive, ne tichým přeskočením.
        if ($ctx->reader->getManifest()->accountingItemsRequest() !== null) {
            $seeders[] = new AccountingItemsSeeder(new AccountingItemsOffer($ctx->dsConnection, $this->buildResolver()));
        }

        $personApplier = null;
        if (isset($ctx->tables['base_persons_persons'])) {
            $personApplier = PersonApplier::create($db, $ctx->config, $ctx->dsConfig, $ctx->registry, $ctx->tables);
            $seeders[] = new PersonSeeder($personApplier);
        }
        if (isset($ctx->tables['economy_items'])) {
            $seeders[] = new ItemSeeder(ItemApplier::create($db, $ctx->config, $ctx->dsConfig, $ctx->registry, $ctx->tables, $personApplier));
        }
        if (isset($ctx->tables['docs_core_heads'])) {
            $seeders[] = new DocumentSeeder(DocumentApplier::create($db, $ctx->config, $ctx->dsConfig, $ctx->registry, $ctx->tables, $ctx->dispatcher));
        }
        if (isset($ctx->tables['base_registry_documents'])) {
            $seeders[] = new RegistrySeeder(
                new RegistryApplier($ctx->dsConnection, $ctx->registry, $ctx->attachments, $ctx->config, $party),
                $party,
            );
        }
        if (isset($ctx->tables['core_mail_incoming_messages'])) {
            $seeders[] = new MailSeeder($party);
        }
        return $seeders;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Not a data source directory (config/main.json missing).</error>');
            return Command::FAILURE;
        }
        $merge = (bool) $input->getOption('no-reset');

        // 1. Sada + preflight — před jakoukoli změnou DS. DataSourceConfig
        // čte jen main.json, takže země DS je k dispozici už tady (D24).
        try {
            $reader = DatasetReader::open((string) $input->getArgument('path'));
            $manifest = $reader->getManifest();
        } catch (DatasetException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $check = $this->createPreflight()->check($reader, $dsConfig->getCountry());
        foreach ($check['warnings'] as $w) {
            $output->writeln("  <comment>warning:</comment> {$w}");
        }
        if ($check['errors'] !== []) {
            $output->writeln('<error>Dataset preflight failed — nothing was changed:</error>');
            foreach ($check['errors'] as $e) {
                $output->writeln("  <error>{$e}</error>");
            }
            if ($manifest->country !== null && !$dsConfig->hasCountry()) {
                $output->writeln('  <comment>(main.json zdroje dat nemá country — bere se cz)</comment>');
            }
            $reader->close();
            return Command::FAILURE;
        }

        $counts = [];
        foreach ($manifest->counts as $section => $n) {
            $counts[] = "{$section} {$n}";
        }
        $output->writeln(sprintf(
            '<info>Dataset</info> %s — %s (%s)',
            $manifest->name,
            $manifest->title,
            $counts !== [] ? implode(', ', $counts) : 'bez counts',
        ));

        // 2. Potvrzení.
        if (!$input->getOption('yes')) {
            $question = new ConfirmationQuestion(
                $merge
                    ? "Seed dataset '{$manifest->name}' into data source '{$dsConfig->getName()}' WITHOUT reset (merge into existing content)? [y/N] "
                    : "This will RESET data source '{$dsConfig->getName()}' (all data tables dropped) and seed dataset '{$manifest->name}'. Continue? [y/N] ",
                false,
            );
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('Aborted.');
                $reader->close();
                return Command::SUCCESS;
            }
        }

        // 3. Schéma na čerstvém DS, nastavení DS před resetem, reset.
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        if (!$merge) {
            if (!$this->hasSettingsTable($dsConnection)) {
                $output->writeln('<info>Fresh data source — running ds-upgrade to create the schema…</info>');
                if ($this->runUpgrade($output) !== Command::SUCCESS) {
                    $output->writeln('<error>ds-upgrade failed — dataset not seeded.</error>');
                    $reader->close();
                    return Command::FAILURE;
                }
            }
            try {
                $n = $this->applySettingsBeforeReset($reader, $dsConfig);
                if ($n > 0) {
                    $output->writeln("Applied {$n} data source setting(s) from setup/settings.jsonc.");
                }
            } catch (\Throwable $e) {
                $output->writeln("<error>Settings failed: {$e->getMessage()}</error>");
                $reader->close();
                return Command::FAILURE;
            }
            $output->writeln('<info>Resetting data source…</info>');
            if ($this->runReset($output) !== Command::SUCCESS) {
                $output->writeln('<error>ds-reset failed — dataset not seeded.</error>');
                $reader->close();
                return Command::FAILURE;
            }
        }

        // 4. Seed.
        try {
            $ctx = $this->createContext($reader, $dsConfig, $dsConnection, $dsDir, $merge);
            $report = (new DatasetSeeder())->seed($ctx, $this->createSeeders($ctx));
        } catch (\Throwable $e) {
            $output->writeln("<error>Seed failed: {$e->getMessage()}</error>");
            $reader->close();
            return Command::FAILURE;
        } finally {
            $reader->close();
        }

        foreach ($report->counts() as $section => $c) {
            $output->writeln(sprintf(
                '  %-17s ok %d%s%s',
                $section . ':',
                $c['ok'],
                $c['failed'] > 0 ? ", <error>failed {$c['failed']}</error>" : '',
                $c['skipped'] > 0 ? ", skipped {$c['skipped']}" : '',
            ));
        }
        foreach ($report->warnings() as $w) {
            $output->writeln("  <comment>warning:</comment> {$w}");
        }
        foreach ($report->errors() as $e) {
            $output->writeln("  <error>error:</error> {$e}");
        }

        if ($report->hasErrors()) {
            $output->writeln(sprintf('<error>Seed finished with %d error(s).</error>', count($report->errors())));
            return Command::FAILURE;
        }
        $output->writeln(sprintf('<info>Seed complete.</info> %d warning(s).', count($report->warnings())));
        return Command::SUCCESS;
    }
}
