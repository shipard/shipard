<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Document;

use Dibi\Connection;

/**
 * Dohledání dokladu podle klíče idempotence `source.kind` + `source.ref`
 * (#166 D46) — čte ho pokyn (apply i náhled) i interpretace návrhu, proto
 * stojí mimo applier. Jen čte; zámek souběhu drží ten, kdo ukládá.
 */
final class SourceRefLookup
{
    /** Koš — doklad v koši nový pokyn neblokuje (#166 D46). */
    private const TRASH_STATE = 90;

    public function __construct(
        private readonly Connection $db,
    ) {
    }

    /**
     * Živý doklad se stejným druhem zdroje a identifikátorem ve zdroji.
     * Index `idx_source_ref` dá kandidáty podle collation sloupce (bez
     * ohledu na velikost písmen), shodu rozhoduje {@see SourceRef::matches()}
     * přesně. Víc živých dokladů (doklad obnovený z koše vedle novějšího)
     * = nejnovější — ten, který pokyn vrátil naposledy.
     *
     * @param int|null $excludeId Cílový koncept přegenerování (U5) —
     *        `replaceConcept` nahrazuje právě jeho, nesmí se najít sám.
     * @return array{id: int, docType: string, docNumber: ?string}|null
     */
    public function find(SourceRef $key, ?int $excludeId): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT [id], [doc_type], [doc_number], [source_kind], [source_ref]
             FROM [docs_core_heads]
             WHERE [source_kind] = %s AND [source_ref] = %s AND [docState] <> %i
             ORDER BY [id] DESC',
            $key->kind, $key->ref, self::TRASH_STATE,
        );
        foreach ($rows as $row) {
            $row = is_array($row) ? $row : $row->toArray();
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || $id === $excludeId || !$key->matches($row['source_kind'] ?? null, $row['source_ref'] ?? null)) {
                continue;
            }
            // Koncept nese zástupné číslo `!<id>` — skutečné číslo nemá.
            $number = $row['doc_number'] ?? null;
            return [
                'id'        => $id,
                'docType'   => (string) ($row['doc_type'] ?? ''),
                'docNumber' => is_string($number) && $number !== '' && !str_starts_with($number, '!') ? $number : null,
            ];
        }
        return null;
    }

    /**
     * Existující doklad pro payload: klíč z `source`, cílový koncept
     * přegenerování (`applyOptions.replaceConcept`) se z dohledání vylučuje.
     *
     * @param array<string, mixed> $canonical
     * @return array{id: int, docType: string, docNumber: ?string}|null
     */
    public function existingFor(array $canonical): ?array
    {
        $key = SourceRef::fromCanonical($canonical);
        if ($key === null) {
            return null;
        }
        $options = is_array($canonical['applyOptions'] ?? null) ? $canonical['applyOptions'] : [];
        return $this->find($key, self::replaceConceptId($options));
    }

    /**
     * Náhled (#166 U4): existující doklad se stejným klíčem jako nález —
     * info `already_applied`, při jiném typu chyba `source_ref_conflict`.
     *
     * @param array<string, mixed> $canonical
     * @param array<int, array<string, mixed>> $issues
     */
    public function appendIssue(array $canonical, array &$issues): void
    {
        $existing = $this->existingFor($canonical);
        if ($existing !== null) {
            $issues[] = self::issue($canonical, $existing);
        }
    }

    /**
     * Nález k existujícímu dokladu: info `already_applied`, nebo chyba
     * `source_ref_conflict` při jiném typu dokladu. Nese `docId`
     * a `docNumber` (koncept číslo nemá → null).
     *
     * @param array<string, mixed> $canonical
     * @param array{id: int, docType: string, docNumber: ?string} $existing
     * @return array{severity: string, path: string, code: string, message: string, docId: int, docNumber: ?string}
     */
    public static function issue(array $canonical, array $existing): array
    {
        $label = '#' . $existing['id'] . ($existing['docNumber'] !== null ? ' (' . $existing['docNumber'] . ')' : '');
        $docType = $canonical['docType'] ?? null;
        $payloadType = is_string($docType) ? DocumentApplier::mapDocTypeValue($docType) : '';
        if ($payloadType !== $existing['docType']) {
            return [
                'severity'  => 'error',
                'path'      => 'source.ref',
                'code'      => 'source_ref_conflict',
                'message'   => "Identifikátor ve zdroji (source.ref) už nese doklad {$label} typu {$existing['docType']}, payload je typu {$payloadType}.",
                'docId'     => $existing['id'],
                'docNumber' => $existing['docNumber'],
            ];
        }
        return [
            'severity'  => 'info',
            'path'      => 'source.ref',
            'code'      => 'already_applied',
            'message'   => "Doklad se stejným identifikátorem ve zdroji už existuje: {$label}. Apply ho vrátí beze změny.",
            'docId'     => $existing['id'],
            'docNumber' => $existing['docNumber'],
        ];
    }

    /**
     * Cílový koncept přegenerování z `applyOptions.replaceConcept`.
     *
     * @param array<string, mixed> $applyOptions
     */
    public static function replaceConceptId(array $applyOptions): ?int
    {
        $value = $applyOptions['replaceConcept'] ?? null;
        return is_int($value) && $value > 0 ? $value : null;
    }
}
