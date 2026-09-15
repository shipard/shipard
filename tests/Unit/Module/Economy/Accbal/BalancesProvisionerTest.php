<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accbal;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accbal\BalancesProvisioner;

/**
 * Seed saldokont: nová skupina se založí i s účty; existující skupina se
 * nepřepisuje, jen do ní přibudou účty seedu, které chybí (#72 D6 — 315
 * v Pohledávkách na DS, které skupinu už mají). Recording mock jako
 * ClearingInfrastructureProvisionerTest.
 */
class BalancesProvisionerTest extends TestCase
{
    private const SEED = __DIR__ . '/../../../../../modules/economy/accbal/config/balancesDefault.cz.jsonc';

    /**
     * @param list<array<string, mixed>> $balances
     * @param list<array<string, mixed>> $accounts
     */
    private function recordingDb(array $balances = [], array $accounts = []): object
    {
        $store = new \stdClass();
        $store->balances = $balances;
        $store->accounts = $accounts;
        $store->autoIncrement = 1000;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($store): ?array {
                $code = (string) ($params[0] ?? '');
                foreach ($store->balances as $row) {
                    if ((string) $row['code'] === $code) {
                        return $row;
                    }
                }
                return null;
            },
        );
        $db->method('fetchAll')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($store): array {
                $balanceId = (int) ($params[0] ?? 0);
                return array_values(array_filter($store->accounts, fn(array $a) => (int) $a['balance'] === $balanceId));
            },
        );
        $db->method('insertRow')->willReturnCallback(
            static function (string $table, array $data) use ($store): int {
                $store->autoIncrement++;
                $data['id'] = $store->autoIncrement;
                if ($table === 'economy_accbal_balances') {
                    $store->balances[] = $data;
                } else {
                    $store->accounts[] = $data;
                }
                return $store->autoIncrement;
            },
        );
        $store->db = $db;
        return $store;
    }

    /** @return list<array<string, mixed>> */
    private function seedGroups(): array
    {
        return JsoncParser::parseFile(self::SEED);
    }

    public function testSeedHasReceivables315(): void
    {
        $receivables = array_values(array_filter($this->seedGroups(), fn(array $g) => $g['code'] === 'receivables'))[0];
        $numbers = array_map(fn(array $a) => $a['account_number'] . '/' . $a['acc_side'] . '/' . $a['bal_side'], $receivables['accounts']);
        $this->assertContains('315/0/0', $numbers, '315 MD = předpis (vyúčtování brány)');
        $this->assertContains('315/1/1', $numbers, '315 DAL = úhrada');
    }

    public function testEmptyDsCreatesAllGroupsWithAccounts(): void
    {
        $store = $this->recordingDb();
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision();

        $groups = $this->seedGroups();
        $this->assertSame(count($groups), $result['balances']['created']);
        $this->assertSame(0, $result['balances']['existing']);
        $this->assertSame(0, $result['accounts']['added']);
        $this->assertCount(array_sum(array_map(fn(array $g) => count($g['accounts']), $groups)), $store->accounts);
    }

    public function testExistingGroupGetsMissingAccountsOnly(): void
    {
        // DS se skupinou Pohledávky z doby před #72 (jen 311, uživatel si
        // upravil znaménko a poznámku úhrady) — provisioner doplní jen 315.
        $store = $this->recordingDb(
            [['id' => 1, 'code' => 'receivables', 'name' => 'Pohledávky (naše)']],
            [
                ['id' => 10, 'balance' => 1, 'account_number' => '311', 'acc_side' => 0, 'bal_side' => 0, 'modify_sign' => 0, 'amounts_sign' => 1, 'sort_order' => 10],
                ['id' => 11, 'balance' => 1, 'account_number' => '311', 'acc_side' => 1, 'bal_side' => 1, 'modify_sign' => 0, 'amounts_sign' => 0, 'sort_order' => 20, 'note' => 'upraveno'],
            ],
        );
        $provisioner = new BalancesProvisioner($store->db, self::SEED);
        $result = $provisioner->provision();

        $this->assertSame(1, $result['balances']['existing']);
        $this->assertSame(2, $result['accounts']['added'], 'jen 315 MD a 315 DAL');
        $added = array_values(array_filter($store->accounts, fn(array $a) => (int) $a['balance'] === 1 && (int) ($a['id'] ?? 0) > 1000));
        $this->assertSame(['315', '315'], array_column($added, 'account_number'));
        $this->assertSame([0, 1], array_column($added, 'acc_side'));
        $this->assertSame([30, 40], array_column($added, 'sort_order'), 'řadí se za existující');
        $this->assertSame(40, $added[0]['docState']);
        $this->assertSame('upraveno', $store->accounts[1]['note'], 'existující řádek se nemění');

        // Druhý běh nic nepřidá.
        $result = $provisioner->provision();
        $this->assertSame(0, $result['accounts']['added']);
        $this->assertSame(1 + count($this->seedGroups()) - 1, $result['balances']['created'] + $result['balances']['existing']);
    }

    public function testWithoutCreateGroupsOnlyExistingGroupsGetAccounts(): void
    {
        // skipProvisioning DS: Pohledávky existují (jen 311), ostatní skupiny ne
        // → doplní se 315, žádná nová skupina nevznikne.
        $store = $this->recordingDb(
            [['id' => 1, 'code' => 'receivables']],
            [
                ['id' => 10, 'balance' => 1, 'account_number' => '311', 'acc_side' => 0, 'bal_side' => 0, 'modify_sign' => 0, 'sort_order' => 10],
                ['id' => 11, 'balance' => 1, 'account_number' => '311', 'acc_side' => 1, 'bal_side' => 1, 'modify_sign' => 0, 'sort_order' => 20],
            ],
        );
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision(createGroups: false);

        $this->assertSame(['created' => 0, 'existing' => 1], $result['balances']);
        $this->assertSame(2, $result['accounts']['added']);
        $this->assertCount(1, $store->balances, 'nová skupina nevznikla');
    }

    public function testCreditNoteRowsAreDistinguishedByModifySign(): void
    {
        // Závazky: 311 MD/DAL s modify_sign (dobropisy) — klíč je musí odlišit
        // od řádků bez modify_sign, jinak by se seed pokusil vložit duplicity.
        $payables = array_values(array_filter($this->seedGroups(), fn(array $g) => $g['code'] === 'payables'))[0];
        $existing = [];
        $id = 100;
        foreach ($payables['accounts'] as $acc) {
            $existing[] = ['id' => ++$id, 'balance' => 2] + $acc + ['sort_order' => $id];
        }
        $store = $this->recordingDb([['id' => 2, 'code' => 'payables']], $existing);
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision();

        $this->assertSame(0, $result['accounts']['added'], 'kompletní skupina → nic k doplnění');
    }
}
