<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportStatus;
use Shipard\Module\Economy\Assets\Reports\MovementsBuilder;
use Shipard\Module\Economy\Assets\Reports\RegisterBuilder;

/**
 * Přírůstky a úbytky a Soupis majetku (docs/assets.md D66) nad kartami
 * v paměti.
 */
class MovementsAndRegisterBuilderTest extends AssetReportTestCase
{
    /** @param array<string, mixed> $params */
    private function movements(int $year, array $params = [], int $monthFrom = 1, int $monthTo = 12): ReportResult
    {
        return (new MovementsBuilder($this->support))->build(
            $this->request('economy.assets.movements', $year, $params + ['kind' => 'all'], $monthFrom, $monthTo),
        );
    }

    /** @param array<string, mixed> $params */
    private function register(int $year, array $params = [], int $monthFrom = 1, int $monthTo = 12): ReportResult
    {
        return (new RegisterBuilder($this->support))->build(
            $this->request('economy.assets.register', $year, $params + ['groupBy' => 'type', 'foreign' => 'include'], $monthFrom, $monthTo),
        );
    }

    /** @param array<string, mixed> $o */
    private function small(int $id, array $o = []): void
    {
        $this->card($id, $o + [
            'category' => 'small', 'tax_method' => null, 'tax_rule' => null, 'acc_method' => null, 'acc_months' => null,
            'accounting_group' => null, 'group_code' => null, 'group_name' => null, 'group_order' => null,
        ]);
    }

    private function seed(): void
    {
        // Zařazení 2023, TZ a snížení 2024.
        $this->card(1);
        $this->event(1, 'activation', '2023-03-15', ['amount' => 100000]);
        $this->depreciated(1, 'tax', 2023, 11000);
        $this->depreciated(1, 'acc', 2023, 15000);
        $this->event(1, 'improvement', '2024-05-20', ['amount' => 30000]);
        $this->event(1, 'reduction', '2024-08-01', ['amount' => 5000]);

        // Zařazení 2022, vyřazení 10. 9. 2024 s odpisy do vyřazení.
        $this->card(2, ['docState' => 70, 'asset_type' => 7, 'type_name' => 'Vozidla']);
        $this->event(2, 'activation', '2022-12-20', ['amount' => 120000]);
        $this->depreciated(2, 'acc', 2023, 24000);
        $this->depreciated(2, 'tax', 2022, 13200);
        $this->depreciated(2, 'tax', 2023, 26700);
        $this->event(2, 'depreciation', '2024-09-10', [
            'scope' => 'acc', 'amount' => 18000, 'origin' => 'system',
            'period_begin' => '2024-01-01', 'period_end' => '2024-09-30',
        ]);
        $this->event(2, 'disposal', '2024-09-10');

        // Zařazení v posledním dni roku 2024.
        $this->card(3);
        $this->event(3, 'activation', '2024-12-31', ['amount' => 50000]);

        // Počáteční stav není přírůstek.
        $this->card(4);
        $opening = ['amount' => 70000, 'accumulated' => 20000, 'units_done' => 2, 'original_date' => '2022-01-10'];
        $this->event(4, 'opening', '2024-01-01', ['scope' => 'tax'] + $opening);
        $this->event(4, 'opening', '2024-01-01', ['scope' => 'acc', 'units_done' => 24] + $opening);

        // Drobný majetek: pořízení a vyřazení podle karty.
        $this->small(5, ['acquired_date' => '2024-02-01', 'price' => 4990]);
        $this->small(6, ['acquired_date' => '2023-06-01', 'disposed_date' => '2024-11-30', 'price' => 2500, 'docState' => 70]);

        // Cizí majetek bez událostí (leasing) a neodepisovaný pozemek.
        $this->card(7, ['is_foreign' => 1, 'owner' => 9, 'owner_name' => 'Leasing a.s.', 'acquired_date' => '2024-03-01', 'price' => 900000,
            'tax_method' => null, 'tax_rule' => null, 'acc_method' => null, 'acc_months' => null]);
        $this->card(8, ['category' => 'nondepreciable', 'tax_method' => null, 'tax_rule' => null, 'acc_method' => null, 'acc_months' => null,
            'accounting_group' => 2, 'group_code' => '031', 'group_name' => 'Pozemky', 'group_order' => 20]);
        $this->event(8, 'activation', '2021-06-01', ['amount' => 400000]);

        // Dlouhodobá karta bez zařazení (čeká na zařazení) do přehledů nepatří.
        $this->card(9);
    }

    // ── Přírůstky a úbytky ──────────────────────────────────────────────────

    public function testMovementsOfTheYearGroupedByKind(): void
    {
        $this->seed();

        $result = $this->movements(2024);

        $labels = array_map(static fn($row): string => $row->kind->value . ':' . $row->label, $result->rows);
        $this->assertSame([
            'subtotal:activation',
            'detail:Stroj 3',
            'subtotal:improvement',
            'detail:Stroj 1',
            'subtotal:Drobný majetek — pořízení',
            'detail:Stroj 5',
            'subtotal:reduction',
            'detail:Stroj 1',
            'subtotal:disposal',
            'detail:Stroj 2',
            'subtotal:Drobný majetek — vyřazení',
            'detail:Stroj 6',
            'computed:Přírůstky celkem',
            'computed:Úbytky celkem',
        ], $labels);

        $rows = $result->rows;
        $this->assertSame('2024-12-31', $rows[1]->values['date']);
        $this->assertSame('MA0003', $rows[1]->values['number']);
        $this->assertSame(50000.0, $rows[1]->values['amount']['balance']);
        $this->assertArrayNotHasKey('accumulated', $rows[1]->values);

        // Vyřazení: vstupní cena, oprávky a zůstatková cena z plánu účetního okruhu.
        $disposal = $rows[9];
        $this->assertSame('2024-09-10', $disposal->values['date']);
        $this->assertSame(120000.0, $disposal->values['amount']['balance']);
        $this->assertSame(42000.0, $disposal->values['accumulated']['balance']);
        $this->assertSame(78000.0, $disposal->values['residual']['balance']);
        $this->assertSame(78000.0, $rows[8]->values['residual']['balance']);

        $this->assertSame(50000.0 + 30000.0 + 4990.0, $rows[12]->values['amount']['balance']);
        $this->assertSame(5000.0 + 120000.0 + 2500.0, $rows[13]->values['amount']['balance']);
        $this->assertSame(ReportStatus::Ok, $result->status);
    }

    public function testMovementsKindFilterAndPeriod(): void
    {
        $this->seed();

        $additions = $this->movements(2024, ['kind' => 'additions']);
        $this->assertSame(
            ['subtotal', 'detail', 'subtotal', 'detail', 'subtotal', 'detail', 'computed'],
            array_map(static fn($row): string => $row->kind->value, $additions->rows),
        );
        $this->assertSame('Přírůstky celkem', $additions->rows[6]->label);

        $disposals = $this->movements(2024, ['kind' => 'disposals']);
        $this->assertSame('Úbytky celkem', $disposals->rows[count($disposals->rows) - 1]->label);
        $this->assertCount(7, $disposals->rows);

        // 3. čtvrtletí: jen snížení hodnoty (1. 8.) a vyřazení (10. 9.).
        $q3 = $this->movements(2024, [], 7, 9);
        $this->assertSame(
            ['event:' . $this->eventIdOf(1, 'reduction'), 'event:' . $this->eventIdOf(2, 'disposal')],
            array_values(array_filter(array_keys($this->rowsByKey($q3)), static fn(string $k): bool => str_starts_with($k, 'event:'))),
        );

        $this->assertSame([], $this->movements(2025)->rows);
    }

    private function eventIdOf(int $asset, string $kind): int
    {
        foreach ($this->plans->events as $event) {
            if ($event['asset'] === $asset && $event['event_kind'] === $kind) {
                return (int) $event['id'];
            }
        }
        self::fail("event {$kind} of asset {$asset} not found");
    }

    // ── Soupis majetku ──────────────────────────────────────────────────────

    public function testRegisterAtYearEnd(): void
    {
        $this->seed();

        $result = $this->register(2024);
        $rows = $this->rowsByKey($result);

        // Vyřazené karty 2 a 6 a nezařazená karta 9 v soupisu k 31. 12. nejsou.
        $assets = array_values(array_filter(array_keys($rows), static fn(string $k): bool => str_starts_with($k, 'asset:')));
        sort($assets);
        $this->assertSame(['asset:1', 'asset:3', 'asset:4', 'asset:5', 'asset:7', 'asset:8'], $assets);

        // Dlouhodobý: cena z událostí (100 000 + 30 000 − 5 000), zůstatková cena z plánu.
        $this->assertSame(125000.0, $this->balance($rows['asset:1'], 'entryPrice'));
        $this->assertSame('2023-03-15', $rows['asset:1']['values']['acquired']);
        $this->assertLessThan(125000.0, $this->balance($rows['asset:1'], 'residual'));
        // Zařazení v posledním dni roku: v soupisu, zůstatková cena = vstupní cena.
        $this->assertSame(50000.0, $this->balance($rows['asset:3'], 'residual'));
        // Drobný majetek: cena z karty, bez zůstatkové ceny.
        $this->assertSame(4990.0, $this->balance($rows['asset:5'], 'entryPrice'));
        $this->assertArrayNotHasKey('residual', $rows['asset:5']['values']);
        $this->assertSame('', $rows['asset:5']['values']['owner']);
        // Cizí majetek bez událostí: data a cena z karty, vlastník.
        $this->assertSame(900000.0, $this->balance($rows['asset:7'], 'entryPrice'));
        $this->assertSame('Leasing a.s.', $rows['asset:7']['values']['owner']);
        // Neodepisovaný: zůstatková cena = vstupní cena.
        $this->assertSame(400000.0, $this->balance($rows['asset:8'], 'residual'));

        $rows = $result->rows;
        $total = end($rows);
        $this->assertSame(ReportRowKind::Total, $total->kind);
        $this->assertSame(125000.0 + 50000.0 + 70000.0 + 4990.0 + 900000.0 + 400000.0, $total->values['entryPrice']['balance']);
    }

    public function testRegisterStateAtMonthEnd(): void
    {
        $this->seed();

        // K 31. 8. 2024: karta 2 ještě není vyřazená (10. 9.), karta 3 ještě není zařazená.
        $august = $this->rowsByKey($this->register(2024, [], 8, 8));
        $this->assertArrayHasKey('asset:2', $august);
        $this->assertArrayNotHasKey('asset:3', $august);
        $this->assertArrayHasKey('asset:6', $august);
        $this->assertSame(120000.0, $this->balance($august['asset:2'], 'entryPrice'));

        // K 30. 9. 2024 je karta 2 vyřazená; v den vyřazení už v evidenci není.
        $september = $this->rowsByKey($this->register(2024, [], 9, 9));
        $this->assertArrayNotHasKey('asset:2', $september);

        // K 31. 1. 2024: drobný majetek pořízený 1. 2. ještě ne.
        $january = $this->rowsByKey($this->register(2024, [], 1, 1));
        $this->assertArrayNotHasKey('asset:5', $january);
        $this->assertArrayNotHasKey('asset:7', $january);
        // Před TZ a snížením je vstupní cena karty 1 původní.
        $this->assertSame(100000.0, $this->balance($january['asset:1'], 'entryPrice'));
    }

    public function testRegisterGroupingAndForeignFilter(): void
    {
        $this->seed();

        $byCategory = $this->register(2024, ['groupBy' => 'category']);
        $groups = array_values(array_filter($this->keys($byCategory), static fn(?string $k): bool => str_starts_with((string) $k, 'group:')));
        // Pořadí druhů podle číselníku.
        $this->assertSame(['group:small', 'group:tangible', 'group:nondepreciable'], $groups);

        $byGroup = $this->register(2024, ['groupBy' => 'accountingGroup']);
        $labels = array_values(array_map(
            static fn($row): string => $row->label,
            array_filter($byGroup->rows, static fn($row): bool => $row->kind === ReportRowKind::Subtotal),
        ));
        $this->assertSame(['Bez účetní skupiny', '022 Stroje', '031 Pozemky'], $labels);

        $own = $this->rowsByKey($this->register(2024, ['foreign' => 'exclude']));
        $this->assertArrayNotHasKey('asset:7', $own);
        $this->assertArrayHasKey('asset:1', $own);

        $foreign = $this->rowsByKey($this->register(2024, ['foreign' => 'only']));
        $this->assertSame(['group:0', 'asset:7'], array_keys($foreign));
    }
}
