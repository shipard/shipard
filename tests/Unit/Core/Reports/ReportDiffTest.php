<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Reports;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Reports\ReportDiff;

class ReportDiffTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function makeResult(array $rows, array $columns = ['closing'], string $status = 'ok'): array
    {
        return [
            'reportId' => 'economy.accounting.generalLedger',
            'params'   => ['period' => ['fiscalYear' => '2026', 'monthFrom' => 1, 'monthTo' => 12]],
            'status'   => $status,
            'messages' => [],
            'columns'  => array_map(
                static fn (string $id): array => ['id' => $id, 'type' => 'money', 'label' => $id, 'display' => 'sides'],
                $columns,
            ),
            'rows'     => $rows,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(string $account, float $md, float $d, string $column = 'closing'): array
    {
        return [
            'kind'    => 'detail',
            'level'   => 4,
            'account' => $account,
            'label'   => "Účet {$account}",
            'values'  => [$column => ['md' => $md, 'd' => $d, 'balance' => $md - $d]],
        ];
    }

    public function testIdenticalResults(): void
    {
        $a = $this->makeResult([$this->detail('311001', 1000.0, 250.0), $this->detail('602001', 0.0, 1000.0)]);
        $diff = (new ReportDiff())->diff($a, $a);

        $this->assertTrue($diff['identical']);
        $this->assertSame([], $diff['differences']);
        $this->assertSame([], $diff['onlyInA']);
        $this->assertSame([], $diff['onlyInB']);
        $this->assertSame('ok', $diff['statusA']);
        $this->assertSame('ok', $diff['statusB']);
    }

    public function testValueDifferenceInMdAndBalance(): void
    {
        $a = $this->makeResult([$this->detail('311001', 1000.0, 250.0)]);
        $b = $this->makeResult([$this->detail('311001', 1100.0, 250.0)]);
        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertFalse($diff['identical']);
        $this->assertCount(2, $diff['differences']);

        [$md, $balance] = $diff['differences'];
        $this->assertSame(['311001', 'closing', 'md'], [$md['account'], $md['column'], $md['field']]);
        $this->assertSame(1000.0, $md['a']);
        $this->assertSame(1100.0, $md['b']);
        $this->assertSame(100.0, $md['delta']);
        $this->assertSame('balance', $balance['field']);
        $this->assertSame(100.0, $balance['delta']);
    }

    public function testAccountsOnlyInOneSide(): void
    {
        $a = $this->makeResult([$this->detail('311001', 100.0, 0.0), $this->detail('321001', 0.0, 100.0)]);
        $b = $this->makeResult([$this->detail('311001', 100.0, 0.0), $this->detail('343021', 21.0, 0.0)]);
        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertFalse($diff['identical']);
        $this->assertSame([], $diff['differences']);
        $this->assertSame(['321001'], $diff['onlyInA']);
        $this->assertSame(['343021'], $diff['onlyInB']);
    }

    public function testColumnsOnlyInOneSideAreWarningNotDifference(): void
    {
        $a = $this->makeResult([$this->detail('311001', 100.0, 0.0)], ['closing']);
        $b = $this->makeResult([$this->detail('311001', 100.0, 0.0)], ['closing']);
        $a['columns'][] = ['id' => 'opening', 'type' => 'money', 'label' => 'opening', 'display' => 'balance'];
        $a['rows'][0]['values']['opening'] = ['md' => 55.0, 'd' => 0.0, 'balance' => 55.0];
        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertTrue($diff['identical']);
        $this->assertSame(['opening'], $diff['columnsOnlyInA']);
        $this->assertSame([], $diff['columnsOnlyInB']);
    }

    public function testToleranceBoundary(): void
    {
        $a = $this->makeResult([$this->detail('311001', 100.0, 0.0)]);

        $within = $this->makeResult([$this->detail('311001', 100.004, 0.0)]);
        $this->assertTrue((new ReportDiff())->diff($a, $within)['identical']);

        $beyond = $this->makeResult([$this->detail('311001', 100.006, 0.0)]);
        $diff = (new ReportDiff())->diff($a, $beyond);
        $this->assertFalse($diff['identical']);
        $this->assertSame('md', $diff['differences'][0]['field']);
    }

    public function testErrorStatusesArePropagated(): void
    {
        $a = $this->makeResult([$this->detail('311001', 100.0, 0.0)], ['closing'], 'errors');
        $b = $this->makeResult([$this->detail('311001', 100.0, 0.0)], ['closing'], 'warnings');
        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertTrue($diff['identical']);
        $this->assertSame('errors', $diff['statusA']);
        $this->assertSame('warnings', $diff['statusB']);
    }

    public function testSubtotalsAndComputedAreIgnoredTotalsCompareByLabel(): void
    {
        $total = [
            'kind'    => 'total',
            'level'   => 0,
            'account' => null,
            'label'   => 'CELKEM',
            'values'  => ['closing' => ['md' => 100.0, 'd' => 0.0, 'balance' => 100.0]],
        ];
        $a = $this->makeResult([
            $this->detail('311001', 100.0, 0.0),
            ['kind' => 'subtotal', 'level' => 2, 'account' => '311', 'label' => 'Pohledávky',
                'values' => ['closing' => ['md' => 100.0, 'd' => 0.0, 'balance' => 100.0]]],
            ['kind' => 'computed', 'level' => 1, 'account' => null, 'label' => 'Výsledek hospodaření',
                'values' => ['closing' => ['md' => 0.0, 'd' => 0.0, 'balance' => 0.0]]],
            $total,
        ]);
        // Strana B bez subtotal/computed — shodné detaily => shoda (stará strana je mít nemusí).
        $b = $this->makeResult([$this->detail('311001', 100.0, 0.0), $total]);

        $this->assertTrue((new ReportDiff())->diff($a, $b)['identical']);

        // Total s odlišnou hodnotou = rozdíl klíčovaný labelem.
        $b['rows'][1]['values']['closing']['balance'] = 200.0;
        $diff = (new ReportDiff())->diff($a, $b);
        $this->assertFalse($diff['identical']);
        $this->assertSame('CELKEM', $diff['differences'][0]['account']);
        $this->assertSame('balance', $diff['differences'][0]['field']);
    }

    public function testTotalOnlyInOneSideIsNotADifference(): void
    {
        $a = $this->makeResult([
            $this->detail('311001', 100.0, 0.0),
            ['kind' => 'total', 'level' => 0, 'account' => null, 'label' => 'CELKEM',
                'values' => ['closing' => ['md' => 100.0, 'd' => 0.0, 'balance' => 100.0]]],
        ]);
        $b = $this->makeResult([$this->detail('311001', 100.0, 0.0)]);

        $this->assertTrue((new ReportDiff())->diff($a, $b)['identical']);
    }

    // ── Text/date sloupce ───────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function textDetail(string $account, string $evidNumber, float $balance): array
    {
        return [
            'kind'    => 'detail',
            'level'   => 0,
            'account' => $account,
            'label'   => $account,
            'values'  => [
                'evidNumber' => $evidNumber,
                'closing'    => ['md' => $balance, 'd' => 0.0, 'balance' => $balance],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function makeTextResult(array $rows): array
    {
        $result = $this->makeResult($rows);
        array_unshift($result['columns'], ['id' => 'evidNumber', 'type' => 'text', 'label' => 'Ev. číslo', 'display' => 'balance']);
        return $result;
    }

    public function testTextColumnIdenticalAndDiffers(): void
    {
        $a = $this->makeTextResult([$this->textDetail('doc1', 'FV-2026-001', 100.0)]);
        $this->assertTrue((new ReportDiff())->diff($a, $a)['identical']);

        $b = $this->makeTextResult([$this->textDetail('doc1', 'FV-2026-002', 100.0)]);
        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertFalse($diff['identical']);
        $this->assertCount(1, $diff['differences']);
        $this->assertSame(
            ['account' => 'doc1', 'column' => 'evidNumber', 'field' => 'value',
                'a' => 'FV-2026-001', 'b' => 'FV-2026-002', 'delta' => null],
            $diff['differences'][0],
        );
    }

    public function testMissingColumnTypeIsTreatedAsMoney(): void
    {
        // Staré exporty `type` nemají — money porovnání musí fungovat dál.
        $a = $this->makeResult([$this->detail('311001', 100.0, 0.0)]);
        $b = $this->makeResult([$this->detail('311001', 200.0, 0.0)]);
        unset($a['columns'][0]['type'], $b['columns'][0]['type']);

        $diff = (new ReportDiff())->diff($a, $b);
        $this->assertFalse($diff['identical']);
        $this->assertSame('md', $diff['differences'][0]['field']);
        $this->assertSame(100.0, $diff['differences'][0]['delta']);
    }

    public function testTextColumnOnlyInOneSide(): void
    {
        $a = $this->makeTextResult([$this->textDetail('doc1', 'FV-2026-001', 100.0)]);
        $b = $this->makeResult([$this->detail('doc1', 100.0, 0.0)]);
        $b['rows'][0]['values'] = ['closing' => ['md' => 100.0, 'd' => 0.0, 'balance' => 100.0]];
        $b['rows'][0]['account'] = 'doc1';

        $diff = (new ReportDiff())->diff($a, $b);
        $this->assertTrue($diff['identical']);
        $this->assertSame(['evidNumber'], $diff['columnsOnlyInA']);
    }

    // ── Řádky s `key` (nejsou účty) se párují podle klíče ───────────────────

    /** @return array<string, mixed> */
    private function keyed(string $key, float $balance, ?string $account = null): array
    {
        return [
            'kind'    => 'detail',
            'level'   => 2,
            'account' => $account,
            'key'     => $key,
            'label'   => "Karta {$key}",
            'values'  => ['closing' => ['md' => $balance, 'd' => 0.0, 'balance' => $balance]],
        ];
    }

    public function testRowsWithKeyArePairedByKey(): void
    {
        $a = $this->makeResult([$this->keyed('asset:1', 100.0), $this->keyed('asset:2', 50.0)]);
        $b = $this->makeResult([$this->keyed('asset:2', 55.0), $this->keyed('asset:3', 10.0)]);

        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertFalse($diff['identical']);
        $this->assertSame(['asset:1'], $diff['onlyInA']);
        $this->assertSame(['asset:3'], $diff['onlyInB']);
        $this->assertSame('asset:2', $diff['differences'][0]['account']);
        $this->assertSame(5.0, $diff['differences'][0]['delta']);
    }

    public function testKeyTakesPrecedenceOverAccount(): void
    {
        // Stejný účet na dvou řádcích (dvě karty téže skupiny) — bez klíče by se přepsaly.
        $a = $this->makeResult([$this->keyed('asset:1', 100.0, '022100'), $this->keyed('asset:2', 50.0, '022100')]);
        $b = $this->makeResult([$this->keyed('asset:1', 100.0, '022100'), $this->keyed('asset:2', 50.0, '022100')]);

        $diff = (new ReportDiff())->diff($a, $b);

        $this->assertTrue($diff['identical']);
    }
}
