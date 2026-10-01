<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets\Checks;

use Shipard\Core\Alerts\AlertCheck;
use Shipard\Core\Alerts\AlertFinding;
use Shipard\Module\Economy\Assets\AssetAcquisitionService;

/**
 * Pořízení majetku bez karty (docs/assets.md D61): potvrzený doklad má
 * řádek `purchase.asset`, který nenese kartu majetku. Karta na řádku je
 * nepovinná (AI analýza a import ji neznají), takže ji hlídá tento alert —
 * bez ní pořízení na kartě chybí a nejde z něj předvyplnit zařazení.
 *
 * Jeden nález per doklad (`finding_key` = id dokladu); zmizí, jakmile
 * všechny řádky pořízení dokladu kartu mají. Karta z hlavičky dokladu se
 * nepočítá — pořízení je věc řádku.
 */
class PurchaseWithoutAssetCheck extends AlertCheck
{
    /** Stable tableId of docs_core_heads. */
    private const SUBJECT_TABLE_ID = 401;

    public function run(): array
    {
        $isCs = $this->language === 'cs';
        $findings = [];
        foreach ($this->rows() as $row) {
            $id = (int) $row['id'];
            $number = trim((string) ($row['doc_number'] ?? ''));
            $label = $number === '' || str_starts_with($number, '!') ? '#' . $id : $number;
            $count = (int) $row['row_count'];
            $amount = number_format((float) $row['amount'], 2, ',', ' ');

            $findings[] = new AlertFinding(
                findingKey: (string) $id,
                title: $isCs
                    ? "Doklad {$label}: pořízení majetku bez karty"
                    : "Document {$label}: asset acquisition without an asset card",
                message: $isCs
                    ? "Řádků pořízení bez karty: {$count}, základ celkem {$amount}. Otevři doklad (Opravit) a na řádku"
                        . ' vyber kartu majetku, nebo ji tam rovnou založ — bez karty pořízení na kartě chybí'
                        . ' a zařazení se z něj nepředvyplní.'
                    : "Acquisition rows without a card: {$count}, base total {$amount}. Open the document and pick"
                        . ' or create the asset card on the row — otherwise the acquisition is missing on the card.',
                severity: 'warning',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $id,
                actions: [[
                    'id'      => 'open_doc',
                    'label'   => $isCs ? 'Otevřít doklad' : 'Open document',
                    'kind'    => 'open_form',
                    'variant' => 'primary',
                    'primary' => true,
                    'target'  => ['table' => 'docs_core_heads', 'mode' => 'edit', 'id' => $id],
                ]],
                context: ['doc_number' => $number, 'rows' => $count],
            );
        }
        return $findings;
    }

    /**
     * Potvrzené doklady s řádkem pořízení bez karty. Seam pro testy.
     *
     * @return list<array{id: int, doc_number: string, row_count: int, amount: float}>
     */
    protected function rows(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [h].[id], [h].[doc_number], COUNT(*) AS [row_count], SUM([r].[vat_base_dom]) AS [amount]'
            . ' FROM [docs_core_rows] [r]'
            . ' JOIN [docs_core_heads] [h] ON [h].[id] = [r].[doc_head]'
            . ' WHERE [r].[operation] = %s AND ([r].[asset] IS NULL OR [r].[asset] = 0) AND [h].[docState] = %i'
            . ' GROUP BY [h].[id], [h].[doc_number]'
            . ' ORDER BY [h].[id]',
            AssetAcquisitionService::OPERATION,
            AssetAcquisitionService::DOC_STATE_CONFIRMED,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'doc_number' => (string) ($row['doc_number'] ?? ''),
                'row_count'  => (int) $row['row_count'],
                'amount'     => (float) ($row['amount'] ?? 0),
            ];
        }
        return $out;
    }
}
