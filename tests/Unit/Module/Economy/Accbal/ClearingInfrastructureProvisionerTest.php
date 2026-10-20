<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accbal\ClearingInfrastructureProvisioner;

class ClearingInfrastructureProvisionerTest extends TestCase
{
    private const CHART_SEED    = __DIR__ . '/../../../../../modules/economy/accounting/config/accountChartDefault.jsonc';
    private const BALANCES_SEED = __DIR__ . '/../../../../../modules/economy/accbal/config/balancesDefault.cz.jsonc';

    /**
     * Recording mock — lookup existence by `number` (accounts) / `code`
     * (balances), capture inserts. Vzor: AccountChartProvisionerTest.
     *
     * @param list<array{id: int, number: string}> $existingAccounts
     * @param list<array{id: int, code: string}> $existingBalances
     * @param list<array<string, mixed>> $existingBalanceAccounts řádky skupin (`balance`, klíč, `sort_order`)
     */
    private function recordingDb(array $existingAccounts = [], array $existingBalances = [], array $existingBalanceAccounts = []): object
    {
        $store = new \stdClass();
        $store->tables = [
            'economy_accounting_accounts'    => $existingAccounts,
            'economy_accbal_balances'        => $existingBalances,
            'economy_accbal_balance_accounts' => $existingBalanceAccounts,
        ];
        $store->autoIncrement = count($existingAccounts) + count($existingBalances) + count($existingBalanceAccounts);

        $db = $this->createMock(DataSourceConnection::class);

        $db->method('fetchAll')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): array {
                $this->assertStringContainsString('economy_accbal_balance_accounts', $sql);
                $balanceId = (int) ($params[0] ?? 0);
                return array_values(array_filter(
                    $store->tables['economy_accbal_balance_accounts'],
                    static fn(array $row) => (int) ($row['balance'] ?? 0) === $balanceId,
                ));
            }
        );

        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                $needle = (string) ($params[0] ?? '');
                if (str_contains($sql, 'economy_accounting_accounts') && str_contains($sql, 'number')) {
                    foreach ($store->tables['economy_accounting_accounts'] as $row) {
                        if ((string) ($row['number'] ?? '') === $needle) {
                            return $row;
                        }
                    }
                    return null;
                }
                if (str_contains($sql, 'economy_accbal_balances') && str_contains($sql, 'code')) {
                    foreach ($store->tables['economy_accbal_balances'] as $row) {
                        if ((string) ($row['code'] ?? '') === $needle) {
                            return $row;
                        }
                    }
                    return null;
                }
                return null;
            }
        );

        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $store->autoIncrement++;
                $row = $data;
                $row['id'] = $store->autoIncrement;
                $store->tables[$table][] = $row;
                return $store->autoIncrement;
            }
        );

        $store->db = $db;
        return $store;
    }

    public function testEmptyDsCreatesAccountsAndGroup(): void
    {
        $store = $this->recordingDb();

        $provisioner = new ClearingInfrastructureProvisioner($store->db);
        $result = $provisioner->provision();

        $this->assertSame(2, $result['accounts']['created']);
        $this->assertSame(0, $result['accounts']['existing']);
        $this->assertSame(1, $result['group']['created']);
        $this->assertSame(0, $result['group']['existing']);
        $this->assertSame(4, $result['group']['accountsAdded']);

        $accounts = $store->tables['economy_accounting_accounts'];
        $this->assertCount(2, $accounts);
        $this->assertSame('261200', $accounts[0]['number']);
        $this->assertSame(4, $accounts[0]['account_level']);
        $this->assertSame('261', $accounts[0]['g3']);
        $this->assertSame(0, $accounts[0]['account_kind']);
        $this->assertSame(1, $accounts[0]['is_system']);
        $this->assertSame(40, $accounts[0]['docState']);
        $this->assertSame(3, $accounts[0]['docStateMain']);

        $balances = $store->tables['economy_accbal_balances'];
        $this->assertCount(1, $balances);
        $this->assertSame('unmatched_payments', $balances[0]['code']);
        $this->assertSame(40, $balances[0]['docState']);

        $balAccounts = $store->tables['economy_accbal_balance_accounts'];
        $this->assertCount(4, $balAccounts);
        $this->assertSame($balances[0]['id'], $balAccounts[0]['balance']);
        $this->assertSame('261200', $balAccounts[0]['account_number']);
        $this->assertSame(10, $balAccounts[0]['sort_order']);
        $this->assertSame('261300', $balAccounts[1]['account_number']);
        $this->assertSame(20, $balAccounts[1]['sort_order']);
        // Zrcadlové řádky (#131 fáze 2a): opačná strana, úhrada, modify_sign.
        $this->assertSame(['261200', 0, 1, 1, 30], [
            $balAccounts[2]['account_number'], $balAccounts[2]['acc_side'], $balAccounts[2]['bal_side'],
            $balAccounts[2]['modify_sign'], $balAccounts[2]['sort_order'],
        ]);
        $this->assertSame(['261300', 1, 1, 1, 40], [
            $balAccounts[3]['account_number'], $balAccounts[3]['acc_side'], $balAccounts[3]['bal_side'],
            $balAccounts[3]['modify_sign'], $balAccounts[3]['sort_order'],
        ]);
    }

    /** @return list<array<string, mixed>> řádky skupiny 3 ve tvaru z DB */
    private static function groupRows(int $count): array
    {
        $rows = [];
        foreach (array_slice(ClearingInfrastructureProvisioner::GROUP['accounts'], 0, $count) as $i => $acc) {
            $rows[] = [
                'id' => 10 + $i, 'balance' => 3, 'account_number' => $acc['account_number'],
                'acc_side' => $acc['acc_side'], 'bal_side' => $acc['bal_side'],
                'modify_sign' => $acc['modify_sign'] ? 1 : 0, 'sort_order' => ($i + 1) * 10,
            ];
        }
        return $rows;
    }

    public function testSecondRunIsIdempotent(): void
    {
        $store = $this->recordingDb(
            [
                ['id' => 1, 'number' => '261200'],
                ['id' => 2, 'number' => '261300'],
            ],
            [
                ['id' => 3, 'code' => 'unmatched_payments'],
            ],
            self::groupRows(4),
        );

        $provisioner = new ClearingInfrastructureProvisioner($store->db);
        $result = $provisioner->provision();

        $this->assertSame(0, $result['accounts']['created']);
        $this->assertSame(2, $result['accounts']['existing']);
        $this->assertSame(0, $result['group']['created']);
        $this->assertSame(1, $result['group']['existing']);
        $this->assertSame(0, $result['group']['accountsAdded']);
        $this->assertCount(2, $store->tables['economy_accounting_accounts']);
        $this->assertCount(1, $store->tables['economy_accbal_balances']);
        $this->assertCount(4, $store->tables['economy_accbal_balance_accounts']);
    }

    public function testExistingGroupGetsMissingMirrorRows(): void
    {
        // DS vzniklý před #131 fází 2a: skupina má jen dva původní řádky.
        $store = $this->recordingDb(
            [['id' => 1, 'number' => '261200'], ['id' => 2, 'number' => '261300']],
            [['id' => 3, 'code' => 'unmatched_payments']],
            self::groupRows(2),
        );

        $result = (new ClearingInfrastructureProvisioner($store->db))->provision();

        $this->assertSame(['created' => 0, 'existing' => 1, 'accountsAdded' => 2], $result['group']);
        $rows = $store->tables['economy_accbal_balance_accounts'];
        $this->assertCount(4, $rows);
        $this->assertSame([3, '261200', 0, 1, 1, 30], [
            $rows[2]['balance'], $rows[2]['account_number'], $rows[2]['acc_side'], $rows[2]['bal_side'],
            $rows[2]['modify_sign'], $rows[2]['sort_order'],
        ], 'sort_order navazuje na nejvyšší existující');
        $this->assertSame(['261300', 1, 1, 1, 40], [
            $rows[3]['account_number'], $rows[3]['acc_side'], $rows[3]['bal_side'], $rows[3]['modify_sign'], $rows[3]['sort_order'],
        ]);
    }

    public function testDriftAccountsMatchChartSeed(): void
    {
        $seed = JsoncParser::parseFile(self::CHART_SEED);
        $byNumber = [];
        foreach ($seed as $entry) {
            $byNumber[(string) $entry['number']] = $entry;
        }

        foreach (ClearingInfrastructureProvisioner::ACCOUNTS as $acc) {
            $number = $acc['number'];
            $this->assertArrayHasKey($number, $byNumber, "Účet {$number} chybí v accountChartDefault.jsonc");
            $seedEntry = $byNumber[$number];
            $this->assertSame($seedEntry['name'], $acc['name'], "name pro {$number} se rozešel se seedem");
            $this->assertSame($seedEntry['short_name'], $acc['short_name'], "short_name pro {$number} se rozešel se seedem");
            $this->assertSame((int) $seedEntry['account_kind'], $acc['account_kind'], "account_kind pro {$number} se rozešel se seedem");
        }
    }

    public function testDriftGroupMatchesBalancesSeed(): void
    {
        $seed = JsoncParser::parseFile(self::BALANCES_SEED);
        $seedGroup = null;
        foreach ($seed as $group) {
            if (($group['code'] ?? null) === 'unmatched_payments') {
                $seedGroup = $group;
                break;
            }
        }
        $this->assertNotNull($seedGroup, 'Skupina unmatched_payments chybí v balancesDefault.cz.jsonc');

        $const = ClearingInfrastructureProvisioner::GROUP;
        $this->assertSame($seedGroup['code'], $const['code']);
        $this->assertSame($seedGroup['name'], $const['name']);
        $this->assertSame($seedGroup['short_name'], $const['short_name']);
        $this->assertSame((int) $seedGroup['sort_order'], $const['sort_order']);

        $this->assertCount(count($const['accounts']), $seedGroup['accounts'], 'Počet řádků skupiny se rozešel se seedem');
        foreach ($const['accounts'] as $i => $row) {
            $seedRow = $seedGroup['accounts'][$i];
            $this->assertSame($seedRow['account_number'], $row['account_number'], "řádek {$i}: account_number");
            $this->assertSame((int) $seedRow['acc_side'], $row['acc_side'], "řádek {$i}: acc_side");
            $this->assertSame((int) $seedRow['amounts_sign'], $row['amounts_sign'], "řádek {$i}: amounts_sign");
            $this->assertSame((int) $seedRow['bal_side'], $row['bal_side'], "řádek {$i}: bal_side");
            $this->assertSame((bool) $seedRow['modify_sign'], $row['modify_sign'], "řádek {$i}: modify_sign");
        }
    }
}
