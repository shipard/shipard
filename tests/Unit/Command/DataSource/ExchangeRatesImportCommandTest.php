<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\ExchangeRatesImportCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImporter;
use Shipard\Module\Economy\ExchangeRates\ExchangeRateListImportResult;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `shpd-ds exchange-rates-import`: soubor / složka setříděná podle jména,
 * zdroj „soubor“, --replace, výsledky per soubor, souhrn a exit.
 */
class ExchangeRatesImportCommandTest extends TestCase
{
    private string $dir;

    /** @var list<array{0: array, 1: int, 2: bool}> */
    private array $imports = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/shpd-xr-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * @param array<string, string> $outcomes listType → outcome
     * @param array<string, list<string>> $skipped listType → přeskočené měny
     */
    private function tester(bool $moduleActive = true, array $outcomes = [], array $skipped = []): CommandTester
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getAllTableNames')->willReturn($moduleActive ? ['economy_exchange_rates_lists'] : ['docs_core_heads']);

        $imports = &$this->imports;
        $importer = new class($outcomes, $skipped, $imports) extends ExchangeRateListImporter {
            public function __construct(private readonly array $outcomes, private readonly array $skipped, private array &$imports)
            {
            }

            public function import(array $list, int $source, bool $replace = false): ExchangeRateListImportResult
            {
                $this->imports[] = [$list, $source, $replace];
                $type = (string) ($list['listType'] ?? '');
                $outcome = $this->outcomes[$type] ?? ExchangeRateListImportResult::CREATED;
                $errors = $outcome === ExchangeRateListImportResult::CONFLICT ? ['jiný obsah'] : [];
                return new ExchangeRateListImportResult($outcome, $type, (string) ($list['validFrom'] ?? ''), 1, $errors, $this->skipped[$type] ?? []);
            }
        };

        $command = new class($dsConfig, $db, $importer) extends ExchangeRatesImportCommand {
            public function __construct(DataSourceConfig $dsConfig, DataSourceConnection $db, private readonly ExchangeRateListImporter $importer)
            {
                parent::__construct($dsConfig, $db);
            }

            protected function getDataSourceDir(): string
            {
                return '/nonexistent';
            }

            protected function createImporter(string $dsDir, DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): ExchangeRateListImporter
            {
                return $this->importer;
            }
        };
        $app = new Application();
        $app->add($command);
        return new CommandTester($app->find('exchange-rates-import'));
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->dir . '/' . $name, $content);
        return $this->dir . '/' . $name;
    }

    public function testMissingPathAndEmptyFolderAreInvalid(): void
    {
        $tester = $this->tester();
        $this->assertSame(2, $tester->execute(['path' => $this->dir . '/nope']));
        $this->assertStringContainsString('neexistuje', $tester->getDisplay());

        $this->assertSame(2, $tester->execute(['path' => $this->dir]));
        $this->assertStringContainsString('není žádný soubor', $tester->getDisplay());
        $this->assertSame([], $this->imports);
    }

    public function testInactiveModuleFails(): void
    {
        $this->write('a.jsonc', '{"listType": "cz.cnb.daily"}');
        $tester = $this->tester(moduleActive: false);

        $this->assertSame(1, $tester->execute(['path' => $this->dir]));
        $this->assertStringContainsString('ds-upgrade', $tester->getDisplay());
        $this->assertSame([], $this->imports);
    }

    public function testFolderImportsFilesByNameWithFileSourceAndReplaceFlag(): void
    {
        $this->write('0002-b.jsonc', '{"listType": "cz.cnb.other", "validFrom": "2026-10-01"} // komentář');
        $this->write('0001-a.json', '{"listType": "cz.cnb.daily", "validFrom": "2026-10-16"}');
        $this->write('notes.txt', 'ignored');
        $tester = $this->tester();

        $this->assertSame(0, $tester->execute(['path' => $this->dir, '--replace' => true]));
        $this->assertSame(['cz.cnb.daily', 'cz.cnb.other'], array_map(static fn(array $i): string => $i[0]['listType'], $this->imports));
        $this->assertSame([ExchangeRateListImporter::SOURCE_FILE, ExchangeRateListImporter::SOURCE_FILE], array_column($this->imports, 1));
        $this->assertSame([true, true], array_column($this->imports, 2));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('založen     0001-a.json — cz.cnb.daily 2026-10-16', $display);
        $this->assertStringContainsString('Lístky: 2 založeno, 0 beze změny, 0 nahrazeno, 0 selhalo', $display);
    }

    public function testConflictInvalidAndParseErrorFailTheRun(): void
    {
        $this->write('1.jsonc', '{"listType": "cz.cnb.daily", "validFrom": "2026-10-16"}');
        $this->write('2.jsonc', '{"listType": "cz.cnb.other", "validFrom": "2026-10-01"}');
        $this->write('3.jsonc', '{"listType": "x.bad"');
        $tester = $this->tester(outcomes: ['cz.cnb.daily' => ExchangeRateListImportResult::SKIPPED, 'cz.cnb.other' => ExchangeRateListImportResult::CONFLICT]);

        $this->assertSame(1, $tester->execute(['path' => $this->dir]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('beze změny  1.jsonc', $display);
        $this->assertStringContainsString('jiný obsah  2.jsonc', $display);
        $this->assertStringContainsString('- jiný obsah', $display);
        $this->assertStringContainsString('neplatný    3.jsonc', $display);
        $this->assertStringContainsString('JSONC parse error', $display);
        $this->assertStringContainsString('Lístky: 0 založeno, 1 beze změny, 0 nahrazeno, 2 selhalo (1 jiný obsah, 1 neplatných)', $display);
        $this->assertStringContainsString('--replace', $display);
        $this->assertCount(2, $this->imports, 'neparsovatelný soubor se importeru nepředá');
    }

    public function testSkippedCurrenciesAreWarningsNotFailures(): void
    {
        $this->write('1.jsonc', '{"listType": "cz.cnb.daily", "validFrom": "2026-10-16"}');
        $tester = $this->tester(skipped: ['cz.cnb.daily' => ['XDR', 'VEF']]);

        $this->assertSame(0, $tester->execute(['path' => $this->dir]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('založen     1.jsonc', $display);
        $this->assertStringContainsString('přeskočené měny: XDR, VEF', $display);
        $this->assertStringContainsString('Lístky: 1 založeno, 0 beze změny, 0 nahrazeno, 0 selhalo', $display);
    }

    public function testSingleFileImport(): void
    {
        $file = $this->write('single.jsonc', '{"listType": "cz.cnb.daily", "validFrom": "2026-10-16"}');
        $tester = $this->tester(outcomes: ['cz.cnb.daily' => ExchangeRateListImportResult::REPLACED]);

        $this->assertSame(0, $tester->execute(['path' => $file]));
        $this->assertStringContainsString('nahrazen    single.jsonc', $tester->getDisplay());
        $this->assertSame([false], array_column($this->imports, 2));
    }
}
