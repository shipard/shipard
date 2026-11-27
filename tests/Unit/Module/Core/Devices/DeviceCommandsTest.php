<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\Command\DeviceChallengesPruneCommand;
use Shipard\Module\Core\Devices\Command\DeviceListCommand;
use Shipard\Module\Core\Devices\Command\DeviceRevokeCommand;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Příkazy modulu (#177 U20, U22): přehled zařízení, zneplatnění bez UI
 * s potvrzením a úklid výzev pro cron.
 */
class DeviceCommandsTest extends TestCase
{
    private const string ACTIVE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';
    private const string REVOKED = '5d0e7c34-8b1a-4c59-a2f7-3e9b6d4c1f02';

    private InMemoryDevicesDb $db;
    private int $activeId;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->activeId = $this->db->seed(DeviceDocument::TABLE, [
            'device_id' => self::ACTIVE, 'name' => 'Pokladna 2', 'mode' => 'shared', 'platform' => 'ipados',
            'app_version' => '1.0.0', 'public_key' => 'MFkw', 'docState' => DeviceDocument::STATE_ACTIVE,
            'registered_at' => '2026-11-27T10:00:00', 'last_seen_at' => '2026-11-27T12:30:45',
        ]);
        $this->db->seed(DeviceDocument::TABLE, [
            'device_id' => self::REVOKED, 'name' => 'Starý Mac', 'mode' => 'personal', 'platform' => 'macos',
            'app_version' => null, 'public_key' => 'MFkx', 'docState' => DeviceDocument::STATE_REVOKED,
            'registered_at' => '2026-10-01T08:00:00', 'last_seen_at' => null,
        ]);
    }

    private function tester(Command $command): CommandTester
    {
        // Potvrzení potřebuje helper `question` — ten dává aplikace.
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    /** @return array<string, mixed> */
    private function device(): array
    {
        return $this->db->tables[DeviceDocument::TABLE][$this->activeId];
    }

    // ── device-list ─────────────────────────────────────────────────────────

    public function testListShowsDevicesWithStateAndLastContact(): void
    {
        $tester = $this->tester(new DeviceListCommand(dsConnection: $this->db));

        $this->assertSame(0, $tester->execute([]));
        $display = $tester->getDisplay();

        foreach (['Pokladna 2', 'shared', 'ipados', 'active', '2026-11-27 12:30', self::ACTIVE, 'Starý Mac', 'revoked', self::REVOKED] as $expected) {
            $this->assertStringContainsString($expected, $display);
        }
        $this->assertStringNotContainsString('MFkw', $display, 'veřejný klíč se nevypisuje');
    }

    public function testListAsJson(): void
    {
        $tester = $this->tester(new DeviceListCommand(dsConnection: $this->db));

        $this->assertSame(0, $tester->execute(['--json' => true]));
        $data = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'deviceId'     => self::ACTIVE,
            'name'         => 'Pokladna 2',
            'mode'         => 'shared',
            'platform'     => 'ipados',
            'appVersion'   => '1.0.0',
            'state'        => 'active',
            'registeredAt' => '2026-11-27 10:00',
            'lastSeenAt'   => '2026-11-27 12:30',
        ], $data['devices'][0]);
        $this->assertSame('revoked', $data['devices'][1]['state']);
        $this->assertNull($data['devices'][1]['lastSeenAt']);
        $this->assertStringNotContainsString('MFkw', $tester->getDisplay());
    }

    public function testListWithoutDevices(): void
    {
        $empty = InMemoryDevicesDb::create();

        $tester = $this->tester(new DeviceListCommand(dsConnection: $empty));
        $tester->execute([]);
        $this->assertStringContainsString('No devices registered.', $tester->getDisplay());

        $tester = $this->tester(new DeviceListCommand(dsConnection: $empty));
        $tester->execute(['--json' => true]);
        $this->assertSame(['devices' => []], json_decode($tester->getDisplay(), true));
    }

    // ── device-revoke ───────────────────────────────────────────────────────

    public function testRevokeWithYesRevokesAndDropsChallenges(): void
    {
        $challenge = (new ChallengeService($this->db))->issue(self::ACTIVE, DevicePurpose::Self);
        $tester = $this->tester(new DeviceRevokeCommand(dsConnection: $this->db));

        $this->assertSame(0, $tester->execute(['deviceId' => self::ACTIVE, '--yes' => true]));

        $this->assertStringContainsString("Device 'Pokladna 2' revoked.", $tester->getDisplay());
        $this->assertStringContainsString('Revocation is final', $tester->getDisplay());
        $this->assertSame(DeviceDocument::STATE_REVOKED, $this->device()['docState']);
        $this->assertNull($this->device()['revoked_by'], 'zneplatnění z příkazové řádky nemá uživatele');
        $this->assertFalse((new ChallengeService($this->db))->consume($challenge, self::ACTIVE, DevicePurpose::Self));
    }

    public function testRevokeAsksForConfirmation(): void
    {
        $tester = $this->tester(new DeviceRevokeCommand(dsConnection: $this->db));
        $tester->setInputs(['n']);
        $this->assertSame(0, $tester->execute(['deviceId' => self::ACTIVE]));
        $this->assertStringContainsString('Aborted. No changes made.', $tester->getDisplay());
        $this->assertSame(DeviceDocument::STATE_ACTIVE, $this->device()['docState']);

        $tester = $this->tester(new DeviceRevokeCommand(dsConnection: $this->db));
        $tester->setInputs(['y']);
        $this->assertSame(0, $tester->execute(['deviceId' => self::ACTIVE]));
        $this->assertSame(DeviceDocument::STATE_REVOKED, $this->device()['docState']);
    }

    public function testRevokeWithoutAnswerInAScriptChangesNothing(): void
    {
        $tester = $this->tester(new DeviceRevokeCommand(dsConnection: $this->db));

        $this->assertSame(0, $tester->execute(['deviceId' => self::ACTIVE], ['interactive' => false]));

        $this->assertStringContainsString('Aborted. No changes made.', $tester->getDisplay());
        $this->assertSame(DeviceDocument::STATE_ACTIVE, $this->device()['docState']);
    }

    public function testRevokeOfAnUnknownDeviceFails(): void
    {
        $tester = $this->tester(new DeviceRevokeCommand(dsConnection: $this->db));

        $this->assertSame(1, $tester->execute(['deviceId' => 'ffffffff-ffff-ffff-ffff-ffffffffffff', '--yes' => true]));
        $this->assertStringContainsString('not found', $tester->getDisplay());
        $this->assertSame([], $this->db->writes);
    }

    public function testRevokeOfARevokedDeviceIsANoOp(): void
    {
        $tester = $this->tester(new DeviceRevokeCommand(dsConnection: $this->db));

        $this->assertSame(0, $tester->execute(['deviceId' => self::REVOKED, '--yes' => true]));
        $this->assertStringContainsString("Device 'Starý Mac' is already revoked. No changes made.", $tester->getDisplay());
        $this->assertSame([], $this->db->writes);
    }

    // ── device-challenges-prune ─────────────────────────────────────────────

    public function testPruneRemovesUsedChallengesAndStaysQuietOtherwise(): void
    {
        $service = new ChallengeService($this->db);
        $used = $service->issue(self::ACTIVE, DevicePurpose::Self);
        $service->issue(self::ACTIVE, DevicePurpose::Peripherals);
        $service->consume($used, self::ACTIVE, DevicePurpose::Self);

        $tester = $this->tester(new DeviceChallengesPruneCommand(dsConnection: $this->db));
        $this->assertSame(0, $tester->execute([]));
        $this->assertStringContainsString('Removed 1 device challenges.', $tester->getDisplay());
        $this->assertCount(1, $this->db->rows(ChallengeService::TABLE));

        // Není co mazat — cron každých pět minut log nezaplňuje.
        $tester = $this->tester(new DeviceChallengesPruneCommand(dsConnection: $this->db));
        $this->assertSame(0, $tester->execute([]));
        $this->assertSame('', $tester->getDisplay());

        $tester = $this->tester(new DeviceChallengesPruneCommand(dsConnection: $this->db));
        $tester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);
        $this->assertStringContainsString('Removed 0 device challenges.', $tester->getDisplay());
    }

    public function testCommandsRefuseADirectoryThatIsNotADataSource(): void
    {
        $command = new class extends DeviceListCommand {
            protected function getDataSourceDir(): string
            {
                return sys_get_temp_dir() . '/shpd-not-a-data-source';
            }
        };
        $tester = $this->tester($command);

        $this->assertSame(1, $tester->execute([]));
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
    }
}
