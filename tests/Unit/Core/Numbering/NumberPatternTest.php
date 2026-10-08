<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Numbering;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Numbering\NumberContext;
use Shipard\Core\Numbering\NumberPattern;

final class NumberPatternTest extends TestCase
{
    private function context(int $sequence = 42, string $code = 'A', string|\Closure $year = '2026', array $domain = []): NumberContext
    {
        return new NumberContext(sequence: $sequence, seriesCode: $code, yearLabel: $year, domain: $domain);
    }

    // ── resolve ────────────────────────────────────────────────────────────

    public function testResolvesGeneralPlaceholders(): void
    {
        $ctx = $this->context();

        $this->assertSame('A',      NumberPattern::resolve('%C', $ctx));
        $this->assertSame('26',     NumberPattern::resolve('%y', $ctx));
        $this->assertSame('2026',   NumberPattern::resolve('%Y', $ctx));
        $this->assertSame('042',    NumberPattern::resolve('%3', $ctx));
        $this->assertSame('0042',   NumberPattern::resolve('%4', $ctx));
        $this->assertSame('00042',  NumberPattern::resolve('%5', $ctx));
        $this->assertSame('000042', NumberPattern::resolve('%6', $ctx));
    }

    public function testResolvesDomainPlaceholdersAsValueOrClosure(): void
    {
        $ctx = $this->context(sequence: 1, domain: ['D' => '1', 'K' => fn(): string => 'ZAK']);

        $this->assertSame('126A0001', NumberPattern::resolve('%D%y%C%4', $ctx));
        $this->assertSame('ZAK-2026-001', NumberPattern::resolve('%K-%Y-%3', $ctx));
    }

    public function testUnknownPlaceholderStaysLiteral(): void
    {
        $ctx = $this->context(sequence: 1, domain: ['D' => '1']);

        $this->assertSame('FX1', NumberPattern::resolve('FX%D', $ctx));
        $this->assertSame('%X-0001', NumberPattern::resolve('%X-%4', $ctx));
        // Bez doménové mapy je i %D literál.
        $this->assertSame('%D0001', NumberPattern::resolve('%D%4', $this->context(sequence: 1)));
    }

    public function testYearLabelClosureIsLazyAndEvaluatedOnce(): void
    {
        $calls = 0;
        $year  = function () use (&$calls): string {
            $calls++;
            return '2025';
        };

        $this->assertSame('0007', NumberPattern::resolve('%4', $this->context(sequence: 7, year: $year)));
        $this->assertSame(0, $calls, 'Bez placeholderu roku se popisek nevyhodnocuje');

        $this->assertSame('25/2025', NumberPattern::resolve('%y/%Y', $this->context(year: $year)));
        $this->assertSame(1, $calls, 'Popisek roku se vyhodnotí nejvýš jednou');
    }

    public function testSequencePaddingNeverTruncates(): void
    {
        $this->assertSame('12345', NumberPattern::resolve('%3', $this->context(sequence: 12345)));
    }

    public function testDomainPlaceholderMustBeSingleAlphanumericCharacter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NumberContext(sequence: 1, seriesCode: '', yearLabel: '2026', domain: ['DD' => 'x']);
    }

    // ── validate ───────────────────────────────────────────────────────────

    public function testValidPatternHasNoErrors(): void
    {
        $this->assertSame([], NumberPattern::validate('%D-%C-%y-%Y-%3-%4-%5-%6', 'A', ['D']));
        $this->assertSame([], NumberPattern::validate('%y%4', null));
    }

    public function testEmptyPatternIsRequired(): void
    {
        $errors = NumberPattern::validate('', 'A');

        $this->assertCount(1, $errors);
        $this->assertSame(NumberPattern::TARGET_PATTERN, $errors[0]['target']);
        $this->assertSame('required', $errors[0]['code']);
        $this->assertSame('Vzorec čísla dokladu je povinný', $errors[0]['message']);
    }

    public function testSeriesCodePlaceholderRequiresCode(): void
    {
        $errors = NumberPattern::validate('%C%4', '');

        $this->assertCount(1, $errors);
        $this->assertSame(NumberPattern::TARGET_CODE, $errors[0]['target']);
        $this->assertSame('required_for_pattern', $errors[0]['code']);
        $this->assertSame('Vzorec obsahuje %C — kód řady je povinný', $errors[0]['message']);

        $this->assertSame([], NumberPattern::validate('%y%4', ''));
    }

    public function testUnknownPlaceholderReportsOnlyFirst(): void
    {
        $errors = NumberPattern::validate('%X%Z%4', 'A');

        $this->assertCount(1, $errors);
        $this->assertSame(NumberPattern::TARGET_PATTERN, $errors[0]['target']);
        $this->assertSame('unknown_placeholder', $errors[0]['code']);
        $this->assertSame('Neznámý placeholder %X', $errors[0]['message']);
    }

    public function testDomainPlaceholderIsKnownOnlyWhenListed(): void
    {
        $this->assertSame([], NumberPattern::validate('%D%4', 'A', ['D']));

        $errors = NumberPattern::validate('%D%4', 'A');
        $this->assertSame('unknown_placeholder', $errors[0]['code'] ?? null);
    }
}
