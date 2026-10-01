<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportStatus;
use Shipard\Module\Economy\Assets\Reports\DepreciationScheduleBuilder;
use Shipard\Module\Economy\Assets\Reports\TaxDepreciationReturnBuilder;

/**
 * Sestava odpisů a Daňové odpisy pro DPPO (docs/assets.md D66) nad kartami
 * v paměti: hodnoty roku z plánů, okrajové karty, seskupení a zprávy.
 *
 * Skupina cz-2 rovnoměrně: 1. rok 11 %, další 22,25 %; účetní časový odpis
 * 60 měsíců od měsíce po zařazení, nahoru na koruny jednou za období.
 */
class DepreciationScheduleBuilderTest extends AssetReportTestCase
{
    private const SCHEDULE = 'economy.assets.depreciationSchedule';

    /** @param array<string, mixed> $params */
    private function schedule(int $year, array $params = []): ReportResult
    {
        return (new DepreciationScheduleBuilder($this->support))->build(
            $this->request(self::SCHEDULE, $year, $params + ['groupBy' => 'accountingGroup', 'category' => 'all']),
        );
    }

    /** Karta zařazená 15. 3. 2022 za 100 000 s potvrzenými odpisy 2022 a 2023. */
    private function steadyCard(int $id = 1): void
    {
        $this->card($id);
        $this->event($id, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated($id, 'tax', 2022, 11000);
        $this->depreciated($id, 'acc', 2022, 15000);
        $this->depreciated($id, 'tax', 2023, 22250);
        $this->depreciated($id, 'acc', 2023, 20000);
    }

    public function testYearValuesComeFromConfirmedEventsAndPlan(): void
    {
        $this->steadyCard();

        $result = $this->schedule(2024);
        $row = $this->rowsByKey($result)['asset:1'];

        $this->assertSame('Stroj 1', $row['label']);
        $this->assertNull($row['account']);
        $this->assertSame('MA0001', $row['values']['number']);
        $this->assertSame('plán', $row['values']['state']);
        $this->assertSame(100000.0, $this->balance($row, 'taxEntry'));
        $this->assertSame(33250.0, $this->balance($row, 'taxOpening'));
        $this->assertSame(22250.0, $this->balance($row, 'taxDepreciation'));
        $this->assertSame(55500.0, $this->balance($row, 'taxClosing'));
        $this->assertSame(44500.0, $this->balance($row, 'taxResidual'));
        $this->assertSame(35000.0, $this->balance($row, 'accOpening'));
        $this->assertSame(20000.0, $this->balance($row, 'accDepreciation'));
        $this->assertSame(55000.0, $this->balance($row, 'accClosing'));
        $this->assertSame(45000.0, $this->balance($row, 'accResidual'));
        // Účetní − daňový odpis je záporný → strana D, balance se znaménkem.
        $this->assertSame(['md' => 0.0, 'd' => 2250.0, 'balance' => -2250.0], $row['values']['difference']);

        // Neodepsaný rok = plán: jedna souhrnná informace, stav zůstává ok.
        $this->assertSame(['assets.depreciationPlanned'], $this->codes($result));
        $this->assertSame(ReportStatus::Ok, $result->status);
    }

    public function testConfirmedYearHasNoPlannedState(): void
    {
        $this->steadyCard();

        $result = $this->schedule(2023);
        $row = $this->rowsByKey($result)['asset:1'];

        $this->assertSame('', $row['values']['state']);
        $this->assertSame(11000.0, $this->balance($row, 'taxOpening'));
        $this->assertSame(22250.0, $this->balance($row, 'taxDepreciation'));
        $this->assertSame([], $result->messages);
    }

    public function testEdgeCardsOfTheYear(): void
    {
        $this->steadyCard(1);
        // Zařazení v posledním dni roku — v evidenci je.
        $this->card(2);
        $this->event(2, 'activation', '2024-12-31', ['amount' => 50000]);
        // Vyřazení v prvním dni roku — v evidenci ještě je.
        $this->card(3, ['docState' => 70]);
        $this->event(3, 'activation', '2022-01-10', ['amount' => 80000]);
        $this->event(3, 'disposal', '2024-01-01');
        // Vyřazení poslední den předchozího roku — v evidenci už není.
        $this->card(4, ['docState' => 70]);
        $this->event(4, 'activation', '2022-01-10', ['amount' => 80000]);
        $this->event(4, 'disposal', '2023-12-31');
        // Zařazení až v dalším roce.
        $this->card(5);
        $this->event(5, 'activation', '2025-01-01', ['amount' => 10000]);
        // Karta bez zařazení a drobný majetek do sestavy nepatří.
        $this->card(6);
        $this->card(7, ['category' => 'small', 'tax_method' => null, 'acc_method' => null, 'price' => 5000]);
        // Koncept se nenačítá vůbec.
        $this->card(8, ['docState' => 10]);
        $this->event(8, 'activation', '2023-01-01', ['amount' => 10000]);

        $rows = $this->rowsByKey($this->schedule(2024));

        $this->assertSame(['group:1', 'asset:1', 'asset:2', 'asset:3'], array_keys($rows));
        // Rok zařazení: daňový odpis celý (11 %), účetní až od dalšího měsíce.
        $this->assertSame(5500.0, $this->balance($rows['asset:2'], 'taxDepreciation'));
        $this->assertSame(0.0, $this->balance($rows['asset:2'], 'accDepreciation'));
        $this->assertStringContainsString('vyřazeno 1. 1. 2024', $rows['asset:3']['values']['state']);
        $this->assertSame(0.0, $this->balance($rows['asset:3'], 'taxResidual'));
        $this->assertSame(0.0, $this->balance($rows['asset:3'], 'accResidual'));
        $this->assertSame(80000.0, $this->balance($rows['asset:3'], 'accEntry'));
    }

    public function testOpeningStateAtYearStartIsTheOpeningBalance(): void
    {
        $this->card(1);
        $opening = ['amount' => 100000, 'accumulated' => 33250, 'units_done' => 2, 'original_date' => '2022-03-15'];
        $this->event(1, 'opening', '2024-01-01', ['scope' => 'tax'] + $opening);
        $this->event(1, 'opening', '2024-01-01', ['scope' => 'acc', 'accumulated' => 35000, 'units_done' => 21] + $opening);

        $row = $this->rowsByKey($this->schedule(2024))['asset:1'];

        $this->assertSame(33250.0, $this->balance($row, 'taxOpening'));
        $this->assertSame(22250.0, $this->balance($row, 'taxDepreciation'));
        $this->assertSame(35000.0, $this->balance($row, 'accOpening'));
        $this->assertSame(20000.0, $this->balance($row, 'accDepreciation'));

        // Před počátečním stavem karta v evidenci není.
        $this->assertSame([], $this->schedule(2023)->rows);
    }

    public function testCategoryFilterAndNondepreciableCard(): void
    {
        $this->steadyCard(1);
        $this->card(2, ['category' => 'nondepreciable', 'tax_method' => null, 'tax_rule' => null, 'acc_method' => null, 'acc_months' => null]);
        $this->event(2, 'activation', '2023-05-01', ['amount' => 500000]);

        $all = $this->rowsByKey($this->schedule(2024));
        $this->assertArrayHasKey('asset:2', $all);
        $this->assertSame(500000.0, $this->balance($all['asset:2'], 'accResidual'));
        $this->assertSame(0.0, $this->balance($all['asset:2'], 'accDepreciation'));
        $this->assertSame('', $all['asset:2']['values']['taxRule']);

        $this->assertSame(['group:1', 'asset:1'], array_keys($this->rowsByKey($this->schedule(2024, ['category' => 'longTerm']))));
        $this->assertSame(['group:1', 'asset:2'], array_keys($this->rowsByKey($this->schedule(2024, ['category' => 'nondepreciable']))));
    }

    public function testGroupingSubtotalsAndTotal(): void
    {
        $this->steadyCard(1);
        $this->steadyCard(2);
        $this->plans->cards[2] = ['accounting_group' => 2, 'group_code' => '013', 'group_name' => 'Software', 'group_order' => 5,
            'asset_type' => 7, 'type_name' => 'Počítače', 'tax_method' => 'accelerated'] + $this->plans->cards[2];
        $this->steadyCard(3);

        // Účetní skupiny v pořadí číselníku (sort_order), karty podle inv. čísla.
        $byGroup = $this->schedule(2023);
        $this->assertSame(['group:2', 'asset:2', 'group:1', 'asset:1', 'asset:3', null], $this->keys($byGroup));
        $this->assertSame('013 Software', $byGroup->rows[0]->label);
        $this->assertSame(ReportRowKind::Subtotal, $byGroup->rows[0]->kind);
        $this->assertSame(2, $byGroup->rows[1]->level);
        $this->assertSame(44500.0, $byGroup->rows[2]->values['taxDepreciation']['balance']);
        $total = $byGroup->rows[5];
        $this->assertSame(ReportRowKind::Total, $total->kind);
        $this->assertSame('Celkem', $total->label);
        $this->assertSame(300000.0, $total->values['accEntry']['balance']);
        $this->assertSame(60000.0, $total->values['accDepreciation']['balance']);
        $this->assertArrayNotHasKey('number', $total->values);

        $byType = $this->schedule(2023, ['groupBy' => 'type']);
        $this->assertSame(['group:0', 'asset:1', 'asset:3', 'group:7', 'asset:2', null], $this->keys($byType));
        $this->assertSame('Bez typu', $byType->rows[0]->label);
        $this->assertSame('Počítače', $byType->rows[3]->label);

        $byRule = $this->schedule(2023, ['groupBy' => 'taxRule']);
        $this->assertSame(
            // Testovací konfigurace není lokalizovaná kompilací → holé (anglické) názvy.
            ['Depreciation group 2 / Accelerated', 'Depreciation group 2 / Straight-line'],
            array_values(array_map(
                static fn($row): string => $row->label,
                array_filter($byRule->rows, static fn($row): bool => $row->kind === ReportRowKind::Subtotal),
            )),
        );

        $flat = $this->schedule(2023, ['groupBy' => 'none']);
        $this->assertSame(['asset:1', 'asset:2', 'asset:3', null], $this->keys($flat));
        $this->assertSame(1, $flat->rows[0]->level);
    }

    public function testPlanErrorMakesTheReportUnreliable(): void
    {
        $this->steadyCard(1);
        // Odpis 2024 potvrzený bez odpisu 2023 u druhé karty → missingPeriod.
        $this->card(2);
        $this->event(2, 'activation', '2022-03-15', ['amount' => 100000]);
        $this->depreciated(2, 'tax', 2022, 11000);
        $this->depreciated(2, 'tax', 2024, 22250);

        $result = $this->schedule(2024);

        $this->assertSame(ReportStatus::Errors, $result->status);
        $error = $result->messages[0];
        $this->assertSame('assets.planError', $error->code);
        $this->assertStringContainsString('MA0002 Stroj 2', $error->text);
        $this->assertSame('rows.' . array_search('asset:2', $this->keys($result), true), $error->rowRef);
    }

    public function testUnrecordedClaimIsNotInTaxDepreciation(): void
    {
        $this->card(1);
        $this->event(1, 'activation', '2022-03-15', ['amount' => 100000, 'origin' => 'import']);
        $this->event(1, 'depreciation', '2022-12-31', [
            'scope' => 'tax', 'amount' => 11000, 'origin' => 'import', 'claim_unrecorded' => 1,
            'period_begin' => '2022-01-01', 'period_end' => '2022-12-31',
        ]);

        $result = $this->schedule(2022);
        $row = $this->rowsByKey($result)['asset:1'];

        $this->assertSame(0.0, $this->balance($row, 'taxDepreciation'));
        $this->assertSame(11000.0, $this->balance($row, 'taxClosing'));
        $this->assertSame(89000.0, $this->balance($row, 'taxResidual'));
        $this->assertContains('assets.claimUnrecorded', $this->codes($result));
    }

    // ── Daňové odpisy pro DPPO ──────────────────────────────────────────────

    private function taxReturn(int $year): ReportResult
    {
        return (new TaxDepreciationReturnBuilder($this->support))->build(
            $this->request('economy.assets.taxDepreciationReturn', $year),
        );
    }

    public function testTaxReturnGroupsMatchTheSchedule(): void
    {
        $this->steadyCard(1);
        $this->steadyCard(2);
        // Skupina 1: 1. rok 20 %, další 40 %.
        $this->card(3, ['tax_rule' => 'cz-1']);
        $this->event(3, 'activation', '2023-02-01', ['amount' => 30000]);
        $this->depreciated(3, 'tax', 2023, 6000);
        $this->depreciated(3, 'acc', 2023, 5000);
        // Varianta se zvýšeným odpisem 1. roku patří do základní skupiny.
        $this->card(4, ['tax_rule' => 'cz-1-b10']);
        $this->event(4, 'activation', '2023-02-01', ['amount' => 10000]);
        $this->depreciated(4, 'tax', 2023, 3000);
        // Daňově neodepisovaný majetek do členění nevstupuje.
        $this->card(5, ['tax_method' => 'none', 'tax_rule' => null]);
        $this->event(5, 'activation', '2023-02-01', ['amount' => 60000]);
        $this->depreciated(5, 'acc', 2023, 10000);

        $result = $this->taxReturn(2023);
        $rows = $this->rowsByKey($result);

        $this->assertSame(['taxReturnGroup:1', 'taxReturnGroup:2'], array_keys($rows));
        $this->assertSame('2', $rows['taxReturnGroup:1']['values']['count']);
        $this->assertSame(9000.0, $this->balance($rows['taxReturnGroup:1'], 'amount'));
        $this->assertSame('2', $rows['taxReturnGroup:2']['values']['count']);
        $this->assertSame(44500.0, $this->balance($rows['taxReturnGroup:2'], 'amount'));

        [$total, $accounting, $difference] = array_slice($result->rows, -3);
        $this->assertSame(ReportRowKind::Total, $total->kind);
        $this->assertSame('4', $total->values['count']);
        $this->assertSame(53500.0, $total->values['amount']['balance']);
        $this->assertSame(ReportRowKind::Computed, $accounting->kind);
        // Karta 4 má účetní odpis 2023 jen v plánu (od března: 10 měsíců z 60).
        $accExpected = 20000.0 + 20000.0 + 5000.0 + 10000.0 + $this->plannedAcc(4, 2023);
        $this->assertSame($accExpected, $accounting->values['amount']['balance']);
        $this->assertSame($accExpected - 53500.0, $difference->values['amount']['balance']);

        // Součet daňových odpisů sedí se Sestavou odpisů.
        $scheduleRows = $this->schedule(2023)->rows;
        $scheduleTotal = end($scheduleRows);
        $this->assertSame(53500.0, $scheduleTotal->values['taxDepreciation']['balance']);
        $this->assertSame($accExpected, $scheduleTotal->values['accDepreciation']['balance']);

        // Nepotvrzený účetní odpis karty 4 → čísla nejsou konečná.
        $this->assertSame(['assets.depreciationPlanned'], $this->codes($result));
        $this->assertSame(ReportStatus::Warnings, $result->status);
    }

    private function plannedAcc(int $assetId, int $year): float
    {
        $sum = 0.0;
        foreach ($this->plans->planForAsset($assetId)['acc']->rows as $row) {
            if ($row->isPlanned() && $row->isDepreciation() && str_starts_with((string) $row->period?->end, (string) $year)) {
                $sum += $row->amount;
            }
        }
        return $sum;
    }

    public function testTaxReturnConfirmedYearHasNoWarning(): void
    {
        $this->steadyCard(1);

        $result = $this->taxReturn(2023);

        $this->assertSame(ReportStatus::Ok, $result->status);
        $this->assertSame('Depreciation group 2', $result->rows[0]->label);
        $rows = $result->rows;
        $this->assertSame(-2250.0, end($rows)->values['amount']['balance']);
    }

    public function testTaxReturnAccountingMethodAndIntangible(): void
    {
        // Nehmotný majetek od 2021: daňově podle účetních odpisů.
        $this->card(1, ['category' => 'intangible', 'tax_method' => 'accounting', 'tax_rule' => null, 'acc_months' => 36]);
        $this->event(1, 'activation', '2022-12-20', ['amount' => 36000]);
        $this->depreciated(1, 'acc', 2023, 12000);
        $this->depreciated(1, 'tax', 2023, 12000);

        $rows = $this->rowsByKey($this->taxReturn(2023));

        $this->assertSame(['taxReturnGroup:accounting'], array_keys($rows));
        $this->assertSame(12000.0, $this->balance($rows['taxReturnGroup:accounting'], 'amount'));
    }

    public function testTaxReturnEmptyYearStillHasTotals(): void
    {
        $result = $this->taxReturn(2024);

        $this->assertCount(3, $result->rows);
        $this->assertSame(0.0, $result->rows[0]->values['amount']['balance']);
        $this->assertSame(ReportStatus::Ok, $result->status);
    }
}
