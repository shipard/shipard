<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;

/**
 * Idempotentní seed účetních skupin majetku (docs/assets.md D24).
 *
 * Pro každý záznam seedu (`config/accountingGroups.jsonc`):
 *   - existuje-li skupina se stejným `code` (libovolný stav), nic neděláme
 *     — uživatel si ji mohl upravit nebo zarchivovat;
 *   - jinak dohledáme každý vyplněný účet podle čísla mezi aktivními
 *     analytickými účty osnovy; chybí-li kterýkoli, skupinu přeskočíme
 *     (vrací se v `skipped` a ds-upgrade ji vypíše pod -v) — nikdy se
 *     nezakládá skupina s dírou v účtech;
 *   - jinak INSERT ve stavu 40 (V pořádku).
 *
 * Běží jen ve větvi bez `skipProvisioning` (DsUpgradeCommand) — migrované
 * DS dostanou skupiny importem (Fáze 6). Vzor: AccountChartProvisioner.
 */
class AccountingGroupsProvisioner
{
    /** Klíč seedu → sloupec tabulky. */
    private const ACCOUNT_COLUMNS = [
        'asset'        => 'account_asset',
        'acquisition'  => 'account_acquisition',
        'accumulated'  => 'account_accumulated',
        'depreciation' => 'account_depreciation',
        'disposal'     => 'account_disposal',
    ];

    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly string $seedFilePath,
    ) {}

    /**
     * @return array{accountingGroups: array{created: int, existing: int, skipped: list<array{code: string, missing: list<string>}>}}
     */
    public function provision(): array
    {
        $seed = JsoncParser::parseFile($this->seedFilePath);
        if (!is_array($seed)) {
            throw new \RuntimeException('Asset accounting groups seed file must contain a JSON array: ' . $this->seedFilePath);
        }

        $created = 0;
        $existing = 0;
        $skipped = [];

        foreach ($seed as $entry) {
            if (!is_array($entry) || trim((string) ($entry['code'] ?? '')) === '' || trim((string) ($entry['name'] ?? '')) === '') {
                throw new \RuntimeException('Invalid asset accounting group seed entry — missing code or name');
            }
            $code = trim((string) $entry['code']);

            $row = $this->db->fetchRow(
                'SELECT id FROM economy_assets_accounting_groups WHERE code = %s',
                $code,
            );
            if ($row !== null) {
                $existing++;
                continue;
            }

            $values = [
                'code'         => $code,
                'name'         => trim((string) $entry['name']),
                'note'         => isset($entry['note']) ? (string) $entry['note'] : null,
                'sort_order'   => (int) ($entry['sort_order'] ?? 0),
                'docState'     => 40,
                'docStateMain' => 3,
            ];

            $missing = [];
            foreach (self::ACCOUNT_COLUMNS as $seedKey => $column) {
                $number = trim((string) ($entry[$seedKey] ?? ''));
                if ($number === '') {
                    $values[$column] = null;
                    continue;
                }
                $accountId = $this->findAccountId($number);
                if ($accountId === null) {
                    $missing[] = $number;
                    continue;
                }
                $values[$column] = $accountId;
            }

            if ($missing !== []) {
                $skipped[] = ['code' => $code, 'missing' => array_values(array_unique($missing))];
                continue;
            }

            $this->db->insertRow('economy_assets_accounting_groups', $values);
            $created++;
        }

        return ['accountingGroups' => ['created' => $created, 'existing' => $existing, 'skipped' => $skipped]];
    }

    /** Id aktivního analytického účtu s daným číslem, null = v osnově není. */
    private function findAccountId(string $number): ?int
    {
        $row = $this->db->fetchRow(
            'SELECT id FROM economy_accounting_accounts'
            . ' WHERE number = %s AND account_level = 4 AND docState IN (10, 40, 80)',
            $number,
        );
        return $row === null ? null : (int) $row['id'];
    }
}
