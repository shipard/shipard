<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormHeaderInfo;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Module\Base\Persons\Send\SendPurposes;

class PersonsForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $personType  = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
        $isCompany   = $personType === PersonType::Company;
        $isPerson    = $personType === PersonType::Person;
        $isUndefined = $personType === null || $personType === PersonType::Undefined;

        $personTypeOptions = $this->resolvePersonTypeOptions();

        // ── Tab: Základní údaje ──────────────────────────────────────────────
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->select(
                        'person_type',
                        options: $personTypeOptions,
                        triggers: 'reload',
                        required: true,
                    )
                    ->input(
                        'full_name',
                        required: $isCompany,
                        hidden: $isPerson,
                    )

            ->section(title: 'Identifikace firmy', hidden: $isPerson || $isUndefined)
                ->col()
                    ->inline()
                        ->input('company_id')
                        ->input('tax_id')
                    ->endInline()

            ->section(title: 'Jméno', hidden: $isCompany || $isUndefined)
                ->col()
                    ->input('title_before')
                    ->inline()
                        ->input('first_name', required: $isPerson)
                        ->input('middle_name')
                        ->input('last_name', required: $isPerson)
                    ->endInline()
                    ->input('title_after')

            ->section(title: 'Osobní údaje', hidden: $isCompany || $isUndefined)
                ->col()
                    ->inline()
                        ->date('birth_date')
                        ->input('national_id')
                    ->endInline()
                    ->input('id_card_number')

            ->section(title: 'Kontakt')
                ->col()
                    ->inline()
                        ->input('email', inputType: 'email')
                        ->input('phone', inputType: 'tel')
                    ->endInline()
                    ->input('web', inputType: 'url')
            ->build();

        // ── Subtable a attachments taby ──────────────────────────────────────
        $contacts     = $this->subtableTab('contacts',      'Kontakty',      'base_persons_contacts',      'person', 'base.persons.contacts');
        $addresses    = $this->subtableTab('addresses',     'Adresy',        'base_persons_addresses',     'person', 'base.persons.addresses');
        $bankAccounts = $this->subtableTab('bank_accounts', 'Bankovní účty', 'base_persons_bank_accounts', 'person', 'base.persons.bank_accounts');

        // ── Tab: Nastavení (úplně na konci, za Přílohami) ───────────────────
        $settingsTab = $this->tab('settings', 'Nastavení')
            ->section(title: 'Identifikace')
                ->col()
                    ->input('person_id', required: true)

            ->section(title: 'Identifikace firmy - doplňující', hidden: $isPerson || $isUndefined)
                ->col()
                    ->input('vat_id')
                    ->input('court_registration')
                    ->checkbox('is_own')

            ->section(title: 'Obchodní podmínky')
                ->col()
                    ->number('payment_term_days')

            // Jazyk je výjimka, ne povinný údaj (#94 D1): prázdná volba =
            // automaticky podle země adresy na dokladu.
            ->section(title: 'Dokumenty')
                ->col()
                    ->select(
                        'language',
                        options: $this->resolveDocumentLanguageOptions(),
                        placeholder: $this->languageAutoLabel(),
                    );

        // Spojování příloh je extension z docs.core — bez modulu sloupec není.
        if ($this->hasColumn('send_attachments_merged')) {
            $settingsTab->checkbox('send_attachments_merged');
        }
        $settings = $settingsTab->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Osoba',
            titleNew: 'Nová osoba',
            tabs: [$basic, $contacts, $addresses, $bankAccounts, $this->attachmentsTab(), $settings],
        );
    }

    // ── Sub-tabulky: Kontakty / Adresy / Bankovní účty ──────────────────────

    /**
     * Sloupce sub-tabulek osoby (issue #53, fáze 1). Výběr podle JSONC
     * definic `base_persons_{contacts,addresses,bank_accounts}`:
     *
     *  - Kontakty: Název · Funkce · E-mail · Telefon · Poznámka
     *    (tabulka nemá „typ kontaktu / hodnotu" — kontakt je osoba s rolí).
     *  - Adresy: Typ adresy · Název · Ulice a číslo · Obec · PSČ · Země
     *    (země = `name` z cfgItem `world.base.countries`; příznak
     *    hlavní/fakturační v tabulce neexistuje — typ adresy ho nahrazuje).
     *  - Bankovní účty: Název účtu · Číslo účtu · IBAN · BIC/SWIFT · Měna · Zdroj.
     *
     * Všechny tři tabulky mají docStates (archivační model) — `stateStyle`
     * řádku doplní `TableForm::subtableRowStateStyle()`: archivované řádky
     * jsou tlumené, ale zobrazené (uživatel je potřebuje najít
     * a odarchivovat). Labely z definic tabulek (lokalizované), fallback
     * česky.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $parentData
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        return match ($tab->id) {
            'contacts'      => $this->renderPersonChildRows($tab, $rows, [
                'name'  => ['Název', true],
                'role'  => ['Funkce', false],
                'email' => ['E-mail', false],
                'send_purposes' => ['Účely odesílání', false],
                'phone' => ['Telefon', false],
                'note'  => ['Poznámka', false],
            ]),
            'addresses'     => $this->renderPersonChildRows($tab, $rows, [
                'address_type' => ['Typ adresy', false],
                'name'         => ['Název', false],
                'street'       => ['Ulice', true],
                'city'         => ['Obec', false],
                'zip'          => ['PSČ', false],
                'country'      => ['Země', false],
            ]),
            'bank_accounts' => $this->renderPersonChildRows($tab, $rows, [
                'name'           => ['Název účtu', false],
                'account_number' => ['Číslo účtu', true],
                'iban'           => ['IBAN', false],
                'bic'            => ['BIC/SWIFT', false],
                'currency'       => ['Měna', false],
                'source'         => ['Zdroj', false],
            ]),
            default         => parent::renderSubtable($tab, $rows, $parentData),
        };
    }

    /**
     * Společný renderer tří sub-tabulek osoby: sloupce ve zvoleném pořadí,
     * buňky přes typ sloupce z definice dětské tabulky (cfgItem → label,
     * datum → d.m.Y…), plus per-sloupec výjimky: `street` skládá ulici
     * s číslem popisným/orientačním, `currency` velkými písmeny,
     * `send_purposes` kontaktu jako štítky s názvy účelů.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, array{string, bool}> $spec column id → [fallback label, grow]
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, mixed>>, order_column: ?string}
     */
    private function renderPersonChildRows(FormTab $tab, array $rows, array $spec): array
    {
        $table = (string) ($tab->subtable['table'] ?? '');
        $childDef = $this->tables[$table] ?? null;
        $colDefs = [];
        if ($childDef !== null) {
            foreach ($childDef->columns as $col) {
                $colDefs[$col->id] = $col;
            }
        }

        $columns = [];
        foreach ($spec as $id => [$fallback, $grow]) {
            $column = ['id' => $id, 'label' => $this->subtableLabel($table, $id, $fallback)];
            if ($grow) {
                $column['grow'] = true;
            }
            $columns[] = $column;
        }

        $out = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($spec as $id => $_) {
                $text = match ($id) {
                    'send_purposes' => $this->purposeBadges($row['send_purposes'] ?? null),
                    'street'   => $this->formatStreet($row),
                    'currency' => isset($row['currency']) && $row['currency'] !== ''
                        ? strtoupper((string) $row['currency'])
                        : null,
                    default    => isset($colDefs[$id])
                        ? $this->defaultSubtableCell($colDefs[$id], $row[$id] ?? null)
                        : $this->plainCell($row[$id] ?? null),
                };
                if ($text !== null) {
                    $cells[$id] = $text;
                }
            }
            $entry = ['id' => (int) ($row['id'] ?? 0), 'cells' => $cells];
            $style = $childDef !== null ? $this->subtableRowStateStyle($childDef, $row) : null;
            if ($style !== null) {
                $entry['stateStyle'] = $style;
            }
            $out[] = $entry;
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => null];
    }

    /**
     * Účely odesílání kontaktu (#90 D34) jako štítky; bez účelu prázdná buňka.
     *
     * @return list<array{text: string, badge: string}>|null
     */
    private function purposeBadges(mixed $value): ?array
    {
        $labels = SendPurposes::labels(SendPurposes::decode($value) ?? [], $this->config);
        if ($labels === []) {
            return null;
        }
        return array_map(static fn (string $label): array => ['text' => $label, 'badge' => 'neutral'], $labels);
    }

    /**
     * „Ulice 12/3": ulice + číslo popisné, orientační za lomítkem; bez
     * ulice jen čísla, bez čehokoli prázdno.
     *
     * @param array<string, mixed> $row
     */
    private function formatStreet(array $row): ?string
    {
        $street = trim((string) ($row['street'] ?? ''));
        $house  = trim((string) ($row['house_number'] ?? ''));
        $orient = trim((string) ($row['orientation_number'] ?? ''));
        $number = $house;
        if ($orient !== '') {
            $number = $number !== '' ? "{$number}/{$orient}" : $orient;
        }
        $text = trim("{$street} {$number}");
        return $text !== '' ? $text : null;
    }

    private function plainCell(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }
        return (string) $value;
    }

    public function buildHeaderInfo(array $data): ?FormHeaderInfo
    {
        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '') {
            return null;
        }

        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));

        $info = [];
        $icon = null;
        if ($personType === PersonType::Company) {
            $icon = 'company';
            $companyId = trim((string) ($data['company_id'] ?? ''));
            if ($companyId !== '') {
                $info[] = ['label' => 'IČO', 'value' => $companyId];
            }
        } elseif ($personType === PersonType::Person) {
            $icon = 'user';
            $birthDate = $data['birth_date'] ?? null;
            if ($birthDate !== null && $birthDate !== '') {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $birthDate);
                if ($dt instanceof \DateTimeImmutable) {
                    $info[] = ['label' => 'Datum narození', 'value' => $dt->format('d.m.Y')];
                }
            }
        } else {
            return null;
        }

        $personId = trim((string) ($data['person_id'] ?? ''));
        if ($personId !== '') {
            $info[] = ['label' => 'Kód osoby', 'value' => $personId];
        }

        return new FormHeaderInfo(title: $fullName, info: $info, icon: $icon);
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        $personType = PersonType::tryFrom((int) ($data['person_type'] ?? 0));
        $isPerson = $personType === PersonType::Person;

        if ($isPerson) {
            $data['full_name'] = trim(
                ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''),
            );
        }

        $formDefinition = $this->buildFormDefinition($data, empty($data['id']));
        return new RecalculateResult($formDefinition, $data);
    }

    /** @return list<array{value: int|string, label: string}> */
    private function resolveDocumentLanguageOptions(): array
    {
        $cfgData = $this->config?->cfgItem('world.base.documentLanguages');
        if (!is_array($cfgData)) {
            return [];
        }
        return EnumOptionsHelper::fromCfgData($cfgData, 'enumString', 'world.base.documentLanguages');
    }

    /** Text prázdné volby jazyka dokumentů (cfgItem `base.persons.formLabels`). */
    private function languageAutoLabel(): string
    {
        $labels = $this->config?->cfgItem('base.persons.formLabels');
        return $labels['languageAuto']['name'] ?? 'Automatic (by country)';
    }

    private function hasColumn(string $column): bool
    {
        foreach ($this->tableDef?->columns ?? [] as $col) {
            if ($col->id === $column) {
                return true;
            }
        }
        return false;
    }

    private function resolvePersonTypeOptions(): array
    {
        if ($this->config === null) {
            return [];
        }

        $cfgData = $this->config->cfgItem('base.persons.personTypes');
        if (!is_array($cfgData)) {
            return [];
        }

        $options = [];
        foreach ($cfgData as $key => $entry) {
            if (is_array($entry) && isset($entry['name'])) {
                $options[] = ['value' => (int) $key, 'label' => $entry['name']];
            }
        }
        return $options;
    }
}
