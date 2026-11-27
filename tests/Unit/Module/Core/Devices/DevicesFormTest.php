<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DevicesForm;
use Shipard\Module\Core\Devices\PeripheralDocument;

/**
 * Formulář zařízení (#177 U16, U18): název, režim, automatické zamčení,
 * pokladna jen s extension sloupcem z economy.codebooks, podtabulka
 * periferií bez Přidat a Smazat.
 */
class DevicesFormTest extends TestCase
{
    use DevicesConfig;

    private const array DATA = ['id' => 5, 'name' => 'Pokladna 2', 'mode' => 'shared', 'auto_lock_minutes' => 10, 'docState' => 40];

    private function form(bool $withCashDesk = false): DevicesForm
    {
        $data = self::repoJsonc('modules/core/devices/tables/core_devices_devices.jsonc');
        if ($withCashDesk) {
            $extension = self::repoJsonc('modules/economy/codebooks/extensions/core_devices_devices.jsonc');
            $data['columns'] = array_merge($data['columns'], $extension['columns']);
        }
        $def = TableDefinition::fromArray($data);

        $form = new DevicesForm(DeviceDocument::TABLE);
        $form->setConfig($this->devicesConfig());
        $form->setTableDef($def);
        $form->setTables([
            DeviceDocument::TABLE     => $def,
            PeripheralDocument::TABLE => TableDefinition::fromArray(self::repoJsonc('modules/core/devices/tables/core_devices_peripherals.jsonc')),
        ]);
        $form->setLanguage('cs');
        return $form;
    }

    /** @return list<FormElement> prvky prvního tabu */
    private function elements(FormDefinition $def): array
    {
        $out = [];
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                array_push($out, ...$col->elements);
            }
        }
        return $out;
    }

    public function testFormEditsSettingsOnly(): void
    {
        $def = $this->form()->buildFormDefinition(self::DATA, false);
        $elements = $this->elements($def);

        $this->assertSame(['name', 'mode', 'auto_lock_minutes', null], array_map(static fn (FormElement $e) => $e->column, $elements));
        $this->assertSame('content', $elements[3]->type);

        $mode = $elements[1];
        $this->assertSame('select', $mode->type);
        $this->assertSame(
            [['value' => 'personal', 'label' => 'Osobní'], ['value' => 'shared', 'label' => 'Sdílené pracoviště']],
            $mode->options,
        );
        $this->assertStringContainsString('Sdílené pracoviště používá víc uživatelů', (string) $mode->hint);
    }

    public function testCashDeskIsOfferedOnlyWithTheCodebooksExtension(): void
    {
        $columns = fn (bool $withCashDesk): array => array_map(
            static fn (FormElement $e) => $e->column,
            $this->elements($this->form($withCashDesk)->buildFormDefinition(self::DATA, false)),
        );

        $this->assertNotContains('cash_desk', $columns(false));
        $this->assertSame(['name', 'mode', 'auto_lock_minutes', 'cash_desk', null], $columns(true));

        $cashDesk = $this->elements($this->form(true)->buildFormDefinition(self::DATA, false))[3];
        $this->assertSame('lookup', $cashDesk->type);
        $this->assertSame('economy_codebooks_cash_desks', $cashDesk->lookup['table']);
    }

    public function testCashDeskExtensionTargetsTheDevicesTable(): void
    {
        $extension = JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/economy/codebooks/extensions/core_devices_devices.jsonc');
        $codebooks = JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/economy/codebooks/module.jsonc');

        $this->assertSame(DeviceDocument::TABLE, $extension['table']);
        $this->assertSame(DevicesForm::CASH_DESK_COLUMN, $extension['columns'][0]['id']);
        $this->assertSame(DevicesForm::CASH_DESKS_TABLE, $extension['columns'][0]['reference']);
        $this->assertTrue($extension['columns'][0]['nullable']);
        $this->assertContains(DeviceDocument::TABLE, $codebooks['extensions']);
        // Modul, který rozšiřuje cizí tabulku, na jejím modulu závisí.
        $this->assertContains('core.devices', $codebooks['dependencies']);
    }

    public function testPeripheralsAreASubtableWithoutAddAndDelete(): void
    {
        $def = $this->form()->buildFormDefinition(self::DATA, false);

        $this->assertSame(['basic', DevicesForm::PERIPHERALS_TAB], array_map(static fn ($tab) => $tab->id, $def->tabs));
        $subtable = $def->tabs[1]->subtable;
        $this->assertSame(PeripheralDocument::TABLE, $subtable['table']);
        $this->assertSame('device', $subtable['foreignKey']);
        $this->assertTrue($subtable['independentRows']);
    }

    public function testPeripheralsSubtableShowsWhatTheDeviceReported(): void
    {
        $form = $this->form();
        $tab = $form->buildFormDefinition(self::DATA, false)->tabs[1];

        $rendered = $form->renderSubtable($tab, [
            ['id' => 11, 'name' => 'Účtenky – pult', 'kind' => 'printer', 'transport' => 'tcp', 'status' => 'ready', 'present' => 1, 'role' => 'receipt'],
            ['id' => 12, 'name' => 'Čtečka u kasy', 'kind' => 'scanner', 'transport' => 'ble', 'status' => 'error', 'present' => 1, 'role' => null],
            ['id' => 13, 'name' => 'Odpojená', 'kind' => 'display', 'transport' => null, 'status' => 'ready', 'present' => 0, 'role' => null],
        ], self::DATA);

        $this->assertSame(['Název', 'Druh', 'Připojení', 'Stav', 'Role'], array_column($rendered['columns'], 'label'));
        $this->assertSame(
            [
                ['id' => 11, 'cells' => ['name' => 'Účtenky – pult', 'kind' => 'Tiskárna', 'transport' => 'tcp', 'state' => 'připravená', 'role' => 'Účtenky']],
                ['id' => 12, 'cells' => ['name' => 'Čtečka u kasy', 'kind' => 'Čtečka', 'transport' => 'ble', 'state' => 'chyba']],
                ['id' => 13, 'cells' => ['name' => 'Odpojená', 'kind' => 'display', 'state' => ['text' => 'nepřipojená', 'class' => 'muted']]],
            ],
            $rendered['rows'],
        );
    }

    public function testPeripheralFormOffersOnlyTheRole(): void
    {
        $form = JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/core/devices/forms/core_devices_peripherals.jsonc');
        $elements = $form['tabs'][0]['sections'][0]['columns'][0]['elements'];

        $this->assertSame([['select', 'role']], array_map(static fn (array $e): array => [$e['type'], $e['column']], $elements));
    }
}
