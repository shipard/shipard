<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\Accounting\AccountingRules;

/**
 * AccountingRules — maska kategorie bez řádkového kontextu (první záznam
 * kategorie, řetěz masek dá první, chybějící kategorie '') a dohledání
 * předpisu (bez konfigurace null, fallback cz).
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
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn(null);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([
            ['economy.accounting.rules.cz', self::RULES],
        ]);

        $this->assertSame(self::RULES, AccountingRules::resolve($config, $db));
    }
}
