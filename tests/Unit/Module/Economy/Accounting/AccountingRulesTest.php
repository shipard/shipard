<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingRules;

/**
 * AccountingRules — maska kategorie bez řádkového kontextu (první záznam
 * kategorie, řetěz masek dá první, chybějící kategorie ''), dohledání
 * předpisu (bez konfigurace null, fallback cz) a efektivní předpis pro
 * variantu osnovy (`chartOverrides`, #122 D1): přepis nahradí celou
 * kategorii na jejím místě, ostatní zůstávají, sekce se odebere, varianta
 * bez přepisů dostane předpis beze změny, neznámá kategorie je chyba.
 */
class AccountingRulesTest extends TestCase
{
    private const RULES = [
        'accounts' => [
            ['cat' => 'advances.received', 'accountMask' => '324', 'query' => ['vat_amount' => 0]],
            ['cat' => 'advances.received', 'accountMask' => ['3249', '324']],
            ['cat' => 'offbalance.contra', 'accountMask' => '799'],
            ['cat' => 'fx.loss', 'accountMask' => ['563100', '563']],
            'not-an-entry',
        ],
    ];

    private const RULES_WITH_OVERRIDES = [
        'categories' => ['costs' => ['name' => 'Costs']],
        'accounts' => [
            ['cat' => 'costs', 'accountMask' => '504', 'query' => ['operation' => 'purchase.goods']],
            ['cat' => 'costs', 'accountMask' => '548', 'query' => ['operation' => 'purchase.other']],
            ['cat' => 'receivables', 'accountMask' => '311'],
            ['cat' => 'rounding.cost', 'accountMask' => '548'],
            ['cat' => 'fx.loss', 'accountMask' => '563'],
        ],
        'chartOverrides' => [
            'npo' => [
                'accounts' => [
                    ['cat' => 'rounding.cost', 'accountMask' => '549'],
                    ['cat' => 'costs', 'accountMask' => '504', 'query' => ['operation' => 'purchase.goods']],
                    ['cat' => 'costs', 'accountMask' => '549', 'query' => ['operation' => 'purchase.other']],
                ],
            ],
        ],
        'documents' => [['docType' => 'invno', 'accounting' => []]],
    ];

    public function testFirstEntryOfCategoryWins(): void
    {
        $this->assertSame('324', AccountingRules::firstMaskForCategory(self::RULES, 'advances.received'));
        $this->assertSame('799', AccountingRules::firstMaskForCategory(self::RULES, 'offbalance.contra'));
    }

    public function testMaskChainGivesFirstMask(): void
    {
        $this->assertSame('563100', AccountingRules::firstMaskForCategory(self::RULES, 'fx.loss'));
    }

    public function testUnknownCategoryOrMissingRulesIsEmpty(): void
    {
        $this->assertSame('', AccountingRules::firstMaskForCategory(self::RULES, 'no.such'));
        $this->assertSame('', AccountingRules::firstMaskForCategory(null, 'offbalance.contra'));
        $this->assertSame('', AccountingRules::firstMaskForCategory([], 'offbalance.contra'));
    }

    public function testResolveWithoutConfigIsNull(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $this->assertNull(AccountingRules::resolve(null, $db));
    }

    public function testResolveFallsBackToCzWhenCountryRulesMissing(): void
    {
        // Bez vlastní firmy (fetch vrací null) → země cz; předpis cz existuje.
        // Bez varianty osnovy (fetchSingle null) → předpis beze změny.
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.accounting.rules.cz', self::RULES],
        ]);

        $this->assertSame(self::RULES, AccountingRules::resolve($config, $db));
    }

    public function testResolveAppliesChartVariantFromSettings(): void
    {
        // Varianta osnovy je v core_system_settings jako JSON ("npo").
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->method('fetchSingle')->willReturnCallback(
            static fn (...$args) => str_contains((string) $args[0], 'core_system_settings')
                && ($args[1] ?? null) === AccountingRules::SETTING_CHART_VARIANT
                ? '"npo"'
                : null,
        );
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.accounting.rules.cz', self::RULES_WITH_OVERRIDES],
        ]);

        $rules = AccountingRules::resolve($config, $db);
        $this->assertIsArray($rules);
        $this->assertArrayNotHasKey('chartOverrides', $rules);
        $this->assertSame('549', AccountingRules::firstMaskForCategory($rules, 'rounding.cost'));
        $this->assertSame('311', AccountingRules::firstMaskForCategory($rules, 'receivables'));
    }

    public function testEffectiveReplacesWholeCategoryInPlace(): void
    {
        $rules = AccountingRules::effective(self::RULES_WITH_OVERRIDES, 'npo');

        $this->assertArrayNotHasKey('chartOverrides', $rules);
        $this->assertSame(self::RULES_WITH_OVERRIDES['categories'], $rules['categories']);
        $this->assertSame(self::RULES_WITH_OVERRIDES['documents'], $rules['documents']);
        $this->assertSame([
            // costs: oba přepsané záznamy na místě prvního obecného, v pořadí přepisu
            ['cat' => 'costs', 'accountMask' => '504', 'query' => ['operation' => 'purchase.goods']],
            ['cat' => 'costs', 'accountMask' => '549', 'query' => ['operation' => 'purchase.other']],
            ['cat' => 'receivables', 'accountMask' => '311'],
            ['cat' => 'rounding.cost', 'accountMask' => '549'],
            ['cat' => 'fx.loss', 'accountMask' => '563'],
        ], $rules['accounts']);
    }

    public function testEffectiveWithoutOverridesForVariantOnlyDropsSection(): void
    {
        $expected = self::RULES_WITH_OVERRIDES;
        unset($expected['chartOverrides']);

        $this->assertSame($expected, AccountingRules::effective(self::RULES_WITH_OVERRIDES, 'default'));
        $this->assertSame($expected, AccountingRules::effective(self::RULES_WITH_OVERRIDES, 'none'));
        $this->assertSame($expected, AccountingRules::effective(self::RULES_WITH_OVERRIDES, null));
        $this->assertSame(self::RULES, AccountingRules::effective(self::RULES, 'npo'));
    }

    public function testEffectiveRejectsOverrideOfUnknownCategory(): void
    {
        $rules = self::RULES_WITH_OVERRIDES;
        $rules['chartOverrides']['npo']['accounts'][] = ['cat' => 'no.such', 'accountMask' => '549'];

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage("kategorii 'no.such'");
        AccountingRules::effective($rules, 'npo');
    }

    public function testEffectiveRejectsOverrideEntryWithoutCategory(): void
    {
        $rules = self::RULES_WITH_OVERRIDES;
        $rules['chartOverrides']['npo']['accounts'][] = ['accountMask' => '549'];

        $this->expectException(\LogicException::class);
        AccountingRules::effective($rules, 'npo');
    }
}
