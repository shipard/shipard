<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Items;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Items\ItemsViewer;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Cena položky v seznamu se symbolem domácí měny (#133 D65, krok 2.5):
 * kód z nastavení `economy.homeCurrency`, symbol z `world.base.currencies`,
 * bez symbolu kód měny, bez nastavené měny jen číslo — a nastavení se čte
 * jednou na instanci, ne pro každý řádek.
 */
class ItemsViewerTest extends TestCase
{
    private int $settingReads = 0;

    private function viewer(?string $homeCurrency): ItemsViewer
    {
        $this->settingReads = 0;

        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturnCallback(function () use ($homeCurrency): ?string {
            $this->settingReads++;
            return $homeCurrency !== null ? json_encode($homeCurrency) : null;
        });

        $viewer = new ItemsViewer($db, 'economy_items');
        $viewer->setConfig(ConfigRuntimeFactory::fromItems([
            'world.base.currencies' => [
                'czk' => ['alpha3' => 'CZK', 'symbol' => 'Kč'],
                'eur' => ['alpha3' => 'EUR', 'symbol' => '€'],
                'xxx' => ['alpha3' => 'XXX'],
            ],
        ]));
        return $viewer;
    }

    /** @return array<string, mixed> */
    private static function row(int $id, ?string $price, ?string $unit = 'ks'): array
    {
        return ['id' => $id, 'code' => 'P' . $id, 'name' => 'Položka', 'item_type' => 0, 'docState' => 10,
            'sales_price_no_vat' => $price, 'unit_shortcut' => $unit];
    }

    public function testPriceCarriesHomeCurrencySymbol(): void
    {
        $this->assertSame('1 250,50 Kč / ks', $this->viewer('czk')->renderRow(self::row(1, '1250.50'))['t3']);
        $this->assertSame('12,00 €', $this->viewer('EUR')->renderRow(self::row(1, '12', null))['t3']);
    }

    public function testCurrencyWithoutSymbolFallsBackToCode(): void
    {
        $this->assertSame('5,00 XXX / ks', $this->viewer('xxx')->renderRow(self::row(1, '5'))['t3']);
        $this->assertSame('5,00 USD / ks', $this->viewer('usd')->renderRow(self::row(1, '5'))['t3'], 'měna mimo číselník');
    }

    public function testWithoutHomeCurrencyOnlyNumber(): void
    {
        $this->assertSame('5,00 / ks', $this->viewer(null)->renderRow(self::row(1, '5'))['t3']);
        $this->assertSame('5,00', $this->viewer('')->renderRow(self::row(1, '5', null))['t3']);
        $this->assertNull($this->viewer('czk')->renderRow(self::row(1, null))['t3'], 'bez ceny žádný řádek');
    }

    public function testHomeCurrencyIsReadOncePerInstance(): void
    {
        $viewer = $this->viewer('czk');
        foreach ([1, 2, 3] as $id) {
            $viewer->renderRow(self::row($id, '10'));
        }
        $this->assertSame(1, $this->settingReads);

        $viewer = $this->viewer(null);
        $viewer->renderRow(self::row(1, '10'));
        $viewer->renderRow(self::row(2, '10'));
        $this->assertSame(1, $this->settingReads, 'i nenastavená měna se zjišťuje jednou');
    }
}
