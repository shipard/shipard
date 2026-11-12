<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\RuleQuery;
use Shipard\Module\Economy\Accounting\VatAnalytics;

/**
 * Analytika 343 řádku rekapitulace mimo AccountingEngine (#89 D9) — nad
 * skutečným předpisem `accountingRules.cz.jsonc`: konvence `343{NNN}`
 * a výjimka 343117 pro cz-115 + předmět plnění 5. Stejná pravidla i pořadí
 * („první odpovídající vyhrává“) jako engine.
 */
final class VatAnalyticsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function accountRules(): array
    {
        $rules = JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/economy/accounting/config/accountingRules.cz.jsonc',
        );
        return $rules['accounts'];
    }

    public function testSubjectFiveOfDomesticReverseChargeGoesToItsOwnAnalytic(): void
    {
        $rules = self::accountRules();

        $this->assertSame(['343117'], VatAnalytics::masks($rules, 'cz-115', '5'));
        $this->assertSame(['343115'], VatAnalytics::masks($rules, 'cz-115', '4'));
        $this->assertSame(['343115'], VatAnalytics::masks($rules, 'cz-115', '14'));
        $this->assertSame(['343115'], VatAnalytics::masks($rules, 'cz-115', '4a'));
        $this->assertSame(['343115'], VatAnalytics::masks($rules, 'cz-115', null));
        $this->assertSame(['343115'], VatAnalytics::masks($rules, 'cz-115', ''));
    }

    public function testOtherCodesKeepTheConvention(): void
    {
        $rules = self::accountRules();

        // Oddaňovací pár nese předmět skupiny, analytiku má svou.
        $this->assertSame(['343203'], VatAnalytics::masks($rules, 'cz-203', '5'));
        // Výstupní přenesení: 343152 zaniklo, předmět 5 jde na obecný účet.
        $this->assertSame(['343150'], VatAnalytics::masks($rules, 'cz-150', '5'));
        $this->assertSame(['343110'], VatAnalytics::masks($rules, 'cz-110', null));
        // Kód bez pravidla: konvence, zahraniční se zemí.
        $this->assertSame(['343999'], VatAnalytics::masks($rules, 'cz-999', null));
        $this->assertSame(['343SK110'], VatAnalytics::masks($rules, 'sk-110', null));
        $this->assertSame(['343115'], VatAnalytics::masks([], 'cz-115', '5'), 'bez předpisu jen konvence');
    }

    public function testDistinguishedSubjectsAndKeys(): void
    {
        $distinguished = VatAnalytics::distinguishedSubjects(self::accountRules());
        $this->assertSame(['cz-115' => ['5']], $distinguished);

        $this->assertSame('cz-115|5', VatAnalytics::key('cz-115', '5', $distinguished));
        $this->assertSame('cz-115', VatAnalytics::key('cz-115', '4', $distinguished));
        $this->assertSame('cz-115', VatAnalytics::key('cz-115', null, $distinguished));
        $this->assertSame('cz-150', VatAnalytics::key('cz-150', '5', $distinguished));

        $this->assertSame(['cz-115', '5'], VatAnalytics::splitKey('cz-115|5'));
        $this->assertSame(['cz-110', null], VatAnalytics::splitKey('cz-110'));
    }

    public function testRuleQueryComparesLooselyAndKnowsOperators(): void
    {
        $this->assertTrue(RuleQuery::matches(null, ['a' => 1]));
        $this->assertTrue(RuleQuery::matches(['a' => '1'], ['a' => 1]));
        $this->assertTrue(RuleQuery::matches(['reverse_charge_code' => '5'], ['reverse_charge_code' => '5']));
        $this->assertFalse(RuleQuery::matches(['reverse_charge_code' => '5'], ['reverse_charge_code' => null]));
        $this->assertFalse(RuleQuery::matches(['reverse_charge_code' => '5'], []));
        $this->assertFalse(RuleQuery::matches(['reverse_charge_code' => '4'], ['reverse_charge_code' => '4a']));
        $this->assertTrue(RuleQuery::matches(['a' => ['$ne' => 0]], ['a' => 5]));
        $this->assertTrue(RuleQuery::matches(['a' => ['$in' => [1, 2]]], ['a' => '2']));
        $this->assertFalse(RuleQuery::matches(['a' => ['$in' => [1, 2]]], ['a' => 3]));

        $this->expectException(\LogicException::class);
        RuleQuery::matches(['a' => ['$gt' => 1]], ['a' => 5]);
    }
}
