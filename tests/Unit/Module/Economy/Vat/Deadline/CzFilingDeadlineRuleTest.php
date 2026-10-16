<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Deadline;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Vat\Deadline\CzFilingDeadlineRule;
use Shipard\Module\Economy\Vat\Deadline\FilingDeadlines;

/** Termín podání přiznání ČR (#120 D17): 25. den po konci období, posun přes víkend a svátek. */
final class CzFilingDeadlineRuleTest extends TestCase
{
    /** @return iterable<string, array{0: string, 1: string}> */
    public static function deadlines(): iterable
    {
        yield 'měsíc, všední den' => ['2026-01-31', '2026-02-25'];
        yield 'měsíc, sobota → pondělí' => ['2026-06-30', '2026-07-27'];
        yield 'prosinec, neděle → pondělí' => ['2025-12-31', '2026-01-26'];
        yield 'svátek + víkend' => ['2026-11-30', '2026-12-28'];
        yield 'Velký pátek + Velikonoční pondělí 2016' => ['2016-02-29', '2016-03-29'];
        yield 'Velikonoční pondělí 2011' => ['2011-03-31', '2011-04-26'];
        yield 'Velký pátek 2015 ještě není svátek' => ['2015-03-09', '2015-04-03'];
        yield 'čtvrtletí, sobota → pondělí' => ['2026-03-31', '2026-04-27'];
    }

    #[DataProvider('deadlines')]
    public function testReturnDeadline(string $periodEnd, string $expected): void
    {
        $this->assertSame($expected, (new CzFilingDeadlineRule())->returnDeadline($periodEnd));
    }

    public function testRuleByCountry(): void
    {
        $this->assertInstanceOf(CzFilingDeadlineRule::class, FilingDeadlines::forCountry('cz'));
        $this->assertInstanceOf(CzFilingDeadlineRule::class, FilingDeadlines::forCountry(' CZ '));
        $this->assertNull(FilingDeadlines::forCountry('sk'));
        $this->assertNull(FilingDeadlines::forCountry(''));
    }
}
