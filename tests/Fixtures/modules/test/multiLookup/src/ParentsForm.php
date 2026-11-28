<?php

declare(strict_types=1);

namespace Shipard\Module\Test\MultiLookup;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Fixture (#176): formulář rodiče s prvkem `multilookup` nad child tabulkou
 * `targets`. Podle `kind` rodiče:
 *
 *  - jiná hodnota než `locked` / `plain` jde do filtru lookupu (`kind`),
 *  - `locked` = prvek jen ke čtení,
 *  - `plain` = formulář bez prvku (klíč child tabulky se pak nesmí uložit).
 *
 * Nápověda prvku nese počet vybraných záznamů — test na ní vidí, že
 * přepočet formuláře seznam dostal.
 */
class ParentsForm extends TableForm
{
    public const KIND_LOCKED = 'locked';
    public const KIND_PLAIN = 'plain';

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $kind = (string) ($data['kind'] ?? '');
        $tab = $this->tab('basic', 'Basic')->section()->col()
            ->input('name')
            // Výběr, ne textové pole — změna spouští přepočet i v prohlížeči.
            ->select('kind', options: [
                ['value' => 'a', 'label' => 'A'],
                ['value' => 'b', 'label' => 'B'],
                ['value' => self::KIND_LOCKED, 'label' => 'Locked'],
                ['value' => self::KIND_PLAIN, 'label' => 'Plain'],
            ], triggers: 'reload');

        if ($kind !== self::KIND_PLAIN) {
            $selected = is_array($data['targets'] ?? null) ? count($data['targets']) : 0;
            $tab->multiLookup(
                'targets',
                table: TargetsLookup::TABLE,
                filter: $kind !== '' && $kind !== self::KIND_LOCKED ? [TargetsLookup::FILTER_KIND => $kind] : null,
                label: 'Targets',
                placeholder: 'Search targets…',
                readOnly: $kind === self::KIND_LOCKED,
                hint: 'Selected: ' . $selected,
            );
        }

        return new FormDefinition(
            table: $this->table,
            title: 'Parent',
            titleNew: 'New parent',
            tabs: [$tab->build()],
        );
    }
}
