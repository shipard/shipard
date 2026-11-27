<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Devices;

use Shipard\Core\Form\EnumOptionsHelper;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\Form\TableForm;

/**
 * Formulář zařízení (#177 U18, U23): název, režim, automatické zamčení,
 * pokladna a role periferií. Zařízení vzniká jen registrací — formulář
 * upravuje nastavení existujícího záznamu; zneplatněné je jen ke čtení
 * (stav `readOnly`).
 *
 * Pokladna je extension sloupec z `economy.codebooks` (U16): bez toho
 * modulu sloupec v definici tabulky není a prvek se nevykreslí.
 *
 * Periferie jsou podtabulka bez Přidat a Smazat (`independentRows`) —
 * hlásí je zařízení samo; v dialogu řádku jde změnit jen role
 * (`forms/core_devices_peripherals.jsonc`, pravidla `PeripheralDocument`).
 */
class DevicesForm extends TableForm
{
    public const string PERIPHERALS_TAB = 'peripherals';
    public const string CASH_DESK_COLUMN = 'cash_desk';
    public const string CASH_DESKS_TABLE = 'economy_codebooks_cash_desks';

    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $builder = $this->tab('basic', $this->defaultGeneralTabLabel())
            ->section()
                ->col()
                    ->input('name', required: true)
                    ->select(
                        'mode',
                        options: $this->modeOptions(),
                        required: true,
                        hint: $this->formText('core.devices.formTexts', 'device.modeHint', 'A personal device belongs to one user. A shared workplace is used by several users with profiles, lock and PIN.'),
                    )
                    ->number(
                        'auto_lock_minutes',
                        hint: $this->formText('core.devices.formTexts', 'device.autoLockHint', 'Minutes of inactivity after which the app locks. Empty = no automatic lock.'),
                    );

        if ($this->hasCashDeskColumn()) {
            $builder->lookup(
                self::CASH_DESK_COLUMN,
                self::CASH_DESKS_TABLE,
                hint: $this->formText('core.devices.formTexts', 'device.cashDeskHint', 'The cash desk the cash register on this device works with.'),
            );
        }

        $builder->content([[
            'type' => 'hint',
            'text' => $this->formText('core.devices.formTexts', 'device.applyHint', 'The device takes over the changes at its next synchronisation — at the start of the app or via Synchronise in This device.'),
        ]]);

        $tabs = [$builder->build()];
        if (!$isNew) {
            $tabs[] = $this->subtableTab(
                self::PERIPHERALS_TAB,
                'Periferie',
                PeripheralDocument::TABLE,
                'device',
                independentRows: true,
            );
        }

        return new FormDefinition(
            table: $this->table,
            title: 'Zařízení',
            titleNew: 'Zařízení',
            tabs: $tabs,
        );
    }

    /**
     * Periferie: název, druh, připojení, stav a role. Výchozí vykreslení
     * systémové sloupce vynechává — tady jsou obsahem, hlásí je zařízení.
     */
    public function renderSubtable(FormTab $tab, array $rows, array $parentData): array
    {
        if ($tab->id !== self::PERIPHERALS_TAB) {
            return parent::renderSubtable($tab, $rows, $parentData);
        }

        $table = PeripheralDocument::TABLE;
        $columns = [
            ['id' => 'name',      'label' => $this->subtableLabel($table, 'name', 'Name'), 'grow' => true],
            ['id' => 'kind',      'label' => $this->subtableLabel($table, 'kind', 'Kind')],
            ['id' => 'transport', 'label' => $this->subtableLabel($table, 'transport', 'Connection')],
            ['id' => 'state',     'label' => $this->formText('core.devices.formTexts', 'peripherals.state', 'State')],
            ['id' => 'role',      'label' => $this->subtableLabel($table, 'role', 'Role')],
        ];

        $roles = new PeripheralRoles($this->config);
        $out = [];
        foreach ($rows as $row) {
            $present = (int) ($row['present'] ?? 0) === 1;
            $role = (string) ($row['role'] ?? '');
            $cells = [
                'name'      => (string) ($row['name'] ?? ''),
                'kind'      => $this->kindLabel((string) ($row['kind'] ?? '')),
                'transport' => (string) ($row['transport'] ?? ''),
                'state'     => $present
                    ? $this->statusLabel((string) ($row['status'] ?? ''))
                    : ['text' => $this->formText('core.devices.formTexts', 'peripherals.absent', 'not connected'), 'class' => 'muted'],
            ];
            if ($role !== '') {
                $cells['role'] = $roles->label($role);
            }
            $out[] = ['id' => (int) ($row['id'] ?? 0), 'cells' => array_filter($cells, static fn (mixed $c): bool => $c !== '')];
        }

        return ['columns' => $columns, 'rows' => $out, 'order_column' => null];
    }

    /** Má zdroj dat extension sloupec pokladny (modul `economy.codebooks`)? */
    protected function hasCashDeskColumn(): bool
    {
        foreach ($this->tableDef?->columns ?? [] as $column) {
            if ($column->id === self::CASH_DESK_COLUMN) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{value: string, label: string}> */
    private function modeOptions(): array
    {
        $cfg = $this->config?->cfgItem(DeviceModes::CFG_ITEM);
        return is_array($cfg) ? EnumOptionsHelper::fromCfgData($cfg, 'enumString', DeviceModes::CFG_ITEM) : [];
    }

    /** Hodnoty hlásí aplikace — neznámá se ukáže, jak přišla (kontrakt §2.4). */
    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            'printer' => $this->formText('core.devices.formTexts', 'peripheral.kind.printer', 'Printer'),
            'scanner' => $this->formText('core.devices.formTexts', 'peripheral.kind.scanner', 'Scanner'),
            default   => $kind,
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'ready'   => $this->formText('core.devices.formTexts', 'peripheral.status.ready', 'ready'),
            'offline' => $this->formText('core.devices.formTexts', 'peripheral.status.offline', 'offline'),
            'error'   => $this->formText('core.devices.formTexts', 'peripheral.status.error', 'error'),
            default   => $status,
        };
    }
}
