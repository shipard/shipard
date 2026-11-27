<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\DeviceDocument;

/**
 * Zařízení (#177 U18): vzniká jen registrací, stav se přes dokument nemění
 * (zneplatňuje služba registrace) a údaje z registrace jsou pevné.
 */
class DeviceDocumentTest extends TestCase
{
    use DevicesConfig;

    private const array STORED = [
        'id'                => 5,
        'name'              => 'Pokladna 2',
        'mode'              => 'shared',
        'auto_lock_minutes' => 10,
        'device_id'         => '0b9f6c1e-52a4-4f0e-9a43-6f2d1c7e8a10',
        'platform'          => 'ipados',
        'public_key'        => 'MFkw',
        'key_algorithm'     => 'ES256',
        'key_storage'       => 'secureEnclave',
        'registered_by'     => 3,
        'registered_at'     => '2026-11-27 10:00:00',
        'docState'          => DeviceDocument::STATE_ACTIVE,
    ];

    /** @param array<string, mixed>|null $stored */
    private function doc(?array $stored = self::STORED): DeviceDocument
    {
        $doc = new class extends DeviceDocument {
            /** @var array<string, mixed>|null */
            public ?array $stored = null;

            protected function loadDeviceRow(int $id): ?array
            {
                return $this->stored;
            }
        };
        $doc->stored = $stored;
        $doc->setConfig($this->devicesConfig());
        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function codes(DeviceDocument $doc, array $data): array
    {
        return array_map(static fn($e) => $e->code, $doc->validate($data)->getErrors());
    }

    public function testDeviceCannotBeCreatedByHand(): void
    {
        $this->assertSame(['system_managed'], $this->codes($this->doc(null), ['name' => 'Nové', 'mode' => 'personal']));
    }

    public function testSettingsOfAnActiveDeviceCanChange(): void
    {
        $data = ['id' => 5, 'name' => '  Pokladna u vchodu ', 'mode' => 'personal', 'auto_lock_minutes' => ''];
        $doc = $this->doc();

        $this->assertSame([], $this->codes($doc, $data));

        $doc->beforeSave($data, self::STORED);
        $this->assertSame('Pokladna u vchodu', $data['name']);
        $this->assertNull($data['auto_lock_minutes']);
    }

    public function testNameIsRequiredAndLimited(): void
    {
        $this->assertSame(['required'], $this->codes($this->doc(), ['id' => 5, 'name' => '   ']));
        $this->assertSame(['too_long'], $this->codes($this->doc(), ['id' => 5, 'name' => str_repeat('a', 101)]));
    }

    public function testModeMustBeKnown(): void
    {
        $this->assertSame(['invalid'], $this->codes($this->doc(), ['id' => 5, 'mode' => 'unregistered']));
    }

    public function testAutoLockMustBeAPositiveNumberOfMinutes(): void
    {
        foreach ([0, -5, 'abc', 1441, '1.5'] as $value) {
            $this->assertSame(
                ['out_of_range'],
                $this->codes($this->doc(), ['id' => 5, 'auto_lock_minutes' => $value]),
                var_export($value, true),
            );
        }
        $this->assertSame([], $this->codes($this->doc(), ['id' => 5, 'auto_lock_minutes' => '15']));
        $this->assertSame([], $this->codes($this->doc(), ['id' => 5, 'auto_lock_minutes' => null]));
    }

    public function testRegistrationDataAreImmutable(): void
    {
        foreach (['device_id' => 'jine', 'public_key' => 'MFkx', 'platform' => 'macos', 'registered_by' => 9] as $column => $value) {
            $this->assertSame(['immutable'], $this->codes($this->doc(), ['id' => 5, $column => $value]), $column);
        }
        // Stejná hodnota (formulář posílá celý řádek) nevadí.
        $this->assertSame([], $this->codes($this->doc(), ['id' => 5] + self::STORED));
    }

    public function testStateCannotBeChangedByHand(): void
    {
        $this->assertSame(
            ['state_locked'],
            $this->codes($this->doc(), ['id' => 5, 'docState' => DeviceDocument::STATE_REVOKED]),
        );
    }

    public function testRevokedDeviceIsFinal(): void
    {
        $revoked = ['docState' => DeviceDocument::STATE_REVOKED] + self::STORED;

        $this->assertSame(['revoked'], $this->codes($this->doc($revoked), ['id' => 5, 'name' => 'Jinak']));
        $this->assertSame(
            ['revoked'],
            $this->codes($this->doc($revoked), ['id' => 5, 'docState' => DeviceDocument::STATE_ACTIVE]),
        );
    }

    public function testDeviceIsNeverDeleted(): void
    {
        $this->expectException(\LogicException::class);
        $this->doc()->beforeDelete(self::STORED);
    }
}
