<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval\Browse;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;

/**
 * Katalog prohlížeče výsledků nad checkoutem repa (#103 D52): sady
 * z manifestů, běhy z `tests/eval/<sada>/`, výběr jen z výpisu — parametr
 * URL nikdy nevede mimo `tests/datasets` a `tests/eval`. Počty, pořadí
 * a výběr běhů se tvrdí nad vlastním kořenem {@see EvalFixtureRoot} (D106),
 * skutečný checkout slouží jen seznamu sad.
 */
final class EvalCatalogTest extends TestCase
{
    private EvalFixtureRoot $fixture;

    protected function setUp(): void
    {
        $this->fixture = EvalFixtureRoot::create();
    }

    protected function tearDown(): void
    {
        $this->fixture->remove();
    }

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
        $hash = $this->fixture->seedPayerAndAssociation();
        $catalog = new EvalCatalog($this->fixture->root);
        $dataset = $catalog->dataset(EvalFixtureRoot::PAYER);
        $this->assertNotNull($dataset);
        $runs = $catalog->runs($dataset);

        $this->assertSame(
            ['2026-10-16-baseline.json', '2026-10-16-alpha.json', '2026-10-15-baseline.json'],
            array_column($runs, '_basename'),
            'nejnovější první podle startedAt, ne podle názvu souboru — alpha je abecedně dřív, ale začala dřív',
        );
        foreach ($runs as $run) {
            $this->assertSame(basename((string) $run['_file']), $run['_basename']);
            $this->assertStringStartsWith(EvalRunStore::defaultDir($this->fixture->root, EvalFixtureRoot::PAYER) . '/', (string) $run['_file']);
            $this->assertSame(EvalRunStore::FORMAT, $run['format']);
        }

        $run = $catalog->run($dataset, '2026-10-16-alpha.json');
        $this->assertNotNull($run);
        $this->assertSame('alpha', $run['variant']['name']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        $this->assertSame($hash, $catalog->currentHash($dataset), 'hash sady jde spočítat i nad sadou jen s manifestem');
        $this->assertSame($hash, $run['set']['hash'], 'běh nad aktuální sadou');
        $this->assertNotSame($hash, $catalog->run($dataset, '2026-10-15-baseline.json')['set']['hash'], 'starší běh nad jinou verzí sady');

        $association = $catalog->dataset(EvalFixtureRoot::ASSOCIATION);
        $this->assertNotNull($association);
        $this->assertSame([], $catalog->runs($association), 'sada bez složky výsledků');
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
        $this->fixture->seedPayerAndAssociation();
        $catalog = new EvalCatalog($this->fixture->root);
        $this->assertNull($catalog->dataset($name), "sada '{$name}'");
        $this->assertNull($catalog->run($catalog->dataset(EvalFixtureRoot::PAYER), $name), "běh '{$name}'");
    }

    public function testIgnoresDemoDatasetsOfTheSeedCatalog(): void
    {
        // Sekce Testování zůstává jen nad tests/datasets (#131): demo sada
        // z demo/datasets nemá expected/ ani běhy — nabízí ji jen DatasetCatalog.
        $this->fixture->manifest('tests/datasets/cz/payer', EvalFixtureRoot::PAYER, 'Plátce');
        $this->fixture->manifest('demo/datasets/it-servis', 'it-servis', 'Demo — IT servis');

        $catalog = new EvalCatalog($this->fixture->root);
        $this->assertTrue($catalog->isAvailable());
        $this->assertSame([EvalFixtureRoot::PAYER], $catalog->datasetNames());
        $this->assertNull($catalog->dataset('it-servis'));
        $this->assertSame([EvalFixtureRoot::PAYER, 'it-servis'], (new DatasetCatalog($this->fixture->root))->datasetNames(), 'katalog k seedu nabízí obě');
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
