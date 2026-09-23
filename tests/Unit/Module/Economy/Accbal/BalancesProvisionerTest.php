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

    // ── #79 D3a: skupina proformas_out s kategorií úhrady ────────────────────

    public function testSeedHasProformasOutWithPaymentAndClosingCategory(): void
    {
        $group = array_values(array_filter($this->seedGroups(), fn(array $g) => $g['code'] === 'proformas_out'))[0] ?? null;
        $this->assertNotNull($group, 'seed má skupinu Zálohové faktury vydané');
        $this->assertSame('advances.received', $group['payment_category'], 'úhrada proformy jde na přijatou zálohu (324)');
        $this->assertSame('offbalance.contra', $group['closing_category'], 'uzavření proti 799 čte až navazující task');
        $this->assertSame(15, $group['sort_order'], 'hned za Pohledávkami (10) — lookup ji pro příjem projde druhou');
        $this->assertSame(1, $group['show_in_navigation']);
        $numbers = array_map(fn(array $a) => $a['account_number'] . '/' . $a['acc_side'] . '/' . $a['bal_side'], $group['accounts']);
        $this->assertSame(['756/0/0', '756/1/1'], $numbers, '756 MD = předpis, 756 DAL = úhrada');
        $this->assertSame([false], array_values(array_unique(array_column($group['accounts'], 'modify_sign'))));
        $this->assertArrayNotHasKey('creditNoteRule', $group['accounts'][0], 'bez sign-pravidel → legacy transformace ji nemění');

        foreach ($this->seedGroups() as $g) {
            if ($g['code'] !== 'proformas_out') {
                $this->assertArrayNotHasKey('payment_category', $g, "{$g['code']}: kategorie úhrady má jen skupina proforem");
                $this->assertArrayNotHasKey('closing_category', $g);
            }
        }
    }

    public function testCreatedGroupCarriesCategoriesInBothVariants(): void
    {
        foreach ([false, true] as $legacy) {
            $store = $this->recordingDb();
            (new BalancesProvisioner($store->db, self::SEED))->provision(legacy: $legacy);

            $byCode = array_column($store->balances, null, 'code');
            $this->assertSame('advances.received', $byCode['proformas_out']['payment_category'], 'legacy=' . var_export($legacy, true));
            $this->assertSame('offbalance.contra', $byCode['proformas_out']['closing_category']);
            $this->assertNull($byCode['receivables']['payment_category'], 'ostatní skupiny bez kategorie = účet předpisu');
            $this->assertNull($byCode['receivables']['closing_category']);

            $rows = array_values(array_filter($store->accounts, fn(array $a) => (int) $a['balance'] === (int) $byCode['proformas_out']['id']));
            $this->assertSame(['756', '756'], array_column($rows, 'account_number'));
            $this->assertSame([$legacy ? 0 : 1, $legacy ? 0 : 1], array_column($rows, 'amounts_sign'), 'legacy = částky Všechny; záporná proforma v datech není');
        }
    }

    public function testExistingGroupKeepsItsCategories(): void
    {
        // Skupina založená bez kategorie (nebo s vlastní) se nepřepisuje.
        $store = $this->recordingDb(
            [['id' => 1, 'code' => 'proformas_out', 'payment_category' => null, 'closing_category' => null]],
            [
                ['id' => 10, 'balance' => 1, 'account_number' => '756', 'acc_side' => 0, 'bal_side' => 0, 'modify_sign' => 0, 'sort_order' => 10],
                ['id' => 11, 'balance' => 1, 'account_number' => '756', 'acc_side' => 1, 'bal_side' => 1, 'modify_sign' => 0, 'sort_order' => 20],
            ],
        );
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision();

        $this->assertSame(0, $result['accounts']['added']);
        $this->assertNull($store->balances[0]['payment_category'], 'existující skupina se nepřepisuje ani v kategoriích');
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
        $this->assertSame(4, $result['accounts']['added'], '315 MD/DAL + zrcadlové 321 dobropisy (D18)');
        $added = array_values(array_filter($store->accounts, fn(array $a) => (int) $a['balance'] === 1 && (int) ($a['id'] ?? 0) > 1000));
        $this->assertSame(['315', '315', '321', '321'], array_column($added, 'account_number'));
        $this->assertSame([0, 1, 1, 0], array_column($added, 'acc_side'));
        $this->assertSame([30, 40, 50, 60], array_column($added, 'sort_order'), 'řadí se za existující');
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
        $this->assertSame(4, $result['accounts']['added']);
        $this->assertCount(1, $store->balances, 'nová skupina nevznikla');
    }

    // ── #69 D18: varianta legacy ─────────────────────────────────────────────

    public function testSeedMarksCreditNoteRulesExactlyOnModifySignRows(): void
    {
        $marked = [];
        foreach ($this->seedGroups() as $g) {
            foreach ($g['accounts'] as $a) {
                $this->assertSame(!empty($a['modify_sign']), BalancesProvisioner::isCreditNoteRule($a), "{$g['code']} {$a['account_number']}");
                if (BalancesProvisioner::isCreditNoteRule($a)) {
                    $marked[] = $g['code'] . ':' . $a['account_number'] . '/' . $a['acc_side'] . '/' . $a['bal_side'];
                }
            }
        }
        $this->assertSame(
            ['receivables:321/1/0', 'receivables:321/0/1', 'payables:311/0/0', 'payables:311/1/1'],
            $marked,
            'sign-pravidla dobropisů zrcadlově v obou hlavních skupinách',
        );
    }

    public function testLegacyCreatesGroupsWithoutCreditNoteRulesAndAllAmounts(): void
    {
        $store = $this->recordingDb();
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision(legacy: true);

        $groups = $this->seedGroups();
        $this->assertSame(count($groups), $result['balances']['created']);
        $expected = 0;
        foreach ($groups as $g) {
            $expected += count(array_filter($g['accounts'], fn(array $a) => empty($a['creditNoteRule'])));
        }
        $this->assertCount($expected, $store->accounts, 'creditNoteRule řádky se nezaložily');
        $this->assertSame([0], array_values(array_unique(array_column($store->accounts, 'modify_sign'))));
        $this->assertSame([0], array_values(array_unique(array_column($store->accounts, 'amounts_sign'))), 'legacy = částky Všechny');
        $this->assertSame(['legacy'], array_values(array_unique(array_column($store->balances, 'provisioning_variant'))));
    }

    public function testDefaultVariantHasNoMarkerAndKeepsPositiveOnlyRows(): void
    {
        $store = $this->recordingDb();
        (new BalancesProvisioner($store->db, self::SEED))->provision();

        $this->assertSame([null], array_values(array_unique(array_column($store->balances, 'provisioning_variant'))));
        $receivables = array_filter($store->accounts, fn(array $a) => $a['account_number'] === '311' && $a['acc_side'] === 0 && $a['bal_side'] === 0 && $a['modify_sign'] === 0);
        $this->assertSame([1], array_values(array_unique(array_column($receivables, 'amounts_sign'))), 'výchozí seed: 311 MD předpis jen kladné');
    }

    public function testLegacyGroupNeverReceivesCreditNoteRules(): void
    {
        // Importovaný DS: Pohledávky založené jako legacy (jen 311), později
        // ds-upgrade bez skipProvisioning — doplní 315 (Všechny), zrcadlové 321 ne.
        $store = $this->recordingDb(
            [['id' => 1, 'code' => 'receivables', 'provisioning_variant' => 'legacy']],
            [
                ['id' => 10, 'balance' => 1, 'account_number' => '311', 'acc_side' => 0, 'bal_side' => 0, 'modify_sign' => 0, 'amounts_sign' => 0, 'sort_order' => 10],
                ['id' => 11, 'balance' => 1, 'account_number' => '311', 'acc_side' => 1, 'bal_side' => 1, 'modify_sign' => 0, 'amounts_sign' => 0, 'sort_order' => 20],
            ],
        );
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision();

        $this->assertSame(2, $result['accounts']['added'], 'jen 315 MD a 315 DAL');
        $added = array_values(array_filter($store->accounts, fn(array $a) => (int) $a['balance'] === 1 && (int) ($a['id'] ?? 0) > 1000));
        $this->assertSame(['315', '315'], array_column($added, 'account_number'));
        $this->assertSame([0, 0], array_column($added, 'amounts_sign'), 'doplněné řádky legacy skupiny berou i záporné částky');
    }

    public function testDefaultGroupReceivesMirroredCreditNoteRules(): void
    {
        // DS s výchozími Pohledávkami z doby před D18 — přibudou zrcadlové 321 řádky.
        $store = $this->recordingDb(
            [['id' => 1, 'code' => 'receivables']],
            [
                ['id' => 10, 'balance' => 1, 'account_number' => '311', 'acc_side' => 0, 'bal_side' => 0, 'modify_sign' => 0, 'amounts_sign' => 1, 'sort_order' => 10],
                ['id' => 11, 'balance' => 1, 'account_number' => '311', 'acc_side' => 1, 'bal_side' => 1, 'modify_sign' => 0, 'amounts_sign' => 1, 'sort_order' => 20],
                ['id' => 12, 'balance' => 1, 'account_number' => '315', 'acc_side' => 0, 'bal_side' => 0, 'modify_sign' => 0, 'amounts_sign' => 1, 'sort_order' => 30],
                ['id' => 13, 'balance' => 1, 'account_number' => '315', 'acc_side' => 1, 'bal_side' => 1, 'modify_sign' => 0, 'amounts_sign' => 1, 'sort_order' => 40],
            ],
        );
        $result = (new BalancesProvisioner($store->db, self::SEED))->provision();

        $this->assertSame(2, $result['accounts']['added']);
        $added = array_values(array_filter($store->accounts, fn(array $a) => (int) $a['balance'] === 1 && (int) ($a['id'] ?? 0) > 1000));
        $this->assertSame(['321', '321'], array_column($added, 'account_number'));
        $this->assertSame([1, 1], array_column($added, 'modify_sign'));
        $this->assertSame([2, 2], array_column($added, 'amounts_sign'), 'jen záporné částky');
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
