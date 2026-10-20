<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Export;

use Dibi\Connection;
use Shipard\Module\Core\Exchange\Dataset\ExportedRecord;
use Shipard\Module\Core\Exchange\Dataset\RecordExporter;
use Shipard\Module\Core\Exchange\Dataset\ValueNormalizer as V;

/**
 * `economy_items` (+ druh, jednotka, dodavatelské kódy) → `shpd.items.item.v1`.
 * Zrcadlo `ItemApplier::transformHeaderForCreate` a `saveSupplierCodes`.
 *
 * Druh se referencuje `system_code` (fallback název), jednotka
 * `system_code` → `shortcut` → název (pořadí probe v `UnitResolver`),
 * dodavatel identifikátory osoby. `accountingAccount` (číslo účtu) a
 * `contentTags` jsou rozšíření formátu z fáze 1 datasetů — bez účtu
 * položky by se seedované doklady zaúčtovaly jinak než originál.
 */
final class ItemExporter implements RecordExporter
{
    private const ACTIVE_STATES = [10, 40, 80];

    private ?bool $hasAccounting = null;

    /** @var list<string> */
    private array $warnings = [];

    /**
     * @param list<int> $excludedIds položky, které `exportAll()` vynechá —
     *        nezměněné položky z nabídky osnovy, které sada místo souborů
     *        v `items/` nese jako `provision.accountingItems` v manifestu
     *        ({@see \Shipard\Module\Economy\Items\Dataset\ProvisionedItemsDetector}, #131)
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $excludedIds = [],
    ) {}

    public function section(): string
    {
        return 'items';
    }

    public function exportAll(): array
    {
        $where = ' WHERE [i.docState] <> 90';
        $order = ' ORDER BY [i.code], [i.name], [i.id]';
        if ($this->excludedIds !== []) {
            return $this->exportRows($this->db->fetchAll(
                $this->selectSql() . $where . ' AND [i.id] NOT IN %in' . $order,
                $this->excludedIds,
            ));
        }
        return $this->exportRows($this->db->fetchAll($this->selectSql() . $where . $order));
    }

    public function exportByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        return $this->exportRows($this->db->fetchAll(
            $this->selectSql() . ' WHERE [i.id] IN %in AND [i.docState] <> 90 ORDER BY [i.code], [i.name], [i.id]',
            $ids,
        ));
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    private function selectSql(): string
    {
        $account = $this->hasAccounting()
            ? ', [a.number] AS [account_number]'
            : ', NULL AS [account_number]';
        $join = $this->hasAccounting()
            ? ' LEFT JOIN [economy_accounting_accounts] AS [a] ON [a.id] = [i.accounting_account]'
            : '';
        return 'SELECT [i.*], [k.system_code] AS [kind_code], [k.name] AS [kind_name], [k.item_type] AS [kind_item_type],'
            . ' [u.system_code] AS [unit_code], [u.shortcut] AS [unit_shortcut], [u.name] AS [unit_name]'
            . $account
            . ' FROM [economy_items] AS [i]'
            . ' LEFT JOIN [economy_items_kinds] AS [k] ON [k.id] = [i.item_kind]'
            . ' LEFT JOIN [core_units] AS [u] ON [u.id] = [i.unit]'
            . $join;
    }

    /**
     * Sloupec `accounting_account` přidává extension modulu economy.accounting —
     * bez něj by JOIN spadl.
     */
    private function hasAccounting(): bool
    {
        if ($this->hasAccounting === null) {
            $row = $this->db->fetch("SHOW COLUMNS FROM [economy_items] LIKE 'accounting_account'");
            $this->hasAccounting = $row !== null;
        }
        return $this->hasAccounting;
    }

    /**
     * @param iterable<\Dibi\Row|array<string, mixed>> $rows
     * @return list<ExportedRecord>
     */
    private function exportRows(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->exportItem(is_array($row) ? $row : $row->toArray());
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $i
     */
    public function exportItem(array $i): ExportedRecord
    {
        $id = (int) $i['id'];
        $docState = (int) ($i['docState'] ?? 40);

        $canonical = [
            'format'        => 'shpd.items.item',
            'formatVersion' => '1.0',
            'source'        => $this->source($i),
            'code'          => V::str($i['code'] ?? null),
            'name'          => V::str($i['name'] ?? null) ?? '',
            'description'   => V::str($i['description'] ?? null),
            'sku'           => V::str($i['sku'] ?? null),
            'ean'           => V::str($i['ean'] ?? null),
            'kind'          => [
                'code'     => V::str($i['kind_code'] ?? null),
                'name'     => V::str($i['kind_name'] ?? null),
                'itemType' => V::int($i['kind_item_type'] ?? $i['item_type'] ?? null),
            ],
            'validFrom'       => V::date($i['valid_from'] ?? null),
            'validTo'         => V::date($i['valid_to'] ?? null),
            'salesPriceNoVat' => V::float($i['sales_price_no_vat'] ?? null),
            'unit'            => V::str($i['unit_code'] ?? null)
                                 ?? V::str($i['unit_shortcut'] ?? null)
                                 ?? V::str($i['unit_name'] ?? null)
                                 ?? 'pcs',
            'accountingAccount' => V::str($i['account_number'] ?? null),
            'contentTags'       => $this->contentTags($i['content_tags'] ?? null),
            'supplierCodes'     => $this->supplierCodes($id),
            'status'            => ['docState' => $docState],
            'applyOptions'      => [
                'mergeStrategy'  => 'createOnly',
                'matchStrategy'  => 'identifiersOnly',
                'targetDocState' => in_array($docState, [10, 40], true) ? $docState : 40,
            ],
        ];

        $slug = trim((V::str($i['code'] ?? null) ?? '') . ' ' . (V::str($i['name'] ?? null) ?? ''));

        return new ExportedRecord($id, $slug !== '' ? $slug : 'item', V::prune($canonical));
    }

    /**
     * @param array<string, mixed> $i
     * @return array<string, mixed>|null
     */
    private function source(array $i): ?array
    {
        $kind = V::str($i['source_kind'] ?? null);
        if ($kind === null) {
            return null;
        }
        // fetchedAt se neexportuje: applier ho při seedu razítkuje NOW()
        // (source_imported_at = okamžik importu), takže by dump → seed → dump
        // nebyl stabilní.
        return [
            'kind'        => $kind,
            'registryRef' => V::str($i['source_ref'] ?? null),
        ];
    }

    /**
     * @return list<string>|null
     */
    private function contentTags(mixed $value): ?array
    {
        $tags = V::json($value);
        if ($tags === null) {
            return null;
        }
        $out = array_values(array_filter($tags, static fn($t): bool => is_string($t) && $t !== ''));
        return $out === [] ? null : $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function supplierCodes(int $itemId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [sc.supplier_code], [sc.supplier_name], [p.full_name], [p.company_id], [p.tax_id], [p.vat_id], [p.gov_e_box_id]
             FROM [economy_items_supplier_codes] AS [sc]
             JOIN [base_persons_persons] AS [p] ON [p.id] = [sc.person]
             WHERE [sc.item] = %i AND [p.docState] IN %in
             ORDER BY [p.full_name], [p.company_id], [sc.supplier_code]',
            $itemId,
            self::ACTIVE_STATES,
        );
        $out = [];
        foreach ($rows as $r) {
            $r = is_array($r) ? $r : $r->toArray();
            $code = V::str($r['supplier_code'] ?? null);
            if ($code === null) {
                continue;
            }
            $out[] = [
                'supplier' => [
                    'name'      => V::str($r['full_name'] ?? null),
                    'companyId' => V::str($r['company_id'] ?? null),
                    'taxId'     => V::str($r['tax_id'] ?? null),
                    'vatId'     => V::str($r['vat_id'] ?? null),
                    'govEBoxId' => V::str($r['gov_e_box_id'] ?? null),
                ],
                'supplierCode' => $code,
                'supplierName' => V::str($r['supplier_name'] ?? null),
            ];
        }
        return $out;
    }
}
