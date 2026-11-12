<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Vat\VatJournalCrossCheck;

/**
 * Křížová kontrola přiznání proti deníku: analytika řádku rekapitulace se
 * řídí týmiž pravidly předpisu jako účtování dokladu — odpočet u předmětu
 * plnění 5 je na 343117, ostatní předměty na 343115 (#89 D9).
 */
final class VatJournalCrossCheckTest extends TestCase
{
    private const VAT_CODES = [
        'cz-110' => ['direction' => 'input'],
        'cz-115' => ['direction' => 'input'],
        'cz-203' => ['direction' => 'output'],
    ];

    /** @param array<string, array{dr: float, cr: float}> $journal číslo účtu → obraty */
    private function db(array $journal): DataSourceConnection
    {
        $rows = [];
        foreach ($journal as $account => $turnover) {
            $rows[] = ['account_number' => $account, 'is_error' => 0, 'cnt' => 1] + $turnover;
        }
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn($rows);
        return $db;
    }

    /** @return list<array<string, mixed>> */
    private static function accountRules(): array
    {
        return JsoncParser::parseFile(
            dirname(__DIR__, 5) . '/modules/economy/accounting/config/accountingRules.cz.jsonc',
        )['accounts'];
    }

    /** @return list<array<string, mixed>> doklad s předměty 4 a 5 na jednom kódu */
    private static function docs(): array
    {
        return [[
            'id' => 7,
            'recap' => [
                ['vat_code' => 'cz-115', 'tax_dom' => 420.0, 'reverse_charge_code' => '4'],
                ['vat_code' => 'cz-203', 'tax_dom' => 420.0, 'reverse_charge_code' => '4'],
                ['vat_code' => 'cz-115', 'tax_dom' => 210.0, 'reverse_charge_code' => '5'],
                ['vat_code' => 'cz-203', 'tax_dom' => 210.0, 'reverse_charge_code' => '5'],
                ['vat_code' => 'cz-110', 'tax_dom' => 21.0, 'reverse_charge_code' => null],
            ],
        ]];
    }

    public function testSubjectFiveIsExpectedOnItsOwnAnalytic(): void
    {
        $db = $this->db([
            '343115' => ['dr' => 420.0, 'cr' => 0.0],
            '343117' => ['dr' => 210.0, 'cr' => 0.0],
            '343203' => ['dr' => 0.0, 'cr' => 630.0],
            '343110' => ['dr' => 21.0, 'cr' => 0.0],
        ]);

        $check = (new VatJournalCrossCheck($db, self::VAT_CODES, self::accountRules()))->check(self::docs());

        $this->assertSame([], $check['differences']);
        $this->assertSame(0, $check['journalErrorRows']);
    }

    public function testJournalOnWrongAnalyticIsReported(): void
    {
        // Celý odpočet na 343115 — předmět 5 na 343117 chybí.
        $db = $this->db([
            '343115' => ['dr' => 630.0, 'cr' => 0.0],
            '343203' => ['dr' => 0.0, 'cr' => 630.0],
            '343110' => ['dr' => 21.0, 'cr' => 0.0],
        ]);

        $check = (new VatJournalCrossCheck($db, self::VAT_CODES, self::accountRules()))->check(self::docs());

        $byAccount = array_column($check['differences'], null, 'account');
        $this->assertSame(['343115', '343117'], array_map('strval', array_keys($byAccount)));
        $this->assertSame(210.0, $byAccount['343115']['delta']);
        $this->assertSame(-210.0, $byAccount['343117']['delta']);
    }

    public function testWithoutRulesOnlyConventionApplies(): void
    {
        $db = $this->db([
            '343115' => ['dr' => 630.0, 'cr' => 0.0],
            '343203' => ['dr' => 0.0, 'cr' => 630.0],
            '343110' => ['dr' => 21.0, 'cr' => 0.0],
        ]);

        $this->assertSame([], (new VatJournalCrossCheck($db, self::VAT_CODES))->check(self::docs())['differences']);
    }
}
