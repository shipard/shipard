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
 * kód, sazbu, základ, daň a celkem. Přepočítaná rekapitulace se needituje
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
        $codeOptions = DocHeadVatContext::vatCodeOptions($headContext, $this->config);
        $fromTotal = (int) ($headContext['vat_mode'] ?? 1) === 2;

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->select('vat_code',
                        options: $codeOptions,
                        triggers: 'reload',
                        required: true,
                    )
                    ->number('vat_pct', required: true, hint: 'Sazba z dokladu — sazebník ji předplní podle kódu')
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
            $options = DocHeadVatContext::vatCodeOptions($headContext, $this->config);
            if ($options !== []) {
                $data['vat_code'] = $options[0]['value'];
            }
        }
        $this->deriveVatPct($data, $headContext);
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'vat_code') {
            $headContext = DocHeadVatContext::load($this->db, $this->config, $data['doc_head'] ?? null);
            // Sazbu jen předplníme — na dokladu může být historická nebo
            // jinak zaokrouhlená a ta je pro převzatou rekapitulaci fakt.
            $data['vat_pct'] = null;
            $this->deriveVatPct($data, $headContext);
        }

        $isNew = !isset($data['id']) || $data['id'] === null || $data['id'] === '';
        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
    }

    /**
     * Sazba kódu k datu sazby dokladu (DUZP; u nedaňového dokladu datum
     * vystavení, `DocHeadVatContext`) — jen předvolba, uživatel ji smí přepsat
     * (spec `docs/vat-calculation.md` § 5: sazba z dokladu je vstup,
     * neshoda se sazebníkem je warning, ne blok).
     *
     * @param array<string, mixed>|null $headContext
     */
    private function deriveVatPct(array &$data, ?array $headContext): void
    {
        if (!empty($data['vat_pct'])
            || empty($data['vat_code'])
            || $headContext === null
            || empty($headContext['country'])
            || empty($headContext['vat_rate_date'])
            || $this->config === null
        ) {
            return;
        }
        try {
            $data['vat_pct'] = (new VatRateResolver($this->config))->resolveVatPct(
                (string) $headContext['country'],
                (string) $data['vat_code'],
                (string) $headContext['vat_rate_date'],
            );
        } catch (\LogicException) {
            // Kód bez sazby k datu — sazbu zadá uživatel z dokladu.
        }
    }
}
