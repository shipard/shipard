<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Formulář podání DPH (#55 D20). Vybírá se instance tvrzení a druh podání;
 * všechno ostatní (pořadí, název, typ, předchozí podání) dopočítá
 * `FilingDocument` a snapshot sestaví `FilingComposer`.
 *
 * Druh podání závisí na typu vybrané instance, proto má instance
 * `triggers: reload` — po změně se roleta druhů přestaví. Datum zjištění
 * důvodů se ukazuje jen u druhů, kde je povinné; datum podání jen dokud
 * je podání koncept (po podání ho drží guard).
 *
 * Záložka **Hlavička** (#55 Fáze 3, X2) drží snapshot věty P a needvozených
 * polí věty D. Kreslí se u existujícího podání — u nového ještě hlavička
 * neexistuje, předvyplní ji composer při sestavení. Ve stavech Podáno
 * a Zrušeno je formulář read-only ze stavu dokladu, takže tab slouží
 * k nahlédnutí, co se podalo.
 */
class FilingsForm extends TableForm
{
    /**
     * Zrcadlo `FilingDocument::structuredSchemaFor()` — obě strany musí
     * vybrat totéž schéma, jinak by se hlavička kreslila podle jiné sady
     * polí, než jakou gateway uloží (docs/structured-fields.md § 5).
     */
    public function structuredSchemaFor(string $column, array $data): ?string
    {
        if ($column !== FilingHeaderSchema::COLUMN) {
            return null;
        }

        $type     = (string) ($data['report_type'] ?? '');
        $periodId = (int) ($data['report_period'] ?? 0);
        if ($type === '' && $periodId > 0) {
            $type = (string) ($this->loadPeriod($periodId)['report_type'] ?? '');
        }
        return FilingHeaderSchema::forReportType($type);
    }

    /**
     * Výchozí hodnoty nového podání, které se **propíšou do formuláře**
     * (mutace v `buildFormDefinition` se do response `data` nedostanou).
     * Druh se odvozuje z instance: dokud za ni není nic podané, řádné.
     */
    public function applyNewRecordDefaults(array &$data): void
    {
        $periodOptions = $this->resolvePeriodOptions();
        if (empty($data['report_period']) && count($periodOptions) === 1) {
            $data['report_period'] = $periodOptions[0]['value'];
        }

        $periodId = (int) ($data['report_period'] ?? 0);
        if ($periodId <= 0 || !empty($data['filing_kind'])) {
            return;
        }
        $period  = $this->loadPeriod($periodId);
        $mapping = VatOutputsMapping::fromConfig($this->config);
        if ($period === null || $mapping === null) {
            return;
        }
        $kinds = $mapping->filingKinds((string) $period['report_type']);
        if ($kinds !== []) {
            $data['filing_kind'] = $this->defaultKind($periodId, $kinds);
        }
    }

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $periodOptions = $this->resolvePeriodOptions();
        if ($isNew && empty($data['report_period']) && count($periodOptions) === 1) {
            $data['report_period'] = $periodOptions[0]['value'];
        }

        $periodId = (int) ($data['report_period'] ?? 0);
        $period   = $periodId > 0 ? $this->loadPeriod($periodId) : null;
        $type     = $period !== null ? (string) $period['report_type'] : '';
        $mapping  = VatOutputsMapping::fromConfig($this->config);

        // Roleta druhů se přestavuje i při recalculate (kde
        // applyNewRecordDefaults neběží) — proto se default druhu počítá
        // na obou místech.
        $kinds = $type !== '' && $mapping !== null ? $mapping->filingKinds($type) : [];
        if ($isNew && empty($data['filing_kind']) && $kinds !== []) {
            $data['filing_kind'] = $this->defaultKind($periodId, $kinds);
        }
        $kind  = (string) ($data['filing_kind'] ?? '');
        $state = (int) ($data['docState'] ?? FilingDocument::DOC_STATE_COMPOSED);

        $column = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->select('report_period', options: $periodOptions, triggers: 'reload', required: true)
                    ->select('filing_kind', options: $this->kindOptions($kinds), triggers: 'reload', required: true);

        if ($this->isDateFoundRequired($mapping, $type, $kind)) {
            $column->date('date_found', hint: 'Den, kdy jste zjistili důvody pro podání — u dodatečného přiznání a následného hlášení je povinný.');
        }

        $column->separator('Datumy')
            ->date('date_issue', hint: 'Den sestavení podání. Prázdné = dnes.');

        // Datum podání se vyplňuje před přechodem do stavu Podáno; přechod
        // ho jinak doplní na dnešek.
        if ($state === FilingDocument::DOC_STATE_COMPOSED) {
            $column->date('date_filed', hint: 'Den odeslání na Finanční správu. Prázdné = doplní se při podání.');
        }

        $basic = $column
                    ->separator('Ostatní')
                    ->textarea('note', hint: 'Poznámka je jediné, co lze doplnit i k podanému podání.')
            ->build();

        $tabs = [$basic];

        // Hlavička podání = strukturované pole `header` se schématem per typ
        // tvrzení (#55 Fáze 3). U nového podání ještě neexistuje — vyplní ji
        // composer z profilu podatele při sestavení.
        if (!$isNew && $this->hasStructuredColumn(FilingHeaderSchema::COLUMN)) {
            $tabs[] = $this->tab('header', 'Hlavička')
                ->section()
                    ->col()
                        ->content([['type' => 'hint', 'text' => $this->formText(
                            'economy.vat.formTexts',
                            'filing.headerHint',
                            'Data that go into the header of the file for the tax portal. Pre-filled from the Filing details of the VAT registration and from your own company; changes here apply to this filing only.',
                        )]])
                        ->addElements($this->structuredFieldElements(FilingHeaderSchema::COLUMN, $data))
                ->build();
        }

        $tabs[] = $this->attachmentsTab();

        return new FormDefinition(
            table: $this->table,
            title: 'Podání DPH',
            titleNew: 'Nové podání DPH',
            tabs: $tabs,
        );
    }

    /**
     * Druh, který za instanci půjde podat: dokud není nic podané, řádné;
     * potom první z povolených navazujících (opravné, jinak následné či
     * dodatečné). Uživatel ho beztak může přepnout.
     *
     * @param list<string> $kinds
     */
    private function defaultKind(int $periodId, array $kinds): string
    {
        if (!$this->hasFiledFiling($periodId)) {
            return FilingDocument::KIND_REGULAR;
        }
        foreach ($kinds as $kind) {
            if ($kind !== FilingDocument::KIND_REGULAR) {
                return $kind;
            }
        }
        return FilingDocument::KIND_REGULAR;
    }

    private function isDateFoundRequired(?VatOutputsMapping $mapping, string $type, string $kind): bool
    {
        if ($kind === FilingDocument::KIND_SUPPLEMENTARY) {
            return true;
        }
        return $mapping !== null && $type !== ''
            && in_array($kind, $mapping->dateFoundRequiredFor($type), true);
    }

    /**
     * @param list<string> $kinds
     * @return list<array{value: string, label: string}>
     */
    private function kindOptions(array $kinds): array
    {
        $cfgData = $this->config?->cfgItem('economy.vat.filingKinds');
        $options = [];
        foreach ($kinds as $kind) {
            $label = is_array($cfgData) ? (string) ($cfgData[$kind]['name'] ?? '') : '';
            $options[] = ['value' => $kind, 'label' => $label !== '' ? $label : $kind];
        }
        return $options;
    }

    /**
     * Instance, za které lze podat — živé (koncept i V pořádku) registrací
     * ve stavu V pořádku, nejnovější první.
     *
     * @return list<array{value: int, label: string}>
     */
    private function resolvePeriodOptions(): array
    {
        if ($this->db === null) {
            return [];
        }
        $typeLabels = $this->typeLabels();
        $rows = $this->db->fetchAll(
            'SELECT `p`.`id`, `p`.`name`, `p`.`report_type`, `p`.`date_begin`, `p`.`date_end`'
            . ' FROM `economy_vat_report_periods` `p`'
            . ' JOIN `economy_codebooks_vat_registrations` `r` ON `r`.`id` = `p`.`vat_registration`'
            . ' WHERE `p`.`docState` != %i AND `r`.`docState` != %i'
            . ' ORDER BY `p`.`date_begin` DESC, `p`.`report_type` ASC, `p`.`id` DESC',
            FilingDocument::DOC_STATE_CANCELLED, 90,
        );

        $options = [];
        foreach ($rows as $row) {
            $type = (string) $row['report_type'];
            $options[] = [
                'value' => (int) $row['id'],
                'label' => sprintf(
                    '%s — %s',
                    $typeLabels[$type] ?? $type,
                    (string) $row['name'],
                ),
            ];
        }
        return $options;
    }

    /** @return array<string, string> */
    private function typeLabels(): array
    {
        $cfgData = $this->config?->cfgItem('economy.vat.reportTypes');
        if (!is_array($cfgData)) {
            return [];
        }
        $labels = [];
        foreach ($cfgData as $key => $entry) {
            $labels[(string) $key] = (string) ($entry['name'] ?? $key);
        }
        return $labels;
    }

    /** @return ?array<string, mixed> */
    private function loadPeriod(int $periodId): ?array
    {
        if ($this->db === null) {
            return null;
        }
        $row = $this->db->fetchRow(
            'SELECT `id`, `report_type`, `name` FROM `economy_vat_report_periods` WHERE `id` = %i',
            $periodId,
        );
        return $row !== null ? (array) $row : null;
    }

    private function hasFiledFiling(int $periodId): bool
    {
        if ($this->db === null) {
            return false;
        }
        return (int) $this->db->fetchSingle(
            'SELECT COUNT(*) FROM `' . FilingDocument::TABLE . '`'
            . ' WHERE `report_period` = %i AND `docState` = %i',
            $periodId, FilingDocument::DOC_STATE_FILED,
        ) > 0;
    }

    /**
     * Poznámka smí přibýt i k podanému podání — FilingDocument ji jako
     * jedinou (vedle `acc_document`, který nastavuje jen služba) nezmrazuje
     * a zvládá částečné uložení.
     */
    public function getReadOnlyEditableColumns(): array
    {
        return ['note'];
    }
}
