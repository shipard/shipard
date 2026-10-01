<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\Assets\AssetAcquisitionService;
use Shipard\Module\Economy\Assets\AssetCategories;
use Shipard\Module\Economy\Assets\AssetEventDocument;
use Shipard\Module\Economy\Assets\Depreciation\AssetEvent;

/**
 * Majetek čeká na zařazení (docs/assets.md D63): dlouhodobá karta má
 * potvrzené pořízení na účtu 04x (řádek `purchase.asset` potvrzeného
 * dokladu), ale žádné potvrzené zařazení ani počáteční stav — pořízení
 * zůstává na 042 a majetek se neodepisuje.
 *
 * Jeden nález per karta (`finding_key` = id karty); zmizí po potvrzení
 * zařazení. Informační — majetek může být pořízený, ale ještě neuvedený
 * do užívání.
 */
class AwaitingActivationCheck extends AlertCheck
{
    /** Stable tableId of economy_assets_assets. */
    private const SUBJECT_TABLE_ID = 450;

    /** Aktivní stavy karty (koncept, V pořádku, V opravě). */
    private const CARD_STATES = [10, 40, 80];

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->rows() as $row) {
            $id = (int) $row['id'];
            $number = trim((string) ($row['asset_number'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $label = trim($number . ' ' . $name) ?: '#' . $id;
            $amount = number_format((float) $row['amount'], 2, ',', ' ');

            $findings[] = new AlertFinding(
                findingKey: (string) $id,
                title: $isCs
                    ? "Majetek {$label} čeká na zařazení"
                    : "Asset {$label} is waiting for activation",
                message: $isCs
                    ? "Karta má na dokladech pořízení za {$amount}, ale není zařazená — pořízení zůstává na účtu"
                        . ' pořízení a majetek se neodepisuje. Na kartě zvol Zařadit; částka a datum se'
                        . ' předvyplní z pořízení.'
                    : "The card has acquisition of {$amount} on documents but is not activated — it is not"
                        . ' depreciated yet. Use Activate on the card; amount and date are prefilled.',
                severity: 'info',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $id,
                actions: [[
                    'id'       => 'open_asset',
                    'label'    => $isCs ? 'Otevřít kartu' : 'Open asset card',
                    'kind'     => 'open_viewer',
                    'viewerId' => 'economy.assets.assets',
                    'recordId' => $id,
                    'primary'  => true,
                ]],
                context: ['asset_number' => $number, 'amount' => (float) $row['amount']],
            );
        }
        return $findings;
    }

    /**
     * Dlouhodobé karty s potvrzeným pořízením na 04x bez potvrzeného
     * zařazení / počátečního stavu. Seam pro testy.
     *
     * @return list<array{id: int, asset_number: string, name: string, amount: float}>
     */
    protected function rows(): array
    {
        $longTerm = [];
        $categories = new AssetCategories($this->config);
        foreach (array_keys($categories->all()) as $category) {
            if ($categories->isLongTerm((string) $category)) {
                $longTerm[] = (string) $category;
            }
        }
        if ($longTerm === []) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT [a].[id], [a].[asset_number], [a].[name], SUM([r].[vat_base_dom]) AS [amount]'
            . ' FROM [economy_assets_assets] [a]'
            . ' JOIN [docs_core_rows] [r] ON [r].[asset] = [a].[id] AND [r].[operation] = %s'
            . ' JOIN [docs_core_heads] [h] ON [h].[id] = [r].[doc_head] AND [h].[docState] = %i'
            . ' JOIN [economy_accounting_accounts] [acc] ON [acc].[id] = [r].[account] AND [acc].[number] LIKE %s'
            . ' WHERE [a].[docState] IN %in AND [a].[category] IN %in'
            . '   AND NOT EXISTS (SELECT 1 FROM [economy_assets_events] [e]'
            . '     WHERE [e].[asset] = [a].[id] AND [e].[docState] = %i AND [e].[event_kind] IN %in)'
            . ' GROUP BY [a].[id], [a].[asset_number], [a].[name]'
            . ' ORDER BY [a].[id]',
            AssetAcquisitionService::OPERATION,
            AssetAcquisitionService::DOC_STATE_CONFIRMED,
            AssetAcquisitionService::ACQUISITION_ACCOUNT_PREFIX . '%',
            self::CARD_STATES,
            $longTerm,
            AssetEventDocument::STATE_CONFIRMED,
            [AssetEvent::KIND_ACTIVATION, AssetEvent::KIND_OPENING],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'           => (int) $row['id'],
                'asset_number' => (string) ($row['asset_number'] ?? ''),
                'name'         => (string) ($row['name'] ?? ''),
                'amount'       => (float) ($row['amount'] ?? 0),
            ];
        }
        return $out;
    }
}
