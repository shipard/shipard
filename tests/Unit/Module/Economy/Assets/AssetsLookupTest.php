<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Economy\Assets\AssetsLookup;

/**
 * Výchozí hodnoty nové karty zakládané z řádku dokladu (docs/assets.md
 * D62): název z textu řádku, druh podle účtu (04x dlouhodobý hmotný, 5xx
 * drobný), u drobného cena ze základu řádku v domácí měně a datum pořízení
 * z účetního data. U dlouhodobého cenu a datum nese až zařazení.
 */
class AssetsLookupTest extends TestCase
{
    private const ACCOUNTS = [37 => '042100', 442 => '501201', 462 => '518100', 90 => '321100'];

    private const HEAD = [
        'accounting_date' => '2026-05-10', 'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => 1,
    ];

    private function lookup(): AssetsLookup
    {
        $lookup = new class(self::ACCOUNTS) extends AssetsLookup {
            /** @param array<int, string> $accounts */
            public function __construct(private readonly array $accounts)
            {
            }

            protected function accountNumber(int $accountId): ?string
            {
                return $this->accounts[$accountId] ?? null;
            }
        };
        $lookup->setConfig(TestAssetPlanService::config());
        return $lookup;
    }

    public function testAcquisitionAccountGivesLongTermCardWithoutDateAndPrice(): void
    {
        $defaults = $this->lookup()->createDefaults(
            ['description' => ' CNC soustruh ', 'account' => 37, 'vat_base' => 80000, 'vat_base_dom' => 80000],
            self::HEAD,
        );

        // Cenu a datum pořízení dlouhodobého majetku plní zařazení (D13, D38).
        $this->assertSame(['name' => 'CNC soustruh', 'category' => 'tangible'], $defaults);
    }

    public function testExpenseAccountGivesSmallAssetWithPriceAndDate(): void
    {
        $defaults = $this->lookup()->createDefaults(
            ['description' => 'Notebook', 'account' => '442', 'vat_base' => '24990.00'],
            self::HEAD,
        );

        $this->assertSame([
            'name' => 'Notebook', 'category' => 'small', 'acquired_date' => '2026-05-10', 'price' => 24990.0,
        ], $defaults);
        // 518 je taky třída 5.
        $this->assertSame('small', $this->lookup()->createDefaults(['account' => 462], self::HEAD)['category']);
    }

    public function testForeignCurrencyRowIsConvertedByHeadRate(): void
    {
        // Formulář řádku počítá živě jen základ v měně dokladu.
        $head = ['doc_currency' => 'eur', 'exchange_rate' => 25.0] + self::HEAD;

        $this->assertSame(2500.0, $this->lookup()->createDefaults(['account' => 442, 'vat_base' => 100], $head)['price']);
        // Bez živého základu se vezme uložený domácí.
        $this->assertSame(2600.0, $this->lookup()->createDefaults(['account' => 442, 'vat_base_dom' => 2600], $head)['price']);
    }

    public function testRowWithoutAccountKeepsFormDefaultCategory(): void
    {
        // Bez účtu (nebo mimo 04x / 5xx) se druh nenavrhuje a cena taky ne.
        foreach ([[], ['account' => 90], ['account' => 999]] as $account) {
            $defaults = $this->lookup()->createDefaults($account + ['description' => 'Kopírka', 'vat_base' => 5000], self::HEAD);
            $this->assertSame(['name' => 'Kopírka', 'acquired_date' => '2026-05-10'], $defaults);
        }
    }

    public function testEmptyParentsGiveNoDefaults(): void
    {
        $this->assertSame([], $this->lookup()->createDefaults([], []));
        // Nesmyslné datum a nulový základ se nepřenáší.
        $this->assertSame(
            ['category' => 'small'],
            $this->lookup()->createDefaults(['account' => 442, 'vat_base' => 0], ['accounting_date' => 'zítra']),
        );
    }

    public function testNameIsCutToColumnLength(): void
    {
        $defaults = $this->lookup()->createDefaults(['description' => str_repeat('ž', 400)], []);

        $this->assertSame(150, mb_strlen($defaults['name']));
    }
}
