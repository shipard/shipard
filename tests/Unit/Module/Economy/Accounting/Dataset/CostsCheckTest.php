<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Accounting\Dataset;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\Accounting\Dataset\CostsCheck;

/**
 * Kontrola nákladů vyřazuje zaokrouhlení podle původu řádku (#122 D5):
 * řádek bez operace s textem kroku zaokrouhlení. Strážce nad předpisem:
 * každý krok kategorie rounding.* je hlavičkový, bez operace a nese právě
 * ROUNDING_TEXT; žádný jiný krok ten text nepoužívá — jinak by kontrola
 * vyřadila náklad, nebo zaokrouhlení nechala v nákladu.
 */
class CostsCheckTest extends TestCase
{
    private const RULES = __DIR__ . '/../../../../../../modules/economy/accounting/config/accountingRules.cz.jsonc';

    public function testRoundingStepsCarryTheRoundingTextAndNoOtherStepDoes(): void
    {
        $rules = JsoncParser::parseFile(self::RULES);
        $roundingSteps = 0;
        foreach ($rules['documents'] as $doc) {
            foreach ($doc['accounting'] as $step) {
                $cat = (string) ($step['cat'] ?? '');
                $where = "{$doc['docType']}: " . json_encode($step, JSON_UNESCAPED_UNICODE);
                if (in_array($cat, ['rounding.cost', 'rounding.revenue'], true)) {
                    $roundingSteps++;
                    $this->assertSame('head', $step['src'] ?? null, "krok zaokrouhlení je hlavičkový — {$where}");
                    $this->assertArrayNotHasKey('operation', $step, "krok zaokrouhlení bez operace — {$where}");
                    $this->assertSame(CostsCheck::ROUNDING_TEXT, $step['text'] ?? null, "text kroku zaokrouhlení — {$where}");
                } else {
                    $this->assertNotSame(CostsCheck::ROUNDING_TEXT, $step['text'] ?? null, "text zaokrouhlení mimo rounding.* — {$where}");
                }
            }
        }
        $this->assertGreaterThan(0, $roundingSteps);
    }

    public function testJournalCostExcludesRoundingByOriginNotByAccount(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);
        $db->expects($this->once())->method('fetchSingle')->willReturnCallback(
            function (...$args): string {
                $sql = (string) $args[0];
                $this->assertStringContainsString('[doc_head] = %i', $sql);
                $this->assertStringContainsString('NOT ([operation] IS NULL AND [text] = %s)', $sql);
                $this->assertStringNotContainsString('NOT LIKE', $sql, 'žádné vyřazení podle prefixu účtu');
                $this->assertSame([7, '5', CostsCheck::ROUNDING_TEXT], array_slice($args, 1));
                return '12.50';
            },
        );

        $this->assertSame(12.5, CostsCheck::journalCost($db, 7));
    }
}
