<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Mail\AllowedSenders;
use Shipard\Core\Settings\SettingsStore;

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
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Číselná řada',
            titleNew: 'Nová číselná řada',
            tabs: [$basic, $this->attachmentsTab()],
        );
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
        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
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
