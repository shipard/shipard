<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Reports;

use Shipard\Core\Reports\DbFiscalPeriodProvider;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * `yearForDate` nad reálnými fiskálními roky DS: uvnitř roku, na obou
 * hranicích (1. 1. je zároveň datem otevíracího měsíce — vrátit se musí
 * rok, ne nic), mimo všechny roky → null; `years()` nejnovější první.
 * Nic se neseeduje — testuje se nad roky, které DS má.
 */
class DbFiscalPeriodProviderTest extends IntegrationTestCase
{
    /** @var list<array{id: int, name: string, date_begin: string, date_end: string}> */
    private array $years = [];

    protected function setUp(): void
    {
        parent::setUp();
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [date_begin], [date_end] FROM [economy_codebooks_fiscal_years]'
            . ' WHERE [docState] != 90 ORDER BY [date_begin]',
        );
        foreach ($rows as $r) {
            $this->years[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) $r['name'],
                'date_begin' => $this->ymd($r['date_begin']),
                'date_end'   => $this->ymd($r['date_end']),
            ];
        }
        if ($this->years === []) {
            $this->markTestSkipped('DS nemá žádný fiskální rok');
        }
    }

    private function ymd(mixed $v): string
    {
        return $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v;
    }

    public function testYearForDateInsideAndOnBoundaries(): void
    {
        $provider = new DbFiscalPeriodProvider($this->db);
        $y = $this->years[0];
        $expected = ['id' => $y['id'], 'name' => $y['name']];

        $inside = (new \DateTimeImmutable($y['date_begin']))->modify('+40 days')->format('Y-m-d');
        $this->assertLessThanOrEqual($y['date_end'], $inside);

        $this->assertSame($expected, $provider->yearForDate($inside), 'uvnitř roku');
        $this->assertSame($expected, $provider->yearForDate($y['date_begin']), 'první den (datum otevíracího měsíce)');
        $this->assertSame($expected, $provider->yearForDate($y['date_end']), 'poslední den (datum uzavíracího měsíce)');
    }

    public function testYearForDateOutsideAllYearsIsNull(): void
    {
        $provider = new DbFiscalPeriodProvider($this->db);
        $before = (new \DateTimeImmutable($this->years[0]['date_begin']))->modify('-1 day')->format('Y-m-d');
        $after = (new \DateTimeImmutable(end($this->years)['date_end']))->modify('+1 day')->format('Y-m-d');

        $this->assertNull($provider->yearForDate($before), 'den před prvním rokem');
        $this->assertNull($provider->yearForDate($after), 'den po posledním roku');
    }

    public function testYearsAreNewestFirst(): void
    {
        $years = (new DbFiscalPeriodProvider($this->db))->years();

        $expected = array_map(static fn (array $y): array => ['id' => $y['id'], 'name' => $y['name']], array_reverse($this->years));
        $this->assertSame($expected, $years);
    }
}
