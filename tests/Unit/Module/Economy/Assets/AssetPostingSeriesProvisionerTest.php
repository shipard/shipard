<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Tests\Unit\Module\Docs\Core\NumberSeriesDbTrait;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Settings\KeyValueStore;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Assets\Posting\AssetPostingSeries;
use Shipard\Module\Economy\Assets\Posting\AssetPostingSeriesProvisioner;

/**
 * Řada účetních dokladů „Majetek“ (D54): založení + nastavení na novém
 * zdroji, idempotence, vyplněné nastavení se nepřepisuje.
 */
class AssetPostingSeriesProvisionerTest extends TestCase
{
    use NumberSeriesDbTrait;

    /** @param array<string, mixed> $values */
    private function settings(array $values = []): KeyValueStore
    {
        return new class($values) implements KeyValueStore {
            /** @param array<string, mixed> $values */
            public function __construct(public array $values)
            {
            }

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function getMany(array $keys): array
            {
                return array_map(fn(string $key): mixed => $this->get($key), array_combine($keys, $keys));
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
    }

    public function testCreatesSeriesAndPointsSettingToIt(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->expects($this->once())->method('insertRow')->with(
            'docs_core_number_series',
            [
                'doc_type' => 'cmnbkp', 'name' => 'Majetek', 'doc_number_code' => 'MA',
                'doc_number_pattern' => '%D%y%C%4', 'reset_scope' => 'fiscal_year', 'docState' => 40, 'docStateMain' => 3,
            ],
        )->willReturn(77);
        $settings = $this->settings();

        $result = (new AssetPostingSeriesProvisioner($db, $settings))->provision();

        $this->assertSame(['created' => 1, 'existing' => 0, 'seriesId' => 77], $result);
        $this->assertSame('77', $settings->get(AssetPostingSeries::SETTING));
    }

    public function testReusesExistingSeriesWithTheCode(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(['id' => 55]);
        $db->expects($this->never())->method('insertRow');
        $settings = $this->settings();

        $result = (new AssetPostingSeriesProvisioner($db, $settings))->provision();

        $this->assertSame(['created' => 0, 'existing' => 1, 'seriesId' => 55], $result);
        $this->assertSame('55', $settings->get(AssetPostingSeries::SETTING));
    }

    public function testModuleDeclaresTheSettingForClearingOnReset(): void
    {
        // #131 D42: ds-reset dropuje řady a vyplněný klíč by provisioner
        // nepřepsal — modul proto deklaruje, že klíč nese id řádku řad.
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(dirname(__DIR__, 5) . '/modules/economy/assets/module.jsonc'));

        $this->assertSame(
            [['key' => AssetPostingSeries::SETTING, 'table' => AssetPostingSeries::TABLE]],
            $module->clearSettingsOnReset,
        );
    }

    public function testFilledSettingIsNeverOverwritten(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchRow');
        $db->expects($this->never())->method('insertRow');
        $settings = $this->settings([AssetPostingSeries::SETTING => '3']);

        $result = (new AssetPostingSeriesProvisioner($db, $settings))->provision();

        $this->assertSame(['created' => 0, 'existing' => 1, 'seriesId' => 3], $result);
        $this->assertSame('3', $settings->get(AssetPostingSeries::SETTING));
    }

    public function testCreatedSeriesGetsItsCode(): void
    {
        // #166 D17: druhá řada účetních dokladů = `cmnbkp-<kód z čísla dokladu>`.
        $store = new \ArrayObject();
        $dibi = $this->seriesDb([
            ['id' => 1, 'doc_type' => 'cmnbkp', 'code' => 'cmnbkp', 'doc_number_code' => null, 'docState' => 40],
        ], [], $store);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);
        $db->method('insertRow')->willReturnCallback(static function (string $table, array $data) use ($store): int {
            $store['rows'] = [...$store['rows'], ['id' => 2, 'code' => null] + $data];
            return 2;
        });
        $db->method('getDibiConnection')->willReturn($dibi);

        (new AssetPostingSeriesProvisioner($db, $this->settings()))->provision();

        $this->assertSame([1 => 'cmnbkp', 2 => 'cmnbkp-MA'], self::codesOf($store));
    }
}
