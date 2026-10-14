<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\ReportDefinitionLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetPreflight;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Verify\ApplyFidelityCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\ApplyReport;
use Shipard\Module\Core\Exchange\Dataset\Verify\CheckResult;
use Shipard\Module\Core\Exchange\Dataset\Verify\ProposalApplyStep;
use Shipard\Module\Core\Exchange\Dataset\Verify\SetDocuments;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyCheck;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyContext;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyExpectations;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyPreflight;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyReport;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyWiring;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Mail\MessageProposalApplier;
use Shipard\Module\Economy\Accounting\Dataset\CostsCheck;
use Shipard\Module\Economy\Accounting\Dataset\LedgerSnapshotCheck;
use Shipard\Module\Economy\Items\Dataset\ItemsAndAccountsCheck;
use Shipard\Module\Economy\Vat\Dataset\VatReportingCheck;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds dataset-verify <sada> [--no-apply] [--json] [--write-ledger-snapshot=<soubor>]`
 * — ověření zaúčtování a DPH nad naseedovanou datovou sadou (#103 F3,
 * tasks/testing-phase3.md D26–D30, docs/test-corpus.md § 17).
 *
 * Preflight (nic se nemění, exit 2): server mimo `production`, země sady =
 * země zdroje dat a schémata (`DatasetPreflight`), každá zpráva zdroje dat
 * je ze sady a každý doklad vznikl ze zprávy sady — žádný `--force`.
 * Apply: otevřené návrhy v pořadí přijetí jako doklady V pořádku
 * (`MessageProposalApplier`), doklady v cizí měně se přeskočí (D30).
 * Kontroly běží nad všemi doklady sady, i z dřívějšího běhu: věrnost apply,
 * položky a účty, DPH, náklady, deník proti snímku. Exit 0 / 1 rozdíl či
 * selhání apply / 2 vstup. Testovatelné přes podtřídu (seamy níže).
 */
class DatasetVerifyCommand extends Command
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
        $this->setName('dataset-verify')
            ->setDescription('Použije návrhy naseedované datové sady jako doklady a ověří zaúčtování, DPH a náklady proti očekávání sady')
            ->addArgument('path', InputArgument::REQUIRED, 'Složka sady (s expected/) nebo .zip')
            ->addOption('no-apply', null, InputOption::VALUE_NONE, 'Jen kontroly — návrhy nepoužívat')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Report jako JSON na stdout')
            ->addOption('write-ledger-snapshot', null, InputOption::VALUE_REQUIRED, 'Zapsat aktuální snímek hlavní knihy (ReportResult JSON) do souboru — ke schválení');
    }

    // ── Seamy ──────────────────────────────────────────────────────────────

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    /** Režim serveru; `development` bez server.json (produkce ho má vždy). */
    protected function getServerMode(): string
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg->getMode();
        } catch (\Throwable) {
            return 'development';
        }
    }

    protected function buildResolver(): ModulePathResolver
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    protected function createPreflight(): DatasetPreflight
    {
        return new DatasetPreflight();
    }

    /** Wiring zdroje dat — stav 40 účtuje jen přes dispatcher (vzor DatasetSeedCommand). */
    protected function createWiring(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, string $dsDir): VerifyWiring
    {
        $lang = $dsConfig->getDefaultLanguage();
        $resolver = $this->buildResolver();
        $config = ConfigRuntime::load($dsDir, $lang);
        $tables = TableLoader::load($dsConfig, $resolver, $lang);
        $dibi = $dsConnection->getDibiConnection();
        $registry = DocumentLoader::load($dsConfig, $resolver);
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);
        return new VerifyWiring($dibi, $dsConnection, $dsConfig, $dsDir, $config, $tables, $registry, $dispatcher, $resolver);
    }

    /**
     * Zprávy mimo sadu a doklady bez zprávy sady (všechny stavy vč. Koše).
     *
     * @param list<string> $setMessageIds
     * @return list<string>
     */
    protected function membershipProblems(VerifyWiring $w, array $setMessageIds): array
    {
        $messages = [];
        if ($w->hasTable('core_mail_incoming_messages')) {
            foreach ($w->db->fetchAll('SELECT [id], [message_id] FROM [core_mail_incoming_messages]') as $m) {
                $messages[(int) $m['id']] = (string) $m['message_id'];
            }
        }
        $docs = [];
        if ($w->hasTable('docs_core_heads')) {
            foreach ($w->db->fetchAll('SELECT [id], [source_message] FROM [docs_core_heads]') as $d) {
                $docs[(int) $d['id']] = $d['source_message'] !== null ? (int) $d['source_message'] : null;
            }
        }
        return VerifyPreflight::membershipProblems($setMessageIds, $messages, $docs);
    }

    /**
     * @param list<string> $setMessageIds
     * @return list<\Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument>
     */
    protected function loadDocuments(VerifyWiring $w, array $setMessageIds): array
    {
        return SetDocuments::load($w->db, $setMessageIds);
    }

    protected function homeCurrency(VerifyWiring $w): string
    {
        $value = (new SettingsStore($w->dsConnection))->get('economy.homeCurrency');
        return strtoupper(is_string($value) && $value !== '' ? $value : 'czk');
    }

    protected function countItems(VerifyWiring $w): ?int
    {
        if (!$w->hasTable('economy_items')) {
            return null;
        }
        return (int) $w->db->fetchSingle('SELECT COUNT(*) FROM [economy_items] WHERE [docState] <> 90');
    }

    /**
     * @param list<\Shipard\Module\Core\Exchange\Dataset\Verify\SetDocument> $documents
     * @param array<string, string> $skipped
     */
    protected function applyProposals(VerifyWiring $w, array $documents, array $skipped): ApplyReport
    {
        if ($w->config === null || $w->registry === null) {
            throw new \RuntimeException('Wiring bez konfigurace nebo registru Document tříd.');
        }
        $applier = DocumentApplier::create($w->db, $w->config, $w->dsConfig, $w->registry, $w->tables, $w->dispatcher);
        $enricher = RowEnrichmentPipeline::create($w->dsConnection, $w->config, $w->dsConfig);
        $proposals = new MessageProposalApplier($w->dsConnection, $applier, $enricher, $w->config);
        return (new ProposalApplyStep($proposals))->run($documents, $skipped);
    }

    protected function createReportRunner(VerifyWiring $w): ?ReportRunner
    {
        if ($w->resolver === null) {
            return null;
        }
        $language = $w->dsConfig->getDefaultLanguage();
        $registry = ReportDefinitionLoader::load($w->dsConfig, $w->resolver, $language);
        return new ReportRunner($registry, $w->dsConnection, $w->config, $w->dsConfig->getId(), $language, country: $w->dsConfig->getCountry());
    }

    /**
     * Kontroly podle aktivních tabulek, v pořadí zadání.
     *
     * @return list<VerifyCheck>
     */
    protected function createChecks(VerifyContext $ctx): array
    {
        $w = $ctx->wiring;
        $checks = [];
        if ($w->hasTable('docs_core_heads')) {
            $checks[] = new ApplyFidelityCheck();
        }
        if ($w->hasTable('economy_items')) {
            $checks[] = new ItemsAndAccountsCheck();
        }
        $runner = $this->createReportRunner($w);
        if ($runner !== null) {
            $checks[] = new VatReportingCheck($runner);
        }
        if ($w->hasTable('economy_accounting_journal')) {
            $checks[] = new CostsCheck();
            if ($runner !== null) {
                $checks[] = new LedgerSnapshotCheck($runner);
            }
        }
        return $checks;
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::INVALID;
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $problems = VerifyPreflight::serverModeProblems($this->getServerMode());
        if ($problems !== []) {
            return $this->refuse($err, $problems);
        }

        try {
            $reader = DatasetReader::open((string) $input->getArgument('path'));
            $preflight = $this->createPreflight()->check($reader, $dsConfig->getCountry());
            if ($preflight['errors'] !== []) {
                return $this->refuse($err, $preflight['errors']);
            }
            $expectations = VerifyExpectations::load($reader);
        } catch (DatasetException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }

        $setMessageIds = [];
        foreach ($reader->listFiles('mail') as $rel) {
            $messageId = $reader->readJsonc($rel)['messageId'] ?? null;
            if (is_string($messageId) && $messageId !== '') {
                $setMessageIds[] = $messageId;
            }
        }
        if ($setMessageIds === []) {
            return $this->refuse($err, ['sada nemá žádnou zprávu v mail/']);
        }

        $wiring = $this->createWiring($dsConfig, $dsConnection, $dsDir);
        $problems = $this->membershipProblems($wiring, $setMessageIds);
        if ($problems !== []) {
            return $this->refuse($err, ['zdroj dat obsahuje data mimo sadu — dataset-verify běží jen nad čistě naseedovaným zdrojem dat:', ...$problems]);
        }

        $home = $this->homeCurrency($wiring);
        $documents = $this->loadDocuments($wiring, $setMessageIds);
        $skipped = SetDocuments::skippedForCurrency($documents, $home);
        $apply = ApplyReport::notRun();
        if (!(bool) $input->getOption('no-apply')) {
            $before = $this->countItems($wiring);
            $apply = $this->applyProposals($wiring, $documents, $skipped);
            $apply = $apply->withItemCounts($before, $this->countItems($wiring));
            $documents = $this->loadDocuments($wiring, $setMessageIds);
            $skipped = SetDocuments::skippedForCurrency($documents, $home);
        }

        $snapshotOut = $input->getOption('write-ledger-snapshot');
        $ctx = new VerifyContext(
            $wiring,
            $reader,
            $expectations,
            $documents,
            $skipped,
            $apply,
            $home,
            is_string($snapshotOut) && $snapshotOut !== '' ? $snapshotOut : null,
        );

        $results = [];
        foreach ($this->createChecks($ctx) as $check) {
            try {
                $results[] = $check->run($ctx);
            } catch (\Throwable $e) {
                $results[] = CheckResult::fromDifferences($check->id(), $check->label(), ['kontrola selhala: ' . $e->getMessage()]);
            }
        }

        $report = new VerifyReport($reader->getManifest(), $dsConfig->getId(), $apply, $skipped, $results);
        if ($json) {
            $output->writeln((string) json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $report->writeText($output);
        }
        return $report->exitCode();
    }

    /** @param list<string> $problems */
    private function refuse(OutputInterface $err, array $problems): int
    {
        $err->writeln('<error>dataset-verify odmítnuto:</error>');
        foreach ($problems as $problem) {
            $err->writeln("  - {$problem}");
        }
        return Command::INVALID;
    }
}
