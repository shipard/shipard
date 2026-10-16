<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Accounting\Overview\ProfitLossBlock;

/**
 * Blok výsledku hospodaření (#120 D8) bez reportu: chybějící účetní
 * období a dnešek mimo běžný měsíc dávají zprávu, ne výjimku. Hodnota
 * = `ytd` výsledovky kryje integrační ProfitLossBlockTest.
 */
final class ProfitLossBlockTest extends TestCase
{
    public function testNoFiscalYearYieldsInfoMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $result = (new ProfitLossBlock())->collect($this->context($db));

        $this->assertSame([], $result->items);
        $this->assertSame(['noFiscalYear'], array_map(static fn ($m) => $m->code, $result->messages));
    }

    public function testTodayOutsideRegularMonthYieldsInfoMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 5, 'name' => '2026']);
        $db->method('fetchSingle')->willReturn(null); // žádný běžný měsíc s dneškem
        $db->expects($this->never())->method('fetchAll');

        $result = (new ProfitLossBlock())->collect($this->context($db));

        $this->assertSame([], $result->items);
        $this->assertSame(['noCurrentMonth'], array_map(static fn ($m) => $m->code, $result->messages));
        $this->assertSame('info', $result->messages[0]->severity);
    }

    public function testAvailabilityRequiresJournalAndMonths(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $this->assertTrue((new ProfitLossBlock())->isAvailable($this->context($db)));
        $this->assertFalse((new ProfitLossBlock())->isAvailable($this->context($db, ['economy_accounting_journal' => true])));
    }

    /** @param ?array<string, true> $tables */
    private function context(DataSourceConnection $db, ?array $tables = null): OverviewContext
    {
        return new OverviewContext(
            $db,
            null,
            'cs',
            new \DateTimeImmutable('2026-10-16'),
            new ReportRunner(new ReportRegistry(), $db, null, 'test-ds'),
            $tables ?? ['economy_accounting_journal' => true, 'economy_codebooks_fiscal_months' => true],
            'cz',
            'czk',
        );
    }
}
