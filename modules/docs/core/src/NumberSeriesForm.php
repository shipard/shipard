<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\System\ActiveUsersOptions;

class NumberSeriesForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        if ($isNew && empty($data['reset_scope'])) {
            $data['reset_scope'] = 'fiscal_year';
        }

        $docTypeOptions    = $this->resolveOptions('docs.core.docTypes');
        $resetScopeOptions = $this->resolveOptions('docs.core.resetScopes');

        // Vazba na entitu: pole jen pro typ s odpovídajícím series_binding,
        // po založení řady jen ke čtení (změna pokladny pod doklady nedává
        // smysl — řady z provisioneru i ruční).
        $binding = $this->resolveSeriesBinding((string) ($data['doc_type'] ?? ''));

        $basic = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    // Kód řady = přirozený klíč záznamu (#166 D17); u nové řady
                    // předvyplněný, prázdný doplní Document při uložení.
                    ->input('code', hint: $this->formText(
                        'docs.core.formTexts',
                        'numberSeries.codeHint',
                        'Unique code of the series — data sets and imports refer to the series by it. It is independent of the code in the document number. Left empty, it is filled in on save.',
                    ))
                    ->select(
                        'doc_type',
                        options: $docTypeOptions,
                        triggers: 'reload',
                        required: true,
                        readOnly: !$isNew,
                    )
                    ->lookup('cash_desk',
                        table: 'economy_codebooks_cash_desks',
                        required: $binding === 'cash_desk',
                        readOnly: !$isNew,
                        hidden: $binding !== 'cash_desk',
                        triggers: 'reload',
                    )
                    ->lookup('warehouse',
                        table: 'economy_codebooks_warehouses',
                        required: $binding === 'warehouse',
                        readOnly: !$isNew,
                        hidden: $binding !== 'warehouse',
                        triggers: 'reload',
                    )
                    ->input('doc_number_code')
                    ->input('doc_number_pattern', required: true, readOnly: !$isNew)
                    ->select('reset_scope', options: $resetScopeOptions, required: true)
                    ->separator('Platnost')
                    ->date('valid_from')
                    ->date('valid_to')
                    ->separator('Poznámka')
                    ->textarea('notice')
            // Odesílatel dokladů řady (#90 D39): nabídka povolených adres,
            // prázdná volba = výchozí adresa zdroje dat.
            ->section(title: 'Odesílání e-mailem')
                ->col()
                    ->select(
                        'email_from',
                        options: $this->senderOptions($data['email_from'] ?? null),
                        placeholder: 'Automaticky',
                        hint: 'Adresa, ze které odcházejí doklady této řady. Automaticky = výchozí adresa odesílatele z nastavení odchozí pošty.',
                    )
                    ->input(
                        'email_from_name',
                        hint: 'Jméno zobrazené u adresy odesílatele. Prázdné = název vlastní firmy.',
                    )
            // Autor dokladů řady vystavených bez uživatele (#93 D11); prázdná
            // volba = globální nastavení Doklady. Pořadí drží DocAuthorResolver.
            ->section(title: 'Automaticky vystavené doklady')
                ->col()
                    ->select(
                        'auto_author',
                        options: $this->db !== null
                            ? ActiveUsersOptions::forForm($this->db, $data['auto_author'] ?? null)
                            : [],
                        placeholder: 'Podle nastavení',
                        hint: 'Použije se u dokladů vystavených bez přihlášeného uživatele (např. automaticky). Prázdné = autor z nastavení Doklady.',
                    )
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Číselná řada',
            titleNew: 'Nová číselná řada',
            tabs: [$basic, $this->attachmentsTab()],
        );
    }

    /**
     * Kód řady jde měnit i u řady ve stavu V pořádku (#166 U4) — odkazy
     * v databázi jsou přes id. Document zvládá částečné uložení (merge
     * s uloženým řádkem).
     */
    public function getReadOnlyEditableColumns(): array
    {
        return ['code'];
    }

    /** Nová řada otevřená s typem dokladu (prefill) dostane rovnou návrh kódu. */
    public function applyNewRecordDefaults(array &$data): void
    {
        if (trim((string) ($data['code'] ?? '')) === '') {
            $suggested = $this->suggestCode($data);
            if ($suggested !== null) {
                $data['code'] = $suggested;
            }
        }
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if ($changedColumn === 'doc_type' && !empty($data['doc_type'])) {
            $docTypes = $this->config?->cfgItem('docs.core.docTypes');

            if (is_array($docTypes) && isset($docTypes[$data['doc_type']])) {
                $entry = $docTypes[$data['doc_type']];

                if (empty($data['doc_number_pattern'])
                    && isset($entry['doc_number_pattern_default'])
                ) {
                    $data['doc_number_pattern'] = (string) $entry['doc_number_pattern_default'];
                }

                if (empty($data['name']) && isset($entry['name'])) {
                    $data['name'] = (string) $entry['name'];
                }
            }

            // Cascading reset: vazba patřila k předchozímu typu (jiný
            // series_binding) — skrytá vyplněná hodnota by padla ve validaci.
            foreach (array_keys(NumberSeriesDocument::BINDINGS) as $column) {
                $data[$column] = null;
            }
        }

        // Výběr entity: kód řady = kód entity (konvence provisioneru,
        // %C ve vzorci), jen když je kód ještě prázdný.
        if (isset(NumberSeriesDocument::BINDINGS[$changedColumn])
            && !empty($data[$changedColumn])
            && empty($data['doc_number_code'])
            && $this->db !== null
        ) {
            $table = NumberSeriesDocument::BINDINGS[$changedColumn]['table'];
            $row = $this->db->fetchRow(
                'SELECT `code` FROM `' . $table . '` WHERE `id` = %i',
                (int) $data[$changedColumn],
            );
            if ($row !== null && !empty($row['code'])) {
                $data['doc_number_code'] = (string) $row['code'];
            }
        }

        $isNew = empty($data['id']);

        // Kód nové řady se odvozuje z typu a vázané entity (#166 D17) — při
        // jejich změně se navrhne znovu, jinak by zůstal kód předchozí volby
        // (`invno-2` u řady přijatých faktur). Vázaný typ bez vybrané
        // entity návrh nemá.
        if ($isNew && ($changedColumn === 'doc_type' || isset(NumberSeriesDocument::BINDINGS[$changedColumn]))) {
            $data['code'] = $this->suggestCode($data);
        }

        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
    }

    /**
     * Návrh kódu nové řady podle {@see NumberSeriesCodeGenerator}; null =
     * zatím nejde určit (chybí typ, vázaný typ bez entity, formulář bez DB).
     *
     * @param array<string, mixed> $data
     */
    private function suggestCode(array $data): ?string
    {
        $docType = (string) ($data['doc_type'] ?? '');
        if ($this->db === null || $docType === '') {
            return null;
        }
        $binding = $this->resolveSeriesBinding($docType);
        if ($binding !== null && empty($data[$binding])) {
            return null;
        }
        return (new NumberSeriesCodeGenerator($this->db->getDibiConnection()))->generate(['id' => null] + $data);
    }

    /**
     * Adresy, ze kterých zdroj dat smí odesílat (`AllowedSenders`). Uložená
     * adresa, která mezi nimi už není (odesílatel deaktivován), v nabídce
     * zůstává s poznámkou — jinak by ji select tiše vyprázdnil; odeslání
     * z ní skončí chybou `SENDER_NOT_ALLOWED`.
     *
     * @return list<array{value: string, label: string}>
     */
    private function senderOptions(mixed $current): array
    {
        $options = [];
        $current = trim((string) ($current ?? ''));
        $found   = $current === '';

        if ($this->db !== null) {
            $allowed = new AllowedSenders($this->db, new SettingsStore($this->db));
            foreach ($allowed->addresses() as $address) {
                $options[] = ['value' => $address['email'], 'label' => $address['email']];
                $found = $found || mb_strtolower($address['email']) === mb_strtolower($current);
            }
        }
        if (!$found) {
            $options[] = ['value' => $current, 'label' => $current . ' (nepovolená adresa)'];
        }
        return $options;
    }

    /** `series_binding` typu dokladu z cfg; null = nevázaný / neznámý typ. */
    private function resolveSeriesBinding(string $docType): ?string
    {
        if ($docType === '' || $this->config === null) {
            return null;
        }
        $docTypes = $this->config->cfgItem('docs.core.docTypes');
        $binding = is_array($docTypes) ? ($docTypes[$docType]['series_binding'] ?? null) : null;
        return is_string($binding) && $binding !== '' ? $binding : null;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function resolveOptions(string $cfgItemId): array
    {
        if ($this->config === null) {
            return [];
        }
        $cfg = $this->config->cfgItem($cfgItemId);
        if (!is_array($cfg)) {
            return [];
        }

        $options = [];
        foreach ($cfg as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (string) $key, 'label' => (string) $entry['name']];
            }
        }
        return $options;
    }
}
