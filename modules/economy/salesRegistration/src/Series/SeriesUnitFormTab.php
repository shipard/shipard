<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Series;

use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\ModuleFormTab;
use Shipard\Core\Module\ModuleExtensionContext;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\Texts;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Záložka Evidence tržeb ve formuláři číselné řady (#171 D21): výběr
 * evidenční jednotky, pod kterou se evidují tržby dokladů řady. Sloupec
 * `sales_registration_unit` přidává řadě extension doplňku a ukládá ho běžné
 * uložení formuláře; kód řady proti masce typu hlídá {@see SeriesUnitHandler}.
 *
 * Nabízí jednotky registrací ve stavu V pořádku; právě vybraná jednotka
 * zůstává v nabídce i po archivaci registrace, aby šla vidět a odebrat.
 * Bez jediné jednotky se záložka nezobrazí.
 */
class SeriesUnitFormTab extends ModuleFormTab
{
    public const string COLUMN = 'sales_registration_unit';

    public function build(array $data, bool $isNew, ModuleExtensionContext $context): ?FormTab
    {
        $selected = (int) ($data[self::COLUMN] ?? 0);
        $options = $this->unitOptions($context, $selected);
        if ($options === []) {
            return null;
        }

        $t = Texts::of($context->config);

        return $this->tab($context, 'salesRegistration', $t->t('series.tab', 'Sales registration'), 'cash-register')
            ->section()
                ->col()
                    ->select(
                        self::COLUMN,
                        options: $options,
                        hint: $t->t(
                            'series.unitHint',
                            'Sales of documents in this series are registered under this unit. Left empty, the default unit of the registration applies. The series code is sent as the cash register identifier.',
                        ),
                    )
            ->build();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function unitOptions(ModuleExtensionContext $context, int $selected): array
    {
        $options = [];
        foreach ($this->loadUnits($context, $selected) as $unit) {
            $name = trim((string) ($unit['name'] ?? ''));
            $options[] = [
                'value' => (int) $unit['id'],
                'label' => trim((string) $unit['registration_name']) . ' — ' . $unit['unit_id'] . ($name !== '' ? ' ' . $name : ''),
            ];
        }
        return $options;
    }

    /**
     * Jednotky registrací V pořádku a právě vybraná jednotka (přepsatelné v testech).
     *
     * @return list<array<string, mixed>>
     */
    protected function loadUnits(ModuleExtensionContext $context, int $selected): array
    {
        return $context->db->fetchAll(
            'SELECT u.[id], u.[unit_id], u.[name], r.[name] AS [registration_name]'
            . ' FROM %n u JOIN %n r ON r.[id] = u.[registration]'
            . ' WHERE r.[docState] = %i OR u.[id] = %i'
            . ' ORDER BY r.[name], u.[unit_id], u.[id]',
            UnitDocument::TABLE,
            RegistrationDocument::TABLE,
            RegistrationDocument::STATE_CONFIRMED,
            $selected,
        );
    }
}
