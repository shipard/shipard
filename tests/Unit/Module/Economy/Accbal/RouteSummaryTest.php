<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Accbal\RouteResult;
use Shipard\Module\Economy\Accbal\RouteSummary;

/** Agregace RouteSummary: počty per status, důvody přeskočení, Σ v domácí měně. */
class RouteSummaryTest extends TestCase
{
    public function testAggregatesRoutedPlannedAndSkipped(): void
    {
        $s = new RouteSummary();
        $s->add(RouteResult::routed(1, '311100', 42, 'czk', 100.00, 100.00));
        $s->add(RouteResult::routed(2, '311100', 42, 'eur', 10.00, 250.50));
        $s->add(RouteResult::planned(3, '321100', 43, 'czk', 30.00, 30.00));
        $s->add(RouteResult::skipped(4, 'no_open_item', 5.00, 5.00));
        $s->add(RouteResult::skipped(5, 'no_open_item', 6.00, 6.00));
        $s->add(RouteResult::skipped(6, 'no_partner', 7.00, 7.00));

        $this->assertSame(6, $s->candidates());
        $this->assertSame(2, $s->routed);
        $this->assertSame(1, $s->planned);
        $this->assertSame(['no_open_item' => 2, 'no_partner' => 1], $s->skipped);
        $this->assertEqualsWithDelta(380.50, $s->routedAmount, 0.001, 'Σ amount_hc přeúčtovaných + naplánovaných');
        $this->assertCount(6, $s->results);
    }

    public function testEmptySummary(): void
    {
        $s = new RouteSummary();

        $this->assertSame(0, $s->candidates());
        $this->assertSame(0, $s->routed);
        $this->assertSame(0, $s->planned);
        $this->assertSame([], $s->skipped);
        $this->assertSame(0.0, $s->routedAmount);
    }
}
