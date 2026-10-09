<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Dibi\Connection;

/**
 * Resolves a canonical row.item to an `economy_items.id`.
 *
 * Probes in order (first hit wins):
 *
 *   1. `ourCode` exact match in `economy_items.code`         → matchedBy = "ourCode"
 *   2. `(supplier.personId, supplierCode)` per-partner       → matchedBy = "supplierCode"
 *      mapping via `economy_items_supplier_codes`
 *   3. `ean` exact match in `economy_items.ean`              → matchedBy = "ean"
 *   4. `sku` exact match in `economy_items.sku`              → matchedBy = "sku"
 *   5. `name` LIKE %name%                                    → matchedBy = "name" (1)
 *                                                              / ambiguous (n)
 *   6. No match → `canCreate` payload (caller still needs to supply
 *      `item_kind` and `unit` before INSERT — see ItemDocument::validate).
 *
 * A `matched` result also carries a `createPayload` — only `name` +
 * `description` — so the review modal can offer "create a new item" with
 * the row text pre-filled even when the row matched automatically
 * (#111 D5, U3). The applier never reads it for matched rows.
 *
 * The `$identifiersOnly` flag drops probe 5 (the `name` fuzzy match). Used by
 * the legacy migration, where the old item `id` is an authoritative code: two
 * distinct items that merely share a name (e.g. "Parkovné" as a service vs. as
 * an accounting item) must stay separate, not get merged by name. Cross-run
 * idempotence there is provided by the importer's LocalIdMap, not by name
 * matching. The AI/extraction flow keeps name matching (default).
 */
class ItemResolver
{
    private const ACTIVE_STATES = [10, 40, 80];
    private const AMBIGUOUS_LIMIT = 5;

    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @param array<string, mixed> $item              Canonical row.item block.
     * @param int|null             $supplierPersonId  Resolved supplier id, for
     *                                                per-partner mapping lookup.
     * @param bool                 $identifiersOnly   Skip the `name` fuzzy probe
     *                                                (legacy migration — see class doc).
     */
    public function resolve(array $item, ?int $supplierPersonId, bool $identifiersOnly = false): ResolveResult
    {
        $ourCode = $this->normalize($item['ourCode'] ?? null);
        $supplierCode = $this->normalize($item['supplierCode'] ?? null);
        $ean = $this->normalize($item['ean'] ?? null);
        $sku = $this->normalize($item['sku'] ?? null);
        $name = $this->normalize($item['name'] ?? null);

        if ($ourCode !== null) {
            $row = $this->fetchByColumn('code', $ourCode);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'ourCode', createPayload: $this->matchedPayload($item, $name));
            }
        }

        if ($supplierPersonId !== null && $supplierCode !== null) {
            $row = $this->db->fetch(
                'SELECT [item] FROM [economy_items_supplier_codes]
                 WHERE [person] = %i AND [supplier_code] = %s
                 LIMIT 1',
                $supplierPersonId, $supplierCode,
            );
            if ($row !== null) {
                return ResolveResult::matched((int) $row['item'], 'supplierCode', createPayload: $this->matchedPayload($item, $name));
            }
        }

        if ($ean !== null) {
            $row = $this->fetchByColumn('ean', $ean);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'ean', createPayload: $this->matchedPayload($item, $name));
            }
        }

        if ($sku !== null) {
            $row = $this->fetchByColumn('sku', $sku);
            if ($row !== null) {
                return ResolveResult::matched((int) $row['id'], 'sku', createPayload: $this->matchedPayload($item, $name));
            }
        }

        if ($name !== null && !$identifiersOnly) {
            $candidates = $this->fetchByName($name);
            if (count($candidates) === 1) {
                return ResolveResult::matched((int) $candidates[0]['id'], 'name', createPayload: $this->matchedPayload($item, $name));
            }
            if (count($candidates) > 1) {
                return ResolveResult::ambiguous($candidates);
            }
        }

        if ($name === null) {
            return ResolveResult::notFound();
        }

        return ResolveResult::canCreate($this->buildCreatePayload($item, $name));
    }

    private function fetchByColumn(string $column, string $value): ?array
    {
        $row = $this->db->fetch(
            'SELECT [id] FROM [economy_items]
             WHERE %n = %s AND [docState] IN (%i, %i, %i)
             LIMIT 1',
            $column, $value,
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
        );
        return $row !== null ? $row->toArray() : null;
    }

    /**
     * @return array<int, array{id: int, name: string, code: ?string}>
     */
    private function fetchByName(string $name): array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [name], [code] FROM [economy_items]
             WHERE [name] LIKE %s AND [docState] IN (%i, %i, %i)
             LIMIT %i',
            '%' . $name . '%',
            self::ACTIVE_STATES[0], self::ACTIVE_STATES[1], self::ACTIVE_STATES[2],
            self::AMBIGUOUS_LIMIT,
        );
        $out = [];
        foreach ($rows as $row) {
            $arr = $row instanceof \Dibi\Row ? $row->toArray() : (array) $row;
            $out[] = [
                'id'   => (int) $arr['id'],
                'name' => (string) ($arr['name'] ?? ''),
                'code' => $arr['code'] !== null ? (string) $arr['code'] : null,
            ];
        }
        return $out;
    }

    /**
     * Předvyplnění formuláře „Vytvořit novou položku" u napárovaného řádku
     * (#111 D5, U3): jen `name` a `description`. Bez `code`, `sku` a `ean`
     * — to jsou identifikátory, přes které se řádek napároval (`ourCode`
     * z historie = kód existující položky); nová položka by s nimi
     * kolidovala. Bez názvu prázdné pole (`ResolveResult::toArray` klíč
     * vynechá).
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function matchedPayload(array $item, ?string $name): array
    {
        if ($name === null) {
            return [];
        }
        return [
            'name'        => $name,
            'description' => $this->normalize($item['description'] ?? null) ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildCreatePayload(array $item, string $name): array
    {
        return [
            'name'        => $name,
            'description' => $this->normalize($item['description'] ?? null) ?? '',
            'code'        => $this->normalize($item['ourCode'] ?? null) ?? '',
            'sku'         => $this->normalize($item['sku'] ?? null),
            'ean'         => $this->normalize($item['ean'] ?? null),
            // item_kind + unit must be supplied by Applier before save —
            // ItemDocument::validate rejects rows without them. The Exchange
            // applier picks defaults: item_kind = "service" kind (well-known),
            // unit = resolved row.unit if present.
        ];
    }

    private function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
