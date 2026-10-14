<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks\Dataset;

use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\SectionSeeder;
use Shipard\Module\Core\Exchange\Dataset\SeedContext;
use Shipard\Module\Core\Exchange\Dataset\SeedReport;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;

/**
 * Fiskální roky pro roky, do kterých spadají data sady (#103 D23). Po
 * `ds-reset` založí provisioner v `ds-upgrade` jen rok aktuální; doklady
 * a návrhy z jiných let by se uložily s `fiscal_year = null` — tiše.
 *
 * Data: `docs/*.jsonc` `dates.{issueDate, accountingDate, taxPointDate,
 * vatObligationDate}`, `mail/*.jsonc` `receivedAt` a tatáž pole
 * v `analyses[].canonicalJson.dates`. Splatnost a období plnění rok
 * neurčují. Každý zajištěný rok (založený i už existující) = `ok`.
 * Nerozhodnutý první měsíc / domácí měna při datech v sadě = chyba
 * (stejná brána jako ds-upgrade; bez nich by roky vznikly s odhadem).
 */
class FiscalYearsSeeder implements SectionSeeder
{
    public const SECTION = 'fiscalYears';

    /** Pole, podle kterých doklad spadá do fiskálního roku. */
    public const DATE_KEYS = ['issueDate', 'accountingDate', 'taxPointDate', 'vatObligationDate'];

    public function section(): string
    {
        return self::SECTION;
    }

    public function seed(SeedContext $ctx, SeedReport $report): void
    {
        $dates = $this->collectDates($ctx->reader, $report);
        if ($dates === []) {
            return;
        }

        $settings = new SettingsStore($ctx->dsConnection);
        $startMonth = $settings->get('economy.fiscalYearStartMonth');
        $currency = $settings->get('economy.homeCurrency');
        $missing = [];
        if ($startMonth === null || $startMonth === '') {
            $missing[] = 'economy.fiscalYearStartMonth';
        }
        if (!is_string($currency) || $currency === '') {
            $missing[] = 'economy.homeCurrency';
        }
        if ($missing !== []) {
            $years = array_values(array_unique(array_map(static fn(string $d): string => substr($d, 0, 4), $dates)));
            $report->failed(self::SECTION, implode(', ', $missing) . ' není rozhodnuto — fiskální roky pro data sady ('
                . implode(', ', $years) . ') nelze založit; doplň setup/settings.jsonc');
            return;
        }

        $provisioner = $this->provisioner($ctx, (int) $startMonth, (string) $currency);
        $ensured = [];
        foreach ($dates as $date) {
            try {
                $year = $provisioner->ensureYearForDate(new \DateTimeImmutable($date));
            } catch (\Throwable $e) {
                $report->failed(self::SECTION, "rok pro datum {$date}: {$e->getMessage()}");
                continue;
            }
            if (isset($ensured[$year['dateBegin']])) {
                continue;
            }
            $ensured[$year['dateBegin']] = true;
            $report->ok(self::SECTION);
            if ($year['created']) {
                $report->warning("fiskální rok {$year['name']} založen pro data sady");
            }
        }
    }

    /**
     * Unikátní data (`Y-m-d`) z dokladů a zpráv sady, seřazená.
     *
     * @return list<string>
     */
    public function collectDates(DatasetReader $reader, ?SeedReport $report = null): array
    {
        $dates = [];
        foreach ($reader->listFiles('docs') as $rel) {
            try {
                $doc = $reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report?->warning("fiscalYears: {$rel}: {$e->getMessage()}");
                continue;
            }
            self::pushDates($dates, $doc['dates'] ?? null);
        }
        foreach ($reader->listFiles('mail') as $rel) {
            try {
                $m = $reader->readJsonc($rel);
            } catch (DatasetException $e) {
                $report?->warning("fiscalYears: {$rel}: {$e->getMessage()}");
                continue;
            }
            self::pushDate($dates, is_string($m['receivedAt'] ?? null) ? substr($m['receivedAt'], 0, 10) : null);
            foreach ((array) ($m['analyses'] ?? []) as $a) {
                $canonical = is_array($a) ? ($a['canonicalJson'] ?? null) : null;
                self::pushDates($dates, is_array($canonical) ? ($canonical['dates'] ?? null) : null);
            }
        }
        $dates = array_keys($dates);
        sort($dates);
        return $dates;
    }

    /**
     * @param array<string, true> $dates
     */
    private static function pushDates(array &$dates, mixed $block): void
    {
        if (!is_array($block)) {
            return;
        }
        foreach (self::DATE_KEYS as $key) {
            self::pushDate($dates, is_string($block[$key] ?? null) ? $block[$key] : null);
        }
    }

    /**
     * @param array<string, true> $dates
     */
    private static function pushDate(array &$dates, ?string $value): void
    {
        if ($value !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $dates[$value] = true;
        }
    }

    /** Seam pro testy. */
    protected function provisioner(SeedContext $ctx, int $startMonth, string $currency): FiscalYearsProvisioner
    {
        return new FiscalYearsProvisioner($ctx->dsConnection, $ctx->config, $startMonth, $currency);
    }
}
