<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\ExchangeRates\Download;

/**
 * Stahování jednoho typu lístku — klíč `download` v
 * `world.<země>.exchangeRateLists` (#121 task B, U2):
 *
 *   - `serviceListType` — `listType` služby (1 = ČNB denní, 2 = ČNB ostatní měny),
 *   - `frequency` — `workingDays` (lístek každý pracovní den kalendáře
 *     `calendar`; dnešní lístek se očekává až po `publishAfter` v zóně
 *     `timezone`) nebo `monthly` (lístek s validFrom = 1. den měsíce),
 *   - `timezone` — výchozí Europe/Prague; u `workingDays` určuje i to,
 *     jaké datum je „dnes“.
 *
 * Neplatná konfigurace = `InvalidArgumentException` s důvodem;
 * ExchangeRateListTypes ji převede na `downloadIssue` typu, aby se
 * resolver nerozbil a stahovač typ jen přeskočil s varováním.
 */
final readonly class ListDownloadConfig
{
    public const string FREQUENCY_WORKING_DAYS = 'workingDays';
    public const string FREQUENCY_MONTHLY = 'monthly';
    public const string DEFAULT_TIMEZONE = 'Europe/Prague';

    public function __construct(
        public int $serviceListType,
        public string $frequency,
        public ?string $calendar,
        public ?string $publishAfter,
        public string $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $raw): self
    {
        $serviceListType = $raw['serviceListType'] ?? null;
        if (!is_int($serviceListType) || $serviceListType < 1) {
            throw new \InvalidArgumentException('download.serviceListType musí být celé číslo od 1');
        }
        $frequency = $raw['frequency'] ?? null;
        if (!in_array($frequency, [self::FREQUENCY_WORKING_DAYS, self::FREQUENCY_MONTHLY], true)) {
            throw new \InvalidArgumentException('download.frequency musí být workingDays nebo monthly');
        }
        $timezone = $raw['timezone'] ?? self::DEFAULT_TIMEZONE;
        if (!is_string($timezone) || $timezone === '') {
            throw new \InvalidArgumentException('download.timezone musí být název časové zóny');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \InvalidArgumentException("download.timezone „{$timezone}“ není platná časová zóna");
        }

        $calendar = null;
        $publishAfter = null;
        if ($frequency === self::FREQUENCY_WORKING_DAYS) {
            $calendar = $raw['calendar'] ?? null;
            if (!is_string($calendar) || $calendar === '') {
                throw new \InvalidArgumentException('download.calendar je u workingDays povinný');
            }
            $publishAfter = $raw['publishAfter'] ?? null;
            if (!is_string($publishAfter) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $publishAfter)) {
                throw new \InvalidArgumentException('download.publishAfter musí být čas HH:MM');
            }
        }

        return new self($serviceListType, $frequency, $calendar, $publishAfter, $timezone);
    }

    public function isWorkingDays(): bool
    {
        return $this->frequency === self::FREQUENCY_WORKING_DAYS;
    }

    public function isMonthly(): bool
    {
        return $this->frequency === self::FREQUENCY_MONTHLY;
    }

    public function timeZone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezone);
    }

    /** Dnešní datum (Y-m-d) v zóně typu. */
    public function today(\DateTimeImmutable $now): string
    {
        return $now->setTimezone($this->timeZone())->format('Y-m-d');
    }

    /** Je v `$now` už po vyhlášení dnešního lístku? Měsíční typ vyhlášení neřeší (true). */
    public function isPublished(\DateTimeImmutable $now): bool
    {
        if ($this->publishAfter === null) {
            return true;
        }
        return $now->setTimezone($this->timeZone())->format('H:i') >= $this->publishAfter;
    }
}
