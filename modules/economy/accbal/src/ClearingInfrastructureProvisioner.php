<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accbal;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Accounting\AccountDocument;

/**
 * Bezpodmínečně zajistí clearing infrastrukturu modulů bank/accbal:
 *   - účty 261200 / 261300 v economy_accounting_accounts
 *   - saldo skupinu `unmatched_payments` (+ 2 řádky) v economy_accbal_*
 *
 * Na rozdíl od AccountChartProvisioner / BalancesProvisioner NEČTE seed file —
 * definice jsou inline konstanty, protože jsou to fakticky enginový kontrakt
 * (maska 261200/261300 v accountingRules, kód `unmatched_payments` natvrdo
 * v ClearingRouter). Drift proti seedům hlídá unit test.
 *
 * Idempotence: účty dle `number`, skupina dle `code` (jakýkoli stav, vč.
 * archivu/koše) — vzor AccountChartProvisioner / BalancesProvisioner. Volá se
 * z DsUpgradeCommand bezpodmínečně (i pod skipProvisioning), protože clearing
 * účty/skupina nemají na migrované staré straně protějšek a bez nich bankovní
 * engine spadne na account_not_found a přeúčtování clearingu (ClearingRouter)
 * najde nula kandidátů.
 *
 * Konstanty jsou public záměrně — jsou součást kontraktu a čte je drift test.
 */
class ClearingInfrastructureProvisioner
{
    /** @var list<array{number: string, name: string, short_name: string, account_kind: int}> */
    public const ACCOUNTS = [
        ['number' => '261200', 'name' => 'Nespárované platby — příjmy', 'short_name' => 'Nespárované příjmy', 'account_kind' => 0],
        ['number' => '261300', 'name' => 'Nespárované platby — výdaje', 'short_name' => 'Nespárované výdaje', 'account_kind' => 0],
    ];

    public const GROUP = [
        'code'       => 'unmatched_payments',
        'name'       => 'Nespárované platby',
        'short_name' => 'Nespárované',
        'sort_order' => 50,
        'accounts'   => [
            ['account_number' => '261200', 'acc_side' => 1, 'amounts_sign' => 1, 'bal_side' => 1, 'modify_sign' => false, 'note' => 'Nespárovaný příjem (clearing)'],
            ['account_number' => '261300', 'acc_side' => 0, 'amounts_sign' => 1, 'bal_side' => 1, 'modify_sign' => false, 'note' => 'Nespárovaný výdaj (clearing)'],
        ],
    ];

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /**
     * @return array{accounts: array{created: int, existing: int}, group: array{created: int, existing: int}}
     */
    public function provision(): array
    {
        return [
            'accounts' => $this->provisionAccounts(),
            'group'    => $this->provisionGroup(),
        ];
    }

    /** @return array{created: int, existing: int} */
    private function provisionAccounts(): array
    {
        $created = 0;
        $existing = 0;

        foreach (self::ACCOUNTS as $entry) {
            $number = $entry['number'];
            $row = $this->db->fetchRow(
                'SELECT id FROM economy_accounting_accounts WHERE number = %s',
                $number,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $structure = AccountDocument::deriveStructure($number);
            $this->db->insertRow('economy_accounting_accounts', [
                'number'        => $number,
                'name'          => $entry['name'],
                'short_name'    => $entry['short_name'],
                'account_level' => $structure['account_level'],
                'g1'            => $structure['g1'],
                'g2'            => $structure['g2'],
                'g3'            => $structure['g3'],
                'account_kind'  => $entry['account_kind'],
                'is_system'     => 1,
                'docState'      => 40,
                'docStateMain'  => 3,
            ]);
            $created++;
        }

        return ['created' => $created, 'existing' => $existing];
    }

    /** @return array{created: int, existing: int} */
    private function provisionGroup(): array
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accbal_balances WHERE code = %s',
            self::GROUP['code'],
        );
        if ($row !== null) {
            return ['created' => 0, 'existing' => 1];
        }

        $balanceId = $this->db->insertRow('economy_accbal_balances', [
            'code'         => self::GROUP['code'],
            'name'         => self::GROUP['name'],
            'short_name'   => self::GROUP['short_name'],
            'sort_order'   => self::GROUP['sort_order'],
            'docState'     => 40,
            'docStateMain' => 3,
        ]);

        $accSort = 0;
        foreach (self::GROUP['accounts'] as $acc) {
            $accSort += 10;
            $this->db->insertRow('economy_accbal_balance_accounts', [
                'balance'        => $balanceId,
                'account_number' => $acc['account_number'],
                'acc_side'       => $acc['acc_side'],
                'amounts_sign'   => $acc['amounts_sign'],
                'bal_side'       => $acc['bal_side'],
                'modify_sign'    => $acc['modify_sign'] ? 1 : 0,
                'note'           => $acc['note'],
                'sort_order'     => $accSort,
                'docState'       => 40,
                'docStateMain'   => 3,
            ]);
        }

        return ['created' => 1, 'existing' => 0];
    }
}
