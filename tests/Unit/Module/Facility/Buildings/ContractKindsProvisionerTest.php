<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleProvisionContext;
use Shipard\Module\Facility\Buildings\ContractKindDocument;
use Shipard\Module\Facility\Buildings\ContractKindsProvisioner;
use Shipard\Module\Facility\Buildings\ContractTypes;

/**
 * Seed druhů smluv (#174 D19, D28): jeden druh na typ se seedovým kódem,
 * idempotentně podle `system_code`; existující druh v jakémkoli stavu se
 * nezakládá znovu ani nepřepisuje.
 */
class ContractKindsProvisionerTest extends TestCase
{
    use BuildingsConfig;

    /**
     * @param list<array<string, mixed>> $rows výchozí obsah tabulky
     * @param array<string, mixed> $config další cfgItemy
     */
    private function context(\stdClass $store, array $rows = [], array $config = [], bool $withConfig = true): ModuleProvisionContext
    {
        $store->rows = $rows;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            static function (string $sql, mixed ...$params) use ($store): ?array {
                foreach ($store->rows as $row) {
                    if (($row['system_code'] ?? null) === $params[0]) {
                        return ['id' => $row['id']];
                    }
                }
                return null;
            },
        );
        $db->method('insertRow')->willReturnCallback(
            static function (string $table, array $data) use ($store): int {
                $id = count($store->rows) + 1;
                $store->rows[] = ['id' => $id, '_table' => $table] + $data;
                return $id;
            },
        );

        return new ModuleProvisionContext(
            $db,
            $this->createMock(DataSourceConfig::class),
            $withConfig ? $this->buildingsConfig($config) : null,
        );
    }

    public function testCreatesOneConfirmedKindPerTypeWithTheAgreedCodes(): void
    {
        $store = new \stdClass();
        $result = new ContractKindsProvisioner()->provision($this->context($store));

        $this->assertSame(['contract kinds' => ['created' => 7, 'existing' => 0]], $result);
        $this->assertSame(
            ['office' => 'KA', 'flat' => 'BY', 'retail' => 'OP', 'storage' => 'SK', 'parking' => 'PA', 'virtualOffice' => 'VK', 'other' => 'OS'],
            array_column($store->rows, 'number_code', 'system_code'),
        );
        $this->assertSame(
            ['Kancelář', 'Byt', 'Obchodní prostor', 'Sklad', 'Parkování', 'Virtuální kancelář', 'Ostatní'],
            array_column($store->rows, 'name'),
            'název druhu je název typu v jazyce zdroje dat',
        );
        foreach ($store->rows as $row) {
            $this->assertSame(ContractKindDocument::TABLE, $row['_table']);
            $this->assertSame($row['system_code'], $row['type']);
            $this->assertSame('%C-%Y-%4', $row['number_pattern']);
            $this->assertSame('yearly', $row['reset_scope']);
            $this->assertSame(40, $row['docState']);
            $this->assertSame(3, $row['docStateMain']);
        }
    }

    public function testSecondRunCreatesNothing(): void
    {
        $store = new \stdClass();
        $provisioner = new ContractKindsProvisioner();
        $context = $this->context($store);
        $provisioner->provision($context);

        $this->assertSame(['contract kinds' => ['created' => 0, 'existing' => 7]], $provisioner->provision($context));
        $this->assertCount(7, $store->rows);
    }

    public function testExistingKindIsLeftAloneWhateverItsStateOrName(): void
    {
        // Uživatel druh přejmenoval, změnil mu vzorec a poslal ho do koše.
        $store = new \stdClass();
        $context = $this->context($store, [
            ['id' => 1, 'name' => 'Kanceláře 2. patro', 'type' => 'office', 'number_code' => 'K2',
                'number_pattern' => '%C%y%3', 'system_code' => 'office', 'docState' => 90],
        ]);

        $result = new ContractKindsProvisioner()->provision($context);

        $this->assertSame(['contract kinds' => ['created' => 6, 'existing' => 1]], $result);
        $this->assertSame('Kanceláře 2. patro', $store->rows[0]['name']);
        $this->assertSame('%C%y%3', $store->rows[0]['number_pattern']);
        $this->assertSame(90, $store->rows[0]['docState']);
        $this->assertSame(1, count(array_keys(array_column($store->rows, 'system_code'), 'office', true)));
    }

    public function testTypeWithoutSeedCodeIsNotSeeded(): void
    {
        $store = new \stdClass();
        $context = $this->context($store, config: [
            ContractTypes::CFG_ITEM => [
                'office' => ['name' => 'Kancelář', 'seedCode' => 'KA', 'order' => 10],
                'custom' => ['name' => 'Vlastní', 'order' => 20],
            ],
        ]);

        $this->assertSame(
            ['contract kinds' => ['created' => 1, 'existing' => 0]],
            new ContractKindsProvisioner()->provision($context),
        );
        $this->assertSame(['office'], array_column($store->rows, 'system_code'));
    }

    public function testMissingTypesFailInsteadOfSeedingByGuess(): void
    {
        $store = new \stdClass();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('contract types unavailable (cfgItem facility.buildings.contractTypes)');

        new ContractKindsProvisioner()->provision($this->context($store, withConfig: false));
    }
}
