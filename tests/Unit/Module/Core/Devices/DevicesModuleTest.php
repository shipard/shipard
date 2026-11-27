<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DeviceModes;
use Shipard\Module\Core\Devices\PeripheralRoles;

/**
 * Deklarace modulu core.devices nad skutečnými soubory (#177 U17–U19):
 * tabulky, co přežije reset, stavy zařízení, přesné porovnání identity
 * a číselníky režimů a rolí.
 */
class DevicesModuleTest extends TestCase
{
    use DevicesConfig;

    private const ROOT = __DIR__ . '/../../../../..';
    private const MODULES = self::ROOT . '/modules';
    private const MODULE = self::MODULES . '/core/devices';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    /** @return array<string, mixed> */
    private static function table(string $table): array
    {
        return JsoncParser::parseFile(self::MODULE . '/tables/' . $table . '.jsonc');
    }

    /** @return array<string, array<string, mixed>> */
    private static function columns(string $table): array
    {
        return array_column(self::table($table)['columns'], null, 'id');
    }

    public function testModuleDefinitionParsesAndDeclaresTables(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('core.devices', $module->id);
        $this->assertSame(['core.system'], $module->dependencies);

        $ids = [];
        foreach ($module->tables as $table) {
            $ids[$table] = TableDefinition::fromArray(self::table($table))->tableId;
        }
        $this->assertSame(
            ['core_devices_devices' => 472, 'core_devices_peripherals' => 473, 'core_devices_challenges' => 474],
            $ids,
        );

        $install = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');
        $this->assertContains('core.devices', $install['dependencies']);
    }

    public function testTableIdsAreUniqueAcrossAllModules(): void
    {
        $seen = [];
        foreach (glob(self::MODULES . '/*/*/tables/*.jsonc') as $file) {
            $id = (int) (JsoncParser::parseFile($file)['tableId'] ?? 0);
            if ($id === 0) {
                continue;
            }
            $this->assertArrayNotHasKey($id, $seen, basename($file) . ' × ' . ($seen[$id] ?? ''));
            $seen[$id] = basename($file);
        }
    }

    public function testDevicesAndPeripheralsSurviveResetChallengesDoNot(): void
    {
        // U17: registraci by po resetu musel administrátor opakovat na každém zařízení.
        $this->assertSame(
            ['core_devices_devices', 'core_devices_peripherals'],
            self::module()['keepOnReset'],
        );
    }

    public function testTablesAreAdminOnlyAndCreatedOnlyByTheApplication(): void
    {
        foreach (self::module()['tables'] as $table) {
            $def = TableDefinition::fromArray(self::table($table));
            $this->assertTrue($def->adminOnly, $table);
            $this->assertTrue($def->systemManaged, $table);
        }
        $this->assertTrue(self::table('core_devices_peripherals')['hideFromNavigation']);
        $this->assertTrue(self::table('core_devices_challenges')['hideFromNavigation']);
    }

    public function testIdentityColumnsCompareExactly(): void
    {
        // Výzva je base64url a ID zařízení identita — česká collation by je
        // porovnávala bez ohledu na velikost písmen.
        foreach ([
            'core_devices_devices'     => ['device_id'],
            'core_devices_peripherals' => ['peripheral_id'],
            'core_devices_challenges'  => ['challenge', 'device_id', 'purpose'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame('utf8mb4_bin', self::columns($table)[$column]['collation'] ?? null, "{$table}.{$column}");
            }
        }
    }

    public function testDeviceIdIsUniqueAndPeripheralIsUniquePerDevice(): void
    {
        $unique = static fn (array $table): array => array_values(array_map(
            static fn (array $index): array => array_column($index['columns'], 'column'),
            array_filter($table['indexes'], static fn (array $i): bool => $i['type'] === 'unique'),
        ));

        $this->assertSame([['device_id']], $unique(self::table('core_devices_devices')));
        $this->assertSame([['device', 'peripheral_id']], $unique(self::table('core_devices_peripherals')));
        $this->assertSame([['challenge']], $unique(self::table('core_devices_challenges')));
    }

    public function testOnlySettingsOfADeviceAndRoleOfAPeripheralAreEditable(): void
    {
        $editable = static fn (array $columns): array => array_keys(array_filter(
            $columns,
            static fn (array $c): bool => empty($c['system']) && empty($c['primaryKey']),
        ));

        $this->assertSame(['name', 'mode', 'auto_lock_minutes'], $editable(self::columns('core_devices_devices')));
        $this->assertSame(['role'], $editable(self::columns('core_devices_peripherals')));
        $this->assertSame([], $editable(self::columns('core_devices_challenges')));
    }

    public function testDeviceStatesAreActiveAndFinalRevoked(): void
    {
        $states = self::devicesItems()['core.devices.docStates'];

        $this->assertSame([DeviceDocument::STATE_ACTIVE, DeviceDocument::STATE_REVOKED], array_keys($states));
        // Aktivní zařízení je editovatelné bez přechodu stavu a bez štítku v detailu.
        $this->assertArrayNotHasKey('readOnly', $states[DeviceDocument::STATE_ACTIVE]);
        $this->assertFalse($states[DeviceDocument::STATE_ACTIVE]['detailBadge']);
        $this->assertSame([], $states[DeviceDocument::STATE_ACTIVE]['goto']);
        // Zneplatnění je konečné.
        $this->assertSame(1, $states[DeviceDocument::STATE_REVOKED]['readOnly']);
        $this->assertSame([], $states[DeviceDocument::STATE_REVOKED]['goto']);
        $this->assertSame('Zneplatněné', $states[DeviceDocument::STATE_REVOKED]['stateName']);

        // Služba registrace zapisuje stav mimo gateway — konstanty musí sedět s číselníkem.
        $this->assertSame(DeviceDocument::STATE_MAIN_ACTIVE, $states[DeviceDocument::STATE_ACTIVE]['mainState']);
        $this->assertSame(DeviceDocument::STATE_MAIN_REVOKED, $states[DeviceDocument::STATE_REVOKED]['mainState']);

        $columns = self::columns('core_devices_devices');
        $this->assertSame(DeviceDocument::STATE_ACTIVE, $columns['docState']['default']);
        $this->assertSame($states[DeviceDocument::STATE_ACTIVE]['mainState'], $columns['docStateMain']['default']);
    }

    public function testModesMatchTheBridgeContract(): void
    {
        $modes = DeviceModes::fromConfig($this->devicesConfig());

        $this->assertSame([DeviceModes::PERSONAL, DeviceModes::SHARED], $modes->ids());
        $this->assertSame('Sdílené pracoviště', $modes->label('shared'));
        $this->assertFalse($modes->isValid('unregistered'));

        // Bez zkompilované konfigurace platí režimy kontraktu, ne cokoli.
        $bare = DeviceModes::fromConfig(null);
        $this->assertTrue($bare->isValid('personal'));
        $this->assertFalse($bare->isValid('kiosk'));
    }

    public function testReceiptRoleIsForPrintersAndOnlyOncePerDevice(): void
    {
        $roles = new PeripheralRoles($this->devicesConfig());

        $this->assertSame(['receipt'], array_keys($roles->all()));
        $this->assertSame('Účtenky', $roles->label('receipt'));
        $this->assertTrue($roles->allowsKind('receipt', 'printer'));
        $this->assertFalse($roles->allowsKind('receipt', 'scanner'));
        $this->assertTrue($roles->isSingle('receipt'));
        $this->assertTrue($roles->isUnknown('labels'));

        // Bez konfigurace není nic neznámé — degradace, ne pád.
        $this->assertFalse((new PeripheralRoles(null))->isUnknown('labels'));
    }

    public function testRoleColumnFitsEveryRoleKey(): void
    {
        $length = self::columns('core_devices_peripherals')['role']['length'];
        foreach (array_keys(JsoncParser::parseFile(self::MODULE . '/config/peripheralRoles.jsonc')) as $role) {
            $this->assertLessThanOrEqual($length, strlen((string) $role), (string) $role);
        }
        $modeLength = self::columns('core_devices_devices')['mode']['length'];
        foreach (array_keys(JsoncParser::parseFile(self::MODULE . '/config/modes.jsonc')) as $mode) {
            $this->assertLessThanOrEqual($modeLength, strlen((string) $mode), (string) $mode);
        }
    }
}
