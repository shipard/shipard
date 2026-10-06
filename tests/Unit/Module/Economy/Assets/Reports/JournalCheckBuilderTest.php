<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets\Reports;

use Shipard\Core\Reports\ReportMessageSeverity;
use Shipard\Core\Reports\ReportResult;
use Shipard\Core\Reports\ReportRowKind;
use Shipard\Core\Reports\ReportStatus;
use Shipard\Module\Economy\Assets\Reports\JournalCheckBuilder;
use Shipard\Tests\Unit\Module\Economy\Assets\TestAssetJournalCheck;

/**
 * Report Kontrola evidence × deník (docs/assets.md D67): části po účtech
 * a po kartách, závažnost zpráv a `rowRef`, čistý stav bez zpráv.
 */
class JournalCheckBuilderTest extends AssetReportTestCase
{
    private TestAssetJournalCheck $check;

    protected function setUp(): void
    {
        parent::setUp();
        $this->check = new TestAssetJournalCheck();
        // Id období jako v TestAssetPlanService: rok N → N − 2020, měsíc → 100 + ….
        $this->check->periodOf = static function (string $date): array {
            $year = (int) substr($date, 0, 4);
            return [$year - 2020, 100 + 12 * ($year - 2021) + (int) substr($date, 5, 2)];
        };
        $this->plans->asOf = '2025-03-10';
    }

    private function run2024(string $language = 'cs'): ReportResult
    {
        return (new JournalCheckBuilder($this->support, $this->check))->build(
            $this->request('economy.assets.journalCheck', 2024, [], 1, 12, $language),
        );
    }

    private function cleanCard(int $id = 1): void
    {
        $this->check->card($id);
        $this->check->journal($id, '042100', 100000, 0, '2024-03-10', 'purchase.asset');
        $this->check->event($id, 'activation', '2024-03-15', 100000);
        $this->check->posting($id, 'asset.activation', '022100', '042100', 100000, '2024-12-31');
        $this->check->event($id, 'depreciation', '2024-12-31', 11000);
        $this->check->posting($id, 'asset.depreciation', '551100', '082100', 11000, '2024-12-31');
    }

    public function testCleanStateHasNoMessagesAndOnlyAccounts(): void
    {
        $this->cleanCard();

        $result = $this->run2024();

        $this->assertSame(ReportStatus::Ok, $result->status);
        $this->assertSame([], $result->messages);
        $this->assertSame(
            ['section:accounts', 'account:022100', 'account:042100', 'account:082100', 'account:551100'],
            $this->keys($result),
        );
        $this->assertSame(ReportRowKind::Subtotal, $result->rows[0]->kind);
        $this->assertSame('Účty účetních skupin', $result->rows[0]->label);

        $asset = $result->rows[1];
        $this->assertSame('022100', $asset->account);
        $this->assertSame('Stroje', $asset->label);
        $this->assertSame('účet majetku', $asset->values['kind']);
        $this->assertSame('zůstatek', $asset->values['measure']);
        $this->assertSame(100000.0, $asset->values['evidence']['balance']);
        $this->assertSame(100000.0, $asset->values['journal']['balance']);
        $this->assertSame(0.0, $asset->values['difference']['balance']);
        $this->assertSame('obrat', $result->rows[4]->values['measure']);
        // Drill-down (D70): účet → deník s filtrem účtu a kontrolovaného roku (id roku 2024 = 4).
        $this->assertSame(
            ['kind' => 'open_viewer', 'target' => [
                'viewerId' => 'economy.accounting.journal',
                'filters'  => ['fiscal_year' => 4, 'account' => '022100'],
            ]],
            $asset->link,
        );
        $this->assertNull($result->rows[0]->link);
    }

    public function testEveryMismatchIsAMessageWithRowRef(): void
    {
        $this->cleanCard(1);
        // Zápis na 022 bez karty → rozdíl účtu (chyba) + zápisy bez karty (varování).
        $this->check->journal(null, '022100', 30000, 0, '2024-09-01');
        // (a) zaúčtovaný odpis, který v deníku není.
        $this->cleanCard(2);
        $this->check->event(2, 'depreciation', '2024-12-31', 500);
        // (b) pořízení ≠ zařazení.
        $this->check->card(3, null, 'Fréza');
        $this->check->journal(3, '042100', 269500, 0, '2024-07-20', 'purchase.asset');
        $this->check->event(3, 'activation', '2024-07-23', 268500, false);
        // (c) nezaúčtovaná událost roku 2024 (dnes je březen 2025, roční odpisy).
        $unposted = $this->check->events[array_key_last($this->check->events)]['id'];

        $result = $this->run2024();

        $this->assertSame(ReportStatus::Errors, $result->status);
        $keys = $this->keys($result);
        $byCode = [];
        foreach ($result->messages as $message) {
            $byCode[$message->code][] = $message;
            $this->assertNotNull($message->rowRef, $message->code);
        }

        $this->assertSame(
            ['assets.journalCheck.accountMismatch', 'assets.journalCheck.withoutAsset', 'assets.journalCheck.postingMismatch',
                'assets.journalCheck.acquisitionMismatch', 'assets.journalCheck.unposted'],
            array_values(array_unique($this->codes($result))),
        );

        // Účet 022100: rozdíl je chyba, zápis bez karty varování — obojí na řádku účtu.
        $accountRow = 'rows.' . array_search('account:022100', $keys, true);
        $mismatch022 = array_values(array_filter(
            $byCode['assets.journalCheck.accountMismatch'],
            static fn($m): bool => $m->rowRef === $accountRow,
        ));
        $this->assertCount(1, $mismatch022);
        $this->assertSame(ReportMessageSeverity::Error, $mismatch022[0]->severity);
        $this->assertStringContainsString('022100', $mismatch022[0]->text);
        $this->assertSame(ReportMessageSeverity::Warning, $byCode['assets.journalCheck.withoutAsset'][0]->severity);
        $this->assertSame($accountRow, $byCode['assets.journalCheck.withoutAsset'][0]->rowRef);

        // Část po kartách začíná nadpisem a nese řádek per nesoulad.
        $section = array_search('section:cards', $keys, true);
        $this->assertSame('Nesoulady po kartách', $result->rows[$section]->label);
        $this->assertContains('posting:2:082100', $keys);
        $this->assertContains('posting:2:551100', $keys);

        $posting = $byCode['assets.journalCheck.postingMismatch'][0];
        $this->assertSame(ReportMessageSeverity::Error, $posting->severity);
        $this->assertSame('rows.' . array_search('posting:2:082100', $keys, true), $posting->rowRef);
        $postingRow = $result->rows[array_search('posting:2:551100', $keys, true)];
        $this->assertSame('551100', $postingRow->account);
        $this->assertSame('MA0002', $postingRow->values['number']);
        $this->assertSame(['md' => 11500.0, 'd' => 0.0, 'balance' => 11500.0], $postingRow->values['evidence']);
        $this->assertSame(11000.0, $postingRow->values['journal']['balance']);
        $this->assertSame(500.0, $postingRow->values['difference']['balance']);
        // Nesoulad karty: název → karta ve vieweru (je co opravit), druh → deník účtu a karty přes všechny roky.
        $this->assertSame(
            ['kind' => 'open_viewer', 'target' => ['viewerId' => 'economy.assets.assets', 'recordId' => 2]],
            $postingRow->link,
        );
        $this->assertSame(
            ['fiscal_year' => '', 'account' => '551100', 'dim_asset' => '#2'],
            $postingRow->cellLinks['kind']['target']['filters'],
        );

        $acquisition = $byCode['assets.journalCheck.acquisitionMismatch'][0];
        $this->assertSame(ReportMessageSeverity::Error, $acquisition->severity);
        $this->assertStringContainsString('MA0003 Fréza', $acquisition->text);
        $this->assertStringContainsString('269 500,00', $acquisition->text);
        $acquisitionRow = $result->rows[array_search('acquisition:3', $keys, true)];
        $this->assertSame('rows.' . array_search('acquisition:3', $keys, true), $acquisition->rowRef);
        $this->assertSame(268500.0, $acquisitionRow->values['evidence']['balance']);
        $this->assertSame(269500.0, $acquisitionRow->values['journal']['balance']);
        $this->assertSame(-1000.0, $acquisitionRow->values['difference']['balance']);
        $this->assertSame(3, $acquisitionRow->link['target']['recordId']);
        $this->assertSame(
            ['fiscal_year' => '', 'account' => '04', 'dim_asset' => '#3'],
            $acquisitionRow->cellLinks['kind']['target']['filters'],
        );

        $this->assertSame(ReportMessageSeverity::Warning, $byCode['assets.journalCheck.unposted'][0]->severity);
        $this->assertContains('unposted:' . $unposted, $keys);
    }

    public function testAcquisitionAccountDifferenceIsOnlyAWarning(): void
    {
        // D73: zařazení bez pořízení s kartou (import) — evidence na 042 je
        // −100 000, deník 0. Rozdíl na účtu pořízení je varování, účet
        // majetku sedí, report proto končí stavem warnings, ne errors.
        $this->check->card(1);
        $this->check->journal(null, '042100', 100000, 0, '2024-03-10', 'purchase.asset');
        $this->check->event(1, 'activation', '2024-03-15', 100000);
        $this->check->posting(1, 'asset.activation', '022100', '042100', 100000, '2024-03-15');

        $result = $this->run2024();

        $this->assertSame(ReportStatus::Warnings, $result->status);
        $byCode = [];
        foreach ($result->messages as $message) {
            $byCode[$message->code][] = $message;
        }
        $this->assertSame(
            ['assets.journalCheck.acquisitionAccountDifference', 'assets.journalCheck.withoutAsset'],
            array_keys($byCode),
        );
        $difference = $byCode['assets.journalCheck.acquisitionAccountDifference'][0];
        $this->assertSame(ReportMessageSeverity::Warning, $difference->severity);
        $this->assertStringContainsString('Účet 042100', $difference->text);
        $this->assertStringContainsString('zařazení bez navázaného pořízení', $difference->text);
        $keys = $this->keys($result);
        $this->assertSame('rows.' . array_search('account:042100', $keys, true), $difference->rowRef);
        // Řádek účtu zůstává se sloupci evidence / deník / rozdíl.
        $row = $result->rows[array_search('account:042100', $keys, true)];
        $this->assertSame(-100000.0, $row->values['evidence']['balance']);
        $this->assertSame(0.0, $row->values['journal']['balance']);
        $this->assertSame(-100000.0, $row->values['difference']['balance']);

        // Rozdíl na účtu majetku je dál chyba.
        $this->check->journal(null, '022100', 30000, 0, '2024-09-01');
        $this->assertSame(ReportStatus::Errors, $this->run2024()->status);
    }

    public function testUnpostedEventsOfTheRunningPeriodAreNotReported(): void
    {
        // Dnes je říjen 2024 a účetní odpisy jsou roční: události roku 2024
        // se zaúčtují až s koncem roku — nesoulad to není.
        $this->plans->asOf = '2024-10-01';
        $this->check->card(1);
        $this->check->event(1, 'activation', '2024-03-15', 100000, false);
        $old = $this->check->event(1, 'improvement', '2023-11-01', 5000, false);

        $keys = $this->keys($this->run2024());

        $this->assertContains('unposted:' . $old, $keys);
        $this->assertCount(1, array_filter($keys, static fn(?string $k): bool => str_starts_with((string) $k, 'unposted:')));

        // Při měsíční četnosti už březnové zařazení zaúčtované být mělo.
        $this->plans->periodicity = 'month';
        $monthly = $this->keys((new JournalCheckBuilder(new TestAssetReportSupport($this->plans), $this->check))->build(
            $this->request('economy.assets.journalCheck', 2024),
        ));
        $this->assertCount(2, array_filter($monthly, static fn(?string $k): bool => str_starts_with((string) $k, 'unposted:')));
    }

    public function testAcquisitionWithoutActivationWaitsThirtyDays(): void
    {
        $this->check->card(1);
        $this->check->journal(1, '042100', 50000, 0, '2024-12-20', 'purchase.asset');

        // 10. 1. 2025: pořízení je 21 dní staré — ještě čeká.
        $this->plans->asOf = '2025-01-10';
        $this->assertNotContains('acquisition:1', $this->keys($this->run2024()));

        // 10. 3. 2025: déle než 30 dní bez zařazení → chyba.
        $this->plans->asOf = '2025-03-10';
        $result = $this->run2024();
        $this->assertContains('acquisition:1', $this->keys($result));
        $this->assertStringContainsString('bez zařazení déle než 30 dní', $result->messages[0]->text);
        $this->assertSame(ReportStatus::Errors, $result->status);
    }

    public function testEmptyDataSourceGivesEmptyReport(): void
    {
        $result = $this->run2024('en');

        $this->assertSame([], $result->rows);
        $this->assertSame(ReportStatus::Ok, $result->status);
        $this->assertSame('Register', $result->columns[3]->label);
    }
}
