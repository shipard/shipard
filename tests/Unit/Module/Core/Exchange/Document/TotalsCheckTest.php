<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\TotalsCheck;

/**
 * Kontrolní součet `totals` (#166 D4, D27) a varování o nepoužitém módu
 * zaokrouhlení (D26) — čisté porovnání payloadu s hlavičkou dokladu.
 */
final class TotalsCheckTest extends TestCase
{
    private const HEAD = [
        'total_base' => 100.0, 'total_vat' => 21.0, 'total_amount' => 121.0, 'total_rounding' => 0.0,
    ];

    public function testMatchingTotalsGiveNoIssue(): void
    {
        $canonical = ['totals' => ['totalBase' => 100.0, 'totalVat' => 21.0, 'totalAmount' => 121.0, 'totalRounding' => 0.0]];

        $this->assertSame([], TotalsCheck::mismatches($canonical, self::HEAD));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function fields(): array
    {
        return [
            'základ'       => ['totalBase', 'total_base'],
            'daň'          => ['totalVat', 'total_vat'],
            'částka'       => ['totalAmount', 'total_amount'],
            'zaokrouhlení' => ['totalRounding', 'total_rounding'],
        ];
    }

    #[DataProvider('fields')]
    public function testEachDeclaredFieldIsCheckedOnItsOwn(string $field, string $column): void
    {
        $declared = self::HEAD[$column] + 1.0;

        $issues = TotalsCheck::mismatches(['totals' => [$field => $declared]], self::HEAD);

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['severity']);
        $this->assertSame('totals_check_failed', $issues[0]['code']);
        $this->assertSame("totals.{$field}", $issues[0]['path']);
        $this->assertSame($declared, $issues[0]['declared']);
        $this->assertSame(self::HEAD[$column], $issues[0]['computed']);
        $this->assertStringContainsString($field, $issues[0]['message']);
    }

    public function testEveryMismatchingFieldIsReported(): void
    {
        $canonical = ['totals' => ['totalBase' => 90.0, 'totalVat' => 21.0, 'totalAmount' => 111.0]];

        $this->assertSame(
            ['totals.totalBase', 'totals.totalAmount'],
            array_column(TotalsCheck::mismatches($canonical, self::HEAD), 'path'),
        );
    }

    public function testDifferenceOfOneCentPassesAndTwoCentsFail(): void
    {
        $this->assertSame([], TotalsCheck::mismatches(['totals' => ['totalAmount' => 121.01]], self::HEAD));
        $this->assertSame([], TotalsCheck::mismatches(['totals' => ['totalAmount' => 120.99]], self::HEAD));
        $this->assertCount(1, TotalsCheck::mismatches(['totals' => ['totalAmount' => 121.02]], self::HEAD));
        $this->assertCount(1, TotalsCheck::mismatches(['totals' => ['totalAmount' => 120.98]], self::HEAD));
    }

    public function testFloatNoiseIsNotADifference(): void
    {
        // 0.1 + 0.2 = 0.30000000000000004; 1.15 * 100 = 114.99999999999999
        $head = ['total_amount' => 0.1 + 0.2, 'total_vat' => 1.15, 'total_base' => 4611.92];
        $canonical = ['totals' => ['totalAmount' => 0.31, 'totalVat' => 1.16, 'totalBase' => 4611.93]];

        $this->assertSame([], TotalsCheck::mismatches($canonical, $head));
    }

    public function testNegativeAmountsAreComparedBySignedValue(): void
    {
        $head = ['total_amount' => -726.0, 'total_rounding' => -0.26];

        $this->assertSame([], TotalsCheck::mismatches(['totals' => ['totalAmount' => -726.0, 'totalRounding' => -0.26]], $head));
        $issues = TotalsCheck::mismatches(['totals' => ['totalAmount' => 726.0, 'totalRounding' => 0.26]], $head);
        $this->assertSame([726.0, 0.26], array_column($issues, 'declared'));
        $this->assertSame([-726.0, -0.26], array_column($issues, 'computed'));
    }

    public function testMissingAndNullFieldsAreNotChecked(): void
    {
        $this->assertSame([], TotalsCheck::mismatches([], self::HEAD));
        $this->assertSame([], TotalsCheck::mismatches(['totals' => null], self::HEAD));
        $this->assertSame([], TotalsCheck::mismatches(['totals' => ['totalAmount' => null, 'totalRoundingAuto' => true]], self::HEAD));
    }

    public function testColumnMissingFromHeadIsNotChecked(): void
    {
        $canonical = ['totals' => ['totalBase' => 5.0, 'totalAmount' => 121.0]];

        $this->assertSame([], TotalsCheck::mismatches($canonical, ['id' => 1234, 'total_amount' => 121.0]));
    }

    public function testNumericStringsFromDatabaseAreCompared(): void
    {
        $head = ['total_amount' => '121.00', 'total_vat' => '21.00'];

        $this->assertSame([], TotalsCheck::mismatches(['totals' => ['totalAmount' => 121.0, 'totalVat' => 21.0]], $head));
        $this->assertCount(1, TotalsCheck::mismatches(['totals' => ['totalAmount' => 122.0]], $head));
    }

    public function testIgnoredModeIsReportedOnlyWithAutomaticOnAndDifferentMode(): void
    {
        $canonical = ['totals' => ['totalRoundingMode' => 'unitUp']];

        $issue = TotalsCheck::ignoredMode($canonical, ['total_rounding_auto' => 1, 'total_rounding_mode' => 0]);
        $this->assertNotNull($issue);
        $this->assertSame(['warning', 'totals.totalRoundingMode', 'total_rounding_mode_ignored'], [$issue['severity'], $issue['path'], $issue['code']]);
        $this->assertStringContainsString('cent', $issue['message']);
        $this->assertStringContainsString('unitUp', $issue['message']);

        $this->assertNull(TotalsCheck::ignoredMode($canonical, ['total_rounding_auto' => 1, 'total_rounding_mode' => 3]), 'shodný mód');
        $this->assertNull(TotalsCheck::ignoredMode($canonical, ['total_rounding_auto' => 0, 'total_rounding_mode' => 3]), 'automatika vypnutá');
        $this->assertNull(TotalsCheck::ignoredMode($canonical, ['total_rounding_auto' => 1]), 'mód nedopočítán (bez konfigurace)');
        $this->assertNull(TotalsCheck::ignoredMode(['totals' => ['totalRoundingMode' => null]], ['total_rounding_auto' => 1, 'total_rounding_mode' => 0]));
        $this->assertNull(TotalsCheck::ignoredMode([], ['total_rounding_auto' => 1, 'total_rounding_mode' => 0]));
    }
}
