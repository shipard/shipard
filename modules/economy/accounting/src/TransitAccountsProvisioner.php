<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Bezpodmínečně zajistí tranzitní účty peněz na cestě, na které míří účtovací
 * předpis pevnou maskou (#59 Task D/E):
 *   - 261100 cash.transit — převody peněz pokladna ↔ banka ↔ pokladna
 *   - 261400 card.transit — platby kartou na pokladně / prodejce
 *   - 261 syntetika (nadřazený účet), když v rozvrhu chybí
 *
 * Na rozdíl od AccountChartProvisioner NEČTE seed file — definice jsou inline
 * konstanty, protože jsou to enginový kontrakt (masky v accountingRules).
 * Drift proti seedům hlídá CashAccountingRulesTest. Vzor
 * ClearingInfrastructureProvisioner (accbal): volá se z DsUpgradeCommand
 * bezpodmínečně, i pod skipProvisioning — migrovaný rozvrh (skipProvisioning)
 * tranzitní analytiky zpravidla nemá (staré 261001/261002) a každý převod
 * peněz by skončil chybovým řádkem 261???.
 *
 * Idempotence dle `number` (jakýkoli stav, vč. archivu/koše) — existující
 * účet, i uživatelsky přejmenovaný, se nepřepisuje.
 */
class TransitAccountsProvisioner
{
    /** @var list<array{number: string, name: string, short_name: string, account_kind: int}> */
    public const ACCOUNTS = [
        ['number' => '261',    'name' => 'Peníze na cestě',         'short_name' => 'Peníze na cestě', 'account_kind' => 0],
        ['number' => '261100', 'name' => 'Peníze na cestě',         'short_name' => 'Peníze na cestě', 'account_kind' => 0],
        // 261400 (karty na cestě) provisioner od #72 D1 nezakládá — karty
        // jdou na 311 za plátcem; v seedech osnovy účet zůstává.
    ];

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /** @return array{created: int, existing: int} */
    public function provision(): array
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
}
