<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Devices;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Devices\DeviceException;
use Shipard\Module\Core\Devices\PeripheralDocument;
use Shipard\Module\Core\Devices\PeripheralSync;
use Shipard\Tests\Fixtures\Module\Devices\InMemoryDevicesDb;

/**
 * Hlášení periferií (#177 D32, U19): párování podle `peripheral_id`,
 * chybějící periferie zůstává jako nepřítomná i s rolí, tvar podle kontraktu.
 */
class PeripheralSyncTest extends TestCase
{
    private const int DEVICE = 5;

    private const array PRINTER = [
        'id' => 'p-1a2b', 'kind' => 'printer', 'name' => 'Účtenky – pult',
        'transport' => 'tcp', 'status' => 'ready', 'profileId' => 'escpos-80-cp852',
    ];
    private const array SCANNER = [
        'id' => 'p-3c4d', 'kind' => 'scanner', 'name' => 'Čtečka u kasy',
        'transport' => 'ble', 'status' => 'offline', 'reads' => ['barcode'],
    ];

    private InMemoryDevicesDb $db;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = InMemoryDevicesDb::create();
        $this->now = new \DateTimeImmutable('2026-11-27 10:00:00');
    }

    private function sync(): PeripheralSync
    {
        $clock = fn (): \DateTimeImmutable => $this->now;
        return new class($this->db, $clock) extends PeripheralSync {
            protected function transactional(callable $work): void
            {
                $work();
            }
        };
    }

    /** @return array<string, array<string, mixed>> `peripheral_id` → řádek */
    private function stored(int $device = self::DEVICE): array
    {
        $rows = array_filter(
            $this->db->rows(PeripheralDocument::TABLE),
            static fn (array $row): bool => (int) $row['device'] === $device,
        );
        return array_column($rows, null, 'peripheral_id');
    }

    /** @return list<string> */
    private function invalidFields(mixed $body): array
    {
        $writes = count($this->db->writes);
        try {
            $this->sync()->sync(self::DEVICE, $body);
            $this->fail('očekávána chyba VALIDATION_ERROR');
        } catch (DeviceException $e) {
            $this->assertSame(DeviceException::VALIDATION_ERROR, $e->errorCode);
            $this->assertSame(422, $e->httpStatus);
            $this->assertCount($writes, $this->db->writes, 'neplatné hlášení nesmí nic zapsat');
            return array_column($e->details, 'field');
        }
    }

    public function testFirstReportCreatesPeripherals(): void
    {
        $count = $this->sync()->sync(self::DEVICE, ['peripherals' => [self::PRINTER, self::SCANNER]]);

        $this->assertSame(2, $count);
        $stored = $this->stored();
        $this->assertSame(['p-1a2b', 'p-3c4d'], array_keys($stored));

        $printer = $stored['p-1a2b'];
        $this->assertSame('printer', $printer['kind']);
        $this->assertSame('Účtenky – pult', $printer['name']);
        $this->assertSame('tcp', $printer['transport']);
        $this->assertSame('ready', $printer['status']);
        $this->assertSame('escpos-80-cp852', $printer['profile_id']);
        $this->assertNull($printer['reads']);
        $this->assertSame(1, $printer['present']);
        $this->assertEquals($this->now, $printer['reported_at']);
        $this->assertArrayNotHasKey('role', $printer, 'hlášení roli nezapisuje');

        $this->assertSame('["barcode"]', $stored['p-3c4d']['reads']);
        $this->assertNull($stored['p-3c4d']['profile_id']);
    }

    public function testMissingPeripheralStaysAsAbsentAndKeepsItsRole(): void
    {
        $sync = $this->sync();
        $sync->sync(self::DEVICE, ['peripherals' => [self::PRINTER, self::SCANNER]]);
        $printerId = (int) $this->stored()['p-1a2b']['id'];
        // Administrátor mezitím přiřadil roli.
        $this->db->tables[PeripheralDocument::TABLE][$printerId]['role'] = 'receipt';

        $this->now = $this->now->modify('+5 minutes');
        $this->assertSame(1, $sync->sync(self::DEVICE, ['peripherals' => [self::SCANNER]]));

        $stored = $this->stored();
        $this->assertCount(2, $stored, 'chybějící periferie se nemaže');
        $this->assertSame(0, $stored['p-1a2b']['present']);
        $this->assertSame('receipt', $stored['p-1a2b']['role']);
        $this->assertEquals($this->now->modify('-5 minutes'), $stored['p-1a2b']['reported_at']);
        $this->assertSame(1, $stored['p-3c4d']['present']);
        $this->assertEquals($this->now, $stored['p-3c4d']['reported_at']);
    }

    public function testReturningPeripheralIsUpdatedInPlaceWithItsRole(): void
    {
        $sync = $this->sync();
        $sync->sync(self::DEVICE, ['peripherals' => [self::PRINTER]]);
        $printerId = (int) $this->stored()['p-1a2b']['id'];
        $this->db->tables[PeripheralDocument::TABLE][$printerId]['role'] = 'receipt';
        $sync->sync(self::DEVICE, ['peripherals' => []]);

        $sync->sync(self::DEVICE, ['peripherals' => [
            ['name' => 'Účtenky – sklad', 'status' => 'error', 'profileId' => 'escpos-58-cp852'] + self::PRINTER,
        ]]);

        $stored = $this->stored();
        $this->assertCount(1, $stored);
        $this->assertSame($printerId, (int) $stored['p-1a2b']['id']);
        $this->assertSame('Účtenky – sklad', $stored['p-1a2b']['name']);
        $this->assertSame('error', $stored['p-1a2b']['status']);
        $this->assertSame('escpos-58-cp852', $stored['p-1a2b']['profile_id']);
        $this->assertSame(1, $stored['p-1a2b']['present']);
        $this->assertSame('receipt', $stored['p-1a2b']['role']);
    }

    public function testEmptyReportMarksEverythingAbsent(): void
    {
        $sync = $this->sync();
        $sync->sync(self::DEVICE, ['peripherals' => [self::PRINTER, self::SCANNER]]);

        $this->assertSame(0, $sync->sync(self::DEVICE, ['peripherals' => []]));
        $this->assertSame([0, 0], array_column($this->stored(), 'present'));
    }

    public function testPeripheralsOfOtherDevicesAreUntouched(): void
    {
        $sync = $this->sync();
        $sync->sync(6, ['peripherals' => [self::PRINTER]]);

        $sync->sync(self::DEVICE, ['peripherals' => []]);
        $sync->sync(self::DEVICE, ['peripherals' => [self::PRINTER]]);

        $this->assertSame(1, $this->stored(6)['p-1a2b']['present']);
        $this->assertNotSame($this->stored(6)['p-1a2b']['id'], $this->stored()['p-1a2b']['id']);
    }

    public function testUnknownEnumValuesAreAccepted(): void
    {
        // Novější aplikace smí hlásit, co server nezná (kontrakt §2.4).
        $this->sync()->sync(self::DEVICE, ['peripherals' => [
            ['id' => 'x-1', 'kind' => 'display', 'name' => 'Zákaznický displej', 'transport' => 'thunderbolt', 'status' => 'sleeping', 'futureField' => true],
        ]]);

        $stored = $this->stored()['x-1'];
        $this->assertSame('display', $stored['kind']);
        $this->assertSame('thunderbolt', $stored['transport']);
        $this->assertSame('sleeping', $stored['status']);
        $this->assertArrayNotHasKey('futureField', $stored);
    }

    public function testBodyMustBeAListOfPeripherals(): void
    {
        foreach ([null, [], ['peripherals' => 'p-1'], ['peripherals' => ['a' => self::PRINTER]], [self::PRINTER]] as $body) {
            $this->assertSame(['peripherals'], $this->invalidFields($body), json_encode($body));
        }
    }

    public function testReportIsLimited(): void
    {
        $many = [];
        for ($i = 0; $i <= PeripheralSync::MAX_PERIPHERALS; $i++) {
            $many[] = ['id' => "p-{$i}"] + self::PRINTER;
        }

        $this->assertSame(['peripherals'], $this->invalidFields(['peripherals' => $many]));

        array_pop($many);
        $this->assertSame(PeripheralSync::MAX_PERIPHERALS, $this->sync()->sync(self::DEVICE, ['peripherals' => $many]));
    }

    public function testEveryPeripheralIsCheckedAndNothingIsWritten(): void
    {
        $this->sync()->sync(self::DEVICE, ['peripherals' => [self::SCANNER]]);

        $fields = $this->invalidFields(['peripherals' => [
            self::PRINTER,
            'p-2',
            ['id' => '', 'kind' => 'printer', 'name' => 'Bez ID'],
            ['id' => 'p-4', 'name' => str_repeat('a', 101), 'transport' => 12, 'reads' => 'barcode'],
            ['id' => 'p-1a2b', 'kind' => 'printer', 'name' => "Řídicí\x07znak"],
            ['id' => 'p-6', 'kind' => 'scanner', 'name' => 'Čtečka', 'reads' => [1, 2]],
        ]]);

        $this->assertSame([
            'peripherals.1',
            'peripherals.2.id',
            'peripherals.3.kind', 'peripherals.3.name', 'peripherals.3.transport', 'peripherals.3.reads',
            'peripherals.4.name', 'peripherals.4.id',
            'peripherals.5.reads',
        ], $fields);
        // Dřívější stav zůstal.
        $this->assertSame(['p-3c4d'], array_keys($this->stored()));
        $this->assertSame(1, $this->stored()['p-3c4d']['present']);
    }

    public function testPeripheralReportedTwiceIsAnError(): void
    {
        $this->assertSame(
            ['peripherals.1.id'],
            $this->invalidFields(['peripherals' => [self::PRINTER, ['name' => 'Podruhé'] + self::PRINTER]]),
        );
    }
}
