<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document\Interpret;

/**
 * Učení dodavatelských kódů položek po uložení dokladu z papíru (#166 D6,
 * D19): kód položky u dodavatele → naše položka, aby se příští faktura
 * napárovala sama. Patří interpretaci — pokyn mapování nezapisuje.
 */
final class SupplierCodeLearning
{
    /**
     * @param \Closure(mixed ...): void $executeSql zápis do databáze
     *        (`Dibi\Connection::query()` je final — volající ho obaluje)
     */
    public function __construct(
        private readonly \Closure $executeSql,
    ) {
    }

    /**
     * Per-partner item code learning — see docs/document-interpretation.md
     * § 5.2 a § 8. Idempotent via unique index.
     *
     * Řádek s výslovnou volbou položky (`rowItemPins`, #111 D8) naučené
     * mapování **přepíše** (`ON DUPLICATE KEY UPDATE`) — oprava špatného
     * napárování se musí projevit u příští faktury. Automatické napárování
     * a potvrzení z Konceptu (`SupplierCodeCaptureHandler`) dál jen
     * `INSERT IGNORE`.
     *
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $plan
     * @param array{supplier: ?int, customer: ?int, supplierBank: ?int, rowItems: array<int, int>} $sideIds
     */
    public function learn(array $canonical, array $plan, array $sideIds): void
    {
        $supplierId = $sideIds['supplier'] ?? $plan['resolvedSupplier'] ?? null;
        if ($supplierId === null) {
            return;
        }

        $rows = is_array($canonical['rows'] ?? null) ? $canonical['rows'] : [];
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            if (in_array($i, $plan['rowSkips'] ?? [], true)) {
                continue;
            }
            $supplierCode = $row['item']['supplierCode'] ?? null;
            if (!is_string($supplierCode) || trim($supplierCode) === '') {
                continue;
            }
            $itemId = $sideIds['rowItems'][$i] ?? ($plan['resolvedRowItems'][$i] ?? null);
            if ($itemId === null) {
                continue;
            }
            $this->write(
                (int) $supplierId,
                (int) $itemId,
                $supplierCode,
                isset($row['item']['name']) ? (string) $row['item']['name'] : null,
                in_array($i, $plan['rowItemPins'] ?? [], true),
            );
        }
    }

    /**
     * Učení ze seznamu, který připravila interpretace
     * ({@see InterpretedDocument::$learn}) — po uložení pokynu, kdy jsou
     * známá id založených záznamů.
     *
     * @param array{supplier: ?int, codes: list<array{row: int, supplierCode: string, supplierName: ?string, item: ?int, overwrite: bool}>} $learn
     * @param array{supplier?: ?int, rowItems?: array<int, int>} $created id záznamů založených z `pending`
     */
    public function learnFrom(array $learn, array $created): void
    {
        $supplierId = $created['supplier'] ?? $learn['supplier'] ?? null;
        if ($supplierId === null) {
            return;
        }
        foreach ($learn['codes'] ?? [] as $code) {
            $itemId = $created['rowItems'][$code['row']] ?? $code['item'] ?? null;
            if ($itemId === null) {
                continue;
            }
            $this->write((int) $supplierId, (int) $itemId, $code['supplierCode'], $code['supplierName'], $code['overwrite']);
        }
    }

    /**
     * Výslovná volba položky mapování přepíše, ostatní jen doplní.
     */
    private function write(int $supplierId, int $itemId, string $supplierCode, ?string $supplierName, bool $overwrite): void
    {
        $sql = $overwrite
            ? 'INSERT INTO [economy_items_supplier_codes]
                   ([person], [item], [supplier_code], [supplier_name], [created])
                   VALUES (%i, %i, %s, %sN, NOW())
                   ON DUPLICATE KEY UPDATE [item] = VALUES([item]), [supplier_name] = VALUES([supplier_name])'
            : 'INSERT IGNORE INTO [economy_items_supplier_codes]
                   ([person], [item], [supplier_code], [supplier_name], [created])
                   VALUES (%i, %i, %s, %sN, NOW())';
        ($this->executeSql)($sql, $supplierId, $itemId, $supplierCode, $supplierName);
    }
}
