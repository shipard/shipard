<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\Depreciation\Period;
use Shipard\Module\Economy\Assets\Depreciation\PlanRow;
use Shipard\Module\Economy\Assets\SystemDepreciationWriter;

/** Řádky plánu → potvrzené systémové odpisy; zamčený měsíc zápis zastaví. */
class SystemDepreciationWriterTest extends TestCase
{
    private function planned(string $date, Period $period, float $amount, bool $half = false): PlanRow
    {
        return new PlanRow(
            'depreciation', PlanRow::STATUS_PLANNED, $date, $period, $amount, $amount, 'x',
            100000.0, $amount, 100000.0 - $amount, halfYear: $half,
        );
    }

    public function testWritesConfirmedSystemEvents(): void
    {
        $writer = new SpyInsertWriter();
        $total = $writer->write(5, 'tax', [
            $this->planned('2022-12-31', new Period(1, '2022-01-01', '2022-12-31'), 11000.0),
            $this->planned('2024-05-10', new Period(null, '2024-01-01', '2024-12-31'), 11125.0, true),
        ]);

        $this->assertSame(22125.0, $total);
        $this->assertCount(2, $writer->rows);
        $this->assertSame([
            'asset' => 5, 'event_kind' => 'depreciation', 'scope' => 'tax', 'event_date' => '2024-05-10',
            'period_begin' => '2024-01-01', 'period_end' => '2024-12-31', 'amount' => 11125.0, 'half_year' => 1,
            'origin' => 'system', 'docState' => 40, 'docStateMain' => 3,
        ], $writer->rows[1]);
    }

    public function testLockedMonthStopsTheWrite(): void
    {
        $writer = new SpyInsertWriter();
        $writer->locked = ['2022-12' => '2022/12'];

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('2022/12');
        $writer->write(5, 'tax', [
            $this->planned('2022-12-31', new Period(1, '2022-01-01', '2022-12-31'), 11000.0),
        ]);
    }

    public function testOnlyPlannedDepreciationRowsAreAccepted(): void
    {
        $confirmed = new PlanRow('depreciation', PlanRow::STATUS_CONFIRMED, '2022-12-31', null, 1.0, 1.0, null, 1.0, 1.0, 0.0);
        $this->expectException(\LogicException::class);
        (new SpyInsertWriter())->write(5, 'tax', [$confirmed]);
    }
}

class SpyInsertWriter extends SystemDepreciationWriter
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];
    /** @var array<string, string> `Y-m` → popisek */
    public array $locked = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    protected function lockedMonthLabel(string $date): ?string
    {
        return $this->locked[substr($date, 0, 7)] ?? null;
    }

    protected function insert(array $row): void
    {
        $this->rows[] = $row;
    }
}
