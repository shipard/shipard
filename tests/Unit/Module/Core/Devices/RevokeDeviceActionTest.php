<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Devices\Actions\RevokeDeviceAction;
use Shipard\Module\Core\Devices\ChallengeService;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\DevicePurpose;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;

/**
 * Akce Zneplatnit (#177 U18): jen aktivní zařízení, jen administrátor
 * (deklarace), potvrzení s názvem zařízení, zneplatnění je konečné.
 */
class RevokeDeviceActionTest extends TestCase
{
    use DevicesConfig;

    private const string DEVICE = '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10';

    private InMemoryDevicesDb $db;
    private int $id;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->id = $this->db->seed(DeviceDocument::TABLE, [
            'device_id' => self::DEVICE,
            'name'      => 'Pokladna 2',
            'docState'  => DeviceDocument::STATE_ACTIVE,
        ]);
    }

    private function context(?int $userId = 3): RecordActionContext
    {
        return new RecordActionContext(DeviceDocument::TABLE, $this->db, $this->devicesConfig(), language: 'cs', userId: $userId, isAdmin: true);
    }

    /** @return array<string, mixed> */
    private function record(): array
    {
        return $this->db->tables[DeviceDocument::TABLE][$this->id];
    }

    public function testActionIsDeclaredForAdministratorsOnly(): void
    {
        $actions = JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/core/devices/module.jsonc')['recordActions'];

        $this->assertCount(1, $actions);
        $this->assertSame('revoke', $actions[0]['id']);
        $this->assertSame(DeviceDocument::TABLE, $actions[0]['table']);
        $this->assertSame(RevokeDeviceAction::class, $actions[0]['class']);
        $this->assertTrue($actions[0]['admin']);
    }

    public function testActionIsOfferedForActiveDevicesOnly(): void
    {
        $action = new RevokeDeviceAction();

        $this->assertTrue($action->available(DeviceDocument::TABLE, $this->record(), $this->context()));
        $this->assertFalse($action->available(
            DeviceDocument::TABLE,
            ['docState' => DeviceDocument::STATE_REVOKED] + $this->record(),
            $this->context(),
        ));
    }

    public function testActionIsDangerousAndAsksForConfirmation(): void
    {
        $descriptor = (new RevokeDeviceAction())->describe(DeviceDocument::TABLE, $this->record(), $this->context());

        $this->assertSame('Zneplatnit', $descriptor->label);
        $this->assertSame(RecordActionDescriptor::DANGER, $descriptor->variant);
        $this->assertNull($descriptor->dialog);
        $this->assertStringContainsString('Zařízení „Pokladna 2“ přestane s tímto zdrojem dat pracovat', (string) $descriptor->confirm);
        $this->assertStringContainsString('nejde vrátit', (string) $descriptor->confirm);
    }

    public function testExecuteRevokesTheDeviceAndDropsItsChallenges(): void
    {
        $challenge = (new ChallengeService($this->db))->issue(self::DEVICE, DevicePurpose::Self);

        $result = (new RevokeDeviceAction())->execute(DeviceDocument::TABLE, $this->record(), new RecordActionInput([]), $this->context(7));

        $this->assertSame('Zařízení je zneplatněné.', $result->message);
        $this->assertSame(DeviceDocument::STATE_REVOKED, $this->record()['docState']);
        $this->assertSame(7, $this->record()['revoked_by']);
        $this->assertFalse((new ChallengeService($this->db))->consume($challenge, self::DEVICE, DevicePurpose::Self));
    }

    public function testExecuteOnADeviceRevokedMeanwhileIsAFormError(): void
    {
        $action = new RevokeDeviceAction();
        $stale = $this->record();
        $action->execute(DeviceDocument::TABLE, $stale, new RecordActionInput([]), $this->context());

        $this->expectException(RecordActionValidationException::class);
        $action->execute(DeviceDocument::TABLE, $stale, new RecordActionInput([]), $this->context());
    }
}
