<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetJournalCheck;

/**
 * Kontrola evidence × deník (docs/assets.md D67): každý typ nesouladu —
 * (a) zaúčtování ≠ deník, (b) pořízení ≠ zařazení, (c) nezaúčtované
 * události —, zápisy bez karty a čistý stav bez nálezů.
 *
 * V účtech má fiskální rok id = kalendářní rok a měsíc id = číslo měsíce
 * (0 = otevírací období).
 */
class AssetJournalCheckTest extends TestCase
{
    private TestAssetJournalCheck $check;

    protected function setUp(): void
    {
        $this->check = new TestAssetJournalCheck();
    }

    /** Karta s pořízením 100 000, zařazením, odpisem 11 000 — vše zaúčtované a v deníku. */
    private function cleanCard(int $id = 1): void
    {
        $this->check->card($id);
        $this->check->journal($id, '042100', 100000, 0, '2024-03-10', 'purchase.asset');
        $this->check->event($id, 'activation', '2024-03-15', 100000);
        $this->check->posting($id, 'asset.activation', '022100', '042100', 100000, '2024-12-31');
        $this->check->event($id, 'depreciation', '2024-12-31', 11000);
        $this->check->posting($id, 'asset.depreciation', '551100', '082100', 11000, '2024-12-31');
    }

    /** @return list<array<string, mixed>> */
    private function accounts(int $year = 2024): array
    {
        return $this->check->accounts("{$year}-01-01", "{$year}-12-31", $year, [0], range(1, 12));
    }

    /** @return array<string, array<string, mixed>> */
    private function accountsByNumber(int $year = 2024): array
    {
        return array_column($this->accounts($year), null, 'account');
    }

    // ── Čistý stav ──────────────────────────────────────────────────────────

    public function testCleanStateHasNoFindings(): void
    {
        $this->cleanCard();

        $this->assertSame(['posting' => [], 'acquisition' => []], $this->check->cardFindings());
        $this->assertSame([], $this->check->unposted('2024-12-31'));

        $accounts = $this->accountsByNumber();
        // Číselné klíče PHP drží jako int — porovnává se jako text.
        $this->assertSame(['022100', '042100', '082100', '551100'], array_map(strval(...), array_keys($accounts)));
        foreach ($accounts as $account) {
            $this->assertSame(0.0, $account['difference'], $account['account']);
            $this->assertSame(0, $account['withoutAssetRows'], $account['account']);
        }
        $this->assertSame(100000.0, $accounts['022100']['evidence']);
        $this->assertTrue($accounts['022100']['balance']);
        $this->assertSame(0.0, $accounts['042100']['evidence']);
        $this->assertSame(-11000.0, $accounts['082100']['evidence']);
        $this->assertSame(11000.0, $accounts['551100']['evidence']);
        $this->assertFalse($accounts['551100']['balance']);
        $this->assertSame('asset', $accounts['022100']['role']);
        $this->assertSame('Stroje', $accounts['022100']['name']);
    }

    public function testDisposalPostingIsDerivedFromEvents(): void
    {
        // Vyřazení: oprávky 11 000 a zůstatková cena 89 000 proti účtu majetku.
        $this->cleanCard();
        $this->check->event(1, 'disposal', '2025-06-30');
        $this->check->posting(1, 'asset.disposal', '082100', '022100', 11000, '2025-12-31');
        $this->check->posting(1, 'asset.disposal', '541100', '022100', 89000, '2025-12-31');

        $this->assertSame([], $this->check->cardFindings()['posting']);

        $accounts = $this->accountsByNumber(2025);
        $this->assertSame(89000.0, $accounts['541100']['evidence']);
        $this->assertSame(0.0, $accounts['541100']['difference']);
        // Stavové účty: evidence po vyřazení nula; deník roku 2025 bez
        // otevíracího dokladu má jen obrat vyřazení — rok není otevřený,
        // účet se neporovná (D85).
        $this->assertSame(0.0, $accounts['022100']['evidence']);
        $this->assertNull($accounts['022100']['journal']);
        $this->assertTrue($accounts['022100']['noOpeningBalances']);

        // S otevíracím dokladem sedí stav účtu majetku po vyřazení na nulu.
        $this->check->journal(null, '022100', 100000, 0, '2025-01-01', 'acc.record', 0);
        $this->check->journal(null, '082100', 0, 11000, '2025-01-01', 'acc.record', 0);
        $accounts = $this->accountsByNumber(2025);
        $this->assertSame(0.0, $accounts['022100']['journal']);
        $this->assertSame(0.0, $accounts['022100']['difference']);
        $this->assertSame(0.0, $accounts['082100']['difference']);
    }

    // ── (a) zaúčtované události ≠ deník ─────────────────────────────────────

    public function testPostedEventMissingInJournal(): void
    {
        $this->cleanCard();
        // Druhý zaúčtovaný odpis v deníku není (doklad přegenerovaný bez řádku).
        $this->check->event(1, 'depreciation', '2025-12-31', 22250);

        $findings = $this->check->cardFindings();

        $this->assertCount(1, $findings['posting']);
        $finding = $findings['posting'][0];
        $this->assertSame(1, $finding['assetId']);
        $this->assertSame('MA0001', $finding['number']);
        $this->assertSame(
            [
                ['account' => '082100', 'expectedDr' => 0.0, 'expectedCr' => 33250.0, 'journalDr' => 0.0, 'journalCr' => 11000.0],
                ['account' => '551100', 'expectedDr' => 33250.0, 'expectedCr' => 0.0, 'journalDr' => 11000.0, 'journalCr' => 0.0],
            ],
            $finding['accounts'],
        );

        // K datu před druhým odpisem je karta v pořádku.
        $this->assertSame([], $this->check->cardFindings('2024-12-31')['posting']);
    }

    public function testJournalRowWithoutPostedEvent(): void
    {
        $this->cleanCard();
        // Řádek operace majetku s kartou, ke kterému evidence nemá zaúčtovanou událost.
        $this->check->posting(1, 'asset.improvement', '022100', '042100', 5000, '2024-12-31');

        $accounts = array_column($this->check->cardFindings()['posting'][0]['accounts'], null, 'account');

        $this->assertSame(['022100', '042100'], array_keys($accounts));
        $this->assertSame(100000.0, $accounts['022100']['expectedDr']);
        $this->assertSame(105000.0, $accounts['022100']['journalDr']);
    }

    public function testChangedAccountingGroupIsAMismatch(): void
    {
        $this->cleanCard();
        // Karta přešla na skupinu s jiným účtem majetku — deník drží původní.
        $this->check->cards[1]['accounts']['asset'] = '022200';

        $accounts = array_column($this->check->cardFindings()['posting'][0]['accounts'], 'account');

        $this->assertSame(['022100', '022200'], $accounts);
    }

    public function testJournalRowsOfUnknownCardAreReported(): void
    {
        $this->check->posting(99, 'asset.depreciation', '551100', '082100', 500, '2024-12-31');

        $finding = $this->check->cardFindings()['posting'][0];

        $this->assertSame(99, $finding['assetId']);
        $this->assertSame('#99', $finding['name']);
        $this->assertCount(2, $finding['accounts']);
    }

    public function testSingleCardScope(): void
    {
        $this->cleanCard(1);
        $this->cleanCard(2);
        $this->check->event(2, 'depreciation', '2025-12-31', 22250);

        $this->assertSame([], $this->check->cardFindings(null, 1)['posting']);
        $this->assertCount(1, $this->check->cardFindings(null, 2)['posting']);
    }

    // ── (b) pořízení ≠ zařazení + TZ ────────────────────────────────────────

    public function testAcquisitionDiffersFromActivation(): void
    {
        $this->check->card(1);
        $this->check->journal(1, '042100', 269500, 0, '2024-07-20', 'purchase.asset');
        // Zařazení ještě není zaúčtované — nesoulad se počítá z potvrzené události.
        $this->check->event(1, 'activation', '2024-07-23', 268500, false);

        $finding = $this->check->cardFindings()['acquisition'][0];

        $this->assertSame(269500.0, $finding['acquired']);
        $this->assertSame(268500.0, $finding['activated']);
        $this->assertSame(1000.0, $finding['difference']);
        $this->assertTrue($finding['started']);
        $this->assertSame('2024-07-23', $finding['since']);
        // Nezaúčtované zařazení samo nesouladem (a) není.
        $this->assertSame([], $this->check->cardFindings()['posting']);

        // Oprava technickým zhodnocením o rozdíl nesoulad uzavře.
        $this->check->event(1, 'improvement', '2024-08-01', 1000, false);
        $this->assertSame([], $this->check->cardFindings()['acquisition']);
    }

    public function testReductionLowersActivatedAmount(): void
    {
        $this->check->card(1);
        $this->check->journal(1, '042100', 100000, 0, '2024-03-10', 'purchase.asset');
        $this->check->journal(1, '042100', 0, 4000, '2024-04-02', 'purchase.asset'); // dobropis
        $this->check->event(1, 'activation', '2024-03-15', 100000, false);
        $this->check->event(1, 'reduction', '2024-04-05', 4000, false);

        $this->assertSame([], $this->check->cardFindings()['acquisition']);
    }

    public function testAcquisitionWithoutActivationCarriesItsAge(): void
    {
        $this->check->card(1);
        $this->check->journal(1, '042100', 50000, 0, '2024-05-10', 'purchase.asset');

        $finding = $this->check->cardFindings()['acquisition'][0];

        $this->assertFalse($finding['started']);
        $this->assertSame('2024-05-10', $finding['since']);
        $this->assertFalse(AssetJournalCheck::isOverdue($finding['since'], '2024-06-09'));
        $this->assertTrue(AssetJournalCheck::isOverdue($finding['since'], '2024-06-10'));
    }

    public function testCardWithoutAcquisitionOnDocumentsIsNotCompared(): void
    {
        // Zařazení bez pořízení s kartou (pořízení bez karty, import) — (b) se neposuzuje.
        $this->check->card(1);
        $this->check->event(1, 'activation', '2024-03-15', 100000);
        $this->check->posting(1, 'asset.activation', '022100', '042100', 100000, '2024-12-31');

        $this->assertSame([], $this->check->cardFindings()['acquisition']);
    }

    public function testOpeningStateIsNotActivation(): void
    {
        // Počáteční stav + doplatek pořízení na 042 čeká na technické zhodnocení.
        $this->check->card(1);
        $this->check->event(1, 'opening', '2024-01-01', 100000, false, ['scope' => 'acc', 'accumulated' => 35000]);
        $this->check->journal(1, '042100', 16528.93, 0, '2024-07-23', 'purchase.asset');

        $finding = $this->check->cardFindings()['acquisition'][0];

        $this->assertTrue($finding['started']);
        $this->assertSame(0.0, $finding['activated']);
        $this->assertSame(16528.93, $finding['difference']);
    }

    // ── (c) nezaúčtované události ───────────────────────────────────────────

    public function testUnpostedPostableEvents(): void
    {
        $this->check->card(1);
        $activation = $this->check->event(1, 'activation', '2023-03-15', 100000, false);
        $this->check->event(1, 'depreciation', '2023-12-31', 11000, false, ['scope' => 'tax']); // daňový se neúčtuje
        $accDepreciation = $this->check->event(1, 'depreciation', '2023-12-31', 15000, false);
        $this->check->event(1, 'depreciation', '2024-12-31', 20000, false); // po hranici
        $this->check->card(2);
        $this->check->event(2, 'opening', '2023-01-01', 50000, false, ['scope' => 'acc']); // počáteční stav se neúčtuje

        $unposted = $this->check->unposted('2023-12-31');

        $this->assertSame([$activation, $accDepreciation], array_column($unposted, 'eventId'));
        $this->assertSame('activation', $unposted[0]['kind']);
        $this->assertSame(100000.0, $unposted[0]['amount']);
        $this->assertSame('MA0001', $unposted[0]['number']);
    }

    // ── Importované události (D76) ──────────────────────────────────────────

    public function testImportedEventsArePostedOutsideTheModule(): void
    {
        // Karta ze starého systému: zařazení a odpisy importované bez dokladu,
        // deník nese jejich zápisy jako obyčejné `acc.record` s kartou.
        $this->check->card(1);
        $this->check->event(1, 'activation', '2022-03-15', 100000, false, ['origin' => 'import']);
        $this->check->journal(1, '022100', 100000, 0, '2022-03-15');
        $this->check->journal(1, '042100', 0, 100000, '2022-03-15');
        foreach ([2022 => 11000.0, 2023 => 22250.0] as $year => $amount) {
            $this->check->event(1, 'depreciation', "{$year}-12-31", $amount, false, ['origin' => 'import']);
            $this->check->journal(1, '551100', $amount, 0, "{$year}-12-31");
            $this->check->journal(1, '082100', 0, $amount, "{$year}-12-31");
        }

        // Evidence po účtech importované události započítá — bez rozdílu.
        // Účet pořízení: zápis importovaného zařazení je obyčejný řádek
        // s kartou, evidence ho nese jako pořízení a podruhé ho neodečítá.
        $accounts = $this->accountsByNumber(2022);
        $this->assertSame(100000.0, $accounts['022100']['evidence']);
        $this->assertSame(0.0, $accounts['022100']['difference']);
        $this->assertSame(-100000.0, $accounts['042100']['evidence']);
        $this->assertSame(0.0, $accounts['042100']['difference']);
        $this->assertSame(-11000.0, $accounts['082100']['evidence']);
        $this->assertSame(0.0, $accounts['082100']['difference']);
        $this->assertSame(11000.0, $accounts['551100']['evidence']);
        $this->assertSame(0.0, $accounts['551100']['difference']);
        // Rok 2023 s počátečními zůstatky účtů (otevírací období).
        $this->check->journal(null, '022100', 100000, 0, '2023-01-01', 'acc.record', 0);
        $this->check->journal(null, '082100', 0, 11000, '2023-01-01', 'acc.record', 0);
        $accounts = $this->accountsByNumber(2023);
        $this->assertSame(0.0, $accounts['022100']['difference']);
        $this->assertSame(-33250.0, $accounts['082100']['evidence']);
        $this->assertSame(0.0, $accounts['082100']['difference']);
        $this->assertSame(22250.0, $accounts['551100']['evidence']);
        $this->assertSame(0.0, $accounts['551100']['difference']);

        // (a) a (c) importované události neposuzují: deník `asset.*` je prázdný,
        // nezaúčtovaná událost žádná. (b) kartu zařazenou importem nesrovnává —
        // pořízení s kartou v deníku je po doplnění karty na doklady 0.
        $this->check->journal(1, '042100', 100000, 0, '2022-03-10', 'purchase.asset');
        $this->assertSame(['posting' => [], 'acquisition' => []], $this->check->cardFindings());
        $this->assertSame([], $this->check->unposted('2023-12-31'));

        // Odpis 2024 už zaúčtuje nový Shipard — chybějící doklad je (c),
        // a jeho zápis `asset.depreciation` se porovnává jen sám se sebou.
        $this->check->event(1, 'depreciation', '2024-12-31', 22250, false);
        $this->assertCount(1, $this->check->unposted('2024-12-31'));
        $this->check->events[array_key_last($this->check->events)]['posted'] = 1;
        $this->check->posting(1, 'asset.depreciation', '551100', '082100', 22250, '2024-12-31');
        $this->assertSame([], $this->check->cardFindings()['posting']);
        $this->check->journal(null, '022100', 100000, 0, '2024-01-01', 'acc.record', 0);
        $this->check->journal(null, '082100', 0, 33250, '2024-01-01', 'acc.record', 0);
        $this->assertSame(0.0, $this->accountsByNumber(2024)['082100']['difference']);
        $this->assertSame(22250.0, $this->accountsByNumber(2024)['551100']['evidence']);
    }

    // ── Po účtech ───────────────────────────────────────────────────────────

    public function testJournalEntryWithoutCard(): void
    {
        $this->cleanCard();
        // Ruční zápis na 022 bez karty.
        $this->check->journal(null, '022100', 30000, 0, '2024-09-01');

        $account = $this->accountsByNumber()['022100'];

        $this->assertSame(100000.0, $account['evidence']);
        $this->assertSame(130000.0, $account['journal']);
        $this->assertSame(-30000.0, $account['difference']);
        $this->assertSame(100000.0, $account['withAsset']);
        $this->assertSame(30000.0, $account['withoutAsset']);
        $this->assertSame(1, $account['withoutAssetRows']);
    }

    public function testOpeningStateAgainstOpeningBalance(): void
    {
        $this->check->card(1);
        $this->check->event(1, 'opening', '2024-01-01', 100000, false, ['scope' => 'acc', 'accumulated' => 35000]);
        $this->check->event(1, 'opening', '2024-01-01', 100000, false, ['scope' => 'tax', 'accumulated' => 33250]);
        // Otevírací doklad roku (měsíc 0) nese počáteční stavy účtů bez karty.
        $this->check->journal(null, '022100', 100000, 0, '2024-01-01', 'acc.record', 0);
        $this->check->journal(null, '082100', 0, 35000, '2024-01-01', 'acc.record', 0);

        $accounts = $this->accountsByNumber();

        $this->assertSame(100000.0, $accounts['022100']['evidence']);
        $this->assertSame(0.0, $accounts['022100']['difference']);
        $this->assertSame(-35000.0, $accounts['082100']['evidence']);
        $this->assertSame(0.0, $accounts['082100']['difference']);
        // Otevírací období není obrat roku — zápis bez karty se nehlásí.
        $this->assertSame(0, $accounts['022100']['withoutAssetRows']);
    }

    public function testUnpostedEventsAreNotInAccountEvidence(): void
    {
        // Potvrzené, ale nezaúčtované zařazení: evidence i deník účtu majetku nula,
        // pořízení zůstává na 042.
        $this->check->card(1);
        $this->check->journal(1, '042100', 100000, 0, '2024-03-10', 'purchase.asset');
        $this->check->event(1, 'activation', '2024-03-15', 100000, false);

        $accounts = $this->accountsByNumber();

        $this->assertSame(['042100'], array_map(strval(...), array_keys($accounts)));
        $this->assertSame(100000.0, $accounts['042100']['evidence']);
        $this->assertSame(0.0, $accounts['042100']['difference']);
    }

    public function testTurnoverAccountsUseTheYearOnly(): void
    {
        $this->cleanCard();
        $this->check->event(1, 'depreciation', '2025-12-31', 22250);
        $this->check->posting(1, 'asset.depreciation', '551100', '082100', 22250, '2025-12-31');

        $accounts = $this->accountsByNumber(2025);

        // Odpisy: jen obrat roku 2025; oprávky: stav z evidence za obě období.
        $this->assertSame(22250.0, $accounts['551100']['evidence']);
        $this->assertSame(0.0, $accounts['551100']['difference']);
        $this->assertSame(-33250.0, $accounts['082100']['evidence']);
    }

    public function testYearWithoutOpeningBalancesSkipsBalanceAccounts(): void
    {
        // D85: rok 2025 ještě není otevřený — deník nemá v měsíci 0 žádný
        // zápis, evidence ale k 1. 1. 2025 stav má. Účty se stavem se
        // neporovnají (deník null, rozdíl 0), účty obratem beze změny.
        $this->cleanCard();
        $this->check->event(1, 'depreciation', '2025-12-31', 22250);
        $this->check->posting(1, 'asset.depreciation', '551100', '082100', 22250, '2025-12-31');

        $accounts = $this->accountsByNumber(2025);

        $this->assertTrue($accounts['022100']['noOpeningBalances']);
        $this->assertSame(100000.0, $accounts['022100']['evidence']);
        $this->assertNull($accounts['022100']['journal']);
        $this->assertSame(0.0, $accounts['022100']['difference']);
        $this->assertTrue($accounts['082100']['noOpeningBalances']);
        $this->assertSame(-33250.0, $accounts['082100']['evidence']);
        $this->assertNull($accounts['082100']['journal']);
        $this->assertSame(0.0, $accounts['082100']['difference']);
        $this->assertFalse($accounts['551100']['noOpeningBalances']);
        $this->assertSame(22250.0, $accounts['551100']['journal']);
        $this->assertSame(0.0, $accounts['551100']['difference']);

        // Otevřený rok (počáteční stavy v měsíci 0) se porovnává jako dřív.
        $this->check->journal(null, '022100', 100000, 0, '2025-01-01', 'acc.record', 0);
        $this->check->journal(null, '082100', 0, 11000, '2025-01-01', 'acc.record', 0);
        $accounts = $this->accountsByNumber(2025);
        $this->assertFalse($accounts['022100']['noOpeningBalances']);
        $this->assertSame(100000.0, $accounts['022100']['journal']);
        $this->assertSame(0.0, $accounts['022100']['difference']);
        $this->assertSame(-33250.0, $accounts['082100']['journal']);
        $this->assertSame(0.0, $accounts['082100']['difference']);
    }

    public function testFirstYearWithAssetsIsComparedWithoutOpeningBalances(): void
    {
        // Před rokem 2024 evidence nic nemá — chybějící otevírací období
        // není nález, účty se porovnají obratem roku.
        $this->cleanCard();
        $this->check->journal(null, '022100', 30000, 0, '2024-09-01');

        $accounts = $this->accountsByNumber();

        $this->assertFalse($accounts['022100']['noOpeningBalances']);
        $this->assertSame(130000.0, $accounts['022100']['journal']);
        $this->assertSame(-30000.0, $accounts['022100']['difference']);
    }

    public function testAccountsOfGroupsWithoutCards(): void
    {
        // Účet skupiny bez jediné karty, ale se zápisem v deníku.
        $this->check->journal(null, '022100', 45000, 0, '2024-02-01');

        $accounts = $this->accounts();

        $this->assertCount(1, $accounts);
        $this->assertSame(0.0, $accounts[0]['evidence']);
        $this->assertSame(-45000.0, $accounts[0]['difference']);
    }
}
