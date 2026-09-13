<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\OpenItem;
use Shipard\Module\Economy\Accbal\LedgerOpenItemLookup;

/**
 * LedgerOpenItemLookup nad mockem Dibi: výběr cílových skupin z nastavení
 * saldokont (řádky předpisu na přirozené straně směru, všechny jejich
 * prefixy), normalizace klíče, reziduum Σ předpisy − Σ úhrady, vyloučení
 * vlastního zdroje. SQL sémantiku (přesná shoda klíče v DB) kryje
 * integrační test.
 */
class LedgerOpenItemLookupTest extends TestCase
{
    private const RECEIVABLES = 1;
    private const PAYABLES    = 2;

    /** @var list<array{sql: string, params: list<mixed>}> */
    private array $queries = [];

    /**
     * Řádky nastavení, jak by je vrátil SQL filtr (bal_side 0, kladné, bez
     * modify_sign) pro daný acc_side, v pořadí sort_order — skupiny záloh
     * (314/324) jsou řádné cíle za Pohledávkami / Závazky.
     *
     * @var array<int, list<array{balance: int, account_number: string}>>
     */
    private array $settings = [
        0 => [
            ['balance' => self::RECEIVABLES, 'account_number' => '311'],
            ['balance' => 3, 'account_number' => '314'],
        ],
        1 => [
            ['balance' => self::PAYABLES, 'account_number' => '321'],
            ['balance' => self::PAYABLES, 'account_number' => '325'],
            ['balance' => 4, 'account_number' => '324'],
        ],
    ];

    /** @var array<int, list<array<string, mixed>>> balance → řádky ledgeru klíče */
    private array $ledger = [];

    private function lookup(): LedgerOpenItemLookup
    {
        $this->queries = [];
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args): array {
            $sql = (string) $args[0];
            $params = array_slice($args, 1);
            $this->queries[] = ['sql' => $sql, 'params' => $params];
            if (str_contains($sql, 'economy_accbal_balance_accounts')) {
                return $this->settings[(int) $params[0]] ?? [];
            }
            return $this->ledger[(int) $params[0]] ?? [];
        });
        $lookup = new LedgerOpenItemLookup();
        $lookup->setDb($db);
        return $lookup;
    }

    /** @return array{sql: string, params: list<mixed>} */
    private function ledgerQuery(int $index = 0): array
    {
        $ledgerQueries = array_values(array_filter(
            $this->queries,
            static fn(array $q) => str_contains($q['sql'], 'economy_accbal_ledger'),
        ));
        $this->assertArrayHasKey($index, $ledgerQueries, 'ledger dotaz proběhl');
        return $ledgerQueries[$index];
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

        $item = $this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1);

        $this->assertInstanceOf(OpenItem::class, $item);
        $this->assertSame(self::RECEIVABLES, $item->balance);
        $this->assertSame('311100', $item->accountNumber, 'účet předpisu vč. analytiky');
        $this->assertEqualsWithDelta(600.00, $item->residual, 0.001);
    }

    public function testClosedRequestReturnsNull(): void
    {
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 1000.00),
            self::row(1, '311100', 1000.00),
        ];

        $this->assertNull($this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1));
    }

    public function testOverpaidRequestReturnsNull(): void
    {
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 1000.00),
            self::row(1, '311100', 1200.00),
        ];

        $this->assertNull($this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1));
    }

    public function testPaymentsWithoutRequestReturnNull(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(1, '311100', 500.00)];

        $this->assertNull($this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1));
    }

    public function testRequestSplitOverInstallmentsSumsUp(): void
    {
        $this->ledger[self::RECEIVABLES] = [
            self::row(0, '311100', 300.00),
            self::row(0, '311100', 300.00),
            self::row(1, '311100', 500.00),
        ];

        $item = $this->lookup()->findOpenRequest(42, '20260001', '', 'czk', 1);

        $this->assertNotNull($item);
        $this->assertEqualsWithDelta(100.00, $item->residual, 0.001);
    }

    // ── Klíč a normalizace ───────────────────────────────────────────────────

    public function testEmptyPaymentReferenceIsNoKey(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 1000.00)];

        $this->assertNull($this->lookup()->findOpenRequest(42, '  ', '', 'czk', 1));
        $this->assertSame([], $this->queries, 'bez VS se do DB nesahá');
    }

    public function testUnknownDirectionIsNull(): void
    {
        $this->assertNull($this->lookup()->findOpenRequest(42, '1', '', 'czk', 3));
        $this->assertSame([], $this->queries);
    }

    public function testWithoutDbIsNull(): void
    {
        $this->assertNull((new LedgerOpenItemLookup())->findOpenRequest(42, '1', '', 'czk', 1));
    }

    public function testKeyIsTrimmedAndCurrencyLowercased(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, ' 20260001 ', ' 77 ', 'CZK', 1);

        $q = $this->ledgerQuery();
        $this->assertSame([self::RECEIVABLES, '311', 42, '20260001', '77', 'czk'], $q['params']);
        $this->assertStringContainsString('TRIM([payment_reference]) = %s', $q['sql']);
        $this->assertStringContainsString("TRIM(COALESCE([specific_symbol], '')) = %s", $q['sql']);
        $this->assertStringContainsString('LOWER([currency]) = %s', $q['sql']);
        $this->assertStringContainsString('[account_number] LIKE %like~', $q['sql']);
        $this->assertStringNotContainsString('NOT (', $q['sql'], 'bez exclude se podmínka nepřidá');
    }

    public function testExclusionOfOwnSourceIsAppended(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1, 'bankTransaction', 555);

        $q = $this->ledgerQuery();
        $this->assertStringContainsString('NOT ([source_kind] = %s AND [source_id] = %i)', $q['sql']);
        $this->assertSame('bankTransaction', $q['params'][6]);
        $this->assertSame(555, $q['params'][7]);
    }

    // ── Výběr cílové skupiny z nastavení ─────────────────────────────────────

    public function testIncomingSearchesGroupsWithRequestOnDebitSideInOrder(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];
        $this->ledger[3] = [self::row(0, '314100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1);

        $this->assertNotNull($item);
        $this->assertSame(self::RECEIVABLES, $item->balance, 'první skupina v pořadí nastavení vyhrává');
        $settings = $this->queries[0];
        $this->assertStringContainsString('economy_accbal_balance_accounts', $settings['sql']);
        $this->assertSame(0, $settings['params'][0], 'příjem → předpis vzniká na MD (acc_side 0)');
        $this->assertStringContainsString('a.[bal_side] = 0', $settings['sql']);
        $this->assertStringContainsString('a.[modify_sign] = 0', $settings['sql']);
        $this->assertStringContainsString('a.[amounts_sign] IN (0, 1)', $settings['sql']);
        $this->assertCount(2, $this->queries, 'zásah v Pohledávkách → zálohy se už neprohledávají');
        $this->assertSame([self::RECEIVABLES, '311'], array_slice($this->ledgerQuery()['params'], 0, 2));
    }

    public function testIncomingFallsThroughToNextGroupWithRequestOnDebitSide(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 100.00), self::row(1, '311100', 100.00)];
        $this->ledger[3] = [self::row(0, '314100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1);

        $this->assertNotNull($item);
        $this->assertSame(3, $item->balance, 'uzavřený klíč v Pohledávkách → poskytnuté zálohy (314 MD předpis)');
        $this->assertSame('314100', $item->accountNumber);
        $this->assertSame([3, '314'], array_slice($this->ledgerQuery(1)['params'], 0, 2));
    }

    public function testOutgoingSearchesAllRequestPrefixesOfGroup(): void
    {
        $this->ledger[self::PAYABLES] = [self::row(0, '325100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 2);

        $this->assertNotNull($item);
        $this->assertSame(self::PAYABLES, $item->balance);
        $this->assertSame('325100', $item->accountNumber, 'účet skutečného předpisu, ne jen 321');
        $this->assertSame(1, $this->queries[0]['params'][0], 'výdaj → předpis vzniká na DAL (acc_side 1)');
        $q = $this->ledgerQuery();
        $this->assertSame([self::PAYABLES, '321', '325', 42, '1', '', 'czk'], $q['params']);
        $this->assertStringContainsString(
            '([account_number] LIKE %like~ OR [account_number] LIKE %like~)',
            $q['sql'],
            'jeden dotaz per skupina přes všechny její předpisové prefixy',
        );
    }

    public function testSettingsPrefixIsUsedVerbatim(): void
    {
        $this->settings[0] = [['balance' => self::RECEIVABLES, 'account_number' => ' 3111 ']];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];

        $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1);

        $this->assertSame('3111', $this->ledgerQuery()['params'][1], 'prefix z nastavení (trim), bez rozšiřování na 311');
    }

    public function testBroaderSettingsPrefixIsNotWidened(): void
    {
        $this->settings[0] = [['balance' => self::RECEIVABLES, 'account_number' => '31']];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '315100', 10.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1);

        $this->assertSame('31', $this->ledgerQuery()['params'][1]);
        $this->assertNotNull($item);
        $this->assertSame('315100', $item->accountNumber);
    }

    public function testDuplicateAndEmptyPrefixesAreCollapsed(): void
    {
        $this->settings[0] = [
            ['balance' => self::RECEIVABLES, 'account_number' => '311'],
            ['balance' => self::RECEIVABLES, 'account_number' => '311'],
            ['balance' => self::RECEIVABLES, 'account_number' => ''],
            ['balance' => 7, 'account_number' => '   '],
        ];
        $this->ledger[self::RECEIVABLES] = [self::row(1, '311100', 10.00)];

        $this->assertNull($this->lookup()->findOpenRequest(42, '1', '', 'czk', 1));
        $ledgerQueries = array_values(array_filter(
            $this->queries,
            static fn(array $q) => str_contains($q['sql'], 'economy_accbal_ledger'),
        ));
        $this->assertCount(1, $ledgerQueries, 'skupina jen s prázdným prefixem není cíl');
        $this->assertSame([self::RECEIVABLES, '311', 42], array_slice($ledgerQueries[0]['params'], 0, 3), 'duplicitní prefix jen jednou');
    }

    public function testFirstTargetWithResidualWins(): void
    {
        $this->settings[0] = [
            ['balance' => self::RECEIVABLES, 'account_number' => '311'],
            ['balance' => 9, 'account_number' => '311'],
        ];
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 100.00), self::row(1, '311100', 100.00)];
        $this->ledger[9] = [self::row(0, '311200', 50.00)];

        $item = $this->lookup()->findOpenRequest(42, '1', '', 'czk', 1);

        $this->assertNotNull($item);
        $this->assertSame(9, $item->balance, 'uzavřený klíč v první skupině → hledá se dál');
        $this->assertSame('311200', $item->accountNumber);
    }

    public function testTargetsAreCachedPerDirection(): void
    {
        $this->ledger[self::RECEIVABLES] = [self::row(0, '311100', 10.00)];
        $lookup = $this->lookup();

        $lookup->findOpenRequest(42, '1', '', 'czk', 1);
        $lookup->findOpenRequest(42, '2', '', 'czk', 1);

        $settingsQueries = array_filter(
            $this->queries,
            static fn(array $q) => str_contains($q['sql'], 'economy_accbal_balance_accounts'),
        );
        $this->assertCount(1, $settingsQueries, 'nastavení se čte jednou per směr');
    }
}
