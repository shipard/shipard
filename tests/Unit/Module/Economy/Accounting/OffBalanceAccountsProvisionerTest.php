<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\OffBalanceAccountsProvisioner;

/**
 * Podrozvahové účty (#79 D2): bezpodmínečný provisioner pro migrované DS
 * (756100 / 799100 + syntetiky) a jednorázová oprava povahy 75–79
 * (kind 0 → 6). Recording mock jako u TransitAccountsProvisionerTest;
 * UPDATE povahy se simuluje nad uloženými řádky.
 */
class OffBalanceAccountsProvisionerTest extends TestCase
{
    /** @param list<array<string, mixed>> $existingAccounts */
    private function recordingDb(array $existingAccounts = []): object
    {
        $store = new \stdClass();
        $store->accounts = $existingAccounts;
        $store->autoIncrement = count($existingAccounts);
        $store->affected = 0;
        $store->updates = [];

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
        $db->method('execute')->willReturnCallback(
            function (string $sql, mixed ...$params) use ($store): void {
                $store->updates[] = ['sql' => $sql, 'params' => $params];
                $this->assertStringContainsString('UPDATE economy_accounting_accounts SET account_kind = %i', $sql);
                $this->assertStringContainsString('account_kind = 0', $sql);
                [$kind, $from, $to] = $params;
                $store->affected = 0;
                foreach ($store->accounts as $i => $row) {
                    $prefix = substr((string) $row['number'], 0, 2);
                    // SQL `account_kind = 0`: NULL nevyhoví (stará hodnota `99 ---`).
                    if ($prefix >= $from && $prefix <= $to && isset($row['account_kind']) && (int) $row['account_kind'] === 0) {
                        $store->accounts[$i]['account_kind'] = $kind;
                        $store->affected++;
                    }
                }
            }
        );
        $db->method('getAffectedRows')->willReturnCallback(fn(): int => $store->affected);

        $store->db = $db;
        return $store;
    }

    public function testEmptyChartCreatesSyntheticsAndAnalytics(): void
    {
        $store = $this->recordingDb();

        $result = (new OffBalanceAccountsProvisioner($store->db))->provision();

        $this->assertSame(['created' => 6, 'existing' => 0, 'kindFixed' => 0], $result);
        $this->assertSame(['75', '756', '756100', '79', '799', '799100'], array_column($store->accounts, 'number'));

        $group = $store->accounts[0];
        $this->assertSame(2, $group['account_level'], 'dvouznakové číslo = skupina');

        $proformas = $store->accounts[2];
        $this->assertSame(4, $proformas['account_level']);
        $this->assertSame('7', $proformas['g1']);
        $this->assertSame('75', $proformas['g2']);
        $this->assertSame('756', $proformas['g3']);
        $this->assertSame(6, $proformas['account_kind'], 'podrozvaha');
        $this->assertSame(1, $proformas['is_system']);
        $this->assertSame(40, $proformas['docState']);
        $this->assertSame(3, $proformas['docStateMain']);

        $this->assertSame([6], array_values(array_unique(array_column($store->accounts, 'account_kind'))));
    }

    public function testMigratedChartWithSyntheticsOnlyGetsAnalyticsAndKindFix(): void
    {
        // Starý DS: syntetiky 75 a 79 s povahou 0 (Aktiva), žádné analytiky;
        // 7 (kind 4) a 701100 mimo rozsah zůstávají.
        $store = $this->recordingDb([
            ['id' => 1, 'number' => '7',      'name' => 'Závěrkové a podrozvahové účty', 'account_kind' => 4],
            ['id' => 2, 'number' => '701100', 'name' => 'Počáteční účet rozvažný',       'account_kind' => 4],
            ['id' => 3, 'number' => '75',     'name' => 'Podrozvahové účty',             'account_kind' => 0],
            ['id' => 4, 'number' => '79',     'name' => 'Podrozvahové účty',             'account_kind' => 0],
            ['id' => 5, 'number' => '751001', 'name' => 'Najatý majetek',                'account_kind' => 0],
        ]);

        $result = (new OffBalanceAccountsProvisioner($store->db))->provision();

        $this->assertSame(['created' => 4, 'existing' => 2, 'kindFixed' => 3], $result);
        $this->assertSame(
            ['7', '701100', '75', '79', '751001', '756', '756100', '799', '799100'],
            array_column($store->accounts, 'number'),
        );
        $byNumber = array_column($store->accounts, null, 'number');
        $this->assertSame(6, $byNumber['75']['account_kind'], 'syntetika 75: 0 → 6');
        $this->assertSame(6, $byNumber['79']['account_kind']);
        $this->assertSame(6, $byNumber['751001']['account_kind'], 'analytika 75x: 0 → 6');
        $this->assertSame(4, $byNumber['7']['account_kind'], 'třída 7 mimo rozsah 75–79');
        $this->assertSame(4, $byNumber['701100']['account_kind'], 'účty 70x mimo rozsah');
        $this->assertSame('Podrozvahové účty', $byNumber['75']['name'], 'existující účet se nepřepisuje');
    }

    public function testOtherKindsOnOffBalanceAccountsAreKept(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'number' => '75',     'account_kind' => 6],
            ['id' => 2, 'number' => '756100', 'account_kind' => 5],
            ['id' => 3, 'number' => '771001', 'account_kind' => null],
        ]);

        $result = (new OffBalanceAccountsProvisioner($store->db))->provision();

        $this->assertSame(0, $result['kindFixed'], 'jen kind 0 je chyba; 5, 6 i NULL zůstávají');
        $byNumber = array_column($store->accounts, null, 'number');
        $this->assertSame(5, $byNumber['756100']['account_kind']);
        $this->assertNull($byNumber['771001']['account_kind']);
    }

    public function testSecondRunIsIdempotentAndKeepsUserRenamedAccount(): void
    {
        $store = $this->recordingDb([
            ['id' => 1, 'number' => '75',     'name' => 'Podrozvahové účty',    'account_kind' => 0],
            ['id' => 2, 'number' => '756100', 'name' => 'Proformy (naše)',      'account_kind' => 6, 'docState' => 70],
        ]);

        $provisioner = new OffBalanceAccountsProvisioner($store->db);
        $this->assertSame(['created' => 4, 'existing' => 2, 'kindFixed' => 1], $provisioner->provision());
        $this->assertSame(['created' => 0, 'existing' => 6, 'kindFixed' => 0], $provisioner->provision());

        $this->assertCount(6, $store->accounts);
        $this->assertSame('Proformy (naše)', $store->accounts[1]['name'], 'uživatelský název ani archiv se nepřepisují');
        $this->assertCount(2, $store->updates, 'oprava povahy běží při každém provisionu, po prvním běhu je no-op');
    }
}
