<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Vat\Overview\VatBlock;

/**
 * Blok DPH bez reportu (#120 D7): neplátce = prázdný výsledek (sekce se
 * neposílá), vypnutá agenda = nedostupný, bez období = zpráva. Hodnoty
 * z živého přiznání a termín kryje integrační VatBlockTest.
 */
final class VatBlockTest extends TestCase
{
    public function testNonPayerYieldsEmptyResult(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null); // žádná aktivní registrace

        $result = (new VatBlock())->collect($this->context($db));

        $this->assertSame([], $result->items);
        $this->assertSame([], $result->messages);
    }

    public function testNoPeriodsYieldInfoMessage(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(static fn (mixed ...$args): ?array
            => str_contains((string) $args[0], 'economy_codebooks_vat_registrations') ? ['id' => 3, 'name' => 'X'] : null);

        $result = (new VatBlock())->collect($this->context($db));

        $this->assertSame([], $result->items);
        $this->assertSame(['noVatPeriod'], array_map(static fn ($m) => $m->code, $result->messages));
    }

    public function testAvailabilityFollowsTablesAndVatAgendaSetting(): void
    {
        $block = new VatBlock();
        foreach ([[null, true], [true, true], [false, false]] as [$agenda, $expected]) {
            $db = $this->createMock(DataSourceConnection::class);
            $db->method('fetchSingle')->willReturn($agenda === null ? null : json_encode($agenda));
            $this->assertSame($expected, $block->isAvailable($this->context($db)), var_export($agenda, true));
        }
        $db = $this->createMock(DataSourceConnection::class);
        $this->assertFalse($block->isAvailable($this->context($db, ['economy_vat_report_periods' => true])), 'bez registrací');
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
            $tables ?? ['economy_vat_report_periods' => true, 'economy_codebooks_vat_registrations' => true, 'economy_vat_filings' => true],
            'cz',
            'czk',
        );
    }
}
