<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\TransitAccountsProvisioner;

/**
 * Tranzitní účty peněz na cestě (#59 Task E, E1): bezpodmínečný provisioner
 * pro migrované DS. Recording mock jako u ClearingInfrastructureProvisionerTest.
 */
class TransitAccountsProvisionerTest extends TestCase
{
    /** @param list<array<string, mixed>> $existingAccounts */
    private function recordingDb(array $existingAccounts = []): object
    {
        $store = new \stdClass();
        $store->accounts = $existingAccounts;
        $store->autoIncrement = count($existingAccounts);

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): ?array {
                $needle = (string) ($params[0] ?? '');
                foreach ($store->accounts as $row) {
                    if ((string) ($row['number'] ?? '') === $needle) {
                        return $row;
                    }
                }
                return null;
            }
        );
        $db->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use ($store): int {
                $this->assertSame('economy_accounting_accounts', $table);
                $store->autoIncrement++;
                $data['id'] = $store->autoIncrement;
                $store->accounts[] = $data;
                return $store->autoIncrement;
            }
        );

        $store->db = $db;
        return $store;
    }

    public function testEmptyChartCreatesSyntheticAndTransferAnalytic(): void
    {
        $store = $this->recordingDb();

        $result = (new TransitAccountsProvisioner($store->db))->provision();

        // 261400 (karty na cestě) od #72 D1 nezakládá — karty jdou na 311 za plátcem.
        $this->assertSame(['created' => 2, 'existing' => 0], $result);
        $this->assertSame(['261', '261100'], array_column($store->accounts, 'number'));

        $synthetic = $store->accounts[0];
        $this->assertSame(3, $synthetic['account_level'], 'tříznakové číslo = syntetika');
        $this->assertSame('261', $synthetic['g3']);

        $transfers = $store->accounts[1];
        $this->assertSame(4, $transfers['account_level']);
        $this->assertSame('2', $transfers['g1']);
        $this->assertSame('26', $transfers['g2']);
        $this->assertSame('261', $transfers['g3']);
        $this->assertSame(0, $transfers['account_kind']);
        $this->assertSame(1, $transfers['is_system']);
        $this->assertSame(40, $transfers['docState']);
        $this->assertSame(3, $transfers['docStateMain']);
    }

    public function testMigratedChartWithSyntheticOnlyGetsTransferAnalytic(): void
    {
        // msi: 261 + staré 261001/261002, žádné 261100
        $store = $this->recordingDb([
            ['id' => 1, 'number' => '261',    'name' => 'Peníze na cestě'],
            ['id' => 2, 'number' => '261001', 'name' => 'Peníze na cestě 1'],
            ['id' => 3, 'number' => '261002', 'name' => 'Peníze na cestě 2'],
        ]);

        $result = (new TransitAccountsProvisioner($store->db))->provision();

        $this->assertSame(['created' => 1, 'existing' => 1], $result);
        $this->assertSame(
            ['261', '261001', '261002', '261100'],
            array_column($store->accounts, 'number'),
        );
    }

    public function testSecondRunIsIdempotentAndKeepsUserRenamedAccount(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'number' => '261',    'name' => 'Peníze na cestě'],
            ['id' => 2, 'number' => '261100', 'name' => 'Převody hotovosti (naše)', 'docState' => 70],
            ['id' => 3, 'number' => '261400', 'name' => 'Terminál',                 'docState' => 40],
        ]);

        $provisioner = new TransitAccountsProvisioner($store->db);
        $this->assertSame(['created' => 0, 'existing' => 2], $provisioner->provision());
        $this->assertSame(['created' => 0, 'existing' => 2], $provisioner->provision());

        $this->assertCount(3, $store->accounts);
        $this->assertSame('Převody hotovosti (naše)', $store->accounts[1]['name'], 'uživatelský název ani archiv se nepřepisují');
        $this->assertSame('Terminál', $store->accounts[2]['name'], 'stará 261400 zůstává, provisioner ji neřeší');
    }
}
