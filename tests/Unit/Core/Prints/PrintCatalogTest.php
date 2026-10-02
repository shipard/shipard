<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\PrintTranslator;

/**
 * Překlady tisků (#90 D8): cesty šablon, slévání katalogů a fallbacky
 * překladače. Moduly jsou dočasný strom na disku.
 */
class PrintCatalogTest extends TestCase
{
    private string $root;
    private string $logFile;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shpd_printcat_' . uniqid('', true);
        $this->writeFile('test/layout/module.jsonc', '{"id": "test.layout", "name": "Layout"}');
        $this->writeFile('test/invoice/module.jsonc', '{"id": "test.invoice", "name": "Invoice"}');
        $this->writeFile('test/plain/module.jsonc', '{"id": "test.plain", "name": "No prints"}');

        $this->logFile = $this->root . '/error.log';
        ErrorLogger::setLogPath($this->logFile);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        $this->rmTree($this->root);
    }

    private function writeFile(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $content);
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function paths(): PrintTemplatePaths
    {
        return new PrintTemplatePaths(new ModulePathResolver([$this->root]));
    }

    /** @param list<string> $catalogs */
    private function definition(array $catalogs = ['@test.layout/_layout']): PrintDefinition
    {
        return PrintDefinition::fromArray(
            PrintDefinitionTest::declaration([
                'id' => 'test.invoice.invoice', 'template' => '@test.invoice/invoice', 'catalogs' => $catalogs,
            ]),
            'test.invoice',
        );
    }

    // ── PrintTemplatePaths ──────────────────────────────────────────────────

    public function testParseAndDirectory(): void
    {
        $this->assertSame(
            ['module' => 'docs.invoicesOut', 'dir' => 'invoice'],
            PrintTemplatePaths::parse('@docs.invoicesOut/invoice'),
        );
        $this->assertSame(
            ['module' => 'docs.core', 'dir' => '_layout/parts'],
            PrintTemplatePaths::parse('@docs.core/_layout/parts'),
        );

        $paths = $this->paths();
        $this->assertSame($this->root . '/test/invoice/prints/invoice', $paths->directory('@test.invoice/invoice'));
        $this->assertNull($paths->directory('@test.missing/invoice'), 'modul na serveru není');
    }

    public function testParseRejectsPathsOutsideModulePrints(): void
    {
        foreach (['invoice', '@test.invoice', '@test.invoice/../x', '@test.invoice//x', '@Test.invoice/x'] as $path) {
            try {
                PrintTemplatePaths::parse($path);
                $this->fail("'{$path}' should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid print template path', $e->getMessage());
            }
        }
    }

    public function testModuleRootsListOnlyModulesWithPrintsDirectory(): void
    {
        $this->writeFile('test/layout/prints/_layout/messages.jsonc', '{}');
        $this->writeFile('test/invoice/prints/invoice/messages.jsonc', '{}');

        $this->assertSame(
            [
                'test.invoice' => $this->root . '/test/invoice/prints',
                'test.layout'  => $this->root . '/test/layout/prints',
            ],
            $this->paths()->moduleRoots(),
        );
    }

    // ── PrintCatalogLoader ──────────────────────────────────────────────────

    public function testTemplateCatalogOverridesSharedCatalog(): void
    {
        $this->writeFile('test/layout/prints/_layout/messages.jsonc', <<<'JSONC'
            {
                // sdílené klíče
                "label.total": { "cs": "Celkem", "en": "Total" },
                "label.due":   { "cs": "Splatnost", "en": "Due date" }
            }
            JSONC);
        $this->writeFile('test/invoice/prints/invoice/messages.jsonc', <<<'JSONC'
            {
                "label.total":   { "cs": "Celkem k úhradě" },
                "title.invoice": { "cs": "Faktura", "en": "Invoice" }
            }
            JSONC);

        $loader = new PrintCatalogLoader($this->paths());

        $this->assertSame(
            [
                'label.total'   => ['cs' => 'Celkem k úhradě'],
                'label.due'     => ['cs' => 'Splatnost', 'en' => 'Due date'],
                'title.invoice' => ['cs' => 'Faktura', 'en' => 'Invoice'],
            ],
            $loader->messages($this->definition()),
        );
        $this->assertSame('Invoice', $loader->translator($this->definition(), 'en')->t('title.invoice'));
    }

    public function testTemplateCatalogIsOptionalButDeclaredCatalogIsNot(): void
    {
        $loader = new PrintCatalogLoader($this->paths());

        $this->assertSame([], $loader->messages($this->definition(catalogs: [])));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("catalog '@test.layout/_layout' has no messages.jsonc");
        $loader->messages($this->definition());
    }

    public function testCatalogWithWrongShapeIsRejected(): void
    {
        $this->writeFile('test/invoice/prints/invoice/messages.jsonc', '{"title.invoice": "Faktura"}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('each key must map languages to texts');
        (new PrintCatalogLoader($this->paths()))->messages($this->definition(catalogs: []));
    }

    // ── PrintTranslator ─────────────────────────────────────────────────────

    public function testTranslatorFallbackChain(): void
    {
        $translator = new PrintTranslator([
            'both'   => ['cs' => 'Česky', 'en' => 'English'],
            'csOnly' => ['cs' => 'Jen česky'],
            'enOnly' => ['en' => 'English only'],
        ], 'en');

        $this->assertSame('en', $translator->language);
        $this->assertSame('English', $translator->t('both'));
        $this->assertSame('Jen česky', $translator->t('csOnly'), 'chybějící jazyk → cs');
        $this->assertTrue($translator->has('csOnly'));
        $this->assertFalse($translator->has('missing'));

        $this->assertFileDoesNotExist($this->logFile);
        $this->assertSame('missing', $translator->t('missing'), 'chybějící klíč → klíč');
        $this->assertStringContainsString('missing translation key', (string) file_get_contents($this->logFile));

        // Klíč existuje, ale ne v jazyce tisku ani v cs → také klíč.
        $this->assertSame('enOnly', (new PrintTranslator(['enOnly' => ['en' => 'x']], 'de'))->t('enOnly'));
    }

    public function testTranslatorReplacesParameters(): void
    {
        $translator = new PrintTranslator(['page' => ['cs' => 'Strana {page} z {total}']], 'cs');

        $this->assertSame('Strana 2 z 5', $translator->t('page', ['page' => 2, 'total' => 5]));
        $this->assertSame('Strana {page} z {total}', $translator->t('page'));
    }
}
