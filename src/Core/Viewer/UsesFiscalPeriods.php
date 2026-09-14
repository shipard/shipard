<?php

declare(strict_types=1);

namespace Shipard\Core\Viewer;

use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Core\Reports\FiscalPeriodProvider;

/**
 * Přístup vieweru k fiskálním obdobím — stejná cesta jako reporty
 * (`ReportRunner`): provider se líně vytváří nad `$this->db`, testy ho
 * podstrčí přes {@see setFiscalPeriodProvider()}. Viewery ho používají
 * pro filtr období s výchozím aktuálním rokem ({@see FiscalYearFilter}).
 *
 * Používá {@see TableViewer::$db}.
 */
trait UsesFiscalPeriods
{
    private ?FiscalPeriodProvider $fiscalPeriods = null;

    /** Pro testy: fake provider místo DB. */
    public function setFiscalPeriodProvider(FiscalPeriodProvider $periods): void
    {
        $this->fiscalPeriods = $periods;
    }

    protected function fiscalPeriods(): FiscalPeriodProvider
    {
        return $this->fiscalPeriods ??= new DbFiscalPeriodProvider($this->db);
    }

    /** Definice filtru `fiscal_year` (select, nejnovější první) s výchozím aktuálním rokem. */
    protected function fiscalYearFilter(string $label): array
    {
        return FiscalYearFilter::build($this->fiscalPeriods(), $label, $this->today()->format('Y-m-d'));
    }

    /** Dnešek — aktuální rok, dny po splatnosti; nic se neukládá. */
    protected function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today');
    }
}
