<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\ExchangeRates\Download\CurlExchangeRateServiceTransport;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadOptions;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadPlan;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadReport;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadState;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateDownloader;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateListPresence;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateServiceClient;
use Shipard\Module\Economy\ExchangeRates\Download\FiscalYears;
use Shipard\Module\Economy\ExchangeRates\Download\WorkingDayCalendars;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Stažení chybějících kurzovních lístků ze služby (#121 D2, D3, task B §5,
 * docs/exchange-rates.md §9): periodická úloha slotu `five-minutes`
 * (CronCommand::SLOT_JOBS) a ruční doplnění.
 *
 *   --from=YYYY-MM-DD, --to=YYYY-MM-DD   okno místo předchozího fiskálního
 *                                        roku; ruční mód bez limitu dotazů
 *                                        a bez pauzy
 *   --type=<id>                          jen jeden typ lístku
 *   --dry-run                            vypíše okno, chybějící data a dotazy;
 *                                        žádná síť, žádný zápis
 *
 * Adresa služby `exchangeRates.serviceUrl` v server.json; prázdná = stahování
 * vypnuté (exit 0). Modul neaktivní = exit 0 (cron). Exit 1 při chybě
 * služby nebo sítě, konfliktu nebo selhání zápisu; INVALID při špatném
 * datu nebo neznámém typu. Varování jdou do logu jen v běhu, který poslal
 * dotaz — bez chybějícího lístku úloha nic neloguje.
 */
class ExchangeRatesDownloadCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('exchange-rates-download')
            ->setDescription('Download missing exchange rate lists from the service (five-minutes cron); --from/--to = manual range, --type = one list type, --dry-run = plan only')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Window start YYYY-MM-DD (default: start of the previous fiscal year)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Window end YYYY-MM-DD (default and maximum: today)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Only this list type id (e.g. cz.cnb.daily)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the window, missing dates and queries; no network, no writes');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $now = $this->now();
        $today = $now->setTimezone(new \DateTimeZone(ExchangeRateDownloader::WINDOW_TIMEZONE))->format('Y-m-d');
        $from = $this->dateOption($input, 'from');
        $to = $this->dateOption($input, 'to');
        if ($from === false || $to === false) {
            $output->writeln('<error>--from a --to musí být datum YYYY-MM-DD</error>');
            return Command::INVALID;
        }
        if ($to !== null && $to > $today) {
            $output->writeln("<error>--to nesmí být po dnešku ({$today})</error>");
            return Command::INVALID;
        }
        if ($from !== null && $from > ($to ?? $today)) {
            $output->writeln('<error>--from nesmí být po --to</error>');
            return Command::INVALID;
        }
        $typeOption = $input->getOption('type');
        $type = is_string($typeOption) && $typeOption !== '' ? $typeOption : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if (!in_array(ExchangeRateListImporter::TABLE_LISTS, $dsConnection->getAllTableNames(), true)) {
            $output->writeln('economy.exchangeRates not active — nothing to do');
            return Command::SUCCESS;
        }

        ['url' => $url, 'error' => $error] = $this->resolveServiceUrl($dsConfig->getId());
        if ($error !== null) {
            $output->writeln("<error>{$error}</error>");
            return Command::FAILURE;
        }
        if ($url === null) {
            $output->writeln('Stahování je vypnuté — exchangeRates.serviceUrl v server.json je prázdné.');
            return Command::SUCCESS;
        }

        $downloader = $this->createDownloader($dsDir, $dsConfig, $dsConnection, $url, $now);
        if ($type !== null && !in_array($type, $downloader->typeIds(), true)) {
            $known = $downloader->typeIds();
            $output->writeln("<error>Neznámý typ lístku „{$type}“" . ($known !== [] ? ' — typy zdroje dat: ' . implode(', ', $known) : ' — zdroj dat nemá žádné typy lístků') . '</error>');
            return Command::INVALID;
        }
        $options = new DownloadOptions($from, $to, $type);

        if ($dryRun) {
            $this->printPlan($downloader->plan($options), $output, $now);
            $output->writeln('<comment>Nic se nestáhlo ani nezapsalo (--dry-run).</comment>');
            return Command::SUCCESS;
        }

        $report = $downloader->run($options);
        $this->printReport($report, $output);
        $this->logReport($report);
        return $report->failed() ? Command::FAILURE : Command::SUCCESS;
    }

    /** null = nezadáno, false = neplatné. */
    private function dateOption(InputInterface $input, string $name): string|false|null
    {
        $raw = $input->getOption($name);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        return $parsed !== false && $parsed->format('Y-m-d') === $raw ? $raw : false;
    }

    private function printPlan(DownloadPlan $plan, OutputInterface $output, \DateTimeImmutable $now): void
    {
        $prague = $now->setTimezone(new \DateTimeZone(ExchangeRateDownloader::WINDOW_TIMEZONE));
        $output->writeln(sprintf('Okno: %s – %s (dnes %s %s Europe/Prague)', $plan->from, $plan->to, $prague->format('Y-m-d'), $prague->format('H:i')));
        if ($plan->types === []) {
            $output->writeln('  <comment>žádný typ lístku ke stažení</comment>');
        }
        foreach ($plan->types as $type) {
            $missing = count($type->missing);
            $line = sprintf('  %s (%s): očekáváno %d, přítomno %d, ', $type->name, $type->listType, $type->expected, $type->present);
            if ($missing === 0) {
                $line .= 'nic nechybí';
            } else {
                $newest = array_slice(array_reverse($type->missing), 0, 5);
                $line .= sprintf('<comment>chybí %d</comment>: %s%s', $missing, implode(', ', $newest), $missing > 5 ? ', …' : '');
            }
            $output->writeln($line);
            if ($type->workingDays && !$type->todayPublished) {
                $output->writeln("    dnešní lístek ({$type->today}) se očekává až po vyhlášení");
            }
        }
        foreach ($plan->configWarnings as $warning) {
            $output->writeln("  <comment>varování:</comment> {$warning}");
        }
        if ($plan->queries === []) {
            $output->writeln('Nic nechybí — žádný dotaz.');
            return;
        }
        $output->writeln($plan->limit !== null ? "Dotazy (nejvýš {$plan->limit} za běh, pauza 1 h na měsíc):" : 'Dotazy (ruční okno — bez limitu a pauzy):');
        $send = $plan->queriesToSend();
        foreach ($plan->queries as $query) {
            $note = '';
            if ($query->paused()) {
                $note = " — <comment>pozastaveno: {$query->pausedReason}</comment>";
            } elseif (!in_array($query, $send, true)) {
                $note = ' — <comment>nad limit tohoto běhu</comment>';
            } elseif ($query->todayPoll) {
                $note = ' — jen dnešní lístek';
            }
            $output->writeln(sprintf('  %s (listType %d)%s', $query->label(), $query->serviceListType, $note));
        }
    }

    private function printReport(DownloadReport $report, OutputInterface $output): void
    {
        if ($report->plan->queries === []) {
            $output->writeln('Nic nechybí.');
            return;
        }
        foreach ($report->results as $line) {
            $query = $line['query'];
            $text = match ($line['kind']) {
                DownloadReport::KIND_NONE  => 'žádný lístek',
                DownloadReport::KIND_ERROR => '<error>chyba: ' . ($report->error !== null ? substr($report->error, strlen($query->label()) + 2) : '?') . '</error>',
                default                    => sprintf('%d lístků: %d založeno, %d beze změny, %d ignorováno, %d jiný obsah, %d selhalo', $line['lists'], $line['created'], $line['skipped'], $line['ignored'], $line['conflict'], $line['failed']),
            };
            $output->writeln(sprintf('  %s → %s', $query->label(), $text));
        }
        foreach ($report->notices as $notice) {
            $output->writeln("    {$notice}");
        }
        foreach ($report->warnings as $warning) {
            $output->writeln("  <comment>varování:</comment> {$warning}");
        }
        foreach ($report->plan->configWarnings as $warning) {
            $output->writeln("  <comment>varování:</comment> {$warning}");
        }
        $output->writeln('');
        $output->writeln(sprintf(
            'Souhrn: %d dotazů; %d založeno, %d beze změny, %d ignorováno, %d jiný obsah, %d selhalo; čeká %d dotazů',
            $report->sent, $report->created, $report->skipped, $report->ignored, $report->conflict, $report->failed, $report->pending(),
        ));
        if ($report->plan->pausedUntil !== null) {
            $output->writeln('<comment>Běh je po chybě služby pozastaven.</comment>');
        }
    }

    /** Do logu jen běh, který poslal dotaz — jinak by úloha každých 5 minut hlásila totéž. */
    private function logReport(DownloadReport $report): void
    {
        if ($report->sent === 0) {
            return;
        }
        foreach ($report->plan->configWarnings as $warning) {
            ErrorLogger::warn('exchange-rates-download: ' . $warning);
        }
        foreach ($report->warnings as $warning) {
            ErrorLogger::warn('exchange-rates-download: ' . $warning);
        }
        foreach ($report->notices as $notice) {
            ErrorLogger::info('exchange-rates-download: ' . $notice);
        }
        if ($report->error !== null) {
            ErrorLogger::warn('exchange-rates-download: služba nedostupná — ' . $report->error);
        }
    }

    /**
     * Adresa služby ze server.json + nastavení logu (přepsatelné v testech).
     *
     * @return array{url: ?string, error: ?string} url null bez chyby = stahování vypnuté
     */
    protected function resolveServiceUrl(string $dsId): array
    {
        try {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
        } catch (\Throwable $e) {
            return ['url' => null, 'error' => 'server.json: ' . $e->getMessage()];
        }
        try {
            $url = $serverConfig->getExchangeRatesServiceUrl();
        } catch (\RuntimeException $e) {
            return ['url' => null, 'error' => $e->getMessage()];
        }
        ErrorLogger::setLogPath($serverConfig->getLogFile());
        ErrorLogger::setLogLevel($serverConfig->getLogLevel());
        ErrorLogger::setRequestContext('cli: exchange-rates-download');
        ErrorLogger::setDsId($dsId);
        return ['url' => $url, 'error' => null];
    }

    /** Produkční wiring (přepsatelné v testech). */
    protected function createDownloader(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, string $serviceUrl, \DateTimeImmutable $now): ExchangeRateDownloader
    {
        $language = $dsConfig->getDefaultLanguage();
        $config   = ConfigRuntime::load($dsDir, $language);
        $resolver = $this->buildResolver();
        $tables   = TableLoader::load($dsConfig, $resolver, $language);
        $registry = DocumentLoader::load($dsConfig, $resolver);
        $dibi     = $dsConnection->getDibiConnection();
        $importer = ExchangeRateListImporter::create($dibi, $config, $dsConfig, $registry, $tables);
        $settings = new SettingsStore($dsConnection);

        return new ExchangeRateDownloader(
            $importer->types()->forHomeCurrency(ExchangeRateResolver::homeCurrencyOf($settings)),
            $importer,
            new ExchangeRateServiceClient($serviceUrl, new CurlExchangeRateServiceTransport()),
            new ExchangeRateListPresence($dibi),
            new DownloadState($settings),
            WorkingDayCalendars::defaults(),
            FiscalYears::load($dsConnection),
            $now,
        );
    }

    private function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }
}
