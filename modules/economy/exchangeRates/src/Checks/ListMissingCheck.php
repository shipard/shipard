<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadSchedule;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadWindow;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateDownloader;
use Shipard\Module\Economy\ExchangeRates\Download\ExchangeRateListPresence;
use Shipard\Module\Economy\ExchangeRates\Download\FiscalYears;
use Shipard\Module\Economy\ExchangeRates\Download\WorkingDayCalendars;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListTypes;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateResolver;

/**
 * Upozornění `economy.exchangerates.list_missing` (#121 D9, task B §6):
 * lístek za pracovní den nepřibyl ani další den.
 *
 *   - denní typ (`workingDays`): pracovní dny kalendáře v posledních 30
 *     dnech před dneškem (Europe/Prague), ne před začátkem okna stahování,
 *     bez lístku → jeden nález na typ (počet + nejvýš 5 nejnovějších dat);
 *     dnešek se nehlídá — stahovač ho ještě zkouší;
 *   - měsíční typ: od 2. dne měsíce chybí lístek s valid_from = 1. den.
 *
 * Běží i se stahováním vypnutým (lístky jsou potřeba i při ručním
 * importu), ale jen u typu, který už nějaký lístek má — zdroj dat bez
 * kurzů by jinak nesl trvalé varování. Očekávaná data počítá stejný
 * {@see DownloadSchedule} jako stahovač. Chráněné metody = seam pro testy.
 */
class ListMissingCheck extends AlertCheck
{
    public const int LOOKBACK_DAYS = 30;
    public const int DATES_IN_MESSAGE = 5;
    public const string VIEWER_ID = 'economy.exchangeRates.lists';

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $now = $this->now();
        $today = $now->setTimezone(new \DateTimeZone(ExchangeRateDownloader::WINDOW_TIMEZONE))->format('Y-m-d');
        $types = ExchangeRateListTypes::fromConfig($this->config, $this->dsConfig?->getCountry() ?? 'cz')
            ->forHomeCurrency($this->homeCurrency());
        $presence = $this->presence();
        $calendars = $this->calendars();
        $windowStart = DownloadWindow::start($today, $this->fiscalYears());

        $findings = [];
        foreach ($types as $type) {
            $config = $type->download;
            if ($config === null || !$presence->hasAny($type->id)) {
                continue;
            }
            if ($config->isMonthly()) {
                $finding = $this->monthlyFinding($type, $today, $windowStart, $presence, $isCs);
            } else {
                $calendar = $calendars->get((string) $config->calendar);
                if ($calendar === null) {
                    continue;
                }
                $typeToday = $config->today($now);
                $yesterday = (new \DateTimeImmutable($typeToday))->modify('-1 day')->format('Y-m-d');
                $from = max($windowStart, (new \DateTimeImmutable($typeToday))->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d'));
                $expected = DownloadSchedule::expectedDates($config, $calendar, $from, $yesterday, $now);
                if ($expected === []) {
                    continue;
                }
                $present = $presence->datesFor($type->id, $expected[0], $expected[count($expected) - 1]);
                $missing = array_values(array_filter($expected, static fn(string $date): bool => !isset($present[$date])));
                $finding = $missing === [] ? null : $this->dailyFinding($type, $missing, $isCs);
            }
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /** @param list<string> $missing */
    private function dailyFinding(ExchangeRateListType $type, array $missing, bool $isCs): AlertFinding
    {
        $count = count($missing);
        $newest = array_map(self::shortDate(...), array_slice(array_reverse($missing), 0, self::DATES_IN_MESSAGE));
        $dates = implode(', ', $newest) . ($count > self::DATES_IN_MESSAGE ? ', …' : '');
        return new AlertFinding(
            findingKey: $type->id,
            title: $isCs ? "Chybí kurzovní lístek {$type->name}" : "Exchange rate list {$type->name} is missing",
            message: $isCs
                ? sprintf('Lístek chybí za %s (%s). K těmto datům se kurz nedoplní.', self::workingDaysCs($count), $dates)
                : sprintf('No list for %d working %s (%s). No rate will be filled in for these dates.', $count, $count === 1 ? 'day' : 'days', $dates),
            severity: 'warning',
            actions: [$this->openListsAction($isCs)],
            context: ['list_type' => $type->id, 'missing' => $missing],
        );
    }

    private function monthlyFinding(ExchangeRateListType $type, string $today, string $windowStart, ExchangeRateListPresence $presence, bool $isCs): ?AlertFinding
    {
        if ((int) substr($today, 8, 2) < 2) {
            return null;
        }
        $first = substr($today, 0, 7) . '-01';
        if (substr($first, 0, 7) < substr($windowStart, 0, 7)) {
            return null;
        }
        if (isset($presence->datesFor($type->id, $first, $first)[$first])) {
            return null;
        }
        return new AlertFinding(
            findingKey: $type->id,
            title: $isCs ? "Chybí kurzovní lístek {$type->name}" : "Exchange rate list {$type->name} is missing",
            message: $isCs
                ? sprintf('Lístek platný od %s ve zdroji dat není. K datům tohoto měsíce se kurz nedoplní.', self::shortDate($first) . ' ' . substr($first, 0, 4))
                : sprintf('The list valid from %s is missing. No rate will be filled in for this month.', $first),
            severity: 'warning',
            actions: [$this->openListsAction($isCs)],
            context: ['list_type' => $type->id, 'missing' => [$first]],
        );
    }

    /** @return array<string, mixed> */
    private function openListsAction(bool $isCs): array
    {
        return [
            'id'      => 'open_lists',
            'label'   => $isCs ? 'Otevřít kurzovní lístky' : 'Open exchange rate lists',
            'kind'    => 'open_viewer',
            'primary' => true,
            'target'  => ['viewerId' => self::VIEWER_ID],
        ];
    }

    /** `2026-10-19` → `19. 10.` */
    private static function shortDate(string $date): string
    {
        return (int) substr($date, 8, 2) . '. ' . (int) substr($date, 5, 2) . '.';
    }

    private static function workingDaysCs(int $count): string
    {
        return $count . ' ' . match (true) {
            $count === 1             => 'pracovní den',
            $count >= 2 && $count <= 4 => 'pracovní dny',
            default                  => 'pracovních dnů',
        };
    }

    // ── Seamy pro testy ──────────────────────────────────────────────────

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function presence(): ExchangeRateListPresence
    {
        return new ExchangeRateListPresence($this->db->getDibiConnection());
    }

    /** @return list<array{begin: string, end: string}> */
    protected function fiscalYears(): array
    {
        return FiscalYears::load($this->db);
    }

    protected function calendars(): WorkingDayCalendars
    {
        return WorkingDayCalendars::defaults();
    }

    protected function homeCurrency(): string
    {
        return ExchangeRateResolver::homeCurrencyOf(new SettingsStore($this->db));
    }
}
