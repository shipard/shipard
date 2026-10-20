<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval\Browse;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/**
 * Katalog prohlížeče výsledků nad checkoutem repa (#103 D52): sady
 * z manifestů, běhy z `tests/eval/<sada>/`, výběr jen z výpisu — parametr
 * URL nikdy nevede mimo `tests/datasets` a `tests/eval`.
 */
final class EvalCatalogTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 7);
    }

    public function testListsDatasetsByManifestName(): void
    {
        $catalog = new EvalCatalog(self::repoRoot());
        $this->assertTrue($catalog->isAvailable());

        $names = array_map(static fn ($d) => $d->name, $catalog->datasets());
        $this->assertContains('cz-vat-payer', $names);
        $this->assertContains('cz-non-payer', $names);
        $this->assertContains('cz-association', $names);
        $this->assertSame($names, (static function (array $n): array { sort($n, SORT_STRING); return $n; })($names), 'řazeno podle názvu');

        $dataset = $catalog->dataset('cz-vat-payer');
        $this->assertNotNull($dataset);
        $this->assertSame('cz', $dataset->country);
        $this->assertSame('vat-payer', $dataset->profile);
        $this->assertSame(20, $dataset->messages);
        $this->assertSame(self::repoRoot() . '/tests/datasets/cz/vat-payer', $dataset->dir);
        $this->assertSame(['name', 'title', 'country', 'profile', 'messages'], array_keys($dataset->toArray()));
    }

    public function testRunsAreListedNewestFirstWithBasename(): void
    {
        $catalog = new EvalCatalog(self::repoRoot());
        $dataset = $catalog->dataset('cz-vat-payer');
        $runs = $catalog->runs($dataset);

        $this->assertGreaterThanOrEqual(13, count($runs), 'běhy z tests/eval/cz-vat-payer/ jsou stálá fixtura');
        $started = array_map(static fn (array $r): string => (string) $r['startedAt'], $runs);
        $sorted = $started;
        rsort($sorted, SORT_STRING);
        $this->assertSame($sorted, $started, 'nejnovější první');
        $this->assertStringStartsWith('2026-10-16-', $runs[0]['_basename']);
        foreach ($runs as $run) {
            $this->assertSame(basename((string) $run['_file']), $run['_basename']);
            $this->assertSame(EvalRunStore::FORMAT, $run['format']);
        }

        $run = $catalog->run($dataset, '2026-10-16-baseline.json');
        $this->assertNotNull($run);
        $this->assertSame('baseline', $run['variant']['name']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['set']['hash']);
        $this->assertNotSame($catalog->currentHash($dataset), $run['set']['hash'], 'běhy z 16. 10. jsou nad sadou před #119 krokem 1 (podoba druhých dokladů variant 5, 6, 8 a 9 je `pdf`, hash sady se změnil); nový běh nad aktuální sadou je věc člověka');
    }

    /** @return list<array{string}> */
    public static function traversalNames(): array
    {
        return [
            ['../../composer.json'],
            ['..'],
            ['/etc/passwd'],
            ['2026-10-16-baseline.json/../../../composer.json'],
            ['2026-10-16-baseline'],
            ['2026-10-16-BASELINE.json'],
            ["2026-10-16-baseline.json\0"],
            [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('traversalNames')]
    public function testUnknownOrTraversalNamesResolveToNothing(string $name): void
    {
        $catalog = new EvalCatalog(self::repoRoot());
        $this->assertNull($catalog->dataset($name), "sada '{$name}'");
        $this->assertNull($catalog->run($catalog->dataset('cz-vat-payer'), $name), "běh '{$name}'");
    }

    public function testUnavailableWithoutTestsDirectories(): void
    {
        $root = sys_get_temp_dir() . '/shpd-evalcat-' . bin2hex(random_bytes(4));
        mkdir($root . '/tests/datasets', 0700, true);
        try {
            $catalog = new EvalCatalog($root);
            $this->assertFalse($catalog->isAvailable(), 'chybí tests/eval');
            $this->assertSame([], $catalog->datasets());
        } finally {
            rmdir($root . '/tests/datasets');
            rmdir($root . '/tests');
            rmdir($root);
        }
    }
}
