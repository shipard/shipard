<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\OpenItem;
use Shipard\Module\Economy\Accbal\LedgerOpenItemLookup;

/**
 * LedgerOpenItemLookup nad mockem Dibi: cíle z nastavení saldokont
 * (skupiny s předpisem; přirozené pro směr před opačnými, #69 D19),
 * prefixy řádků bez modify_sign, normalizace klíče (D10) a období v klíči
 * (D11), reziduum Σ předpisy − Σ úhrady se znaménkem (dluh v přirozené
 * skupině, přeplatek / dobropis / platba bez faktury v opačné), vyloučení
 * vlastního zdroje. SQL sémantiku (přesná shoda klíče v DB) kryje
 * integrační test.
 *
 * Tvar ledger dotazu: podmínky klíče v pořadí idx_case (balance,
 * fiscal_year, partner, VS, SS, měna) → prefixy účtů → vyloučení zdroje;
 * prázdný SS je `IS NULL` bez parametru.
 */
class LedgerOpenItemLookupTest extends TestCase
{
    private const RECEIVABLES       = 1;
    private const PAYABLES          = 2;
    private const ADVANCES_GIVEN    = 3;
    private const ADVANCES_RECEIVED = 4;
    private const UNMATCHED         = 5;
    private const FY                = 5;

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    /**
     * Řádky nastavení, jak je vrátí jeden dotaz (pořadí sort_order) — tvar
     * seedu: Pohledávky a Závazky vč. sign-pravidel dobropisů (modify_sign),
     * zálohy, Nespárované platby (jen úhrady).
     *
     * @var list<array{balance: int, account_number: string, acc_side: int, bal_side: int, modify_sign: int, amounts_sign: int}>
     */
    private array $settings;

    /** @var array<int, list<array<string, mixed>>> balance → řádky ledgeru klíče */
    private array $ledger = [];

    protected function setUp(): void
    {
        $this->settings = [
            self::rule(self::RECEIVABLES, '311', 0, 0),
            self::rule(self::RECEIVABLES, '311', 1, 1),
            self::rule(self::RECEIVABLES, '321', 1, 0, 1, 2),
            self::rule(self::RECEIVABLES, '321', 0, 1, 1, 2),
            self::rule(self::PAYABLES, '321', 1, 0),
            self::rule(self::PAYABLES, '321', 0, 1),
            self::rule(self::PAYABLES, '311', 0, 0, 1, 2),
            self::rule(self::PAYABLES, '311', 1, 1, 1, 2),
            self::rule(self::PAYABLES, '325', 1, 0),
            self::rule(self::PAYABLES, '325', 0, 1),
            self::rule(self::ADVANCES_GIVEN, '314', 0, 0),
            self::rule(self::ADVANCES_GIVEN, '314', 1, 1),
            self::rule(self::ADVANCES_RECEIVED, '324', 1, 0),
            self::rule(self::ADVANCES_RECEIVED, '324', 0, 1),
            self::rule(self::UNMATCHED, '261200', 1, 1),
            self::rule(self::UNMATCHED, '261300', 0, 1),
        ];
    }

    /** @return array{balance: int, account_number: string, acc_side: int, bal_side: int, modify_sign: int, amounts_sign: int} */
    private static function rule(int $balance, string $prefix, int $accSide, int $balSide, int $modifySign = 0, int $amountsSign = 1): array
    {
        return [
            'balance'        => $balance,
            'account_number' => $prefix,
            'acc_side'       => $accSide,
            'bal_side'       => $balSide,
            'modify_sign'    => $modifySign,
            'amounts_sign'   => $amountsSign,
        ];
    }

    private function lookup(): LedgerOpenItemLookup
    {
        $this->queries = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args): array {
            $sql = (string) $args[0];
            $params = array_slice($args, 1);
            $this->queries[] = ['sql' => $sql, 'params' => $params];
            if (str_contains($sql, 'economy_accbal_balance_accounts')) {
                return $this->settings;
            }
            return $this->ledger[(int) $params[0]] ?? [];
        });
        $lookup = new LedgerOpenItemLookup();
        $lookup->setDb($db);
        return $lookup;
    }

    /** @return list<array{sql: string, params: list<mixed>}> */
    private function ledgerQueries(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn(array $q) => str_contains($q['sql'], 'economy_accbal_ledger'),
        ));
    }

    /** @return array{sql: string, params: list<mixed>} */
    private function ledgerQuery(int $index = 0): array
    {
        $ledgerQueries = $this->ledgerQueries();
        $this->assertArrayHasKey($index, $ledgerQueries, 'ledger dotaz proběhl');
        return $ledgerQueries[$index];
    }

    /** @return list<int> skupiny v pořadí ledger dotazů */
    private function queriedBalances(): array
    {
        return array_map(static fn(array $q) => (int) $q['params'][0], $this->ledgerQueries());
    }

    private static function row(int $balSide, string $account, float $amount): array
    {
        return ['id' => random_int(1, 1_000_000), 'bal_side' => $balSide, 'account_number' => $account, 'amount' => $amount];
    }

    // ── Reziduum ─────────────────────────────────────────────────────────────

    public function testOpenRequestReturnsItemWithRequestAccount(): void
    {
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 1000.00),
            self::row(1, '311100', 400.00),
        ];

        $item = $this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY);

        $this->assertInstanceOf(OpenItem::class, $item);
        $this->assertSame(self::RECEIVABLES, $item->balance);
        $this->assertSame('311100', $item->accountNumber, 'účet předpisu vč. analytiky');
        $this->assertEqualsWithDelta(600.00, $item->residual, 0.001);
    }

    public function testClosedRequestReturnsNullForBothDirections(): void
    {
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 1000.00),
            self::row(1, '311100', 1000.00),
        ];
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY));
        $this->assertNull($lookup->findOpenRequest(42, '20260001', '', 'czk', 2, self::FY), 'nulové reziduum není vratka');
    }

    public function testOverpaidRequestIsMissForIncomingAndHitForOutgoing(): void
    {
        // D19/D14: přeplatek zákazníka se vrací výdajem — Pohledávky jsou pro
        // výdaj opačná skupina, otevřený = reziduum < 0.
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 1000.00),
            self::row(1, '311100', 1200.00),
        ];
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY), 'další příjem na přeplacený klíč = clearing');
        $item = $lookup->findOpenRequest(42, '20260001', '', 'czk', 2, self::FY);
        $this->assertNotNull($item);
        $this->assertSame(self::RECEIVABLES, $item->balance);
        $this->assertSame('311100', $item->accountNumber);
        $this->assertEqualsWithDelta(-200.00, $item->residual, 0.001, 'reziduum nese znaménko');
    }

    public function testPaymentWithoutRequestIsRefundableByOutgoing(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(1, '311100', 500.00)];
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY));
        $item = $lookup->findOpenRequest(42, '20260001', '', 'czk', 2, self::FY);
        $this->assertNotNull($item, 'platba bez faktury se vrací');
        $this->assertSame('311100', $item->accountNumber, 'bez předpisu účet úhrady');
        $this->assertEqualsWithDelta(-500.00, $item->residual, 0.001);
    }

    public function testIncomingFindsOverpaidPayable(): void
    {
        // Dodavatel vrací přeplatek: Závazky jsou pro příjem opačná skupina.
        $this->ledger[self::PAYABLES] = [
            self::row(0, '321100', 800.00),
            self::row(1, '321100', 1000.00),
        ];

        $item = $this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY);

        $this->assertNotNull($item);
        $this->assertSame(self::PAYABLES, $item->balance);
        $this->assertSame('321100', $item->accountNumber);
        $this->assertEqualsWithDelta(-200.00, $item->residual, 0.001);
    }

    public function testRequestSplitOverInstallmentsSumsUp(): void
    {
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 300.00),
            self::row(0, '311100', 300.00),
            self::row(1, '311100', 500.00),
        ];

        $item = $this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY);

        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(100.00, $item->residual, 0.001);
    }

    public function testNegativeRequestInLegacyGroupIsRefundableByOutgoing(): void
    {
        // Legacy seed: dobropis pohledávky zůstává záporně v Pohledávkách →
        // vratka zákazníkovi je výdaj proti zápornému reziduu.
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', -500.00)];
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(42, '20260001', '', 'czk', 1, self::FY));
        $item = $lookup->findOpenRequest(42, '20260001', '', 'czk', 2, self::FY);
        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(-500.00, $item->residual, 0.001);
    }

    // ── Klíč a normalizace ───────────────────────────────────────────────────

    public function testEmptyPaymentReferenceIsNoKey(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 1000.00)];

        $this->assertNull($this->lookup()->findOpenRequest(42, '  ', '', 'czk', 1, self::FY));
        $this->assertSame([], $this->queries, 'bez VS se do DB nesahá');
    }

    public function testWithoutFiscalYearIsNoKey(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 1000.00)];

        $this->assertNull($this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1, null));
        $this->assertSame([], $this->queries, 'období je součást klíče (D11) — bez něj se do DB nesahá');
    }

    public function testUnknownDirectionIsNull(): void
    {
        $this->assertNull($this->lookup()->findOpenRequest(42, '1', '', 'czk', 3, self::FY));
        $this->assertSame([], $this->queries);
    }

    public function testWithoutDbIsNull(): void
    {
        $this->assertNull((new LedgerOpenItemLookup())->findOpenRequest(42, '1', '', 'czk', 1, self::FY));
    }

    public function testKeyIsNormalizedAndComparedByEquality(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, ' 20260001 ', ' 77 ', 'CZK', 1, self::FY);

        $q = $this->ledgerQuery();
        $this->assertSame([self::RECEIVABLES, self::FY, 42, '20260001', '77', 'czk', '311'], $q['params']);
        $this->assertStringContainsString('l.[balance] = %i AND l.[fiscal_year] = %i AND l.[partner] = %i', $q['sql']);
        $this->assertStringContainsString('l.[payment_reference] = %s', $q['sql']);
        $this->assertStringContainsString('l.[specific_symbol] = %s', $q['sql']);
        $this->assertStringContainsString('l.[currency] = %s', $q['sql']);
        $this->assertStringContainsString('l.[account_number] LIKE %like~', $q['sql']);
        // D10: ledger je normalizovaný při zápisu → rovnost přes idx_case, žádné funkce ve WHERE.
        $this->assertStringNotContainsString('TRIM(', $q['sql']);
        $this->assertStringNotContainsString('LOWER(', $q['sql']);
        $this->assertStringNotContainsString('NOT (', $q['sql'], 'bez exclude se podmínka nepřidá');
    }

    public function testEmptySpecificSymbolIsComparedAsNull(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, '20260001', '  ', 'czk', 1, self::FY);

        $q = $this->ledgerQuery();
        $this->assertStringContainsString('l.[specific_symbol] IS NULL', $q['sql']);
        $this->assertSame([self::RECEIVABLES, self::FY, 42, '20260001', 'czk', '311'], $q['params'], 'IS NULL bez parametru');
    }

    public function testExclusionOfOwnSourceIsAppended(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY, 'bankTransaction', 555);

        $q = $this->ledgerQuery();
        $this->assertStringContainsString('NOT (l.[source_kind] = %s AND l.[source_id] = %i)', $q['sql']);
        $this->assertSame('bankTransaction', $q['params'][6]);
        $this->assertSame(555, $q['params'][7]);
    }

    // ── Výběr cílových skupin z nastavení ─────────────────────────────────────

    public function testIncomingSearchesNaturalGroupsFirstInSettingsOrder(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];
        $this->ledger[self::ADVANCES_GIVEN] = [self::row(0, '314100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY);

        $this->assertNotNull($item);
        $this->assertSame(self::RECEIVABLES, $item->balance, 'první skupina v pořadí nastavení vyhrává');
        $settings = $this->queries[0];
        $this->assertStringContainsString('economy_accbal_balance_accounts', $settings['sql']);
        $this->assertStringNotContainsString('a.[acc_side] = %i', $settings['sql'], 'strana se neřeší v SQL — cíle se dělí v PHP');
        $this->assertSame([self::RECEIVABLES], $this->queriedBalances(), 'zásah v Pohledávkách → dál se nehledá');
        $this->assertSame([self::RECEIVABLES, self::FY, 42, '1', 'czk', '311'], $this->ledgerQuery()['params'], 'sign-ruled 321 mezi prefixy Pohledávek není');
    }

    public function testTargetsAreNaturalThenOppositeWithoutUnmatched(): void
    {
        // Nic otevřeno → projdou se všechny cíle: pro příjem přirozené (MD
        // předpis) v pořadí nastavení, pak opačné; Nespárované platby nikdy.
        $this->assertNull($this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY));
        $this->assertSame(
            [self::RECEIVABLES, self::ADVANCES_GIVEN, self::PAYABLES, self::ADVANCES_RECEIVED],
            $this->queriedBalances(),
        );

        $this->assertNull($this->lookup()->findOpenRequest(42, '1', '', 'czk', 2, self::FY));
        $this->assertSame(
            [self::PAYABLES, self::ADVANCES_RECEIVED, self::RECEIVABLES, self::ADVANCES_GIVEN],
            $this->queriedBalances(),
            'výdaj zrcadlově',
        );
    }

    public function testNaturalHitPrecedesOppositeHit(): void
    {
        // Příjem: v poskytnutých zálohách (přirozená) dluh, v Závazcích (opačná)
        // přeplatek — přirozená vyhrává i když je v nastavení za Závazky.
        $this->ledger[self::ADVANCES_GIVEN] = [self::row(0, '314100', 10.00)];
        $this->ledger[self::PAYABLES] = [self::row(0, '321100', 100.00), self::row(1, '321100', 150.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY);

        $this->assertNotNull($item);
        $this->assertSame(self::ADVANCES_GIVEN, $item->balance);
    }

    public function testIncomingFallsThroughToNextNaturalGroup(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 100.00), self::row(1, '311100', 100.00)];
        $this->ledger[self::ADVANCES_GIVEN] = [self::row(0, '314100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY);

        $this->assertNotNull($item);
        $this->assertSame(self::ADVANCES_GIVEN, $item->balance, 'uzavřený klíč v Pohledávkách → poskytnuté zálohy (314 MD předpis)');
        $this->assertSame('314100', $item->accountNumber);
        $this->assertSame([self::ADVANCES_GIVEN, self::FY, 42, '1', 'czk', '314'], $this->ledgerQuery(1)['params']);
    }

    public function testOutgoingSearchesAllNonCreditNotePrefixesOfGroup(): void
    {
        $this->ledger[self::PAYABLES] = [self::row(0, '325100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 2, self::FY);

        $this->assertNotNull($item);
        $this->assertSame(self::PAYABLES, $item->balance);
        $this->assertSame('325100', $item->accountNumber, 'účet skutečného předpisu, ne jen 321');
        $q = $this->ledgerQuery();
        $this->assertSame([self::PAYABLES, self::FY, 42, '1', 'czk', '321', '325'], $q['params'], 'sign-ruled 311 v Závazcích mezi prefixy není');
        $this->assertStringContainsString(
            '(l.[account_number] LIKE %like~ OR l.[account_number] LIKE %like~)',
            $q['sql'],
            'jeden dotaz per skupina přes všechny její prefixy bez modify_sign',
        );
    }

    public function testSettingsPrefixIsUsedVerbatim(): void
    {
        $this->settings = [self::rule(self::RECEIVABLES, ' 3111 ', 0, 0)];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY);

        $params = $this->ledgerQuery()['params'];
        $this->assertSame('3111', end($params), 'prefix z nastavení (trim), bez rozšiřování na 311');
    }

    public function testBroaderSettingsPrefixIsNotWidened(): void
    {
        $this->settings = [self::rule(self::RECEIVABLES, '31', 0, 0)];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '315100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY);

        $params = $this->ledgerQuery()['params'];
        $this->assertSame('31', end($params));
        $this->assertNotNull($item);
        $this->assertSame('315100', $item->accountNumber);
    }

    public function testDuplicateAndEmptyPrefixesAreCollapsedAndGroupsWithoutRequestSkipped(): void
    {
        $this->settings = [
            self::rule(self::RECEIVABLES, '311', 0, 0),
            self::rule(self::RECEIVABLES, '311', 1, 1),
            self::rule(self::RECEIVABLES, '', 0, 0),
            self::rule(7, '   ', 0, 0),
            self::rule(8, '381', 0, 1),
        ];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->assertNotNull($this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY));
        $this->assertSame([self::RECEIVABLES], $this->queriedBalances(), 'skupina jen s prázdným prefixem ani skupina bez předpisu není cíl');
        $this->assertSame([self::RECEIVABLES, self::FY, 42, '1', 'czk', '311'], $this->ledgerQuery()['params'], 'duplicitní prefix jen jednou');
    }

    public function testFirstTargetWithResidualWins(): void
    {
        $this->settings = [
            self::rule(self::RECEIVABLES, '311', 0, 0),
            self::rule(9, '311', 0, 0),
        ];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 100.00), self::row(1, '311100', 100.00)];
        $this->ledger[9] = [self::row(0, '311200', 50.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, self::FY);

        $this->assertNotNull($item);
        $this->assertSame(9, $item->balance, 'uzavřený klíč v první skupině → hledá se dál');
        $this->assertSame('311200', $item->accountNumber);
    }

    public function testSettingsAreReadOncePerInstance(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];
        $lookup = $this->lookup();

        $lookup->findOpenRequest(42, '1', '', 'czk', 1, self::FY);
        $lookup->findOpenRequest(42, '2', '', 'czk', 2, self::FY);

        $settingsQueries = array_filter(
            $this->queries,
            static fn(array $q) => str_contains($q['sql'], 'economy_accbal_balance_accounts'),
        );
        $this->assertCount(1, $settingsQueries, 'nastavení se čte jednou pro oba směry');
    }
}
