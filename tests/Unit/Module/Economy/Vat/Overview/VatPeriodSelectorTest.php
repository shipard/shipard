<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat\Overview;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\Overview\VatPeriodSelector;

/** Výběr období DPH (#120 D16): běžné + nepodané předchozí, podané předchozí skryté, bez běžného, neplátce. */
final class VatPeriodSelectorTest extends TestCase
{
    private const TODAY = '2026-10-16';

    public function testActiveRegistrationQueryAndShape(): void
    {
        $db = $this->db(registration: ['id' => 3, 'name' => 'Firma s.r.o.']);

        $this->assertSame(['id' => 3, 'name' => 'Firma s.r.o.'], (new VatPeriodSelector($db))->activeRegistration(self::TODAY));
        [$sql, $params] = $this->queries[0];
        $this->assertStringContainsString('[docState] IN %in AND [valid_from] <= %s AND ([valid_to] IS NULL OR [valid_to] >= %s)', $sql);
        $this->assertSame([[10, 40], self::TODAY, self::TODAY], $params);

        $this->assertNull((new VatPeriodSelector($this->db(registration: null)))->activeRegistration(self::TODAY), 'neplátce');
    }

    public function testCurrentAndUnfiledPrevious(): void
    {
        $db = $this->db(
            current:  ['id' => 21, 'name' => '10/2026', 'date_begin' => '2026-10-01', 'date_end' => '2026-10-31'],
            previous: ['id' => 20, 'name' => '09/2026', 'date_begin' => '2026-09-01', 'date_end' => '2026-09-30'],
            filed: false,
        );

        $periods = (new VatPeriodSelector($db))->periods(3, self::TODAY);

        $this->assertSame(['id' => 21, 'name' => '10/2026', 'dateBegin' => '2026-10-01', 'dateEnd' => '2026-10-31'], $periods['current']);
        $this->assertSame(['id' => 20, 'name' => '09/2026', 'dateBegin' => '2026-09-01', 'dateEnd' => '2026-09-30'], $periods['previous']);
        // Předchozí = poslední s koncem před začátkem běžného; jen typ přiznání, bez smazaných.
        [$sql, $params] = $this->queries[1];
        $this->assertStringContainsString("[report_type] = %s AND [docState] != %i AND [date_end] < %s", $sql);
        $this->assertSame([3, 'return', 90, '2026-10-01'], $params);
        $this->assertSame([20, 40], $this->queries[2][1], 'podání ve stavu Podáno');
    }

    public function testFiledPreviousIsHidden(): void
    {
        $db = $this->db(
            current:  ['id' => 21, 'name' => '10/2026', 'date_begin' => '2026-10-01', 'date_end' => '2026-10-31'],
            previous: ['id' => 20, 'name' => '09/2026', 'date_begin' => '2026-09-01', 'date_end' => '2026-09-30'],
            filed: true,
        );

        $periods = (new VatPeriodSelector($db))->periods(3, self::TODAY);

        $this->assertNotNull($periods['current']);
        $this->assertNull($periods['previous']);
    }

    public function testWithoutCurrentPreviousIsSearchedBeforeToday(): void
    {
        $db = $this->db(
            current:  null,
            previous: ['id' => 20, 'name' => '09/2026', 'date_begin' => '2026-09-01', 'date_end' => '2026-09-30'],
            filed: false,
        );

        $periods = (new VatPeriodSelector($db))->periods(3, self::TODAY);

        $this->assertNull($periods['current']);
        $this->assertSame(20, $periods['previous']['id']);
        $this->assertSame([3, 'return', 90, self::TODAY], $this->queries[1][1]);
    }

    public function testWithoutFilingsTablePreviousIsNeverFiled(): void
    {
        $db = $this->db(
            current:  null,
            previous: ['id' => 20, 'name' => '09/2026', 'date_begin' => '2026-09-01', 'date_end' => '2026-09-30'],
            filed: true,
        );
        $db->expects($this->never())->method('fetchSingle');

        $this->assertSame(20, (new VatPeriodSelector($db, hasFilings: false))->periods(3, self::TODAY)['previous']['id']);
    }

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $queries = [];

    private function db(?array $registration = null, ?array $current = null, ?array $previous = null, bool $filed = false): MockObject&DataSourceConnection
    {
        $this->queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(function (mixed ...$args) use ($registration, $current, $previous): ?array {
            $sql = (string) $args[0];
            $this->queries[] = [$sql, array_slice($args, 1)];
            if (str_contains($sql, 'economy_codebooks_vat_registrations')) {
                return $registration;
            }
            if (str_contains($sql, '[date_begin] <= %s AND [date_end] >= %s')) {
                return $current;
            }
            if (str_contains($sql, '[date_end] < %s')) {
                return $previous;
            }
            throw new \LogicException("Neočekávaný fetchRow: {$sql}");
        });
        $db->method('fetchSingle')->willReturnCallback(function (mixed ...$args) use ($filed): mixed {
            $this->queries[] = [(string) $args[0], array_slice($args, 1)];
            $this->assertStringContainsString('economy_vat_filings', (string) $args[0]);
            return $filed ? 1 : null;
        });
        return $db;
    }
}
