<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DevicesViewer;
use Shipard\Module\Core\Devices\PeripheralDocument;

/**
 * Viewer Zařízení (Nastavení → Zařízení, #177 D13, U18): řádek s režimem,
 * platformou a posledním kontaktem, značka „toto zařízení“ pro frontend,
 * detail s registrací a periferiemi, žádné Přidat.
 */
class DevicesViewerTest extends TestCase
{
    use DevicesConfig;

    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';

    private const array RECORD = [
        'id'                => 5,
        'name'              => 'Pokladna 2',
        'mode'              => 'shared',
        'auto_lock_minutes' => 10,
        'device_id'         => self::DEVICE,
        'platform'          => 'ipados',
        'app_version'       => '1.0.0',
        'public_key'        => 'MFkw',
        'key_algorithm'     => 'ES256',
        'key_storage'       => 'secureEnclave',
        'registered_by'     => 3,
        'registered_at'     => '2026-11-27 10:00:00',
        'last_seen_at'      => '2026-11-27 12:30:00',
        'revoked_at'        => null,
        'revoked_by'        => null,
        'docState'          => DeviceDocument::STATE_ACTIVE,
        'docStateMain'      => 1,
    ];

    /**
     * @param list<array> $calls
     * @param list<array<string, mixed>> $peripherals
     */
    private function viewer(array &$calls, ?array $record = self::RECORD, array $peripherals = []): DevicesViewer
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(function (...$args) use (&$calls, $peripherals): array {
            $calls[] = $args;
            return str_contains((string) $args[0], PeripheralDocument::TABLE) ? $peripherals : [];
        });
        $db->method('fetchRow')->willReturnCallback(function (...$args) use (&$calls, $record): ?array {
            $calls[] = $args;
            return $record;
        });
        $db->method('fetchSingle')->willReturn('Jana Nováková');

        $viewer = new DevicesViewer($db, DeviceDocument::TABLE);
        $viewer->setConfig($this->devicesConfig());
        $viewer->setLanguage('cs');
        $viewer->setTables([
            DeviceDocument::TABLE     => TableDefinition::fromArray(self::repoJsonc('modules/core/devices/tables/core_devices_devices.jsonc')),
            PeripheralDocument::TABLE => TableDefinition::fromArray(self::repoJsonc('modules/core/devices/tables/core_devices_peripherals.jsonc')),
        ]);
        return $viewer;
    }

    public function testSelectRowsNeverReadsTheKey(): void
    {
        $calls = [];
        $this->viewer($calls)->selectRows('pokladna', [], 1);

        $sql = (string) $calls[0][0];
        $this->assertStringNotContainsString('public_key', $sql);
        $this->assertStringNotContainsString('SELECT *', $sql);
        $this->assertStringContainsString('ORDER BY d.`docStateMain` ASC, d.`name` ASC', $sql);
    }

    public function testRowShowsModePlatformAndLastContact(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(self::RECORD);

        $this->assertSame(5, $row['id']);
        $this->assertSame('Pokladna 2', $row['t1']);
        $this->assertSame(
            ['Sdílené pracoviště', 'iPadOS', 'poslední kontakt 27.11.2026 12:30', 'toto zařízení'],
            array_column($row['t2'], 'text'),
            'aktivní zařízení štítek stavu nemá',
        );
        $this->assertSame('done', $row['stateStyle']);
    }

    public function testThisDeviceSpanCarriesTheDeviceIdForTheFrontend(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(self::RECORD);

        // Server neví, ze kterého zařízení se uživatel dívá — span ukáže až
        // frontend, když `nativeDevice` sedí na `deviceId` z mostu.
        $marked = array_values(array_filter($row['t2'], static fn (array $span): bool => isset($span['nativeDevice'])));
        $this->assertCount(1, $marked);
        $this->assertSame(self::DEVICE, $marked[0]['nativeDevice']);
        $this->assertSame('primary', $marked[0]['badge']);
    }

    public function testRevokedDeviceHasAStateTagAndNoThisDeviceSpan(): void
    {
        $calls = [];
        $row = $this->viewer($calls)->renderRow(['docState' => DeviceDocument::STATE_REVOKED, 'last_seen_at' => null, 'platform' => 'visionos'] + self::RECORD);

        $texts = array_column($row['t2'], 'text');
        $this->assertSame(['Sdílené pracoviště', 'visionos', 'zatím bez kontaktu', 'Zneplatněné'], $texts);
        $this->assertSame([], array_filter($row['t2'], static fn (array $span): bool => isset($span['nativeDevice'])));
        $this->assertSame('archive', $row['stateStyle']);
    }

    public function testToolbarHasNoAddAction(): void
    {
        $calls = [];
        $viewer = $this->viewer($calls);

        $this->assertSame([], $viewer->getToolbarActions(null));
        $this->assertSame(['edit'], array_column($viewer->getToolbarActions(self::RECORD), 'id'));
    }

    public function testDetailShowsRegistrationWithoutRepeatingTheHeader(): void
    {
        $calls = [];
        $detail = $this->viewer($calls)->renderDetail(5);

        $this->assertSame('Pokladna 2', $detail['title']);
        // Aktivní stav štítek nemá (`detailBadge: false`), režim ano.
        $this->assertSame(['Sdílené pracoviště'], array_column($detail['badges'], 'label'));
        $this->assertSame(['overview', 'peripherals'], array_column($detail['tabs'], 'id'));

        $overview = $detail['tabs'][0]['content'];
        $this->assertSame('properties', $overview['type']);
        [$settings, $registration] = $overview['groups'];
        $this->assertSame('Nastavení', $settings['title']);
        $this->assertSame([['label' => 'Automatické zamčení po (minuty)', 'value' => '10 min']], $settings['items']);
        $this->assertSame('Registrace', $registration['title']);
        $this->assertSame(
            [
                ['label' => 'Platforma', 'value' => 'iPadOS'],
                ['label' => 'Verze aplikace', 'value' => '1.0.0'],
                ['label' => 'Uložení klíče', 'value' => 'secureEnclave'],
                ['label' => 'ID zařízení', 'value' => self::DEVICE],
                ['label' => 'Registrováno', 'value' => '27.11.2026 10:00'],
                ['label' => 'Registroval', 'value' => 'Jana Nováková'],
                ['label' => 'Poslední kontakt', 'value' => '27.11.2026 12:30'],
            ],
            $registration['items'],
        );
        // Veřejný klíč v detailu není.
        $this->assertStringNotContainsString('MFkw', (string) json_encode($detail));
    }

    public function testDetailOfARevokedDeviceSaysWhenAndByWhom(): void
    {
        $calls = [];
        $record = ['docState' => DeviceDocument::STATE_REVOKED, 'auto_lock_minutes' => null, 'revoked_at' => '2026-11-28 08:15:00', 'revoked_by' => null] + self::RECORD;
        $detail = $this->viewer($calls, $record)->renderDetail(5);

        [$settings, $registration] = $detail['tabs'][0]['content']['groups'];
        $this->assertSame([['label' => 'Automatické zamčení po (minuty)', 'value' => 'vypnuto']], $settings['items']);
        $this->assertSame(
            [['label' => 'Zneplatněno', 'value' => '28.11.2026 08:15'], ['label' => 'Zneplatnil', 'value' => 'příkazová řádka']],
            array_slice($registration['items'], -2),
        );
    }

    public function testPeripheralsTabListsReportedPeripherals(): void
    {
        $calls = [];
        $peripherals = [
            ['name' => 'Účtenky – pult', 'kind' => 'printer', 'transport' => 'tcp', 'status' => 'ready', 'present' => 1, 'role' => 'receipt'],
            ['name' => 'Čtečka u kasy', 'kind' => 'scanner', 'transport' => 'ble', 'status' => 'offline', 'present' => 1, 'role' => null],
            ['name' => 'Stará tiskárna', 'kind' => 'display', 'transport' => null, 'status' => 'ready', 'present' => 0, 'role' => null],
        ];
        $content = $this->viewer($calls, self::RECORD, $peripherals)->renderDetail(5)['tabs'][1]['content'];

        $this->assertSame('table', $content['type']);
        $this->assertSame(['Název', 'Druh', 'Připojení', 'Stav', 'Role'], array_column($content['columns'], 'label'));
        $this->assertSame(
            [
                ['name' => 'Účtenky – pult', 'kind' => 'Tiskárna', 'transport' => 'tcp', 'state' => 'připravená', 'role' => 'Účtenky'],
                ['name' => 'Čtečka u kasy', 'kind' => 'Čtečka', 'transport' => 'ble', 'state' => 'nedostupná', 'role' => ''],
                // Neznámý druh se ukáže, jak přišel; chybějící periferie je nepřipojená.
                ['name' => 'Stará tiskárna', 'kind' => 'display', 'transport' => '', 'state' => 'nepřipojená', 'role' => ''],
            ],
            $content['rows'],
        );
    }

    public function testPeripheralsTabWithoutPeripheralsIsAnEmptyState(): void
    {
        $calls = [];
        $content = $this->viewer($calls)->renderDetail(5)['tabs'][1]['content'];

        $this->assertSame(['type' => 'empty', 'text' => 'Zařízení zatím žádné periferie nenahlásilo.'], $content);
    }
}
