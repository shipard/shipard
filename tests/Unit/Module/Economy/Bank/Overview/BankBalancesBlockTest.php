<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Overview;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Overview\OverviewContext;
use Shipard\Core\Reports\ReportRegistry;
use Shipard\Core\Reports\ReportRunner;
use Shipard\Module\Economy\Bank\Overview\BankBalancesBlock;

/**
 * Blok banky Přehledu firmy (#120 D5, D14): poslední výpis per účet
 * s datem a poznámkou, práh 35 dní, účet bez výpisu, cizí měna jako
 * samostatná položka za domácí, filtr platnosti účtů v SQL.
 */
final class BankBalancesBlockTest extends TestCase
{
    private const TODAY = '2026-10-16';

    /** @var list<array{0: string, 1: list<mixed>}> SQL + parametry zachycené mockem */
    private array $queries = [];

    public function testLastStatementPerAccountWithDateNoteAndLink(): void
    {
        $db = $this->db(
            accounts: [['id' => 3, 'code' => 'BU', 'name' => 'Běžný účet', 'currency' => 'czk']],
            statements: [3 => ['id' => 812, 'statement_number' => '182', 'period_end' => '2026-10-15', 'closing_balance' => '125000.00', 'currency' => 'czk']],
        );

        $items = (new BankBalancesBlock())->collect($this->context($db))->items;

        $this->assertCount(1, $items);
        $this->assertSame([
            'id'      => 'bank:3',
            'label'   => 'Běžný účet',
            'kind'    => 'bank',
            'amount'  => ['value' => 125000.0, 'currency' => 'CZK'],
            'asOf'    => '2026-10-15',
            'state'   => 'ok',
            'note'    => 'According to statement no. 182',
            'dueDate' => null,
            'link'    => ['kind' => 'open_detail', 'target' => ['viewerId' => 'economy.bank.statements', 'recordId' => 812]],
        ], $items[0]->toArray());

        // Poslední výpis = nejvyšší period_end, smazané vynechány.
        [$sql, $params] = $this->queryContaining('economy_bank_statements');
        $this->assertStringContainsString('ORDER BY [period_end] DESC, [id] DESC LIMIT 1', $sql);
        $this->assertSame([3, 90], $params);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function staleThreshold(): iterable
    {
        yield '34 dní → ok' => ['2026-09-12', 'ok'];
        yield '35 dní → ok' => ['2026-09-11', 'ok'];
        yield '36 dní → stale' => ['2026-09-10', 'stale'];
    }

    #[DataProvider('staleThreshold')]
    public function testStaleThreshold(string $periodEnd, string $expectedState): void
    {
        $db = $this->db(
            accounts: [['id' => 1, 'code' => 'BU', 'name' => 'Účet', 'currency' => 'czk']],
            statements: [1 => ['id' => 5, 'statement_number' => null, 'period_end' => $periodEnd, 'closing_balance' => '1.00', 'currency' => 'czk']],
        );

        $item = (new BankBalancesBlock())->collect($this->context($db))->items[0];

        $this->assertSame($expectedState, $item->state);
        $this->assertSame($periodEnd, $item->asOf); // datum vždy (D14)
        $this->assertSame('According to the last statement', $item->note);
    }

    public function testAccountWithoutStatementIsMissingWithViewerLink(): void
    {
        $db = $this->db(
            accounts: [['id' => 7, 'code' => 'NOVY', 'name' => '', 'currency' => 'czk']],
            statements: [],
        );

        $item = (new BankBalancesBlock())->collect($this->context($db))->items[0]->toArray();

        $this->assertSame('NOVY', $item['label']); // bez názvu kód
        $this->assertNull($item['amount']);
        $this->assertNull($item['asOf']);
        $this->assertSame('missing', $item['state']);
        $this->assertSame('No statement yet', $item['note']);
        $this->assertSame(['kind' => 'open_viewer', 'target' => ['viewerId' => 'economy.bank.statements']], $item['link']);
    }

    public function testForeignCurrencyIsSeparateItemAfterHomeCurrency(): void
    {
        // Účty v pořadí číselníku: EUR je výchozí (první), přesto jde CZK první (D4).
        $db = $this->db(
            accounts: [
                ['id' => 1, 'code' => 'EUR', 'name' => 'Eurový účet', 'currency' => 'eur'],
                ['id' => 2, 'code' => 'CZK', 'name' => 'Korunový účet', 'currency' => 'czk'],
            ],
            statements: [
                1 => ['id' => 11, 'statement_number' => '1', 'period_end' => '2026-10-01', 'closing_balance' => '2000.00', 'currency' => 'eur'],
                2 => ['id' => 12, 'statement_number' => '2', 'period_end' => '2026-10-02', 'closing_balance' => '50000.00', 'currency' => 'czk'],
            ],
        );

        $items = (new BankBalancesBlock())->collect($this->context($db))->items;

        $this->assertSame(['bank:2', 'bank:1'], array_map(static fn ($i) => $i->id, $items));
        $this->assertSame(['CZK', 'EUR'], array_map(static fn ($i) => $i->amount['currency'], $items));
    }

    public function testOnlyValidAccountsAreQueried(): void
    {
        $db = $this->db(accounts: [], statements: []);

        $this->assertSame([], (new BankBalancesBlock())->collect($this->context($db))->items);

        [$sql, $params] = $this->queryContaining('economy_codebooks_bank_accounts');
        $this->assertStringContainsString('[docState] != %i', $sql);
        $this->assertStringContainsString('[valid_to] IS NULL OR [valid_to] >= %s', $sql);
        $this->assertSame([90, self::TODAY], $params);
    }

    public function testAvailabilityRequiresStatementsAndAccountsTables(): void
    {
        $block = new BankBalancesBlock();
        $db    = $this->createMock(DataSourceConnection::class);

        $this->assertTrue($block->isAvailable($this->context($db)));
        $this->assertFalse($block->isAvailable($this->context($db, ['economy_bank_statements' => true])));
        $this->assertFalse($block->isAvailable($this->context($db, [])));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>>       $accounts   řádky číselníku účtů
     * @param array<int, array<string, mixed>> $statements id účtu → poslední výpis
     */
    private function db(array $accounts, array $statements): MockObject&DataSourceConnection
    {
        $this->queries = [];
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use ($accounts): array {
            $this->queries[] = [(string) $args[0], array_slice($args, 1)];
            $this->assertStringContainsString('economy_codebooks_bank_accounts', (string) $args[0]);
            return $accounts;
        });
        $db->method('fetchRow')->willReturnCallback(function (mixed ...$args) use ($statements): ?array {
            $this->queries[] = [(string) $args[0], array_slice($args, 1)];
            $this->assertStringContainsString('economy_bank_statements', (string) $args[0]);
            return $statements[(int) $args[1]] ?? null;
        });
        return $db;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function queryContaining(string $needle): array
    {
        foreach ($this->queries as $query) {
            if (str_contains($query[0], $needle)) {
                return $query;
            }
        }
        $this->fail("Žádný dotaz na {$needle}");
    }

    /** @param ?array<string, true> $tables */
    private function context(DataSourceConnection $db, ?array $tables = null): OverviewContext
    {
        return new OverviewContext(
            $db,
            null,
            'cs',
            new \DateTimeImmutable(self::TODAY),
            new ReportRunner(new ReportRegistry(), $db, null, 'test-ds'),
            $tables ?? ['economy_bank_statements' => true, 'economy_codebooks_bank_accounts' => true],
            'cz',
            'czk',
        );
    }
}
