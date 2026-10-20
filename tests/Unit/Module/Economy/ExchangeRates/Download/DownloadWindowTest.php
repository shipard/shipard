<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\ExchangeRates\Download;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\ExchangeRates\Download\DownloadWindow;

/** Začátek okna = začátek předchozího fiskálního roku (#121 task B §4.1), tři větve. */
class DownloadWindowTest extends TestCase
{
    public function testPreviousFiscalYearEndingTheDayBeforeTheCurrentOne(): void
    {
        $years = [
            ['begin' => '2024-01-01', 'end' => '2024-12-31'],
            ['begin' => '2025-01-01', 'end' => '2025-12-31'],
            ['begin' => '2026-01-01', 'end' => '2026-12-31'],
        ];
        $this->assertSame('2025-01-01', DownloadWindow::start('2026-10-20', $years));
        $this->assertSame('2024-01-01', DownloadWindow::start('2025-03-01', $years));
    }

    public function testBrokenFiscalYearBoundaries(): void
    {
        // Hospodářský rok 1. 4. – 31. 3.; předchozí navazuje přesně.
        $years = [['begin' => '2025-04-01', 'end' => '2026-03-31'], ['begin' => '2026-04-01', 'end' => '2027-03-31']];
        $this->assertSame('2025-04-01', DownloadWindow::start('2026-10-20', $years));
    }

    public function testWithoutAdjacentPreviousYearGoesOneYearBack(): void
    {
        $this->assertSame('2025-01-01', DownloadWindow::start('2026-10-20', [['begin' => '2026-01-01', 'end' => '2026-12-31']]));
        // Mezera mezi roky (předchozí nekončí den před začátkem) = taky minus rok.
        $this->assertSame('2025-04-01', DownloadWindow::start('2026-10-20', [['begin' => '2024-01-01', 'end' => '2024-12-31'], ['begin' => '2026-04-01', 'end' => '2027-03-31']]));
    }

    public function testWithoutFiscalYearsOrWithoutTheCurrentOneFallsBackToPreviousCalendarYear(): void
    {
        $this->assertSame('2025-01-01', DownloadWindow::start('2026-10-20', []));
        $this->assertSame('2025-01-01', DownloadWindow::start('2026-01-05', [['begin' => '2025-01-01', 'end' => '2025-12-31']]), 'leden bez založeného roku');
    }
}
