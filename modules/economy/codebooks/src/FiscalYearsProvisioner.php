<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;

/**
 * Idempotentní seed fiskálních roků.
 *
 * Při každém běhu zajistí, že existuje fiskální rok pokrývající dnešní datum,
 * a pokud existuje, zajistí i následující rok. Každý nový rok se vytvoří jako
 * V pořádku (docState=40, docStateMain=3) a doprovází jej 14 měsíců:
 * 1× Otevření (jednodenní) + 12× Běžné + 1× Uzavření (jednodenní).
 */
class FiscalYearsProvisioner
{
    /**
     * $yearStartMonth = rozhodnutí per DS (settings klíč
     * `economy.fiscalYearStartMonth`, docs/ds-setup.md §5.2). Null padá na
     * cfgItem — vzniklo kvůli volajícím, kteří rozhodnutí předávají přímo
     * (ds-upgrade ze settings, průvodce ve Fázi 4) a cfgItem k tomu
     * použít nemohou.
     *
     * $currency = rozhodnutí per DS (settings klíč `economy.homeCurrency`,
     * §5.2) — předává volající, provisioner si settings nečte sám.
     * Null → 'czk' (defenzivní; ds-upgrade bez rozhodnuté měny neseeduje).
     */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ConfigRuntime $config,
        private readonly ?int $yearStartMonth = null,
        private readonly ?string $currency = null,
    ) {}

    /**
     * @return array{fiscalYears: array{created: int, existing: int}}
     */
    public function provision(?\DateTimeImmutable $referenceDate = null): array
    {
        $referenceDate ??= new \DateTimeImmutable('today');
        $yearStartMonth = $this->resolveYearStartMonth();

        $created = 0;
        $existing = 0;

        $current = $this->computeFiscalYear($referenceDate, $yearStartMonth);
        if ($this->yearExistsForBegin($current['date_begin'])) {
            $existing++;
            $next = $this->computeFiscalYear(
                $current['date_begin']->modify('+1 year'),
                $yearStartMonth,
            );
            if ($this->yearExistsForBegin($next['date_begin'])) {
                $existing++;
            } else {
                $this->createYearWithMonths($next);
                $created++;
            }
        } else {
            $this->createYearWithMonths($current);
            $created++;
        }

        return ['fiscalYears' => ['created' => $created, 'existing' => $existing]];
    }

    /**
     * Zajistí fiskální rok obsahující datum. Na rozdíl od {@see provision()}
     * nikdy nezakládá rok následující — „ať existuje rok X“ pro
     * `dataset-seed` (tasks/testing-phase2.md D23): data sady z let, které
     * po resetu nemá kdo založit.
     *
     * @return array{created: bool, name: string, dateBegin: string}
     */
    public function ensureYearForDate(\DateTimeImmutable $date): array
    {
        $year = $this->computeFiscalYear($date, $this->resolveYearStartMonth());
        $dateBegin = $year['date_begin']->format('Y-m-d');
        if ($this->yearExistsForBegin($year['date_begin'])) {
            return ['created' => false, 'name' => $year['name'], 'dateBegin' => $dateBegin];
        }
        $this->createYearWithMonths($year);
        return ['created' => true, 'name' => $year['name'], 'dateBegin' => $dateBegin];
    }

    /** Konstruktor → cfgItem `economy.codebooks.fiscalConfig` → 1; mimo 1–12 → 1. */
    private function resolveYearStartMonth(): int
    {
        $yearStartMonth = $this->yearStartMonth;
        if ($yearStartMonth === null) {
            $cfg = $this->config->cfgItem('economy.codebooks.fiscalConfig');
            $yearStartMonth = is_array($cfg) && isset($cfg['yearStartMonth'])
                ? (int) $cfg['yearStartMonth']
                : 1;
        }
        if ($yearStartMonth < 1 || $yearStartMonth > 12) {
            $yearStartMonth = 1;
        }
        return $yearStartMonth;
    }

    /**
     * @return array{date_begin: \DateTimeImmutable, date_end: \DateTimeImmutable, name: string, doc_number_prefix: string}
     */
    private function computeFiscalYear(\DateTimeImmutable $referenceDate, int $yearStartMonth): array
    {
        $refYear  = (int) $referenceDate->format('Y');
        $refMonth = (int) $referenceDate->format('n');

        $fyStartYear = $refMonth >= $yearStartMonth ? $refYear : $refYear - 1;

        $dateBegin = new \DateTimeImmutable(sprintf('%04d-%02d-01', $fyStartYear, $yearStartMonth));
        $dateEnd   = $dateBegin->modify('+1 year -1 day');

        if ($yearStartMonth === 1) {
            $name   = (string) $fyStartYear;
            $prefix = substr((string) $fyStartYear, -2);
        } else {
            $fyEndYear = $fyStartYear + 1;
            $name      = sprintf('%d-%d', $fyStartYear, $fyEndYear);
            $prefix    = substr((string) $fyEndYear, -2);
        }

        return [
            'date_begin'        => $dateBegin,
            'date_end'          => $dateEnd,
            'name'              => $name,
            'doc_number_prefix' => $prefix,
        ];
    }

    private function yearExistsForBegin(\DateTimeImmutable $dateBegin): bool
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin = %d',
            $dateBegin->format('Y-m-d'),
        );
        return $row !== null;
    }

    /**
     * @param array{date_begin: \DateTimeImmutable, date_end: \DateTimeImmutable, name: string, doc_number_prefix: string} $year
     */
    private function createYearWithMonths(array $year): void
    {
        $this->db->begin();
        try {
            $yearId = $this->db->insertRow('economy_codebooks_fiscal_years', [
                'name'              => $year['name'],
                'doc_number_prefix' => $year['doc_number_prefix'],
                'date_begin'        => $year['date_begin']->format('Y-m-d'),
                'date_end'          => $year['date_end']->format('Y-m-d'),
                'currency'          => $this->currency ?? 'czk',
                'locked'            => 0,
                'docState'          => 40,
                'docStateMain'      => 3,
            ]);

            $this->insertMonth($yearId, $year['date_begin'], $year['date_begin'], 0);

            for ($i = 0; $i < 12; $i++) {
                $monthBegin = $year['date_begin']->modify("+{$i} month");
                $monthEnd   = $monthBegin->modify('+1 month -1 day');
                $this->insertMonth($yearId, $monthBegin, $monthEnd, 1);
            }

            $this->insertMonth($yearId, $year['date_end'], $year['date_end'], 2);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }

    private function insertMonth(
        int $yearId,
        \DateTimeImmutable $dateBegin,
        \DateTimeImmutable $dateEnd,
        int $periodType,
    ): void {
        $this->db->insertRow('economy_codebooks_fiscal_months', [
            'fiscal_year'    => $yearId,
            'date_begin'     => $dateBegin->format('Y-m-d'),
            'date_end'       => $dateEnd->format('Y-m-d'),
            'period_type'    => $periodType,
            'calendar_year'  => (int) $dateBegin->format('Y'),
            'calendar_month' => (int) $dateBegin->format('n'),
        ]);
    }
}
