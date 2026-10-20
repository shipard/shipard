<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListType;

/**
 * Stahovač kurzovních lístků ze služby (#121 D3, task B §4).
 *
 * Okno = od začátku předchozího fiskálního roku ({@see DownloadWindow}) po
 * dnešek v Europe/Prague; `--from` / `--to` ho přepíšou (ruční mód).
 * Per typ s `download` ({@see DownloadSchedule}) se očekávaná data srovnají
 * s přítomnými (jeden SQL na typ) — nic nechybí = žádná síť, žádný zápis.
 * Chybějící data se seskupí po kalendářních měsících, dotaz = první až
 * poslední chybějící datum měsíce, pořadí od nejnovějšího měsíce.
 *
 * Cron mód: nejvýš {@see MAX_QUERIES_PER_RUN} dotazů za běh; měsíc
 * s pokusem mladším než hodina se přeskočí ({@see DownloadState}); jen
 * dnešní chybějící lístek po vyhlášení se zkouší každý běh (`todayPoll`,
 * pokus se nezapisuje); po chybě služby je celý běh hodinu pozastaven.
 *
 * Zápis: lístek k datu, které typ už má, se ignoruje (nic se neporovnává
 * — ruční lístky s jinými hodnotami nesmí každou hodinu hlásit konflikt);
 * ostatní vrácené lístky jdou do importeru se zdrojem *služba*, i mimo
 * očekávaná data (svátek, který služba má). Konflikt se nepřepisuje.
 */
final class ExchangeRateDownloader
{
    public const int MAX_QUERIES_PER_RUN = 6;
    public const int RETRY_AFTER_SECONDS = 3600;
    public const string WINDOW_TIMEZONE = 'Europe/Prague';

    /** @var array<string, ExchangeRateListType> id → typ, v pořadí konfigurace */
    private array $types = [];

    /**
     * @param list<ExchangeRateListType> $types typy domácí měny zdroje dat (`forHomeCurrency`)
     * @param list<array{begin: string, end: string}> $fiscalYears
     */
    public function __construct(
        array $types,
        private readonly ExchangeRateListImporter $importer,
        private readonly ExchangeRateServiceClient $client,
        private readonly ExchangeRateListPresence $presence,
        private readonly DownloadState $state,
        private readonly WorkingDayCalendars $calendars,
        private readonly array $fiscalYears,
        private readonly \DateTimeImmutable $now,
    ) {
        foreach ($types as $type) {
            $this->types[$type->id] = $type;
        }
    }

    /** @return list<string> */
    public function typeIds(): array
    {
        return array_keys($this->types);
    }

    /** Dnešek v Europe/Prague (konec okna). */
    public function today(): string
    {
        return $this->now->setTimezone(new \DateTimeZone(self::WINDOW_TIMEZONE))->format('Y-m-d');
    }

    public function plan(DownloadOptions $options): DownloadPlan
    {
        $manual = $options->manual();
        $nowTs = $this->now->getTimestamp();
        $today = $this->today();
        $to = $options->to ?? $today;
        $from = $options->from ?? DownloadWindow::start($today, $this->fiscalYears);
        $plan = new DownloadPlan($from, $to, $manual ? null : self::MAX_QUERIES_PER_RUN);

        $runPaused = null;
        if (!$manual) {
            $lastError = $this->state->lastError();
            if ($lastError !== null && $nowTs - $lastError < self::RETRY_AFTER_SECONDS) {
                $plan->pausedUntil = $lastError + self::RETRY_AFTER_SECONDS;
                $runPaused = 'po chybě služby do ' . $this->clock($plan->pausedUntil);
            }
        }

        /** @var list<array{0: string, 1: int, 2: DownloadQuery}> $queries */
        $queries = [];
        $order = 0;
        foreach ($this->types as $type) {
            if ($options->type !== null && $type->id !== $options->type) {
                continue;
            }
            $config = $type->download;
            if ($config === null) {
                if ($type->downloadIssue !== null) {
                    $plan->configWarnings[] = "{$type->id}: {$type->downloadIssue}";
                }
                continue;
            }
            $calendar = null;
            if ($config->isWorkingDays()) {
                $calendar = $this->calendars->get((string) $config->calendar);
                if ($calendar === null) {
                    $plan->configWarnings[] = "{$type->id}: neznámý kalendář „{$config->calendar}“";
                    continue;
                }
            }
            $typeToday = $config->today($this->now);
            $published = $config->isPublished($this->now);
            $expected = DownloadSchedule::expectedDates($config, $calendar, $from, $to, $this->now);
            if ($expected === []) {
                $plan->types[] = new TypeDownloadPlan($type->id, $type->name, 0, 0, [], $typeToday, $published, $config->isWorkingDays());
                continue;
            }
            $present = $this->presence->datesFor($type->id, $expected[0], $expected[count($expected) - 1]);
            $plan->present[$type->id] = $present;
            $missing = array_values(array_filter($expected, static fn(string $date): bool => !isset($present[$date])));
            $plan->types[] = new TypeDownloadPlan($type->id, $type->name, count($expected), count($present), $missing, $typeToday, $published, $config->isWorkingDays());

            $byMonth = [];
            foreach ($missing as $date) {
                $byMonth[substr($date, 0, 7)][] = $date;
            }
            foreach ($byMonth as $month => $dates) {
                // Dnešní lístek má výjimku jen u denního typu; měsíční lístek 1. dne čeká jako každý měsíc.
                $todayMissing = $config->isWorkingDays() && in_array($typeToday, $dates, true);
                $rest = $todayMissing ? array_values(array_diff($dates, [$typeToday])) : $dates;
                $attemptAt = $manual ? null : $this->state->attemptAt($type->id, $month);
                $restDue = $rest !== [] && ($manual || $attemptAt === null || $nowTs - $attemptAt >= self::RETRY_AFTER_SECONDS);
                if ($runPaused !== null) {
                    $query = new DownloadQuery($type->id, $config->serviceListType, $month, $dates[0], $dates[count($dates) - 1], false, $runPaused);
                } elseif ($restDue) {
                    $query = new DownloadQuery($type->id, $config->serviceListType, $month, $dates[0], $dates[count($dates) - 1]);
                } elseif ($todayMissing) {
                    $query = new DownloadQuery($type->id, $config->serviceListType, $month, $typeToday, $typeToday, true);
                } else {
                    $query = new DownloadQuery($type->id, $config->serviceListType, $month, $rest[0], $rest[count($rest) - 1], false, 'další pokus po ' . $this->clock((int) $attemptAt + self::RETRY_AFTER_SECONDS));
                }
                $queries[] = [$month, $order, $query];
            }
            $order++;
        }
        usort($queries, static fn(array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
        $plan->queries = array_map(static fn(array $entry): DownloadQuery => $entry[2], $queries);
        return $plan;
    }

    public function run(DownloadOptions $options): DownloadReport
    {
        $plan = $this->plan($options);
        $report = new DownloadReport($plan);
        $manual = $options->manual();
        $nowTs = $this->now->getTimestamp();
        $futureWarned = [];

        foreach ($plan->queriesToSend() as $query) {
            $response = $this->client->fetch($query->serviceListType, $query->dateFrom, $query->dateTo);
            $report->sent++;
            if (!$manual && !$query->todayPoll) {
                $this->state->recordAttempt($query->listType, $query->month, $nowTs);
            }
            $line = ['query' => $query, 'kind' => $response->kind, 'lists' => count($response->lists), 'created' => 0, 'skipped' => 0, 'ignored' => 0, 'conflict' => 0, 'failed' => 0];
            if ($response->isError()) {
                $report->add($line);
                $report->error = $query->label() . ': ' . $response->error;
                if (!$manual) {
                    $this->state->recordError($nowTs);
                }
                break;
            }
            foreach ($response->lists as $list) {
                $listType = is_string($list['listType'] ?? null) ? $list['listType'] : '';
                $validFrom = is_string($list['validFrom'] ?? null) ? $list['validFrom'] : '';
                if (isset($plan->present[$listType][$validFrom])) {
                    $line['ignored']++;
                    continue;
                }
                $type = $this->types[$listType] ?? null;
                if ($type?->download !== null && $validFrom > $type->download->today($this->now)) {
                    if (!isset($futureWarned[$listType])) {
                        $futureWarned[$listType] = true;
                        $report->warnings[] = "{$listType}: služba vrátila lístek s budoucím datem {$validFrom} — přeskočen";
                    }
                    continue;
                }
                $result = $this->importer->import($list, ExchangeRateListImporter::SOURCE_SERVICE);
                if ($result->outcome === ExchangeRateListImportResult::FAILED) {
                    // Souběh dvou běhů na unikátním klíči (list_type, valid_from): druhý pokus lístek už najde.
                    $result = $this->importer->import($list, ExchangeRateListImporter::SOURCE_SERVICE);
                }
                switch ($result->outcome) {
                    case ExchangeRateListImportResult::CREATED:
                    case ExchangeRateListImportResult::REPLACED:
                        $line['created']++;
                        $plan->present[$listType][$validFrom] = true;
                        if ($result->skippedCurrencies !== []) {
                            $report->notices[] = $result->label() . ': přeskočené měny ' . implode(', ', $result->skippedCurrencies);
                        }
                        break;
                    case ExchangeRateListImportResult::SKIPPED:
                        $line['skipped']++;
                        break;
                    case ExchangeRateListImportResult::CONFLICT:
                        $line['conflict']++;
                        $report->warnings[] = $result->label() . ': lístek už existuje s jiným obsahem — nepřepsán';
                        break;
                    default:
                        $line['failed']++;
                        $report->warnings[] = ($result->label() !== '' ? $result->label() : $query->label()) . ': ' . implode(' ', $result->errors);
                }
            }
            $report->add($line);
        }

        if (!$manual) {
            $this->state->save(substr($plan->from, 0, 7), substr($plan->to, 0, 7));
        }
        return $report;
    }

    /** HH:MM v Europe/Prague pro hlášky o pauze. */
    private function clock(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(self::WINDOW_TIMEZONE))->format('H:i');
    }
}
