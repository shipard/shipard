<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\SearchCondition;

/**
 * Helper volného hledání bez diakritiky (#18): tvar fragmentu, počet
 * a hodnoty parametrů, prázdné vstupy, aliasované sloupce.
 */
class SearchConditionTest extends TestCase
{
    public function testContainsPutsCollationOnParameter(): void
    {
        $this->assertSame(
            'p.`full_name` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci',
            SearchCondition::contains('p.`full_name`'),
        );
    }

    public function testAnyContainsJoinsColumnsWithOrAndRepeatsRawTerm(): void
    {
        [$sql, $params] = SearchCondition::anyContains(['`code`', 't.`name`'], 'cesk');

        $this->assertSame(
            '(`code` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci'
            . ' OR t.`name` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci)',
            $sql,
        );
        $this->assertSame(['cesk', 'cesk'], $params);
    }

    public function testAnyContainsSingleColumnIsStillParenthesised(): void
    {
        [$sql, $params] = SearchCondition::anyContains(['`title`'], 'x');

        $this->assertSame('(`title` LIKE %~like~ COLLATE utf8mb4_uca1400_ai_ci)', $sql);
        $this->assertSame(['x'], $params);
    }

    public function testTermIsPassedRawWithoutWildcards(): void
    {
        // Wildcards a escapování přidává Dibi (%~like~) — helper text nemění.
        [, $params] = SearchCondition::anyContains(['`a`'], '50% _x_');

        $this->assertSame(['50% _x_'], $params);
    }

    public function testEmptyColumnsReturnEmptyFragment(): void
    {
        $this->assertSame(['', []], SearchCondition::anyContains([], 'cesk'));
    }

    public function testEmptyTermReturnsEmptyFragment(): void
    {
        $this->assertSame(['', []], SearchCondition::anyContains(['`a`', '`b`'], ''));
    }

    public function testCollationConstantMatchesFragment(): void
    {
        $this->assertStringContainsString(SearchCondition::COLLATION, SearchCondition::contains('`a`'));
    }
}
