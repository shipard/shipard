<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Overview;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\Overview\OpeningSequenceStatus;

/**
 * Pojistka počátečních stavů (#120 D15): chybí = běžné období bez zápisů
 * v otevíracím měsíci a předchozí období se zápisy; první rok firmy ani
 * prázdné předchozí období varování nedávají.
 */
final class OpeningSequenceStatusTest extends TestCase
{
    public function testMissingWhenPreviousYearHasEntriesAndCurrentHasNoOpening(): void
    {
        $status = $this->subject(dateBegin: '2026-01-01', previousYearId: 4, previousHasEntries: true, hasOpening: false);

        $this->assertSame(['missing' => true, 'firstYear' => false, 'previousYearId' => 4], $status->check(5));
    }

    public function testOkWhenOpeningEntriesExist(): void
    {
        $status = $this->subject(dateBegin: '2026-01-01', previousYearId: 4, previousHasEntries: true, hasOpening: true);

        $this->assertSame(['missing' => false, 'firstYear' => false, 'previousYearId' => 4], $status->check(5));
    }

    public function testFirstYearNeverWarns(): void
    {
        $status = $this->subject(dateBegin: '2026-01-01', previousYearId: null, previousHasEntries: false, hasOpening: false);

        $this->assertSame(['missing' => false, 'firstYear' => true, 'previousYearId' => null], $status->check(5));
    }

    public function testPreviousYearWithoutEntriesDoesNotWarn(): void
    {
        $status = $this->subject(dateBegin: '2026-01-01', previousYearId: 4, previousHasEntries: false, hasOpening: false);

        $this->assertSame(['missing' => false, 'firstYear' => false, 'previousYearId' => 4], $status->check(5));
    }

    public function testUnknownYearIsTreatedAsFirst(): void
    {
        $status = $this->subject(dateBegin: null, previousYearId: 4, previousHasEntries: true, hasOpening: false);

        $this->assertSame(['missing' => false, 'firstYear' => true, 'previousYearId' => null], $status->check(99));
    }

    private function subject(?string $dateBegin, ?int $previousYearId, bool $previousHasEntries, bool $hasOpening): OpeningSequenceStatus
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(
            static function (mixed ...$args) use ($dateBegin, $previousYearId, $previousHasEntries, $hasOpening): mixed {
                $sql = (string) $args[0];
                if (str_contains($sql, '[date_begin]')) {
                    return $dateBegin;
                }
                if (str_contains($sql, '[date_end] <')) {
                    return $previousYearId;
                }
                if (str_contains($sql, '[period_type]')) {
                    return $hasOpening ? 1 : null;
                }
                if (str_contains($sql, 'economy_accounting_journal')) {
                    return $previousHasEntries ? 1 : null;
                }
                throw new \LogicException("Neočekávaný dotaz: {$sql}");
            },
        );
        return new OpeningSequenceStatus($db);
    }
}
