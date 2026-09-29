<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Assets\AccountingGroupsProvisioner;

/**
 * Seed účetních skupin majetku: založení, idempotence podle kódu,
 * přeskočení skupiny s chybějícím účtem.
 */
class AccountingGroupsProvisionerTest extends TestCase
{
    private const SEED = [
        ['code' => '021', 'name' => 'Stavby', 'sort_order' => 10, 'asset' => '021100', 'acquisition' => '042100', 'accumulated' => '081100', 'depreciation' => '551100', 'disposal' => '541100'],
        ['code' => '031', 'name' => 'Pozemky', 'sort_order' => 60, 'asset' => '031100', 'acquisition' => '042100', 'disposal' => '541100'],
    ];

    private const ACCOUNTS = ['021100' => 1, '042100' => 2, '081100' => 3, '551100' => 4, '541100' => 5, '031100' => 6];

    private string $seedFile;

    protected function setUp(): void
    {
        $this->seedFile = sys_get_temp_dir() . '/shpd_assetgroups_' . uniqid() . '.jsonc';
        file_put_contents($this->seedFile, json_encode(self::SEED));
    }

    protected function tearDown(): void
    {
        if (is_file($this->seedFile)) {
            unlink($this->seedFile);
        }
    }

    /**
     * @param array<string, int> $accounts číslo účtu → id
     * @param list<array<string, mixed>> $existingGroups
     */
    private function store(array $accounts = self::ACCOUNTS, array $existingGroups = []): object
    {
        $store = new \stdClass();
        $store->groups = $existingGroups;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store, $accounts): ?array {
                $needle = (string) ($params[0] ?? '');
                if (str_contains($sql, 'economy_assets_accounting_groups')) {
                    foreach ($store->groups as $g) {
                        if ($g['code'] === $needle) {
                            return $g;
                        }
                    }
                    return null;
                }
                if (str_contains($sql, 'economy_accounting_accounts')) {
                    return isset($accounts[$needle]) ? ['id' => $accounts[$needle]] : null;
                }
                return null;
            },
        );
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $data['id'] = count($store->groups) + 1;
                $store->groups[] = $data;
                return $data['id'];
            },
        );
        $store->db = $db;
        return $store;
    }

    public function testEmptyDsCreatesAllGroupsWithResolvedAccounts(): void
    {
        $store = $this->store();
        $result = new AccountingGroupsProvisioner($store->db, $this->seedFile)->provision();

        $this->assertSame(2, $result['accountingGroups']['created']);
        $this->assertSame(0, $result['accountingGroups']['existing']);
        $this->assertSame([], $result['accountingGroups']['skipped']);

        $stavby = $store->groups[0];
        $this->assertSame('021', $stavby['code']);
        $this->assertSame(1, $stavby['account_asset']);
        $this->assertSame(2, $stavby['account_acquisition']);
        $this->assertSame(3, $stavby['account_accumulated']);
        $this->assertSame(4, $stavby['account_depreciation']);
        $this->assertSame(5, $stavby['account_disposal']);
        $this->assertSame(40, $stavby['docState']);
        $this->assertSame(3, $stavby['docStateMain']);

        $pozemky = $store->groups[1];
        $this->assertSame(6, $pozemky['account_asset']);
        $this->assertNull($pozemky['account_accumulated']);
        $this->assertNull($pozemky['account_depreciation']);
    }

    public function testSecondRunIsNoOp(): void
    {
        $store = $this->store();
        $provisioner = new AccountingGroupsProvisioner($store->db, $this->seedFile);
        $provisioner->provision();
        $result = $provisioner->provision();

        $this->assertSame(0, $result['accountingGroups']['created']);
        $this->assertSame(2, $result['accountingGroups']['existing']);
        $this->assertCount(2, $store->groups);
    }

    public function testExistingGroupIsNotTouchedEvenWhenArchived(): void
    {
        $store = $this->store(existingGroups: [['id' => 9, 'code' => '021', 'docState' => 70]]);
        $result = new AccountingGroupsProvisioner($store->db, $this->seedFile)->provision();

        $this->assertSame(1, $result['accountingGroups']['created']);
        $this->assertSame(1, $result['accountingGroups']['existing']);
        $this->assertSame('031', $store->groups[1]['code']);
    }

    public function testGroupWithMissingAccountIsSkipped(): void
    {
        $accounts = self::ACCOUNTS;
        unset($accounts['081100']);
        $store = $this->store($accounts);
        $result = new AccountingGroupsProvisioner($store->db, $this->seedFile)->provision();

        $this->assertSame(1, $result['accountingGroups']['created']);
        $this->assertSame([['code' => '021', 'missing' => ['081100']]], $result['accountingGroups']['skipped']);
        $this->assertSame(['031'], array_column($store->groups, 'code'));
    }

    public function testInvalidSeedEntryThrows(): void
    {
        file_put_contents($this->seedFile, json_encode([['name' => 'bez kódu']]));
        $this->expectException(\RuntimeException::class);
        new AccountingGroupsProvisioner($this->store()->db, $this->seedFile)->provision();
    }
}
