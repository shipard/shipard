<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\World\Assets;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\World\Assets\AccountingOnlyTaxRules;
use Shipard\Module\World\Assets\CzTaxDepreciationRules;
use Shipard\Module\World\Assets\TaxDepreciationRules;
use Shipard\Module\World\Assets\TaxRulesRegistry;
use Shipard\Module\World\Assets\TaxScheduleInput;
use Shipard\Module\World\Assets\TaxYearInput;

class TaxRulesRegistryTest extends TestCase
{
    /** @param array<string, mixed> $items */
    private function config(array $items): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $items[$id] ?? null,
        );
        return $config;
    }

    public function testCountryWithConfigGetsItsOwnRules(): void
    {
        $rules = TaxRulesRegistry::forCountry(
            $this->config(['world.assets.cz' => ['methods' => []]]),
            'CZ',
        );
        $this->assertInstanceOf(CzTaxDepreciationRules::class, $rules);
        $this->assertSame('cz', $rules->country());
    }

    public function testCountryWithoutRulesFallsBackToAccountingOnly(): void
    {
        $rules = TaxRulesRegistry::forCountry($this->config(['world.assets.cz' => ['methods' => []]]), 'sk');
        $this->assertInstanceOf(AccountingOnlyTaxRules::class, $rules);
        $this->assertSame('sk', $rules->country());
    }

    public function testMissingConfigFallsBackToAccountingOnly(): void
    {
        $this->assertInstanceOf(AccountingOnlyTaxRules::class, TaxRulesRegistry::forCountry($this->config([]), 'cz'));
        $this->assertInstanceOf(AccountingOnlyTaxRules::class, TaxRulesRegistry::forCountry(null, 'cz'));
    }

    public function testAccountingOnlyRulesOfferNoTaxFormula(): void
    {
        $rules = new AccountingOnlyTaxRules('sk');

        $this->assertSame(['accounting', 'none'], $rules->availableMethods('2024-01-01', false));
        $this->assertSame(['accounting', 'none'], $rules->availableMethods('2024-01-01', true));
        $this->assertSame(TaxDepreciationRules::KIND_ACCOUNTING, $rules->methodKind('accounting'));
        $this->assertSame(TaxDepreciationRules::KIND_NONE, $rules->methodKind('none'));
        $this->assertNull($rules->methodKind('straight'));
        $this->assertSame([], $rules->rules('accounting', '2024-01-01'));
        $this->assertFalse($rules->isInterruptible('accounting'));
        $this->assertFalse($rules->allowsHalfYearOnDisposal('accounting'));
        $this->assertSame(10.13, $rules->round(10.126));

        try {
            $rules->annualAmount(new TaxYearInput('straight', 'x', '2024-01-01', 1000.0, 1000.0, 0));
            $this->fail('Stát bez pravidel nemá roční vzorec');
        } catch (\LogicException) {
        }
        $this->expectException(\LogicException::class);
        $rules->scheduleAmount(new TaxScheduleInput('time', 'x', '2024-01-01', 1000.0, 1000.0, 0, 12));
    }
}
