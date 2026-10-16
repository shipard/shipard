<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalRunComparison;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/**
 * Porovnání dvou běhů (D38): rozdíly, šum z rozpětí opakování, součty
 * a odchylky per uzel na jedno opakování (D41), pole zlepšená / zhoršená
 * s prahem, odmítnutí jiné sady.
 */
final class EvalRunComparisonTest extends TestCase
{
    private static function fixture(string $name): array
    {
        $run = EvalRunStore::read(dirname(__DIR__, 6) . "/tests/Fixtures/Eval/{$name}.json");
        $run['_file'] = "{$name}.json";
        return $run;
    }

    /** Skutečný soubor výsledků z repa (výsledky běhů se nepřepisují, takže je to stálá fixtura). */
    private static function repoRun(string $name): array
    {
        $run = EvalRunStore::read(dirname(__DIR__, 6) . "/tests/eval/cz-vat-payer/{$name}.json");
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
        $this->assertFalse($hit['perRepetition'], 'podíl je průměr už v souboru');

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

        // A má 2 opakování (4 volání), B jedno (2 volání): součty na jedno opakování
        $calls = $rows['calls.total'];
        $this->assertSame([2, 2, 0.0, null, true], [$calls['a'], $calls['b'], $calls['delta'], $calls['better'], $calls['perRepetition']]);
        $this->assertSame([0.04, 0.05, 0.01], [$rows['cost.usd']['a'], $rows['cost.usd']['b'], $rows['cost.usd']['delta']]);
        $this->assertFalse($rows['cost.usd']['better'], 'průchod sadou je dražší, stejně jako cena na doklad');
        $this->assertSame([20000, 20000, 0.0], [$rows['tokens.input']['a'], $rows['tokens.input']['b'], $rows['tokens.input']['delta']]);
        $this->assertSame([20000, 15000], [$rows['time.ms']['a'], $rows['time.ms']['b']]);
        $this->assertTrue($rows['time.ms']['better']);

        $this->assertSame('perRepetition', $c['fields']['basis']);
        $this->assertSame(0.5, $c['fields']['threshold']);
        $this->assertSame(
            [
                ['node' => 'supplier.contact.web', 'a' => 1, 'b' => 0, 'delta' => -1.0],
                ['node' => 'docNumber', 'a' => 0.5, 'b' => 0, 'delta' => -0.5],
                ['node' => 'rows[].item.name', 'a' => 1.5, 'b' => 1, 'delta' => -0.5],
            ],
            $c['fields']['improved'],
            'počty A děleno dvěma; při shodném rozdílu řadí uzel',
        );
        $this->assertSame([['node' => 'totals.totalAmount', 'a' => 0, 'b' => 2, 'delta' => 2.0]], $c['fields']['worsened']);

        $lines = implode("\n", EvalRunComparison::of(self::fixture('run-a'), self::fixture('run-b'))->lines());
        $this->assertStringContainsString('A: run-a.json — varianta baseline, model claude-sonnet-4-6 (server), 2 zpráv × 2', $lines);
        $this->assertStringContainsString('přepočtené na jedno opakování', $lines);
        $this->assertStringContainsString('fields.all.hit', $lines);
        $this->assertStringContainsString('v rámci šumu', $lines);
        $this->assertStringContainsString('lepší', $lines);
        $this->assertStringContainsString('horší', $lines);
        $this->assertStringContainsString('rows[].item.name 1.5→1', $lines);
        $this->assertStringContainsString('docNumber 0.5→0', $lines);
        $this->assertStringContainsString('totals.totalAmount 0→2', $lines);
    }

    /** Běh × 1 proti běhu × 3 nad touž sadou (tasks/testing-phase4.md § 9): uzel beze změny nesmí vyjít jako zhoršený. */
    public function testRunsWithDifferentRepeatAreComparedPerRepetition(): void
    {
        $c = EvalRunComparison::of(self::repoRun('2026-10-15-baseline'), self::repoRun('2026-10-15-sonnet-5-5'))->toArray();
        $this->assertSame([1, 3], [$c['runs']['a']['repeat'], $c['runs']['b']['repeat']]);

        $improved = array_column($c['fields']['improved'], null, 'node');
        $worsened = array_column($c['fields']['worsened'], null, 'node');
        foreach (['supplier.contact.web' => '16 → 48 volání', 'supplier.courtRegistration' => '12 → 35 volání', 'notes.onDocument' => '8 → 25 volání'] as $node => $why) {
            $this->assertArrayNotHasKey($node, $improved, "{$node} ({$why}) je na opakování beze změny");
            $this->assertArrayNotHasKey($node, $worsened, "{$node} ({$why}) je na opakování beze změny");
        }
        $this->assertSame(['node' => 'vat.reverseCharge', 'a' => 0, 'b' => 1.6667, 'delta' => 1.6667], $worsened['vat.reverseCharge']);
        $this->assertSame(['node' => 'supplier.address.street', 'a' => 0, 'b' => 1.3333, 'delta' => 1.3333], $worsened['supplier.address.street']);
        $this->assertSame(['node' => 'dates.taxPointDate', 'a' => 4, 'b' => 1, 'delta' => -3.0], $improved['dates.taxPointDate']);
        $this->assertSame(['node' => 'customer.taxId', 'a' => 6, 'b' => 3, 'delta' => -3.0], $improved['customer.taxId']);
        $this->assertSame(['node' => 'supplier.bankAccount.iban', 'a' => 3, 'b' => 0.6667, 'delta' => -2.3333], $improved['supplier.bankAccount.iban']);

        $rows = array_column($c['metrics'], null, 'key');
        $this->assertSame([20, 20, 0.0, null], [$rows['calls.total']['a'], $rows['calls.total']['b'], $rows['calls.total']['delta'], $rows['calls.total']['better']]);
        $this->assertSame(19.666667, $rows['calls.ok']['b']);
        $this->assertTrue($rows['calls.ok']['withinNoise'], 'jedno selhané volání ze tří opakování je v rozpětí 19–20');
        $this->assertTrue($rows['cost.usd']['perRepetition']);
        $this->assertTrue($rows['cost.usd']['better'], 'jeden průchod sadou je levnější (0,94 proti 1,24 USD)');
        $this->assertFalse($rows['tokens.input']['better'], 'tokenů na průchod víc');
        $this->assertFalse($rows['tokens.inputPerDocument']['perRepetition']);
        $this->assertFalse($rows['cost.perDocument']['perRepetition']);

        $lines = implode("\n", EvalRunComparison::of(self::repoRun('2026-10-15-baseline'), self::repoRun('2026-10-15-sonnet-5-5'))->lines());
        $this->assertStringNotContainsString('supplier.contact.web', $lines);
        $this->assertStringContainsString('vat.reverseCharge 0→1.7', $lines);
        $this->assertStringContainsString('dates.taxPointDate 4→1', $lines);
        $this->assertMatchesRegularExpression('/calls\.total\s+20\s+20\s+0\n/', $lines, 'celý nulový rozdíl bez desetinných míst');
    }

    /** Doklady vedle sebe pro prohlížeč (D53): průměr odchylek na úspěšné volání a selhání per běh. */
    public function testMessagesSideBySide(): void
    {
        $c = EvalRunComparison::of(self::repoRun('2026-10-15-baseline'), self::repoRun('2026-10-15-sonnet-5-5'))->toArray();
        $this->assertCount(20, $c['messages']);
        $byId = array_column($c['messages'], null, 'messageId');
        $m = $byId['MSG-CORPUS-VP-0301'];
        $this->assertSame(['deviations', 'failed', 'calls'], array_keys($m['a']));
        $this->assertSame([1, 3], [$m['a']['calls'], $m['b']['calls']], 'A × 1, B × 3');
        $this->assertIsFloat($m['a']['deviations']);
        $this->assertSame(round($m['b']['deviations'] - $m['a']['deviations'], 4), $m['delta']);
        $this->assertSame(1, array_sum(array_column(array_column($c['messages'], 'b'), 'failed')), 'sonnet-5-5 má jedno selhané volání');
        $deltas = array_map(static fn (array $x): float => $x['delta'] === null ? -1.0 : abs($x['delta']), $c['messages']);
        $sorted = $deltas;
        rsort($sorted);
        $this->assertSame($sorted, $deltas, 'největší rozdíl první');
        $this->assertSame([], EvalRunComparison::of(self::fixture('run-a'), self::fixture('run-b'))->toArray()['messages'], 'fixtury bez calls');
    }

    public function testDifferentSetsAreRefused(): void
    {
        $this->expectException(EvalException::class);
        $this->expectExceptionMessage('nejsou nad touž sadou');
        EvalRunComparison::of(self::fixture('run-a'), self::fixture('run-other-set'));
    }
}
