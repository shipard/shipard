<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\Assets\AssetJournalCheck;

/**
 * Evidence majetku nesouhlasí s deníkem (docs/assets.md invariant §1,
 * D67): zaúčtované události karty neodpovídají řádkům deníku operací
 * `asset.*` s dimenzí karty. Vzniká zásahem mimo Majetek — přegenerovaný
 * nebo stornovaný doklad, změna účetní skupiny karty po zaúčtování.
 *
 * Kontroluje se stav ke konci předchozího a aktuálního účetního roku
 * (`AssetJournalCheck`); nález nese rok, ve kterém se nesoulad objevil
 * poprvé, a akce otevře report Kontrola evidence × deník za ten rok.
 * Jeden nález per karta (`finding_key` = id karty).
 */
class JournalMismatchCheck extends AlertCheck
{
    /** Stable tableId of economy_assets_assets. */
    private const SUBJECT_TABLE_ID = 450;

    public const REPORT_ID = 'economy.assets.journalCheck';

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->mismatches() as $mismatch) {
            $id = (int) $mismatch['assetId'];
            $label = trim($mismatch['number'] . ' ' . $mismatch['name']) ?: '#' . $id;
            $accounts = implode(', ', array_map(
                static fn(array $account): string => $account['account'] !== '' ? $account['account'] : '?',
                $mismatch['accounts'],
            ));
            $year = $mismatch['year'];

            $findings[] = new AlertFinding(
                findingKey: (string) $id,
                title: $isCs
                    ? "Majetek {$label}: zaúčtování nesouhlasí s deníkem"
                    : "Asset {$label}: posting does not match the journal",
                message: $isCs
                    ? "Zaúčtované události karty neodpovídají řádkům deníku na účtech {$accounts} (rok {$year['name']})."
                        . ' Otevři Kontrolu evidence × deník — ukáže rozdíl po účtech. Typická příčina je ruční'
                        . ' zásah do dokladu Majetku nebo změna účetní skupiny karty po zaúčtování.'
                    : "Posted events of the card do not match journal rows on accounts {$accounts} (year {$year['name']})."
                        . ' Open the Register × journal check to see the difference by account.',
                severity: 'error',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $id,
                actions: [
                    [
                        'id'      => 'open_check',
                        'label'   => $isCs ? 'Otevřít kontrolu' : 'Open the check',
                        'kind'    => 'open_report',
                        'primary' => true,
                        'target'  => [
                            'reportId' => self::REPORT_ID,
                            'params'   => ['fiscalYear' => $year['name'], 'monthFrom' => 1, 'monthTo' => $year['months']],
                        ],
                    ],
                    [
                        'id'     => 'open_asset',
                        'label'  => $isCs ? 'Otevřít kartu' : 'Open asset card',
                        'kind'   => 'open_viewer',
                        'target' => ['viewerId' => 'economy.assets.assets', 'recordId' => $id],
                    ],
                ],
                context: ['asset_number' => $mismatch['number'], 'fiscal_year' => $year['name'], 'accounts' => $accounts],
            );
        }
        return $findings;
    }

    /**
     * Nesoulady (a) po kartách s rokem prvního výskytu — nejdřív ke konci
     * předchozího, pak aktuálního účetního roku.
     *
     * @return list<array{assetId: int, number: string, name: string, accounts: list<array<string, mixed>>,
     *     year: array{name: string, end: string, months: int}}>
     */
    protected function mismatches(): array
    {
        $check = $this->check();
        $out = [];
        foreach ($this->years() as $year) {
            foreach ($check->cardFindings($year['end'])['posting'] as $finding) {
                $out[$finding['assetId']] ??= $finding + ['year' => $year];
            }
        }
        return array_values($out);
    }

    /** Seam pro testy. */
    protected function check(): AssetJournalCheck
    {
        return new AssetJournalCheck($this->db->getDibiConnection());
    }

    /**
     * Předchozí a aktuální účetní rok (podle dneška; bez roku pro dnešek
     * poslední dva začaté), od staršího. Seam pro testy.
     *
     * @return list<array{name: string, end: string, months: int}>
     */
    protected function years(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [y].[name], [y].[date_end],'
            . ' (SELECT COUNT(*) FROM [economy_codebooks_fiscal_months] [m]'
            . '   WHERE [m].[fiscal_year] = [y].[id] AND [m].[period_type] = 1) AS [months]'
            . ' FROM [economy_codebooks_fiscal_years] [y]'
            . ' WHERE [y].[docState] <> 90 AND [y].[date_begin] <= %s'
            . ' ORDER BY [y].[date_begin] DESC LIMIT 2',
            (new \DateTimeImmutable('today'))->format('Y-m-d'),
        );
        $years = [];
        foreach (array_reverse($rows) as $row) {
            $end = $row['date_end'];
            $years[] = [
                'name'   => (string) $row['name'],
                'end'    => $end instanceof \DateTimeInterface ? $end->format('Y-m-d') : substr((string) $end, 0, 10),
                'months' => max(1, (int) $row['months']),
            ];
        }
        return $years;
    }
}
