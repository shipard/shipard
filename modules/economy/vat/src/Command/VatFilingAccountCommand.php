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
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingBuilder;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingResult;
use Shipard\Module\Economy\Vat\Accounting\VatReturnAccountingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * shpd-ds vat-filing-account <filingId> [--dry-run]
 *
 * Zaúčtuje podané přiznání DPH: založí účetní doklad (cmnbkp, koncept)
 * a naváže ho na podání — totéž co akce **Zaúčtovat** v detailu podání
 * (#55 D28–D31). `--dry-run` vypíše řádky dokladu bez zápisu, a to i nad
 * konceptem podání — nástroj zlatého testu proti starým dokladům přiznání.
 */
class VatFilingAccountCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vat-filing-account')
            ->setDescription('Zaúčtuje podané přiznání DPH — účetní doklad (koncept) navázaný na podání; --dry-run jen vypíše řádky')
            ->addArgument('filingId', InputArgument::REQUIRED, 'Id podání DPH (economy_vat_filings)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypsat řádky dokladu, nic nezapisovat (jde i nad konceptem podání)');
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

        $filingId = (int) $input->getArgument('filingId');
        if ($filingId <= 0) {
            $output->writeln('<error>filingId musí být kladné číslo.</error>');
            return Command::INVALID;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $dibi         = $dsConnection->getDibiConnection();

        if (!in_array('economy_vat_filings', $dsConnection->getAllTableNames(), true)) {
            $output->writeln('<error>economy.vat není aktivní — tabulka podání neexistuje (spusťte ds-upgrade).</error>');
            return Command::FAILURE;
        }

        $language = $dsConfig->getDefaultLanguage();
        $config   = ConfigRuntime::load($dsDir, $language);
        $resolver = $this->buildResolver();
        $tables   = TableLoader::load($dsConfig, $resolver, $language);
        $registry = DocumentLoader::load($dsConfig, $resolver);

        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher    = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);

        $service = new VatReturnAccountingService($dibi, $config, $dsConfig, $registry, $tables, $dispatcher);

        try {
            $result = $dryRun ? $service->plan($filingId, allowDraft: true) : $service->account($filingId);
        } catch (\DomainException | \RuntimeException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $this->printContext($output, $result, $dryRun);
        if ($result->plan !== null) {
            $this->printPlan($output, $result);
        }
        foreach ($result->messageTexts() as $text) {
            $output->writeln('  ' . $text);
        }

        if (!$result->ok) {
            $output->writeln("<error>{$result->code}: {$result->message}</error>");
            return Command::FAILURE;
        }
        if ($dryRun) {
            $output->writeln('<comment>Dry-run — nic se nezapsalo.</comment>');
        } else {
            $series = $result->context['series'] ?? null;
            $output->writeln(sprintf(
                '<info>%s</info> Řada: %s. Doklad zkontrolujte a uzavřete v aplikaci.',
                $result->message,
                is_array($series) ? "{$series['name']} (#{$series['id']})" : '?',
            ));
        }
        return Command::SUCCESS;
    }

    private function printContext(OutputInterface $output, VatReturnAccountingResult $result, bool $dryRun): void
    {
        $c = $result->context;
        if (!isset($c['filingId'])) {
            return;
        }
        $output->writeln(sprintf(
            'Podání #%d: %s (%s, stav %d)%s%s',
            (int) $c['filingId'],
            (string) ($c['filingName'] ?? ''),
            (string) ($c['filingKind'] ?? ''),
            (int) ($c['docState'] ?? 0),
            !empty($c['draft']) ? ' — KONCEPT, jen náhled' : '',
            isset($c['previousFilingId']) && $c['previousFilingId'] !== null ? ", předchozí podání #{$c['previousFilingId']}" : '',
        ));
        if (isset($c['periodName'])) {
            $output->writeln(sprintf('Instance %s, konec období %s%s', (string) $c['periodName'], (string) ($c['dateEnd'] ?? ''), $dryRun ? '' : ''));
        }
    }

    private function printPlan(OutputInterface $output, VatReturnAccountingResult $result): void
    {
        $plan  = $result->plan;
        $table = new Table($output);
        $table->setHeaders(['Účet', 'Strana', 'Částka', 'Popis', 'Partner', 'VS', 'SS', 'KS', 'Splatnost']);
        foreach ($plan->rows as $row) {
            $table->addRow([
                $row['account_number'],
                $row['acc_side'] === VatReturnAccountingBuilder::SIDE_DEBIT ? 'MD' : 'DAL',
                number_format((float) $row['amount'], 2, ',', ' '),
                $row['description'],
                isset($row['partner']) && $row['partner'] !== null ? (string) $row['partner'] : '',
                (string) ($row['payment_reference'] ?? ''),
                (string) ($row['specific_symbol'] ?? ''),
                (string) ($row['constant_symbol'] ?? ''),
                (string) ($row['due_date'] ?? ''),
            ]);
        }
        $table->render();
        $s = $plan->summary;
        $output->writeln(sprintf(
            'Σ MD %s / Σ DAL %s; změna povinnosti %s, krácení %s, zaokrouhlení %s',
            number_format($s['debit'], 2, ',', ' '),
            number_format($s['credit'], 2, ',', ' '),
            number_format($s['liabilityDelta'], 2, ',', ' '),
            number_format($s['nondeductible'], 2, ',', ' '),
            number_format($s['rounding'], 2, ',', ' '),
        ));
    }
}
