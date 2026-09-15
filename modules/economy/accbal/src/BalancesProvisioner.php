<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní seed standardních saldokont (skupiny + jejich účty) do
 * economy_accbal_balances / economy_accbal_balance_accounts.
 *
 * Idempotence dle `code` skupiny: existuje-li balance se stejným `code`
 * (libovolný stav, vč. archivu/koše), skupina ani její účty se **nepřepisují**
 * — uživatel si je mohl upravit. Doplní se jen účty seedu, které ve skupině
 * chybí (klíč account_number + acc_side + bal_side + modify_sign; #72 D6
 * přidal 315 do Pohledávek a existující DS ho potřebují dostat). Nová
 * skupina = INSERT skupiny (docState 40 / docStateMain 3) + INSERT účtů.
 *
 * Volá se z DsUpgradeCommand. Vzor: AccountChartProvisioner.
 */
class BalancesProvisioner
{
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $seedFilePath,
    ) {}

    /**
     * @param bool $createGroups false = jen doplnit chybějící účty do existujících
     *        skupin, nové skupiny nezakládat (DS se `skipProvisioning` — migrovaná
     *        data si skupiny řídí sama, ale 315 v Pohledávkách je enginový kontrakt).
     * @return array{balances: array{created: int, existing: int}, accounts: array{added: int}}
     */
    public function provision(bool $createGroups = true): array
    {
        $seed = JsoncParser::parseFile($this->seedFilePath);
        if (!is_array($seed)) {
            throw new \RuntimeException('Balances seed file must contain a JSON array: ' . $this->seedFilePath);
        }

        $created = 0;
        $existing = 0;
        $addedAccounts = 0;

        foreach ($seed as $group) {
            if (!is_array($group) || !isset($group['code']) || trim((string) $group['code']) === '') {
                throw new \RuntimeException('Invalid balances seed entry — missing code');
            }

            $code = trim((string) $group['code']);
            $accounts = is_array($group['accounts'] ?? null) ? $group['accounts'] : [];
            $row = $this->db->fetchRow(
                'SELECT id FROM economy_accbal_balances WHERE code = %s',
                $code,
            );
            if ($row !== null) {
                $existing++;
                $addedAccounts += $this->addMissingAccounts((int) $row['id'], $code, $accounts);
                continue;
            }
            if (!$createGroups) {
                continue;
            }

            $balanceId = $this->db->insertRow('economy_accbal_balances', [
                'code'               => $code,
                'name'               => (string) ($group['name'] ?? $code),
                'short_name'         => isset($group['short_name']) ? (string) $group['short_name'] : null,
                'sort_order'         => (int) ($group['sort_order'] ?? 0),
                'show_in_navigation' => !empty($group['show_in_navigation']) ? 1 : 0,
                'docState'           => 40,
                'docStateMain'       => 3,
            ]);

            $accSort = 0;
            foreach ($accounts as $acc) {
                $accSort += 10;
                $this->insertAccount($balanceId, $code, $acc, $accSort);
            }

            $created++;
        }

        return [
            'balances' => ['created' => $created, 'existing' => $existing],
            'accounts' => ['added' => $addedAccounts],
        ];
    }

    /**
     * Do existující skupiny doplní účty seedu, které v ní chybí. Existující
     * řádky (i uživatelsky upravené) se nemění; klíč je věcný —
     * (account_number, acc_side, bal_side, modify_sign), znaménko částek
     * do klíče nepatří (uživatel ho mohl přenastavit).
     *
     * @param list<array<string, mixed>> $seedAccounts
     * @return int počet doplněných účtů
     */
    private function addMissingAccounts(int $balanceId, string $code, array $seedAccounts): int
    {
        $existingKeys = [];
        $maxSort = 0;
        foreach ($this->db->fetchAll(
            'SELECT account_number, acc_side, bal_side, modify_sign, sort_order
             FROM economy_accbal_balance_accounts WHERE balance = %i',
            $balanceId,
        ) as $row) {
            $existingKeys[$this->accountKey($row)] = true;
            $maxSort = max($maxSort, (int) ($row['sort_order'] ?? 0));
        }

        $added = 0;
        foreach ($seedAccounts as $acc) {
            if (!is_array($acc) || trim((string) ($acc['account_number'] ?? '')) === '') {
                throw new \RuntimeException("Invalid account entry in balance '{$code}' — missing account_number");
            }
            if (isset($existingKeys[$this->accountKey($acc)])) {
                continue;
            }
            $maxSort += 10;
            $this->insertAccount($balanceId, $code, $acc, $maxSort);
            $existingKeys[$this->accountKey($acc)] = true;
            $added++;
        }
        return $added;
    }

    /** @param array<string, mixed> $acc */
    private function accountKey(array $acc): string
    {
        return implode('|', [
            trim((string) ($acc['account_number'] ?? '')),
            (int) ($acc['acc_side'] ?? 0),
            (int) ($acc['bal_side'] ?? 0),
            !empty($acc['modify_sign']) ? 1 : 0,
        ]);
    }

    /** @param array<string, mixed> $acc */
    private function insertAccount(int $balanceId, string $code, mixed $acc, int $defaultSort): void
    {
        if (!is_array($acc) || trim((string) ($acc['account_number'] ?? '')) === '') {
            throw new \RuntimeException("Invalid account entry in balance '{$code}' — missing account_number");
        }
        $this->db->insertRow('economy_accbal_balance_accounts', [
            'balance'        => $balanceId,
            'account_number' => trim((string) $acc['account_number']),
            'acc_side'       => (int) ($acc['acc_side'] ?? 0),
            'amounts_sign'   => (int) ($acc['amounts_sign'] ?? 0),
            'bal_side'       => (int) ($acc['bal_side'] ?? 0),
            'modify_sign'    => !empty($acc['modify_sign']) ? 1 : 0,
            'note'           => isset($acc['note']) ? (string) $acc['note'] : null,
            'sort_order'     => (int) ($acc['sort_order'] ?? $defaultSort),
            'docState'       => 40,
            'docStateMain'   => 3,
        ]);
    }
}
