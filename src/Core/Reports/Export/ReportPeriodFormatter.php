<?php

declare(strict_types=1);

namespace Shipard\Core\Reports\Export;

use Shipard\Core\Utils\Slug;

/**
 * Lidský popisek období a přípona názvu souboru z klíče `period`
 * v `ReportResult::params`. Dva tvary dle zdroje období (docs/reports.md
 * §10, §14): fiskální `{fiscalYear, monthFrom, monthTo}` a instance
 * daňového tvrzení `{period, name, dateFrom, dateTo, …}`.
 */
final class ReportPeriodFormatter
{
    /**
     * Fiskální období stejně jako `formatPeriodLabel()` v PeriodPickeru:
     * „2026 / 8", „2026 / 2Q", „2026 / 1|2", „2026" (celý rok), nezarovnaný
     * interval „2026 / 2–4". Instance tvrzení: název + rozsah dat.
     *
     * @param array<string, mixed> $period
     */
    public static function label(array $period, int $fiscalYearMonths, string $language): string
    {
        if (isset($period['fiscalYear'])) {
            $year = (string) $period['fiscalYear'];
            $from = (int) ($period['monthFrom'] ?? 0);
            $to   = (int) ($period['monthTo'] ?? 0);
            if ($from === 1 && $to === $fiscalYearMonths) {
                return $year;
            }
            if ($from === $to) {
                return "{$year} / {$from}";
            }
            if ($to - $from === 2 && $from % 3 === 1) {
                return "{$year} / " . intdiv($from + 2, 3) . 'Q';
            }
            if ($to - $from === 5 && ($from === 1 || $from === 7)) {
                return "{$year} / " . ($from === 1 ? 1 : 2) . '|2';
            }
            return "{$year} / {$from}–{$to}";
        }

        $name = is_scalar($period['name'] ?? null) ? (string) $period['name'] : '';
        $from = self::formatDate($period['dateFrom'] ?? null, $language);
        $to   = self::formatDate($period['dateTo'] ?? null, $language);
        if ($from === '' || $to === '') {
            return $name;
        }
        return $name === '' ? "{$from} – {$to}" : "{$name} ({$from} – {$to})";
    }

    /**
     * Přípona názvu souboru: „2026" (celý rok), „2026-05" (měsíc),
     * „2026-04-06" (interval měsíců); u instance tvrzení slug jejího názvu.
     *
     * @param array<string, mixed> $period
     */
    public static function fileSuffix(array $period, int $fiscalYearMonths): string
    {
        if (isset($period['fiscalYear'])) {
            $year = Slug::make((string) $period['fiscalYear'], fallback: 'period');
            $from = (int) ($period['monthFrom'] ?? 0);
            $to   = (int) ($period['monthTo'] ?? 0);
            if ($from === 1 && $to === $fiscalYearMonths) {
                return $year;
            }
            if ($from === $to) {
                return sprintf('%s-%02d', $year, $from);
            }
            return sprintf('%s-%02d-%02d', $year, $from, $to);
        }

        $name = is_scalar($period['name'] ?? null) ? (string) $period['name'] : '';
        return Slug::make($name, fallback: 'period');
    }

    private static function formatDate(mixed $iso, string $language): string
    {
        if (!is_string($iso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
        if ($date === false) {
            return '';
        }
        return $date->format($language === 'cs' ? 'j. n. Y' : 'Y-m-d');
    }
}
