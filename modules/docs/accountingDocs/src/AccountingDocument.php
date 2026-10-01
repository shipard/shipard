<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\AccountingDocs;

use Shipard\Core\Document\ValidationError;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Docs\Core\DocRowOperationRules;
use Shipard\Module\Docs\Core\DocsHeadsDocument;

/**
 * Účetní doklad (FÚD) — `doc_type = 'cmnbkp'`.
 *
 * Ruční účetní doklad s řádky kontace: každý řádek nese stranu MD/DAL
 * (`acc_side`), účet (přímo na řádku — operace `acc.record`, nebo z položky
 * typu 2 — `acc.item`) a volitelnou per-řádkovou saldo identitu (partner +
 * VS/SS/KS + splatnost). Bez obchodního i daňového směru, bez DPH (D4).
 *
 * Liší se od faktur ve třech bodech:
 *   - hlavičkový partner je nepovinný (žije per řádek),
 *   - součty se počítají z řádků (Σ MD), ne z rekapitulace DPH,
 *   - při potvrzení (stav 40) se kontrola vyrovnanosti Σ MD == Σ DAL.
 */
class AccountingDocument extends DocsHeadsDocument
{
    /** Hlavičkový partner nepovinný — saldo identita je per řádek. */
    protected function headPartnerRequired(): bool
    {
        return false;
    }

    /**
     * cmnbkp má vlastní sumTotals (Σ MD z řádků) a nesmí do součtů přičítat
     * řádky mimo DPH rekapitulaci — jinak by base-class fallback v
     * applyDomesticAmounts sečetl obě strany kontace do total_base_dom.
     */
    protected function headTotalsIncludeRowsOutsideRecap(): bool
    {
        return false;
    }

    public function validate(array &$data): ValidationResult
    {
        // cmnbkp je bez DPH (useTax:0): vat_mode 0 vypne v bázi požadavek na
        // vat_registration a zajistí vat_base = total_price (→ engine).
        $data['vat_mode'] = 0;

        $result = parent::validate($data);

        if ((int) ($data['docState'] ?? 10) === 40) {
            $this->validateBalance($data, $result);
        }

        return $result;
    }

    /**
     * cmnbkp je bez DPH: `vat_mode` 0 platí i pro výpočet bez uložení —
     * náhled návrhu volá `computeAmounts()` přímo a `beforeSave` obchází
     * (tasks/exchange-preview-vat-recompute.md D1). Uložení jde tudy taky.
     */
    public function computeAmounts(array &$data, ?array $originalData = null): array
    {
        $data['vat_mode'] = 0;
        return parent::computeAmounts($data, $originalData);
    }

    /**
     * Součty z řádků: total_amount = Σ total_price řádků na straně MD
     * (acc_side = 0). Vyrovnaný doklad má Σ MD == Σ DAL, takže total_amount
     * je hodnota dokladu. Samovyvažující řádek (FX) účtuje MD i DAL stejnou
     * částkou — do Σ MD se počítá jednou, uložený acc_side se ignoruje
     * (migrace ho může poslat ze zdroje). Bez DPH a zaokrouhlení.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function sumTotals(array &$data, array $recap, array $rows = []): void
    {
        $cfgOps = $this->rowOperationsCfg();

        $sumDr = 0.0;
        foreach ($rows as $row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                continue;
            }
            $selfBalancing = DocRowOperationRules::isSelfBalancing(
                (string) ($row['operation'] ?? ''),
                $cfgOps,
            );
            if ($selfBalancing || (int) ($row['acc_side'] ?? 0) === 0) {
                $sumDr += (float) ($row['total_price'] ?? 0);
            }
        }

        $data['total_base']     = round($sumDr, 2);
        $data['total_vat']      = 0.0;
        $data['total_amount']   = round($sumDr, 2);
        $data['total_rounding'] = 0.0;
    }

    /**
     * Kontrola vyrovnanosti při potvrzení (stav 40): každý kontační řádek
     * musí mít stranu, účet (acc.record) nebo položku (acc.item) a nenulovou
     * částku; Σ MD musí být rovno Σ DAL. Samovyvažující operace
     * (`selfBalancing: 1` — kroky předpisu pokrývají obě strany, FX) stranu
     * nenesou: řádek se počítá do MD i DAL stejnou částkou a případný
     * uložený acc_side se ignoruje. Chyby řádků konvencí
     * `rows.{index}.{column}`, nevyrovnanost jako form-level chyba.
     */
    private function validateBalance(array &$data, ValidationResult $result): void
    {
        $cfgOps = $this->rowOperationsCfg();

        $sumDr = 0.0;
        $sumCr = 0.0;

        foreach ($this->resolveRowsForCompute($data) as $i => $row) {
            if ((int) ($row['row_kind'] ?? 1) !== 1) {
                continue;
            }

            $side  = $row['acc_side'] ?? null;
            $total = (float) ($row['total_price'] ?? 0);
            $op    = (string) ($row['operation'] ?? '');

            if ($total === 0.0) {
                $result->addError("rows.{$i}.total_price", 'Částka řádku nesmí být nulová', 'amount_required');
            }

            if (DocRowOperationRules::isSelfBalancing($op, $cfgOps)) {
                $sumDr += $total;
                $sumCr += $total;
                continue;
            }

            if ($side === null || $side === '') {
                $result->addError("rows.{$i}.acc_side", 'Vyberte stranu (Má dáti / Dal)', 'acc_side_required');
            }
            // Přímý účet na řádku (acc.record, asset.*) — vlajka operace.
            if (($cfgOps[$op]['rowAccount'] ?? ($op === 'acc.record' ? 'direct' : null)) === 'direct'
                && empty($row['account'])
            ) {
                $result->addError("rows.{$i}.account", 'Účetní zápis musí mít vyplněný účet', 'account_required');
            }
            if ($op === 'acc.item' && empty($row['item'])) {
                $result->addError("rows.{$i}.item", 'Účetní položka musí mít vyplněnou položku', 'item_required');
            }

            if ((int) $side === 0) {
                $sumDr += $total;
            } else {
                $sumCr += $total;
            }
        }

        if (round($sumDr, 2) !== round($sumCr, 2)) {
            $result->addError(
                ValidationError::FIELD_FORM,
                sprintf('Doklad není vyrovnaný: Má dáti %.2f ≠ Dal %.2f', $sumDr, $sumCr),
                'unbalanced',
            );
        }
    }

    /**
     * cfgItem docs.core.rowOperations; bez configu (unit testy, degradovaný
     * běh) prázdné pole — žádná operace pak není samovyvažující.
     *
     * @return array<string, mixed>
     */
    private function rowOperationsCfg(): array
    {
        $cfg = $this->config?->cfgItem('docs.core.rowOperations');
        return is_array($cfg) ? $cfg : [];
    }
}
