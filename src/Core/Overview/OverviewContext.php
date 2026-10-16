<?php

declare(strict_types=1);

namespace Shipard\Core\Overview;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Feed\FeedTexts;
use Shipard\Core\Reports\ReportRunner;

/**
 * Kontext jednoho sběru Přehledu firmy — co bloky dostanou k dispozici.
 *
 * `today` je datum sběru (`asOf` odpovědi, práh D14, splatnosti D6);
 * `reports` spouští existující reporty (hlavní kniha, výsledovka, živé
 * přiznání) — jeden výpočet, N prezentací (docs/reports.md §2).
 * `country` = země zdroje dat (`DataSourceConfig::getCountry()`) pro
 * pravidla závislá na zemi (termín podání DPH, D17); `homeCurrency` =
 * nastavení `economy.homeCurrency` (fallback `czk`) — domácí měna první (D4).
 */
final readonly class OverviewContext
{
    /**
     * @param array<string, mixed> $tables registry TableDefinition podle
     *        názvu tabulky (TableLoader::load) — jediný zdroj pravdy o tom,
     *        které tabulky na DS existují
     */
    public function __construct(
        public DataSourceConnection $db,
        public ?ConfigRuntime $config,
        public string $language,
        public \DateTimeImmutable $today,
        public ReportRunner $reports,
        public array $tables = [],
        public string $country = 'cz',
        public string $homeCurrency = 'czk',
    ) {}

    public function hasTable(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    public function todayIso(): string
    {
        return $this->today->format('Y-m-d');
    }

    /**
     * Texty bloku z katalogu modulu (cfgItem `*.overviewTexts`) — stejný
     * mechanismus jako texty karet feedu (ICU vzory, fallback anglicky
     * v PHP, degradace bez compiled configu).
     */
    public function texts(string $cfgItemId): FeedTexts
    {
        return new FeedTexts($this->config, $cfgItemId, $this->language);
    }
}
