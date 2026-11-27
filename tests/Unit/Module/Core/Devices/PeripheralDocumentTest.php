<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\DeviceDocument;
use Shipard\Module\Core\Devices\PeripheralDocument;

/**
 * Periferie zařízení (#177 U19): měnit jde jen role — musí sedět na druh
 * periferie a roli `receipt` nese na zařízení nejvýš jedna periferie.
 */
class PeripheralDocumentTest extends TestCase
{
    use DevicesConfig;

    private const array PRINTER = [
        'id'            => 11,
        'device'        => 5,
        'peripheral_id' => 'p-1a2b',
        'kind'          => 'printer',
        'name'          => 'Účtenky – pult',
        'transport'     => 'tcp',
        'status'        => 'ready',
        'present'       => 1,
        'role'          => null,
    ];

    /**
     * @param array<string, mixed>|null $stored uložený řádek periferie
     * @param list<array{device: int, role: string, id: int}> $others jiné periferie s rolí
     */
    private function doc(?array $stored = self::PRINTER, ?int $deviceState = DeviceDocument::STATE_ACTIVE, array $others = []): PeripheralDocument
    {
        $doc = new class extends PeripheralDocument {
            /** @var array<string, mixed>|null */
            public ?array $stored = null;
            public ?int $state = null;
            /** @var list<array<string, mixed>> */
            public array $others = [];

            protected function loadPeripheralRow(int $id): ?array
            {
                return $this->stored;
            }

            protected function deviceState(int $deviceId): ?int
            {
                return $this->state;
            }

            protected function countWithRole(int $deviceId, string $role, int $exceptId): int
            {
                return count(array_filter(
                    $this->others,
                    static fn(array $p): bool => $p['device'] === $deviceId && $p['role'] === $role && $p['id'] !== $exceptId,
                ));
            }
        };
        $doc->stored = $stored;
        $doc->state = $deviceState;
        $doc->others = $others;
        $doc->setConfig($this->devicesConfig());
        return $doc;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function codes(PeripheralDocument $doc, array $data): array
    {
        return array_map(static fn($e) => $e->code, $doc->validate($data)->getErrors());
    }

    public function testPeripheralCannotBeCreatedByHand(): void
    {
        $this->assertSame(['system_managed'], $this->codes($this->doc(null), ['device' => 5, 'name' => 'Ručně']));
        $this->assertSame(['system_managed'], $this->codes($this->doc(null), ['id' => 99, 'role' => 'receipt']));
    }

    public function testRoleCanBeAssignedAndRemoved(): void
    {
        $doc = $this->doc();
        $data = ['id' => 11, 'role' => 'receipt'];
        $this->assertSame([], $this->codes($doc, $data));

        $data = ['id' => 11, 'role' => ''];
        $this->assertSame([], $this->codes($doc, $data));
        $doc->beforeSave($data, self::PRINTER);
        $this->assertNull($data['role']);
    }

    public function testOnlyRoleIsMutable(): void
    {
        $this->assertSame(['immutable'], $this->codes($this->doc(), ['id' => 11, 'name' => 'Jiný název', 'role' => 'receipt']));
        $this->assertSame(['immutable'], $this->codes($this->doc(), ['id' => 11, 'present' => 0]));
        // Formulář smí poslat nezměněné sloupce.
        $this->assertSame([], $this->codes($this->doc(), ['role' => 'receipt'] + self::PRINTER));
    }

    public function testUnknownRoleIsRejected(): void
    {
        $this->assertSame(['invalid'], $this->codes($this->doc(), ['id' => 11, 'role' => 'labels']));
    }

    public function testRoleMustFitThePeripheralKind(): void
    {
        $scanner = ['id' => 12, 'kind' => 'scanner', 'name' => 'Čtečka u kasy'] + self::PRINTER;

        $this->assertSame(['role_kind_mismatch'], $this->codes($this->doc($scanner), ['id' => 12, 'role' => 'receipt']));
    }

    public function testReceiptRoleIsOnlyOncePerDevice(): void
    {
        $others = [['device' => 5, 'role' => 'receipt', 'id' => 10]];

        $this->assertSame(['duplicate_role'], $this->codes($this->doc(others: $others), ['id' => 11, 'role' => 'receipt']));
        // Jiné zařízení ani tatáž periferie nevadí.
        $this->assertSame([], $this->codes($this->doc(others: [['device' => 6, 'role' => 'receipt', 'id' => 10]]), ['id' => 11, 'role' => 'receipt']));
        $this->assertSame([], $this->codes($this->doc(others: [['device' => 5, 'role' => 'receipt', 'id' => 11]]), ['id' => 11, 'role' => 'receipt']));
    }

    public function testRuleOfSingleRoleComesFromConfiguration(): void
    {
        // Role bez `single` smí být na zařízení víckrát a bez `kinds` u každého druhu.
        $doc = $this->doc(others: [['device' => 5, 'role' => 'labels', 'id' => 10]]);
        $doc->setConfig($this->devicesConfig([
            'core.devices.peripheralRoles' => ['labels' => ['name' => 'Štítky']],
        ]));

        $this->assertSame([], $this->codes($doc, ['id' => 11, 'role' => 'labels']));
    }

    public function testPeripheralOfARevokedDeviceIsReadOnly(): void
    {
        $this->assertSame(
            ['revoked'],
            $this->codes($this->doc(deviceState: DeviceDocument::STATE_REVOKED), ['id' => 11, 'role' => 'receipt']),
        );
        $this->assertSame(['revoked'], $this->codes($this->doc(deviceState: null), ['id' => 11, 'role' => 'receipt']));
    }

    public function testPeripheralIsNeverDeletedByHand(): void
    {
        $this->expectException(\LogicException::class);
        $this->doc()->beforeDelete(self::PRINTER);
    }
}
