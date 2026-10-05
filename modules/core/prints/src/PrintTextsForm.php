<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\RecalculateResult;
use Shipard\Core\Form\TableForm;
use Shipard\Core\Prints\Texts\PrintTextSlot;

/**
 * Formulář textu na tiscích (#90 D47). Nabídky se řídí tím, co je už
 * vybrané: tisky podle umístění (jen ty, které slot podporují), typy dokladů
 * a číselné řady podle tisků — a obě pole jsou vidět jen tehdy, když
 * `PrintTextChoices::targeting()` cílení připouští (tisky dokladů). Změna
 * umístění nebo tisků proto formulář přepočítá a z výběru vypadne, co už
 * v nabídce není.
 */
class PrintTextsForm extends TableForm
{
    /** Stavy číselné řady, které má smysl nabízet (Koncept, V pořádku, V opravě). */
    private const LIVE_DOC_STATES = [10, 40, 80];

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $choices  = new PrintTextChoices($this->config);
        $slot     = PrintTextSlot::tryFrom((string) ($data['slot'] ?? ''));
        $slotId   = $slot?->value;
        $printIds = array_map('strval', self::listValue($data['prints'] ?? null));

        $slots       = $choices->slots();
        $slotOptions = [];
        foreach ($slots as $id => $info) {
            $slotOptions[] = ['value' => $id, 'label' => $info['name']];
        }

        $printOptions = [];
        foreach ($choices->prints($slotId) as $id => $print) {
            $printOptions[] = ['value' => $id, 'label' => $print['name']];
        }

        $targeting      = $choices->targeting($printIds, $slotId);
        $docTypes       = $targeting === null ? [] : $choices->docTypes($targeting, $printIds, $slotId);
        $docTypeOptions = [];
        foreach ($docTypes as $type => $label) {
            $docTypeOptions[] = ['value' => $type, 'label' => $label];
        }
        $seriesOptions = [];
        foreach ($this->series($targeting, array_keys($docTypes)) as $id => $label) {
            $seriesOptions[] = ['value' => $id, 'label' => $label];
        }

        $languageOptions = [];
        foreach ($choices->languages() as $language => $label) {
            $languageOptions[] = ['value' => $language, 'label' => $label];
        }

        $tab = $this->tab('basic', 'Text')
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select('slot',
                        options: $slotOptions,
                        required: true,
                        triggers: 'reload',
                        hint: $slotId === null ? null : ($slots[$slotId]['description'] ?: null),
                    )

            ->section(title: 'Kde se text použije')
                ->col()
                    ->multiselect('prints',
                        options: $printOptions,
                        triggers: 'reload',
                        placeholder: 'Všechny tisky s tímto umístěním',
                    )
                    ->multiselect('doc_types',
                        options: $docTypeOptions,
                        hidden: $targeting === null,
                        placeholder: 'Všechny typy dokladů',
                    )
                    ->multiselect('number_series',
                        options: $seriesOptions,
                        hidden: $targeting === null,
                        placeholder: 'Všechny číselné řady',
                    )
                    ->select('language',
                        options: $languageOptions,
                        required: false,
                        placeholder: 'Všechny jazyky',
                        hint: 'Text se použije jen na tisku v tomto jazyce.',
                    )

            ->section(title: 'Platnost')
                ->col()
                    ->date('valid_from')
                    ->date('valid_to',
                        hint: 'Rozhoduje den tisku nebo odeslání, ne datum dokladu. Prázdné = bez omezení.',
                    )
                    ->number('order_pos',
                        hint: 'Pořadí mezi texty se stejným umístěním — vytisknou se všechny platné.',
                    )

            ->section(title: 'Text')
                ->col()
                    ->textarea('text',
                        required: true,
                        hint: ($slot?->isEmail() ?? false)
                            ? 'Prostý text bez formátování. Údaj z tisku vložíš proměnnou, například {{ data.document.number }}.'
                            : 'Formátuje se Markdownem (**tučně**, *kurzíva*, seznamy). Údaj z tisku vložíš proměnnou, například {{ data.document.number }}.',
                    )
                    ->input('note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'Text na tiscích',
            titleNew: 'Nový text na tiscích',
            tabs: [$tab],
        );
    }

    public function recalculate(string $changedColumn, array $data): RecalculateResult
    {
        if (in_array($changedColumn, ['slot', 'prints'], true)) {
            $choices = new PrintTextChoices($this->config);
            $slotId  = PrintTextSlot::tryFrom((string) ($data['slot'] ?? ''))?->value;

            // Z výběru vypadne, co po změně v nabídce není — jinak by ve
            // skrytém nebo zúženém poli zůstala hodnota, kterou uložení odmítne.
            $available = $choices->prints($slotId);
            $printIds  = array_values(array_filter(
                array_map('strval', self::listValue($data['prints'] ?? null)),
                static fn (string $id): bool => isset($available[$id]),
            ));
            $data['prints'] = $printIds;

            $targeting = $choices->targeting($printIds, $slotId);
            $docTypes  = $targeting === null ? [] : $choices->docTypes($targeting, $printIds, $slotId);
            $series    = $this->series($targeting, array_keys($docTypes));

            $data['doc_types'] = array_values(array_filter(
                self::listValue($data['doc_types'] ?? null),
                static fn (mixed $type): bool => is_string($type) && isset($docTypes[$type]),
            ));
            $data['number_series'] = array_values(array_filter(
                array_map('intval', self::listValue($data['number_series'] ?? null)),
                static fn (int $id): bool => isset($series[$id]),
            ));
        }

        $isNew = !isset($data['id']) || $data['id'] === null;
        return new RecalculateResult($this->buildFormDefinition($data, $isNew), $data);
    }

    /**
     * Číselné řady typů dokladů, které dotčené tisky tisknou.
     *
     * @param list<string> $docTypes
     * @return array<int, string> id řady → název
     */
    private function series(?PrintTextTargeting $targeting, array $docTypes): array
    {
        if ($targeting === null || $docTypes === [] || $this->db === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT [id], [name] FROM %n WHERE %n IN %in AND [docState] IN %in ORDER BY [name], [id]',
            $targeting->seriesTable,
            $targeting->seriesTypeColumn,
            $docTypes,
            self::LIVE_DOC_STATES,
        );

        $series = [];
        foreach ($rows as $row) {
            $series[(int) $row['id']] = (string) $row['name'];
        }
        return $series;
    }

    /** @return list<mixed> */
    private static function listValue(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true);
        }
        return is_array($value) ? array_values($value) : [];
    }
}
