<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Docs\Core\DocTypes;

/**
 * Společný výběr dokladů pro všechny tři DPH kalkulátory (D8/D11): doklady
 * ve stavu „V pořádku" (docState 40), jejichž **materializovaný ukazatel**
 * (`vat_period` / `cs_period` / `rs_period` dle typu reportu) míří na
 * instanci tvrzení — nikdy přes datum přímo; pravidlo clamped DPPD žije
 * v přiřazení při uložení (DocsHeadsVatPeriodHandler). K hlavičkám načte
 * řádky `docs_core_vat_recap` (domácí měna) a rozliší DIČ partnera ze
 * snapshotů (`vat_id`, fallback `tax_id`). Pojistka (#79 D1): nedaňové typy
 * (`docTypes[].tax_document: false`) vyřadí, i kdyby na nich ukazatel
 * historicky visel — seznam z configu přes `DocTypes::nonTaxDocTypes()`.
 */
final class VatDocumentSelection
{
    private const DOC_STATE_OK = 40;

    /** Config jen kvůli pojistce nedaňových typů; bez něj se nic nevyřazuje. */
    public function __construct(
        private readonly DataSourceConnection $db,
        private readonly ?ConfigRuntime $config = null,
    ) {}

    /**
     * @return list<array<string, mixed>> Doklady s klíči: id, doc_type,
     *         doc_number, partner_doc_number, total_amount_dom, vat_duzp,
     *         vat_dppd (ISO string|null), customer_vat_id, supplier_vat_id,
     *         cs_mode (ruční zařazení do KH, #77), recap (list řádků:
     *         vat_code, vat_pct, base_dom, tax_dom, is_reverse_pair).
     */
    /**
     * @param int $periodId id instance `economy_vat_report_periods`
     * @param string $periodColumn sloupec hlavičky dle typu instance
     *        (`vat_period` | `cs_period` | `rs_period`)
     */
    public function load(int $periodId, string $periodColumn): array
    {
        if (!in_array($periodColumn, ['vat_period', 'cs_period', 'rs_period'], true)) {
            throw new \InvalidArgumentException("Unknown period column '{$periodColumn}'");
        }
        $sql = 'SELECT [h].[id], [h].[doc_type], [h].[doc_number], [h].[partner_doc_number],'
            . ' [h].[total_amount_dom], [h].[vat_duzp], [h].[vat_dppd], [h].[cs_mode],'
            . ' [h].[customer_snapshot], [h].[supplier_snapshot]'
            . ' FROM [docs_core_heads] [h]'
            . ' WHERE %n = %i AND [h].[docState] = %i';
        $args = ['h.' . $periodColumn, $periodId, self::DOC_STATE_OK];
        $excluded = DocTypes::nonTaxDocTypes($this->config);
        if ($excluded !== []) {
            $sql .= ' AND [h].[doc_type] NOT IN %in';
            $args[] = $excluded;
        }
        $sql .= ' ORDER BY [h].[doc_number], [h].[id]';
        $heads = $this->db->fetchAll($sql, ...$args);
        if ($heads === []) {
            return [];
        }

        $recapByHead = [];
        $recapRows = $this->db->fetchAll(
            'SELECT [doc_head], [vat_code], [vat_pct], [base_dom], [tax_dom], [is_reverse_pair]'
            . ' FROM [docs_core_vat_recap]'
            . ' WHERE [doc_head] IN %in'
            . ' ORDER BY [doc_head], [order_pos]',
            array_map(static fn (array $h): int => (int) $h['id'], $heads),
        );
        foreach ($recapRows as $row) {
            $recapByHead[(int) $row['doc_head']][] = [
                'vat_code'        => (string) $row['vat_code'],
                'vat_pct'         => (float) $row['vat_pct'],
                'base_dom'        => (float) $row['base_dom'],
                'tax_dom'         => (float) $row['tax_dom'],
                'is_reverse_pair' => (bool) $row['is_reverse_pair'],
            ];
        }

        $docs = [];
        foreach ($heads as $head) {
            $docs[] = [
                'id'                 => (int) $head['id'],
                'doc_type'           => (string) $head['doc_type'],
                'doc_number'         => (string) ($head['doc_number'] ?? ''),
                'partner_doc_number' => (string) ($head['partner_doc_number'] ?? ''),
                'total_amount_dom'   => (float) ($head['total_amount_dom'] ?? 0.0),
                'vat_duzp'           => $this->isoDate($head['vat_duzp']),
                'vat_dppd'           => $this->isoDate($head['vat_dppd']),
                'customer_vat_id'    => $this->vatIdFromSnapshot($head['customer_snapshot']),
                'supplier_vat_id'    => $this->vatIdFromSnapshot($head['supplier_snapshot']),
                'cs_mode'            => (int) ($head['cs_mode'] ?? ControlStatementCalculator::MODE_AUTO),
                'recap'              => $recapByHead[(int) $head['id']] ?? [],
            ];
        }
        return $docs;
    }

    /** DIČ ze snapshotu partnera: `vat_id` (DIČ pro DPH), fallback `tax_id`. */
    private function vatIdFromSnapshot(mixed $snapshot): string
    {
        if (!is_string($snapshot) || $snapshot === '') {
            return '';
        }
        $decoded = json_decode($snapshot, true);
        if (!is_array($decoded)) {
            return '';
        }
        $vatId = trim((string) ($decoded['vat_id'] ?? ''));
        return $vatId !== '' ? $vatId : trim((string) ($decoded['tax_id'] ?? ''));
    }

    private function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = (string) ($value ?? '');
        return $string !== '' ? $string : null;
    }
}
