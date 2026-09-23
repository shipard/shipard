<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Accounting;

use Shipard\Core\Database\DataSourceConnection;

/**
 * Bezpodmínečně zajistí podrozvahové účty, na které míří účtovací předpis
 * pevnou maskou (#79 D2):
 *   - 756100 proformas.out — vydané zálohové faktury (předpis proformy)
 *   - 799100 offbalance.contra — evidenční protiúčet podrozvahy (společný
 *     pro budoucí podrozvahové evidence)
 *   - syntetiky 75 / 756 / 79 / 799, když v rozvrhu chybí
 *
 * Vzor TransitAccountsProvisioner: definice inline (enginový kontrakt —
 * masky 756/799 v accountingRules), NEČTE seed osnovy; drift proti seedům
 * hlídá OffBalanceAccountingRulesTest. Volá se z DsUpgradeCommand
 * bezpodmínečně, i pod skipProvisioning — migrovaný rozvrh má zpravidla jen
 * syntetiky 75 a 79 bez analytik a potvrzená proforma by skončila chybovým
 * řádkem 756???.
 *
 * Navíc jednorázová oprava povahy: podrozvahový účet (skupiny 75–79) s
 * account_kind 0 (Aktiva) je vždy chyba — seed osnovy ji měl do #79 také,
 * migrované rozvrhy ji mají ze starého systému. Kind 0 → 6 (Podrozvaha)
 * na všech úrovních (syntetiky i analytiky); jiné hodnoty se nemění.
 *
 * Idempotence dle `number` (jakýkoli stav, vč. archivu/koše) — existující
 * účet, i uživatelsky přejmenovaný, se nepřepisuje; oprava povahy po
 * prvním běhu nenajde žádný řádek.
 */
class OffBalanceAccountsProvisioner
{
    /** Povaha účtu 6 = Podrozvaha (cfgItem economy.accounting.accountKinds). */
    public const KIND_OFF_BALANCE = 6;

    /** @var list<array{number: string, name: string, short_name: string, account_kind: int}> */
    public const ACCOUNTS = [
        ['number' => '75',     'name' => 'Podrozvahové účty',              'short_name' => 'Podrozvahové účty',              'account_kind' => self::KIND_OFF_BALANCE],
        ['number' => '756',    'name' => 'Vydané zálohové faktury',        'short_name' => 'Vydané zálohové faktury',        'account_kind' => self::KIND_OFF_BALANCE],
        ['number' => '756100', 'name' => 'Vydané zálohové faktury',        'short_name' => 'Vydané zálohové faktury',        'account_kind' => self::KIND_OFF_BALANCE],
        ['number' => '79',     'name' => 'Podrozvahové účty',              'short_name' => 'Podrozvahové účty',              'account_kind' => self::KIND_OFF_BALANCE],
        ['number' => '799',    'name' => 'Evidenční protiúčet podrozvahy', 'short_name' => 'Evidenční protiúčet podrozvahy', 'account_kind' => self::KIND_OFF_BALANCE],
        ['number' => '799100', 'name' => 'Evidenční protiúčet podrozvahy', 'short_name' => 'Evidenční protiúčet podrozvahy', 'account_kind' => self::KIND_OFF_BALANCE],
    ];

    public function __construct(
        private readonly DataSourceConnection $db,
    ) {}

    /** @return array{created: int, existing: int, kindFixed: int} */
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

        return ['created' => $created, 'existing' => $existing, 'kindFixed' => $this->fixKinds()];
    }

    /**
     * Kind 0 → 6 na účtech skupin 75–79 (LEFT(number, 2); syntetika třídy
     * `7` má jen jeden znak, takže mimo rozsah). Jediný UPDATE, vrací počet
     * opravených řádků; po prvním běhu 0.
     */
    private function fixKinds(): int
    {
        $this->db->execute(
            'UPDATE economy_accounting_accounts SET account_kind = %i
             WHERE LEFT(number, 2) BETWEEN %s AND %s AND account_kind = 0',
            self::KIND_OFF_BALANCE,
            '75',
            '79',
        );
        return $this->db->getAffectedRows();
    }
}
