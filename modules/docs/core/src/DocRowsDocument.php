<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document for `docs_core_rows`.
 *
 * Most lifecycle stages defer to base Document (no validation, no
 * beforeSave logic — row-level price/VAT recompute happens in the head's
 * DocDocument::beforeSave so that totals and VAT recap stay in sync).
 *
 * The job of this class is the `afterSave` hook: when a row is added,
 * edited, or deleted via the sub-form endpoint, the parent document's
 * totals and VAT recap need to be rebuilt — dělá to
 * {@see DocHeadRecomputer} (znovu pustí `beforeSave` hlavičky a zapíše
 * výsledek).
 *
 * This keeps the orchestration in one place (DocDocument) and ensures
 * the same logic runs whether the user saves the head directly or
 * through a row change.
 */
class DocRowsDocument extends Document
{
    /**
     * Tvrdá validace pohybu řádku (sdílená pravidla viz
     * DocRowOperationRules). Bez head kontextu nebo compiled configu se
     * validace degradovaně přeskočí — záchytnou sítí je pak kontrola
     * všech řádků v DocDocument::validate při přechodu do stavu 40.
     */
    public function validate(array &$data): ValidationResult
    {
        $result = new ValidationResult();

        // Předmět plnění tuzemského přenesení (#89 D7): hodnota mimo číselník
        // nebo neplatná k datu sazby je chyba vždy; chybějící předmět hlídá
        // až potvrzení dokladu. Nezávisí na konfiguraci pohybů níže.
        if ((int) ($data['row_kind'] ?? 1) === 1 && !empty($data[ReverseChargeSubjects::COLUMN])) {
            $problem = ReverseChargeSubjects::lineProblem($this->db, $this->config, $data);
            if ($problem !== null) {
                $result->addError(ReverseChargeSubjects::COLUMN, $problem['message'], $problem['code']);
            }
        }

        // Datum sazby DPH (#159 D9, D15): kód bez sazby k datu sazby řádku
        // je při uložení ze sub-formuláře chyba, pozdější vlastní datum
        // varování.
        if ((int) ($data['row_kind'] ?? 1) === 1 && !empty($data['vat_code'])) {
            foreach (VatRateDates::subformLineProblems($this->db, $this->config, $data) as $problem) {
                $problem['warning']
                    ? $result->addWarning($problem['column'], $problem['message'], $problem['code'])
                    : $result->addError($problem['column'], $problem['message'], $problem['code']);
            }
        }

        $head = $this->loadHeadContext($data['doc_head'] ?? null);
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');

        // Řádek dokladu s DPH bez kódu znamená „nevyplněno“ (#164 D2, U8):
        // při uložení ze sub-formuláře varování, potvrzení dokladu ho
        // odmítne (`DocDocument::validateVatCodesFilled`). Kontačním řádkům
        // formulář pole Kód DPH nenabízí; bez DPH kontextu hlavičky (doklad
        // Bez DPH, nedohledaná země registrace) se nekontroluje.
        if (VatLineScope::lacksCode($data)
            && !DocRowOperationRules::hasRowSide((string) ($data['operation'] ?? ''), is_array($cfg) ? $cfg : [])
            && ReverseChargeSubjects::headContext($this->db, $this->config, $data['doc_head'] ?? null) !== null
        ) {
            $result->addWarning('vat_code', VatLineScope::MISSING_CODE_MESSAGE, VatLineScope::MISSING_CODE);
        }

        if ($head === null || !is_array($cfg)) {
            return $result;
        }

        // Uložený řádek se systémovou operací (asset.*) sestavila služba —
        // ručně ho nejde ani přepsat na jinou operaci.
        if (!empty($data['id'])
            && DocRowOperationRules::isSystem((string) $this->loadStoredOperation((int) $data['id']), $cfg)
        ) {
            $result->addError('operation', 'Řádek založil systém — ručně ho změnit nelze', 'system_operation');
            return $result;
        }

        foreach (DocRowOperationRules::validateRow($data, $head['doc_type'], $cfg, $head['cash_dir']) as $err) {
            $result->addError($err['column'], $err['message'], $err['code']);
        }
        return $result;
    }

    /** Operace uloženého řádku; null = řádek neexistuje nebo dokument nemá DB. */
    protected function loadStoredOperation(int $rowId): ?string
    {
        $row = $this->db?->fetch('SELECT [operation] FROM [docs_core_rows] WHERE [id] = %i', $rowId);

        return $row !== null && $row !== false ? (string) ($row['operation'] ?? '') : null;
    }

    /**
     * Nový řádek bez pořadí (sub-formulář `order_pos` nepředává — ruční
     * pole „Pořadí" zaniklo) dostane MAX(order_pos) + 1 v rámci dokladu,
     * takže ručně přidávané řádky mají souvislé pořadí (issue #53, fáze 3).
     * Explicitní kladné `order_pos` (importy, výměnný formát, které si
     * číslují samy) zůstává. Update se nedotýká — pořadí existujících
     * řádků mění jen endpoint přesunu (FormController::subtableMove).
     */
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        parent::beforeSave($data, $originalData);
        // Předmět plnění smí nést jen řádek s kódem přenesení (#89 D7) — po
        // změně kódu DPH se nuluje bez hlášky.
        ReverseChargeSubjects::normalizeLine($this->db, $this->config, $data);
        // Procento se nezadává (#159 D1) — z kódu a data sazby řádku; datum
        // řádku jen při zdroji „vlastní datum na řádcích“ (D6).
        VatRateDates::applyToSubformLine(
            $this->db,
            $this->config,
            $data,
            derivePct: (int) ($data['row_kind'] ?? 1) === 1,
        );
        if ($originalData !== null) {
            return;
        }
        if ((int) ($data['order_pos'] ?? 0) > 0) {
            return;
        }
        $headId = (int) ($data['doc_head'] ?? 0);
        if ($headId <= 0 || $this->db === null) {
            return;
        }
        $data['order_pos'] = $this->nextOrderPos($headId);
    }

    private function nextOrderPos(int $headId): int
    {
        $max = $this->db->fetchSingle(
            'SELECT MAX([order_pos]) FROM [docs_core_rows] WHERE [doc_head] = %i',
            $headId,
        );
        return (int) ($max ?: 0) + 1;
    }

    /**
     * Typ a směr hlavičky pro validaci pohybu (cash_dir filtruje pohyby
     * pokladního dokladu, u ostatních typů je 0).
     *
     * @return array{doc_type: string, cash_dir: int}|null
     */
    private function loadHeadContext(mixed $headId): ?array
    {
        if ($headId === null || $headId === '' || $this->db === null) {
            return null;
        }
        $row = $this->db->fetch(
            'SELECT [doc_type], [cash_dir] FROM [docs_core_heads] WHERE [id] = %i',
            (int) $headId,
        );
        $docType = $row !== null ? (string) ($row['doc_type'] ?? '') : '';
        if ($docType === '') {
            return null;
        }
        return ['doc_type' => $docType, 'cash_dir' => (int) ($row['cash_dir'] ?? 0)];
    }

    public function afterSave(array $data): void
    {
        $this->recomputeHeader($data);
    }

    public function afterDelete(array $data): void
    {
        $this->recomputeHeader($data);
    }

    /**
     * Přepočet hlavičky po změně řádku — sdílený {@see DocHeadRecomputer}
     * (týž kód používá `VatRecapDocument`).
     */
    private function recomputeHeader(array $rowData): void
    {
        if ($this->db === null) {
            return;
        }
        $headId = (int) ($rowData['doc_head'] ?? 0);
        if ($headId === 0) {
            return;
        }
        (new DocHeadRecomputer($this->db, $this->config, $this->dsConfig, $this->settings))
            ->recompute($headId);
    }
}
