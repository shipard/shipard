<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/** Soubory výsledků (D36): pojmenování s pořadovým číslem, čtení, výběr běhů varianty, hash sady. */
final class EvalRunStoreTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/shpd_evalstore_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        DatasetReader::removeTree($this->tmp);
    }

    /** @return array<string, mixed> */
    private static function runFile(string $variant, string $hash, string $started, float $cost): array
    {
        return [
            'format' => EvalRunStore::FORMAT,
            'set' => ['name' => 'cz-vat-payer', 'hash' => $hash],
            'variant' => ['name' => $variant],
            'startedAt' => $started,
            'metrics' => ['cost.usd' => $cost, 'calls.total' => 2],
        ];
    }

    public function testWriteNumbersRepeatedRunsOfTheDayAndReadsThemBack(): void
    {
        $store = new EvalRunStore($this->tmp . '/cz-vat-payer');
        $this->assertSame(EvalRunStore::defaultDir('/repo', 'cz-vat-payer'), '/repo/tests/eval/cz-vat-payer');

        $a = $store->write(self::runFile('baseline', 'h1', '2026-10-15T10:00:00+00:00', 0.1), '2026-10-15', 'baseline');
        $b = $store->write(self::runFile('baseline', 'h1', '2026-10-15T11:00:00+00:00', 0.2), '2026-10-15', 'baseline');
        $c = $store->write(self::runFile('baseline', 'h2', '2026-10-15T12:00:00+00:00', 0.3), '2026-10-15', 'baseline');
        $d = $store->write(self::runFile('sonnet55', 'h1', '2026-10-15T13:00:00+00:00', 0.4), '2026-10-15', 'sonnet55');

        $this->assertSame($this->tmp . '/cz-vat-payer/2026-10-15-baseline.json', $a);
        $this->assertSame($this->tmp . '/cz-vat-payer/2026-10-15-baseline-2.json', $b);
        $this->assertSame($this->tmp . '/cz-vat-payer/2026-10-15-baseline-3.json', $c);
        $this->assertSame($this->tmp . '/cz-vat-payer/2026-10-15-sonnet55.json', $d);
        $this->assertStringEndsWith("}\n", (string) file_get_contents($a));
        $this->assertSame(0.1, EvalRunStore::read($a)['metrics']['cost.usd']);

        $runs = $store->runsFor('baseline', 'h1');
        $this->assertSame([0.2, 0.1], array_map(static fn (array $r): float => $r['metrics']['cost.usd'], $runs), 'nejnovější první, jen tatáž sada');
        $this->assertSame($b, $runs[0]['_file']);
        $this->assertCount(3, $store->runsFor('baseline'));
        $this->assertSame([], $store->runsFor('nope'));

        file_put_contents($this->tmp . '/cz-vat-payer/junk.json', '{"format": "other"}');
        $this->assertCount(3, $store->runsFor('baseline'), 'cizí soubor se přeskočí');
        $this->expectException(EvalException::class);
        EvalRunStore::read($this->tmp . '/cz-vat-payer/junk.json');
    }

    public function testDatasetHashCoversMailAttachmentsAndExpected(): void
    {
        $reader = DatasetReader::open(dirname(__DIR__, 6) . '/tests/datasets/cz/vat-payer');
        $hash = EvalRunStore::datasetHash($reader);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, EvalRunStore::datasetHash($reader), 'deterministický');

        // Kopie sady se změněnou přílohou má jiný hash; setup/ do hashe nejde.
        $copy = $this->tmp . '/copy';
        mkdir($copy . '/mail', 0750, true);
        mkdir($copy . '/expected', 0750, true);
        copy($reader->getRootDir() . '/manifest.jsonc', $copy . '/manifest.jsonc');
        foreach (['mail', 'expected'] as $dir) {
            self::copyTree($reader->getRootDir() . '/' . $dir, $copy . '/' . $dir);
        }
        $this->assertSame($hash, EvalRunStore::datasetHash(DatasetReader::open($copy)));
        mkdir($copy . '/setup');
        file_put_contents($copy . '/setup/x.jsonc', '{}');
        $this->assertSame($hash, EvalRunStore::datasetHash(DatasetReader::open($copy)));
        $pdf = glob($copy . '/mail/*.files/*.pdf')[0];
        file_put_contents($pdf, 'x', FILE_APPEND);
        $this->assertNotSame($hash, EvalRunStore::datasetHash(DatasetReader::open($copy)));
    }

    private static function copyTree(string $from, string $to): void
    {
        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $src = $from . '/' . $entry;
            $dst = $to . '/' . $entry;
            if (is_dir($src)) {
                mkdir($dst, 0750, true);
                self::copyTree($src, $dst);
            } else {
                copy($src, $dst);
            }
        }
    }
}
