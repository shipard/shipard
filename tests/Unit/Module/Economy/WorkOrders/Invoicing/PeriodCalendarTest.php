<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Invoicing;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\WorkOrders\Invoicing\Period;
use Shipard\Module\Economy\WorkOrders\Invoicing\PeriodCalendar;

/**
 * Kalendářní období (Q3): hranice všech periodicit, „fakturovat od“
 * uprostřed období, počátek / konec, datum ukončení, přestupný rok.
 */
class PeriodCalendarTest extends TestCase
{
    /** @return list<array{string, string}> */
    private function ranges(array $periods): array
    {
        return array_map(static fn(Period $p): array => [$p->from, $p->to], $periods);
    }

    public function testContainingUsesCalendarBoundaries(): void
    {
        $this->assertSame(['2026-10-01', '2026-10-31'], [PeriodCalendar::containing('2026-10-15', 'month')->from, PeriodCalendar::containing('2026-10-15', 'month')->to]);
        $this->assertSame(['2026-07-01', '2026-09-30'], $this->ranges([PeriodCalendar::containing('2026-08-20', 'quarter')])[0]);
        $this->assertSame(['2026-10-01', '2026-12-31'], $this->ranges([PeriodCalendar::containing('2026-12-31', 'quarter')])[0]);
        $this->assertSame(['2026-07-01', '2026-12-31'], $this->ranges([PeriodCalendar::containing('2026-07-01', 'halfyear')])[0]);
        $this->assertSame(['2026-01-01', '2026-06-30'], $this->ranges([PeriodCalendar::containing('2026-06-30', 'halfyear')])[0]);
        $this->assertSame(['2026-01-01', '2026-12-31'], $this->ranges([PeriodCalendar::containing('2026-05-05', 'year')])[0]);
        // Přestupný únor.
        $this->assertSame(['2028-02-01', '2028-02-29'], $this->ranges([PeriodCalendar::containing('2028-02-10', 'month')])[0]);
    }

    public function testNextAndOrdinal(): void
    {
        $dec = PeriodCalendar::containing('2026-12-05', 'month');
        $this->assertSame(['2027-01-01', '2027-01-31'], $this->ranges([PeriodCalendar::next($dec, 'month')])[0]);
        $q4 = PeriodCalendar::containing('2026-11-01', 'quarter');
        $this->assertSame(['2027-01-01', '2027-03-31'], $this->ranges([PeriodCalendar::next($q4, 'quarter')])[0]);
        $this->assertSame(4, PeriodCalendar::ordinalInYear($q4, 'quarter'));
        $this->assertSame(2, PeriodCalendar::ordinalInYear(PeriodCalendar::containing('2026-09-01', 'halfyear'), 'halfyear'));
        $this->assertSame(11, PeriodCalendar::ordinalInYear(PeriodCalendar::containing('2026-11-01', 'month'), 'month'));
    }

    public function testUnknownPeriodicityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PeriodCalendar::containing('2026-10-01', 'week');
    }

    public function testBillingDateFollowsTiming(): void
    {
        $period = new Period('2026-10-01', '2026-10-31');
        $this->assertSame('2026-10-01', PeriodCalendar::billingDate($period, 'start'));
        $this->assertSame('2026-10-31', PeriodCalendar::billingDate($period, 'end'));
    }

    public function testDuePeriodsStartAtPeriodContainingInvoiceFrom(): void
    {
        // Fakturovat od 10. 8. → první období srpen celý; k 8. 10. jsou splatné srpen, září, říjen (počátek).
        $due = PeriodCalendar::duePeriods('2026-08-10', null, '2026-10-08', 'month', 'start');
        $this->assertSame(
            [['2026-08-01', '2026-08-31'], ['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']],
            $this->ranges($due),
        );
        // Fakturace na konci: říjen ještě ne.
        $due = PeriodCalendar::duePeriods('2026-08-10', null, '2026-10-08', 'month', 'end');
        $this->assertSame([['2026-08-01', '2026-08-31'], ['2026-09-01', '2026-09-30']], $this->ranges($due));
        // Běh přesně v den fakturace období ho zahrne.
        $due = PeriodCalendar::duePeriods('2026-10-01', null, '2026-10-01', 'month', 'start');
        $this->assertSame([['2026-10-01', '2026-10-31']], $this->ranges($due));
        $this->assertSame([], $this->ranges(PeriodCalendar::duePeriods('2026-11-01', null, '2026-10-08', 'month', 'start')));
    }

    public function testQuarterAtEndIsDueOnlyAfterItsLastDay(): void
    {
        $this->assertSame(
            [['2026-07-01', '2026-09-30']],
            $this->ranges(PeriodCalendar::duePeriods('2026-07-01', null, '2026-09-30', 'quarter', 'end')),
        );
        $this->assertSame([], $this->ranges(PeriodCalendar::duePeriods('2026-07-01', null, '2026-09-29', 'quarter', 'end')));
    }

    public function testDateEndStopsPeriodsStartingAfterItButKeepsTheOneCrossingIt(): void
    {
        // Ukončení 31. 10.: listopad už ne.
        $due = PeriodCalendar::duePeriods('2026-09-01', '2026-10-31', '2026-12-01', 'month', 'start');
        $this->assertSame([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']], $this->ranges($due));
        // Ukončení 15. 10.: říjen přes datum ukončení celý (bez krácení), listopad ne.
        $due = PeriodCalendar::duePeriods('2026-09-01', '2026-10-15', '2026-12-01', 'month', 'start');
        $this->assertSame([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']], $this->ranges($due));
        // Roční zakázka s ukončením v půlce roku: rok celý.
        $due = PeriodCalendar::duePeriods('2026-01-01', '2026-06-30', '2027-03-01', 'year', 'start');
        $this->assertSame([['2026-01-01', '2026-12-31']], $this->ranges($due));
    }

    public function testCatchUpListsEveryDuePeriodSinceInvoiceFrom(): void
    {
        $due = PeriodCalendar::duePeriods('2025-10-01', null, '2026-10-08', 'month', 'start');
        $this->assertCount(13, $due);
        $this->assertSame('2025-10-01', $due[0]->from);
        $this->assertSame('2026-10-01', $due[12]->from);
    }

    public function testNextDueIsFirstPeriodNotYetDue(): void
    {
        $next = PeriodCalendar::nextDue('2026-08-01', null, '2026-10-08', 'month', 'start');
        $this->assertSame(['2026-11-01', '2026-11-30'], [$next->from, $next->to]);
        $next = PeriodCalendar::nextDue('2026-08-01', null, '2026-10-08', 'month', 'end');
        $this->assertSame(['2026-10-01', '2026-10-31'], [$next->from, $next->to]);
        $this->assertNull(PeriodCalendar::nextDue('2026-08-01', '2026-10-31', '2026-10-08', 'month', 'start'));
    }
}
