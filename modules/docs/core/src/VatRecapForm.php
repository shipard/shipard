<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Formulář řádku rekapitulace DPH (`docs_core_vat_recap`) — sub-formulář
 * tabu „Rekapitulace DPH" u dokladu s **převzatou** rekapitulací
 * (`vat_recap_source = 1`). Účetní tu opisuje, co je na dokladu:
 * kód, základ, daň a celkem. Sazba se nezadává (#159 D1, D8) — odvodí se
 * z kódu a data sazby, je vidět v popisku kódu; rekapitulaci dodavatele
 * v jiné sazbě ukáže warning `vat_recap_inconsistent`. Při zdroji „vlastní
 * datum na řádcích“ má řádek své datum sazby. Přepočítaná rekapitulace se needituje
 * (tab je u ní jen ke čtení), takže tenhle formulář se pro ni neotvírá.
 *
 * Domácí měna a flagy sčítání ve formuláři nejsou — dopočítává je
 * `DocDocument::beforeSave` z kurzu dokladu a z definice kódu (autorita
 * definice, ne vstupu). Kontroly konzistence jsou warningy na hlavičce
 * dokladu (`vat_recap_inconsistent`), aby haléřová nesrovnalost
 * dodavatele nešla proti uložení.
 */
class VatRecapForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $headContext = DocHeadVatContext::load($this->db, $this->config, $data['doc_head'] ?? null);
        $rateDate = $this->lineRateDate($data, $headContext);
        $codeOptions = $this->vatCodeOptions($headContext, $data);
        $showRateDate = (int) ($headContext['vat_rate_source'] ?? 0) === VatRateDates::SOURCE_ROWS;
        $fromTotal = (int) ($headContext['vat_mode'] ?? 1) === 2;
        // Předmět plnění jen u kódu tuzemského přenesení (#89 D14).
        $showSubject = ReverseChargeSubjects::fieldVisible($headContext, $this->config, $data['vat_code'] ?? null);

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->date(VatRateDates::COLUMN,
                        triggers: 'reload',
                        hidden: !$showRateDate,
                        hint: 'Prázdné = podle dokladu',
                    )
                    ->select('vat_code',
                        options: $codeOptions,
                        triggers: 'reload',
                        required: true,
                    )
                    ->select(ReverseChargeSubjects::COLUMN,
                        options: ReverseChargeSubjects::options($headContext, $this->config, $rateDate),
                        required: $showSubject,
                        hidden: !$showSubject,
                    )
                    ->number('base', required: true)
                    ->number('tax', required: true)
                    ->number('total',
                        required: true,
                        hint: $fromTotal
                            ? 'Celkem s daní — na dokladu v cenách s DPH je to součet cen řádků v sazbě'
                            : 'Základ + daň; u přenesení daňové povinnosti jen základ',
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Řádek rekapitulace DPH',
            titleNew: 'Nový řádek rekapitulace DPH',
            tabs: [$basic],
        );
    }

    public function applyNewRecordDefaults(array &$data): void
    {
        $headContext = DocHeadVatContext::load($this->db, $this->config, $data['doc_head'] ?? null);
        if (empty($data['vat_code'])) {
            $options = $this->vatCodeOptions($headContext, $data);
            if ($options !== []) {
                $data['vat_code'] = $options[0]['value'];
            }
        }
        $this->deriveVatPct($data, $headContext);
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $headContext = DocHeadVatContext::load($this->db, $this->config, $data['doc_head'] ?? null);
        if ($changedColumn === 'vat_code') {
            // Kód bez přenesení předmět plnění nenese (#89 D7).
            if (!ReverseChargeSubjects::fieldVisible($headContext, $this->config, $data['vat_code'] ?? null)) {
                $data[ReverseChargeSubjects::COLUMN] = null;
            }
        }
        // Sazba z kódu a data sazby řádku (#159 D1) — při změně kódu i data.
        $this->deriveVatPct($data, $headContext);

        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
    }

    /**
     * Datum sazby řádku rekapitulace ({@see VatRateDates::lineDate()}).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $headContext
     */
    private function lineRateDate(array $data, ?array $headContext): ?string
    {
        return $headContext !== null ? VatRateDates::lineDate($headContext, $data, $this->config) : null;
    }

    /**
     * Nabídka kódů DPH k datu sazby řádku, s procentem v popisku (#159 D7,
     * U9) — stejná jako v řádku dokladu.
     *
     * @param array<string, mixed>|null $headContext
     * @param array<string, mixed> $data
     * @return list<array{value: string, label: string}>
     */
    private function vatCodeOptions(?array $headContext, array $data): array
    {
        return DocHeadVatContext::vatCodeOptions(
            $headContext,
            $this->config,
            $this->lineRateDate($data, $headContext),
            $data['vat_code'] ?? null,
            $this->formText('docs.core.formTexts', 'vatCode.noRate', '{label} — no rate at this date'),
            // „Mimo DPH“ do rekapitulace nepatří (#164 U6) — nenabízí se.
            includeOutOfScope: false,
        );
    }

    /**
     * Sazba řádku z kódu a data sazby řádku (#159 D1, D8) — stejné pravidlo
     * jako při uložení (`VatRateDates::pct`). Sazba není vstup; kód bez sazby
     * k datu ji nechává, jak je — hlásí to validace při uložení.
     *
     * @param array<string, mixed>|null $headContext
     */
    private function deriveVatPct(array &$data, ?array $headContext): void
    {
        if ($headContext === null || empty($headContext['country']) || $this->config === null) {
            return;
        }
        $pct = VatRateDates::pct(
            new VatRateResolver($this->config),
            (string) $headContext['country'],
            $data['vat_code'] ?? null,
            $this->lineRateDate($data, $headContext),
        );
        if ($pct !== null) {
            $data['vat_pct'] = $pct;
        }
    }
}
