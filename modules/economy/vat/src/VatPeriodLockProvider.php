<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\AbstractDocumentLockProvider;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Module\Docs\Core\DocDocument;
use Shipard\Module\Docs\Core\DocTypes;

/**
 * Zámek instance tvrzení DPH nad doklady (`documentLockProviders` pro
 * `docs_core_heads`, #55 D23/D25).
 *
 * Doklad je zamčený, když má **neprázdnou rekapitulaci DPH v původním nebo
 * novém stavu** a **kterýkoli** ze tří ukazatelů (`vat_period` / `cs_period`
 * / `rs_period`, opět původní i nový) míří na instanci s `locked = 1`:
 *
 *  - původní ukazatele = uložený řádek, původní rekapitulace = řádky
 *    v `docs_core_vat_recap`;
 *  - nové ukazatele = stejné pravidlo jako `DocsHeadsVatPeriodHandler`
 *    (ruční přepis z payloadu, jinak `VatPeriodAssigner::compute`), ale
 *    lookup je **find-only** — validace nesmí zakládat koncepty instancí;
 *    nová rekapitulace = `DocDocument::willHaveVatRecap` (kódy z řádků
 *    payloadu, bez klíče `rows` z uloženého recapu).
 *
 * „Kterýkoli" kvůli měsíčnímu KH čtvrtletního plátce; „podle obsahu DPH"
 * kvůli bezdaňovým pokladním převodům — ty patří pod zámek fiskálního
 * měsíce, ne DPH. Bez registrace, DUZP nebo rekapitulace je doklad volný.
 * Nedaňový typ (`docTypes[].tax_document: false`, #79 D1) nové ukazatele
 * nikdy nemá — handler je nuluje; původní (historické) ukazatele platí dál.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class VatPeriodLockProvider extends AbstractDocumentLockProvider
{
    public const SOURCE = 'vat_period';

    /** tableId `economy_vat_report_periods` */
    public const SUBJECT_TABLE_ID = 441;

    private const TYPE_LABELS = [
        VatPeriodAssigner::TYPE_RETURN => 'Přiznání k DPH',
        VatPeriodAssigner::TYPE_CS     => 'Kontrolní hlášení',
        VatPeriodAssigner::TYPE_RS     => 'Souhrnné hlášení',
    ];

    public function lockReasons(string $tableId, array $data, ?array $original): array
    {
        if ($this->db === null) {
            return [];
        }

        $headId = (int) ($data['id'] ?? $original['id'] ?? 0);
        $storedRecap = $headId > 0 && $this->hasStoredRecap($headId);

        $hasVatOriginal = $original !== null && $storedRecap;
        $hasVatNew = DocDocument::willHaveVatRecap($data, $storedRecap);
        if (!$hasVatOriginal && !$hasVatNew) {
            return [];
        }

        // Kandidátní instance: id → sloupec, ve kterém se objevila (pro text).
        $candidates = [];
        if ($hasVatOriginal) {
            foreach (self::pointers($original) as $column => $instanceId) {
                $candidates[$instanceId] ??= $column;
            }
        }
        if ($hasVatNew) {
            foreach ($this->newPointers($data, $original, $headId) as $column => $instanceId) {
                $candidates[$instanceId] ??= $column;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $reasons = [];
        foreach ($this->lockedInstances(array_keys($candidates)) as $instanceId => $instance) {
            $type = (string) ($instance['report_type'] ?? '');
            $label = self::TYPE_LABELS[$type] ?? 'Tvrzení';
            $name = (string) ($instance['name'] ?? $instanceId);
            $reasons[] = new DocumentLockReason(
                source: self::SOURCE,
                title: "{$label} {$name} je uzamčené",
                message: 'Doklad spadá do uzamčeného tvrzení DPH — uložení, oprava, storno i smazání'
                    . ' vyžadují jeho odemknutí (Daňová tvrzení → Odemknout).',
                subjectTableId: self::SUBJECT_TABLE_ID,
                subjectRowId: $instanceId,
                params: ['type' => $type, 'name' => $name, 'column' => $candidates[$instanceId]],
            );
        }
        return $reasons;
    }

    /**
     * Ne-null ukazatele řádku: sloupec → id instance.
     *
     * @param array<string, mixed> $row
     * @return array<string, int>
     */
    public static function pointers(array $row): array
    {
        $out = [];
        foreach (array_keys(DocsHeadsVatPeriodHandler::COLUMN_TYPES) as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && $value !== '' && (int) $value > 0) {
                $out[$column] = (int) $value;
            }
        }
        return $out;
    }

    /**
     * Ukazatele, které by doklad měl po uložení — zrcadlí
     * DocsHeadsVatPeriodHandler::onBeforeSave, find-only.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $original
     * @return array<string, int>
     */
    private function newPointers(array $data, ?array $original, int $headId): array
    {
        // Nedaňový typ (#79 D1): handler všechna období nuluje → po uložení
        // žádné ukazatele, ani z ručního přepisu v payloadu.
        $docType = (string) ($data['doc_type'] ?? $original['doc_type'] ?? '');
        if (!DocTypes::isTaxDocument($this->config, $docType)) {
            return [];
        }

        $manual = DocsHeadsVatPeriodHandler::manualOverrides($data, $original);

        $regId = (int) ($data['vat_registration'] ?? 0);
        $duzp = VatPeriodAssigner::isoDate($data['vat_duzp'] ?? null);
        $computed = ['vat_period' => null, 'cs_period' => null, 'rs_period' => null];
        if ($regId > 0 && $duzp !== null) {
            $computed = (new VatPeriodAssigner($this->periodLookup(), $this->mapping()))
                ->compute($data, $this->newRecapCodes($data, $headId));
        }

        $out = [];
        foreach (array_keys(DocsHeadsVatPeriodHandler::COLUMN_TYPES) as $column) {
            $value = array_key_exists($column, $manual) ? $manual[$column] : $computed[$column];
            if ($value !== null && (int) $value > 0) {
                $out[$column] = (int) $value;
            }
        }
        return $out;
    }

    /**
     * Kódy DPH nového stavu pro členství v KH/SH: převzatá rekapitulace
     * z payloadu, jinak položkové řádky payloadu; bez klíče `rows` uložený
     * recap.
     *
     * @param array<string, mixed> $data
     * @return list<array{vat_code: string}>
     */
    private function newRecapCodes(array $data, int $headId): array
    {
        if ((int) ($data['vat_recap_source'] ?? 0) === 1
            && isset($data['vatRecap']) && is_array($data['vatRecap']) && $data['vatRecap'] !== []
        ) {
            return self::codesOf($data['vatRecap']);
        }
        if (array_key_exists('rows', $data) && is_array($data['rows'])) {
            $codes = [];
            foreach ($data['rows'] as $row) {
                if (is_array($row) && (int) ($row['row_kind'] ?? 1) === 1 && !empty($row['vat_code'])) {
                    $codes[] = ['vat_code' => (string) $row['vat_code']];
                }
            }
            return $codes;
        }
        return $headId > 0 ? $this->storedRecapCodes($headId) : [];
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array{vat_code: string}>
     */
    private static function codesOf(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            if (is_array($row) && !empty($row['vat_code'])) {
                $codes[] = ['vat_code' => (string) $row['vat_code']];
            }
        }
        return $codes;
    }

    // ── DB přístup (přepsatelné v testech) ──────────────────────────────────

    protected function hasStoredRecap(int $headId): bool
    {
        $found = $this->db?->fetchSingle(
            'SELECT 1 FROM [docs_core_vat_recap] WHERE [doc_head] = %i LIMIT 1',
            $headId,
        );
        return $found !== null && $found !== false;
    }

    /** @return list<array{vat_code: string}> */
    protected function storedRecapCodes(int $headId): array
    {
        if ($this->db === null) {
            return [];
        }
        $codes = [];
        foreach ($this->db->fetchAll('SELECT [vat_code] FROM [docs_core_vat_recap] WHERE [doc_head] = %i', $headId) as $row) {
            $codes[] = ['vat_code' => (string) $row['vat_code']];
        }
        return $codes;
    }

    /**
     * Zamčené živé instance z daných id.
     *
     * @param list<int> $ids
     * @return array<int, array{name: string, report_type: string}>
     */
    protected function lockedInstances(array $ids): array
    {
        if ($this->db === null || $ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT [id], [name], [report_type] FROM [economy_vat_report_periods]'
            . ' WHERE [id] IN %in AND [locked] = 1 AND [docState] != 90',
            $ids,
        ) as $row) {
            $out[(int) $row['id']] = ['name' => (string) $row['name'], 'report_type' => (string) $row['report_type']];
        }
        return $out;
    }

    /** Find-only — validace nikdy nezakládá koncept instance. */
    protected function periodLookup(): ReportPeriodLookup
    {
        return new ReportPeriodsProvisioner(
            new DataSourceConnection($this->db),
            $this->mapping()?->validFromByType() ?? [],
        );
    }

    protected function mapping(): ?VatOutputsMapping
    {
        return VatOutputsMapping::fromConfig($this->config);
    }
}
