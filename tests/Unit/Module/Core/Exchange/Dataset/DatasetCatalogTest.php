<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Dataset;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;

/**
 * Katalog sad k seedu (#131): testovací sady `tests/datasets/<země>/<sada>`
 * a demo sady `demo/datasets/<sada>` nad dočasným „checkoutem“ — kořenové
 * soubory a složky bez manifestu se ignorují, název je jednoznačný napříč
 * druhy (testovací má přednost), výběr jen z výpisu.
 */
final class DatasetCatalogTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shpd-dscat-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->root);
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @param array<string, mixed> $extra */
    private function manifest(string $relDir, string $name, array $extra = []): string
    {
        $dir = $this->root . '/' . $relDir;
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/manifest.jsonc', json_encode(array_merge([
            'format' => 'shpd.dataset.v1',
            'name' => $name,
            'title' => 'Sada ' . $name,
            'dateMode' => 'fixed',
            'created' => '2026-10-20T00:00:00Z',
        ], $extra), JSON_UNESCAPED_UNICODE));
        return $dir;
    }

    private function file(string $relPath, string $content = ''): void
    {
        $path = $this->root . '/' . $relPath;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, $content);
    }

    public function testListsTestDatasetsByNameThenDemoDatasetsWithKindAndDir(): void
    {
        $vatPayer = $this->manifest('tests/datasets/cz/vat-payer', 'cz-vat-payer', ['country' => 'cz', 'profile' => 'vat-payer']);
        $association = $this->manifest('tests/datasets/cz/association', 'cz-association', ['country' => 'cz', 'profile' => 'association']);
        $demo = $this->manifest('demo/datasets/it-servis', 'it-servis', ['country' => 'cz', 'description' => 'Fiktivní IT firma']);
        // Kořen demo/datasets: generátor, README, příběhy a šablony — nic z toho není sada.
        $this->file('demo/datasets/README.md', '# Demo');
        $this->file('demo/datasets/build.py', '');
        $this->file('demo/datasets/stories/it-servis/story.jsonc', '{}');
        $this->file('demo/datasets/templates/contract.html', '');
        $this->file('tests/datasets/README.md', '# Testovací sady');

        $catalog = new DatasetCatalog($this->root);
        $this->assertTrue($catalog->isAvailable());
        $this->assertSame(['cz-association', 'cz-vat-payer', 'it-servis'], $catalog->datasetNames(), 'testovací podle názvu, pak demo');

        $entries = $catalog->datasets();
        $this->assertSame([DatasetCatalog::KIND_TEST, DatasetCatalog::KIND_TEST, DatasetCatalog::KIND_DEMO], array_map(static fn ($d) => $d->kind, $entries));
        $this->assertSame([$association, $vatPayer, $demo], array_map(static fn ($d) => $d->dir, $entries), 'adresář sady z checkoutu — ten jediný jde do dataset-seed');

        $entry = $catalog->dataset('it-servis');
        $this->assertNotNull($entry);
        $this->assertSame([
            'name' => 'it-servis',
            'title' => 'Sada it-servis',
            'kind' => 'demo',
            'country' => 'cz',
            'profile' => null,
            'description' => 'Fiktivní IT firma',
        ], $entry->toArray());
        $this->assertSame('vat-payer', $catalog->dataset('cz-vat-payer')?->profile);
    }

    public function testDemoDatasetWithTheNameOfATestDatasetIsSkipped(): void
    {
        $test = $this->manifest('tests/datasets/cz/vat-payer', 'cz-vat-payer', ['country' => 'cz']);
        $this->manifest('demo/datasets/vat-payer', 'cz-vat-payer', ['country' => 'cz']);
        $this->manifest('demo/datasets/it-servis', 'it-servis', ['country' => 'cz']);

        $catalog = new DatasetCatalog($this->root);

        $this->assertSame(['cz-vat-payer', 'it-servis'], $catalog->datasetNames(), 'nikdy dvě sady téhož jména');
        $this->assertSame($test, $catalog->dataset('cz-vat-payer')?->dir, 'testovací sada má přednost');
        $this->assertSame(DatasetCatalog::KIND_TEST, $catalog->dataset('cz-vat-payer')?->kind);
    }

    public function testUnreadableManifestsAreSkipped(): void
    {
        $this->manifest('demo/datasets/it-servis', 'it-servis', ['country' => 'cz']);
        $this->file('demo/datasets/broken/manifest.jsonc', '{not json');
        $this->manifest('demo/datasets/rolling', 'rolling', ['dateMode' => 'rolling']); // fáze 1 umí jen fixed
        $this->file('demo/datasets/empty/.keep');

        $this->assertSame(['it-servis'], (new DatasetCatalog($this->root))->datasetNames());
    }

    /** @return list<array{string}> */
    public static function unknownNames(): array
    {
        return [
            ['../etc'],
            ['it-servis/../it-servis'],
            ['IT-SERVIS'],
            ['demo/it-servis'],
            ['it-servis '],
            ["it-servis\0"],
            [''],
        ];
    }

    #[DataProvider('unknownNames')]
    public function testUnknownOrTraversalNamesResolveToNothing(string $name): void
    {
        $this->manifest('demo/datasets/it-servis', 'it-servis', ['country' => 'cz']);
        $this->manifest('tests/datasets/cz/vat-payer', 'cz-vat-payer', ['country' => 'cz']);

        $this->assertNull((new DatasetCatalog($this->root))->dataset($name));
    }

    public function testAvailabilityFollowsEitherDirectory(): void
    {
        $none = new DatasetCatalog($this->root);
        $this->assertFalse($none->isAvailable(), 'instalace z balíčku bez obou adresářů');
        $this->assertSame([], $none->datasets());

        $this->manifest('demo/datasets/it-servis', 'it-servis', ['country' => 'cz']);
        $demoOnly = new DatasetCatalog($this->root);
        $this->assertTrue($demoOnly->isAvailable(), 'demo sady stačí — bez tests/datasets i bez tests/eval');
        $this->assertSame(['it-servis'], $demoOnly->datasetNames());

        mkdir($this->root . '/tests/datasets', 0700, true);
        $this->assertTrue((new DatasetCatalog($this->root))->isAvailable());
    }

    public function testDatasetsAreMemoizedPerInstance(): void
    {
        $this->manifest('demo/datasets/it-servis', 'it-servis', ['country' => 'cz']);
        $catalog = new DatasetCatalog($this->root);
        $this->assertSame(['it-servis'], $catalog->datasetNames());

        $this->manifest('demo/datasets/druha', 'druha', ['country' => 'cz']);
        $this->assertSame(['it-servis'], $catalog->datasetNames(), 'výpis se čte jednou za požadavek');
        $this->assertSame(['druha', 'it-servis'], (new DatasetCatalog($this->root))->datasetNames());
    }
}
