<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateLookup;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Kurz měny k datu podle resolveru (#121 §5, docs/exchange-rates.md §4) —
 * ověření a podpora: kurz za jednotku, typ lístku, platnost od, číslo
 * lístku, nebo „nenalezen“ s důvodem per typ lístku.
 *
 * Exit 0 nalezen (i domácí měna); 1 nenalezen nebo chybějící modul;
 * INVALID při špatné měně nebo datu.
 */
class ExchangeRateCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('exchange-rate')
            ->setDescription('Show the exchange rate of a currency for a date as the resolver sees it (default today)')
            ->addArgument('currency', InputArgument::REQUIRED, 'ISO 4217 code, e.g. eur')
            ->addArgument('date', InputArgument::OPTIONAL, 'Date YYYY-MM-DD (default today)');
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

        $currency = strtolower(trim((string) $input->getArgument('currency')));
        if (preg_match('/^[a-z]{3}$/', $currency) !== 1) {
            $output->writeln('<error>Měna musí být třípísmenný kód ISO 4217 (např. eur).</error>');
            return Command::INVALID;
        }
        $date = (string) ($input->getArgument('date') ?? '');
        if ($date === '') {
            $date = date('Y-m-d');
        } elseif (ExchangeRateResolver::normalizeDate($date) === null) {
            $output->writeln('<error>Datum musí být YYYY-MM-DD.</error>');
            return Command::INVALID;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if (!in_array(ExchangeRateResolver::TABLE_LISTS, $dsConnection->getAllTableNames(), true)) {
            $output->writeln('<error>economy.exchangeRates není aktivní — spusť ds-upgrade.</error>');
            return Command::FAILURE;
        }

        $resolver = $this->createResolver($dsDir, $dsConfig, $dsConnection);
        $lookup = $resolver->lookup($currency, $date);
        $upper = strtoupper($currency);
        $home = strtoupper($resolver->homeCurrency());

        if ($lookup->rate !== null && $lookup->rate->isHome()) {
            $output->writeln("<info>{$upper} je domácí měna zdroje dat — kurz 1.</info>");
            return Command::SUCCESS;
        }
        if ($lookup->rate !== null) {
            $rate = $lookup->rate;
            $output->writeln(sprintf(
                '<info>1 %s = %s %s</info> — %s, platný od %s%s',
                $upper,
                self::formatRate($rate->rate),
                $home,
                $resolver->types()->labelOf((string) $rate->listType),
                (string) $rate->validFrom,
                $rate->listNumber !== null ? ", č. {$rate->listNumber}" : '',
            ));
            return Command::SUCCESS;
        }

        $output->writeln("<error>Kurz {$upper} k {$date} nenalezen.</error>");
        foreach ($lookup->reasons as $reason) {
            $output->writeln('  - ' . $this->describeReason($resolver, $reason['listType'], $reason['reason']));
        }
        return Command::FAILURE;
    }

    /** Produkční wiring (přepsatelné v testech). */
    protected function createResolver(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): ExchangeRateResolver
    {
        $config = ConfigRuntime::load($dsDir, $dsConfig->getDefaultLanguage());
        return ExchangeRateResolver::create($dsConnection->getDibiConnection(), $config, $dsConfig);
    }

    private function describeReason(ExchangeRateResolver $resolver, ?string $listType, string $reason): string
    {
        $type = $listType !== null ? $resolver->types()->get($listType) : null;
        $prefix = $type !== null ? $type->name . ': ' : '';
        return $prefix . match ($reason) {
            ExchangeRateLookup::REASON_INVALID_DATE     => 'neplatné datum',
            ExchangeRateLookup::REASON_NO_TYPES         => 'zdroj dat nemá žádný typ lístku pro domácí měnu ' . strtoupper($resolver->homeCurrency()) . ' (world.<země>.exchangeRateLists)',
            ExchangeRateLookup::REASON_NO_LIST          => 'žádný lístek platný k datu',
            ExchangeRateLookup::REASON_LIST_STALE       => 'poslední lístek je starší než ' . ($type?->maxAgeDays ?? '?') . ' dní (U1)',
            ExchangeRateLookup::REASON_LIST_EXPIRED     => 'poslední lístek už neplatí (po validTo)',
            ExchangeRateLookup::REASON_CURRENCY_MISSING => 'poslední lístek měnu neobsahuje (starší se nezkouší, U2)',
            default                                     => $reason,
        };
    }

    private static function formatRate(float $rate): string
    {
        $text = number_format($rate, 6, '.', '');
        $text = rtrim(rtrim($text, '0'), '.');
        return str_contains($text, '.') ? $text : $text . '.0';
    }
}
