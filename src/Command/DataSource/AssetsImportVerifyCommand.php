<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\ReportDefinitionLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Assets\AssetPlanService;
use Shipard\Module\Economy\Assets\Depreciation\Amounts;
use Shipard\Module\Economy\Assets\Depreciation\PlanMessageTexts;
use Shipard\Module\Economy\Assets\Import\AssetImportVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds assets-import-verify [--asset=<číslo>] [--json]` — ověření
 * importu majetku (docs/assets.md D82): zlatý test daňového okruhu,
 * účetní okruh × deník po kartách a letech, Kontrola evidence × deník za
 * každý rok od prvního s dimenzí `asset` a karty s uplynulou dobou
 * účetního odpisování (D83, jen upozornění). Čte, nic nemění. Exit 0 bez
 * rozdílů, 1 s rozdíly; `--json` vypíše výsledek pro log runneru.
 * Logika je v {@see AssetImportVerifier}.
 */
class AssetsImportVerifyCommand extends Command
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
        $this->setName('assets-import-verify')
             ->setDescription('Ověří import majetku: zlatý test daňových odpisů, účetní okruh × deník, kontrola evidence × deník po letech, uplynulá doba účetního odpisování (nic nemění)')
             ->addOption('asset', null, InputOption::VALUE_REQUIRED, 'Jen karta s tímto inventárním číslem (kontrola po letech se pak vynechá)')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Výsledek jako JSON na stdout');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    /** Seam pro testy: ověřovač nad reálným zdrojem dat. */
    protected function createVerifier(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, string $dsDir): AssetImportVerifier
    {
        $language = $dsConfig->getDefaultLanguage();
        $configRuntime = ConfigRuntime::load($dsDir, $language);
        $dibi = $dsConnection->getDibiConnection();
        $plans = new AssetPlanService($dibi, $configRuntime, $dsConfig->getCountry(), new SettingsStore($dsConnection));

        $registry = ReportDefinitionLoader::load($dsConfig, $this->getModulePathResolver(), $language);
        $runner = new ReportRunner($registry, $dsConnection, $configRuntime, $dsConfig->getId(), $language, country: $dsConfig->getCountry());
        $reports = static fn(string $year, int $months): ReportResult => $runner->run(
            AssetImportVerifier::CHECK_REPORT,
            ['fiscalYear' => $year, 'monthFrom' => '1', 'monthTo' => (string) $months],
        );

        return new AssetImportVerifier($plans, $dibi, $reports, new PlanMessageTexts($configRuntime));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::FAILURE;
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $asset = $input->getOption('asset');
        $asset = is_string($asset) && trim($asset) !== '' ? trim($asset) : null;

        try {
            $result = $this->createVerifier($dsConfig, $dsConnection, $dsDir)->run($asset);
        } catch (\Throwable $e) {
            $output->writeln('<error>Ověření selhalo: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
            return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
        }

        $this->printText($output, $result);
        return $result['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function printText(OutputInterface $output, array $result): void
    {
        $s = $result['summary'];
        $output->writeln('<comment>1. Zlatý test daňového okruhu</comment>');
        $output->writeln(sprintf('   importovaných daňových odpisů: %d, rozdílů: %d, chyb plánu: %d', $s['taxChecked'], $s['taxDifferences'], $s['planErrors']));
        foreach ($result['tax'] as $row) {
            $output->writeln(sprintf(
                '   %-12s %-30s %s  import %14s  engine %14s  rozdíl %12s%s%s',
                $row['number'],
                mb_substr($row['name'], 0, 30),
                substr((string) $row['periodEnd'], 0, 4),
                Amounts::money($row['imported']),
                Amounts::money($row['computed']),
                Amounts::money($row['difference']),
                $row['halfYear'] ? '  ½' : '',
                $row['claimUnrecorded'] ? '  (neuplatněno)' : '',
            ));
        }
        foreach ($result['planErrors'] as $row) {
            $output->writeln(sprintf('   %-12s %-30s plán %s: %s', $row['number'], mb_substr($row['name'], 0, 30), $row['circuit'], $row['message']));
        }

        $output->writeln('<comment>2. Účetní okruh × deník</comment>');
        $output->writeln(sprintf('   kontrolovaných let karet: %d, rozdílů: %d', $s['accChecked'], $s['accDifferences']));
        foreach ($result['accounting'] as $row) {
            $output->writeln(sprintf(
                '   %-12s %-30s %s  evidence %14s  deník %14s  rozdíl %12s',
                $row['number'],
                mb_substr($row['name'], 0, 30),
                $row['year'],
                Amounts::money($row['evidence']),
                Amounts::money($row['journal']),
                Amounts::money($row['difference']),
            ));
        }

        $output->writeln('<comment>3. Kontrola evidence × deník po letech</comment>');
        if ($result['journalCheck'] === []) {
            $output->writeln('   (bez roku s dimenzí majetku v deníku, nebo filtr na kartu)');
        }
        foreach ($result['journalCheck'] as $year) {
            $codes = [];
            foreach ($year['codes'] as $code => $count) {
                $codes[] = "{$code}: {$count}";
            }
            $output->writeln(sprintf('   %s  %-9s %s', $year['year'], $year['status'], $codes === [] ? 'bez zpráv' : implode(', ', $codes)));
        }

        $output->writeln('<comment>4. Uplynulá doba účetního odpisování</comment>');
        $output->writeln(sprintf('   karet s varováním: %d (zůstatek by se odepsal v jednom období; oprava = delší doba na kartě)', $s['accPeriodElapsed']));
        foreach ($result['accPeriodElapsed'] as $row) {
            $output->writeln(sprintf(
                '   %-12s %-30s doba do %s  plán %s  částka %14s',
                $row['number'],
                mb_substr($row['name'], 0, 30),
                $row['end'],
                substr((string) $row['periodEnd'], 0, 4),
                Amounts::money($row['amount']),
            ));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '%s karet: %d; rozdíly daňové %d, účetní %d, chyby plánu %d, roky kontroly s chybou %d, uplynulá doba %d',
            $result['ok'] ? '<info>V pořádku.</info>' : '<error>Rozdíly.</error>',
            $s['cards'],
            $s['taxDifferences'],
            $s['accDifferences'],
            $s['planErrors'],
            $s['checkErrors'],
            $s['accPeriodElapsed'],
        ));
    }
}
