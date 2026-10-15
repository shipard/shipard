<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalRunComparison;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/** Porovnání dvou běhů (D38): rozdíly, šum z rozpětí opakování, pole zlepšená / zhoršená, odmítnutí jiné sady. */
final class EvalRunComparisonTest extends TestCase
{
    private static function fixture(string $name): array
    {
        $run = EvalRunStore::read(dirname(__DIR__, 6) . "/tests/Fixtures/Eval/{$name}.json");
        $run['_file'] = "{$name}.json";
        return $run;
    }

    public function testMetricsSideBySideWithNoiseAndDirection(): void
    {
        $c = EvalRunComparison::of(self::fixture('run-a'), self::fixture('run-b'))->toArray();
        $rows = array_column($c['metrics'], null, 'key');

        $this->assertSame(['name' => 'cz-vat-payer', 'hash' => 'aaaa1111'], $c['set']);
        $this->assertSame('baseline', $c['runs']['a']['variant']);
        $this->assertSame('claude-sonnet-5-5', $c['runs']['b']['model']);
        $this->assertTrue($c['runs']['b']['dirty']);
        $this->assertTrue($c['sameSelection']);

        $hit = $rows['fields.all.hit'];
        $this->assertSame([0.9, 0.93, 0.03], [$hit['a'], $hit['b'], $hit['delta']]);
        $this->assertSame(0.04, $hit['noise'], 'rozpětí opakování běhu A');
        $this->assertTrue($hit['withinNoise'], 'rozdíl 0,03 ≤ rozpětí 0,04');
        $this->assertTrue($hit['better']);

        $clean = $rows['documents.clean'];
        $this->assertSame(0.25, $clean['delta']);
        $this->assertSame(0.0, $clean['noise']);
        $this->assertFalse($clean['withinNoise']);
        $this->assertTrue($clean['better']);

        $amounts = $rows['documents.amountsCorrect'];
        $this->assertNull($amounts['noise'], 'bez rozpětí v obou bězích');
        $this->assertFalse($amounts['withinNoise']);
        $this->assertFalse($amounts['better']);

        $cost = $rows['cost.perDocument'];
        $this->assertSame(0.005, $cost['delta']);
        $this->assertFalse($cost['better'], 'dražší = horší');
        $this->assertTrue($rows['time.perDocumentMs']['better'], 'rychlejší = lepší');
        $this->assertNull($rows['fields.header.hit']['better'], 'beze změny');

        $this->assertSame(
            [['node' => 'rows[].item.name', 'a' => 3, 'b' => 1], ['node' => 'supplier.contact.web', 'a' => 2, 'b' => 0], ['node' => 'docNumber', 'a' => 1, 'b' => 0]],
            $c['fields']['improved'],
        );
        $this->assertSame([['node' => 'totals.totalAmount', 'a' => 0, 'b' => 2]], $c['fields']['worsened']);

        $lines = implode("\n", EvalRunComparison::of(self::fixture('run-a'), self::fixture('run-b'))->lines());
        $this->assertStringContainsString('A: run-a.json — varianta baseline, model claude-sonnet-4-6 (server), 2 zpráv × 2', $lines);
        $this->assertStringContainsString('fields.all.hit', $lines);
        $this->assertStringContainsString('v rámci šumu', $lines);
        $this->assertStringContainsString('lepší', $lines);
        $this->assertStringContainsString('horší', $lines);
        $this->assertStringContainsString('rows[].item.name 3→1', $lines);
        $this->assertStringContainsString('totals.totalAmount 0→2', $lines);
    }

    public function testDifferentSetsAreRefused(): void
    {
        $this->expectException(EvalException::class);
        $this->expectExceptionMessage('nejsou nad touž sadou');
        EvalRunComparison::of(self::fixture('run-a'), self::fixture('run-other-set'));
    }
}
