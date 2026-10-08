<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

/**
 * Proměnná `{období}` v textu dokladu (docs/work-orders.md D12, Q6)
 * v jazyce dokumentu zákazníka: měsíc „říjen 2026“ (název měsíce v 1. pádě
 * přes ext-intl), „3. čtvrtletí 2026“, „2. pololetí 2026“, „2026“; en / sk
 * / de obdobně. Texty čtvrtletí a pololetí nese cfgItem
 * `economy.workOrders.periodTexts` zkompilovaný v jazyce dokumentu; bez
 * katalogu anglický fallback.
 */
final class PeriodLabel
{
    public const CFG_ITEM = 'economy.workOrders.periodTexts';

    /** Jazyk dokumentu → locale ext-intl (jako PrintTwigExtension). */
    private const LOCALES = ['cs' => 'cs_CZ', 'en' => 'en_GB', 'sk' => 'sk_SK', 'de' => 'de_DE'];

    private const FALLBACK_TEXTS = [
        'quarter'  => 'Q{n} {year}',
        'halfyear' => 'H{n} {year}',
    ];

    /**
     * @param array<string, mixed>|null $texts cfgItem `periodTexts` v jazyce dokumentu
     */
    public static function format(Period $period, string $periodicity, string $language, ?array $texts = null): string
    {
        $year = substr($period->from, 0, 4);
        return match ($periodicity) {
            'month'    => self::monthName($period->from, $language),
            'quarter', 'halfyear' => strtr(self::text($texts, $periodicity), [
                '{n}'    => (string) PeriodCalendar::ordinalInYear($period, $periodicity),
                '{year}' => $year,
            ]),
            'year'     => $year,
            default    => $period->from . ' – ' . $period->to,
        };
    }

    private static function text(?array $texts, string $periodicity): string
    {
        $entry = $texts[$periodicity] ?? null;
        if (is_array($entry) && is_string($entry['text'] ?? null) && $entry['text'] !== '') {
            return $entry['text'];
        }
        return self::FALLBACK_TEXTS[$periodicity];
    }

    private static function monthName(string $date, string $language): string
    {
        $locale = self::LOCALES[$language] ?? self::LOCALES['en'];
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            'UTC',
            \IntlDateFormatter::GREGORIAN,
            'LLLL yyyy',
        );
        $formatted = $formatter->format(new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('UTC')));
        return is_string($formatted) && $formatted !== '' ? $formatted : $date;
    }
}
