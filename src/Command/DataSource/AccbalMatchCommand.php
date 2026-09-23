<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\JournalContributorLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\OpenItemLookupLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accbal\ClearingRouter;
use Shipard\Module\Economy\Accbal\RouteResult;
use Shipard\Module\Economy\Accbal\RouteSummary;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dávkové přeúčtování clearingových úhrad na účet otevřeného předpisu
 * (#69 D4). Tenká vrstva nad {@see ClearingRouter::rerouteAll}; runtime se
 * nadrátuje jako BankImportStatementCommand (config + DB +
 * JournalEventHandlerLoader — bez něj se po reaccountu nespustí re-derivace
 * ledgeru) + OpenItemLookupLoader (dohledání předpisu) + JournalContributorLoader
 * (příspěvky do deníku, #79 D3b — táž sada pro handlery i router).
 *
 * Bez `--all`/filtru příkaz nic neudělá (vyžádá si rozsah). `--dry-run`
 * vypíše plán bez zápisu. Zrcadlo `POST /_accbal/match` (docs/accbal.md §5.7).
 */
class AccbalMatchCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('accbal-match')
            ->setDescription('Přeúčtuje clearingové úhrady s klíčem otevřeného předpisu na 311/321')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Zpracovat všechny clearingové kandidáty')
            ->addOption('partner', null, InputOption::VALUE_REQUIRED, 'Jen úhrady tohoto partnera (id)')
            ->addOption('fiscal-year', null, InputOption::VALUE_REQUIRED, 'Jen úhrady tohoto fiskálního roku (id)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš plán, nic neměň');
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filters = [];
        if (($p = $input->getOption('partner')) !== null) {
            $filters['partner'] = (int) $p;
        }
        if (($fy = $input->getOption('fiscal-year')) !== null) {
            $filters['fiscalYear'] = (int) $fy;
        }
        if (!$input->getOption('all') && $filters === []) {
            $output->writeln('<error>Vyžaduje --all nebo filtr (--partner / --fiscal-year). Pro náhled přidej --dry-run.</error>');
            return Command::FAILURE;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $dsDir        = $this->getDataSourceDir();
        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $lang         = $dsConfig->getDefaultLanguage();
        $resolver     = $this->buildResolver();

        $config = ConfigRuntime::load($dsDir, $lang);
        $dibi   = $dsConnection->getDibiConnection();
        // Bez handler loaderu by se po reaccountu nespustila re-derivace ledgeru.
        $contributors  = JournalContributorLoader::load($dsConfig, $resolver, $dibi, $config);
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $contributors);
        $openItems     = OpenItemLookupLoader::load($dsConfig, $resolver, $dibi, $config);

        $router  = new ClearingRouter($dibi, $config, $journalEvents, $openItems, $contributors);
        $summary = $router->rerouteAll($filters, $dryRun);
        $this->printSummary($output, $summary, $dryRun);
        return Command::SUCCESS;
    }

    private function printSummary(OutputInterface $output, RouteSummary $summary, bool $dryRun): void
    {
        foreach ($summary->results as $r) {
            $this->printResult($output, $r);
        }

        $output->writeln('');
        $output->writeln(sprintf('Kandidátů: %d', $summary->candidates()));
        if ($dryRun) {
            $output->writeln(sprintf('  k přeúčtování (plán): %d, Σ HC %.2f', $summary->planned, $summary->routedAmount));
        } else {
            $output->writeln(sprintf('  přeúčtováno: %d, Σ HC %.2f', $summary->routed, $summary->routedAmount));
        }
        if ($summary->skipped !== []) {
            $parts = [];
            foreach ($summary->skipped as $reason => $count) {
                $parts[] = "{$reason}: {$count}";
            }
            $output->writeln('  přeskočeno — ' . implode(', ', $parts));
        }
    }

    private function printResult(OutputInterface $output, RouteResult $r): void
    {
        switch ($r->status) {
            case RouteResult::STATUS_ROUTED:
            case RouteResult::STATUS_PLANNED:
                $output->writeln(sprintf(
                    'tx #%d → %s (partner %s, %.2f %s) [%s]',
                    $r->txId,
                    $r->targetLabel(),
                    $r->partner ?? '?',
                    $r->amount,
                    strtoupper((string) $r->currency),
                    $r->status === RouteResult::STATUS_PLANNED ? 'plán' : 'přeúčtováno',
                ));
                break;
            case RouteResult::STATUS_SKIPPED:
                $output->writeln(sprintf('tx #%d — přeskočeno (%s)', $r->txId, $r->reason));
                break;
        }
    }
}
