<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Document\Document;
use Shipard\Core\Document\ValidationResult;

/**
 * Document pro `docs_core_vat_recap` — existuje jen pro **převzatou**
 * rekapitulaci (`vat_recap_source = 1`), kterou uživatel edituje v
 * sub-tabulce tabu „Rekapitulace DPH". Přepočítaná rekapitulace vzniká
 * celá v `DocDocument::beforeSave` a přes tuto třídu nechodí.
 *
 * Úkol je stejný jako u `DocRowsDocument`: po změně řádku rekapitulace
 * přepočítat hlavičku (součty, domácí měnu, dorovnání řádků) —
 * {@see DocHeadRecomputer}. Částky rekapitulace zůstávají tím, co zadal
 * uživatel; přepočet je nepřepisuje.
 *
 * Pozor: účtování se přepočítává na přechodu stavu, ne na změně dětské
 * tabulky. Po ruční opravě rekapitulace zaúčtovaného dokladu je potřeba
 * zaúčtovat znovu — stejně jako po změně řádků.
 */
class VatRecapDocument extends Document
{
    /**
     * Předmět plnění tuzemského přenesení (#89 D7): hodnota mimo číselník
     * nebo neplatná k datu sazby je chyba vždy; chybějící předmět u kódu
     * přenesení hlídá až potvrzení dokladu (`DocDocument::validate`).
     */
    public function validate(array &$data): ValidationResult
    {
        $result = parent::validate($data);
        if (empty($data['is_reverse_pair']) && !empty($data[ReverseChargeSubjects::COLUMN])) {
            $problem = ReverseChargeSubjects::lineProblem($this->db, $this->config, $data);
            if ($problem !== null) {
                $result->addError(ReverseChargeSubjects::COLUMN, $problem['message'], $problem['code']);
            }
        }
        return $result;
    }

    /**
     * Nový řádek rekapitulace bez pořadí dostane MAX(order_pos) + 1
     * v rámci dokladu — stejná úmluva jako u řádků dokladu.
     */
    public function beforeSave(array &$data, ?array $originalData = null): void
    {
        parent::beforeSave($data, $originalData);
        // Předmět jen u kódu přenesení a u oddaňovacího páru (#89 D7).
        ReverseChargeSubjects::normalizeLine($this->db, $this->config, $data);
        if ($originalData !== null || (int) ($data['order_pos'] ?? 0) > 0) {
            return;
        }
        $headId = (int) ($data['doc_head'] ?? 0);
        if ($headId <= 0 || $this->db === null) {
            return;
        }
        $max = $this->db->fetchSingle(
            'SELECT MAX([order_pos]) FROM [docs_core_vat_recap] WHERE [doc_head] = %i',
            $headId,
        );
        $data['order_pos'] = (int) ($max ?: 0) + 1;
    }

    public function afterSave(array $data): void
    {
        $this->recomputeHeader($data);
    }

    public function afterDelete(array $data): void
    {
        $this->recomputeHeader($data);
    }

    private function recomputeHeader(array $recapData): void
    {
        if ($this->db === null) {
            return;
        }
        $headId = (int) ($recapData['doc_head'] ?? 0);
        if ($headId === 0) {
            return;
        }
        (new DocHeadRecomputer($this->db, $this->config, $this->dsConfig, $this->settings))
            ->recompute($headId);
    }
}
