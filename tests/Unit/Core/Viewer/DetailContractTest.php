<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\RecordPattern;
use Shipard\Core\Database\SchemaLoader;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Viewer\Detail\DetailContent;
use Shipard\Core\Viewer\TableViewer;
use Shipard\Core\Viewer\ViewerDefinition;

/**
 * Kontrakt detailu záznamu (#136 D23) nad všemi `viewers[]` v repozitáři:
 * třída vieweru dědí `TableViewer`, detail skládá builder (`renderDetail()`
 * je `final`, viewer dodává háčky), placeholdery `detailHeader` jsou sloupce
 * tabulky, viewer bez tabulky dodává vlastní hlavičku, umístění příloh je
 * známé. Bez výjimek — seznam nepřevedených viewerů task B vyprázdnil.
 *
 * Typy obsahu (#137 D30): `DetailContent::TYPES` bez `html` a žádný PHP
 * zdroj (`modules/*\/*\/src`, `src/`) blok `'type' => 'html'` neskládá —
 * hláška je `notice`, štítky `badges`, prázdný stav `empty`; obsah nad
 * skutečnými daty hlídá integrační `ViewerDetailContractTest`.
 */
class DetailContractTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../modules';

    /** @var array<string, array{module: string, def: array<string, mixed>}> id vieweru → deklarace */
    private static array $viewers = [];

    /** @var array<string, TableDefinition> */
    private static array $tables = [];

    public static function setUpBeforeClass(): void
    {
        $moduleIds = [];
        foreach (glob(self::MODULES . '/*/*/module.jsonc') ?: [] as $file) {
            $module = JsoncParser::parseFile($file);
            $moduleIds[] = $module['id'];
            foreach ($module['viewers'] ?? [] as $viewer) {
                self::$viewers[$viewer['id']] = ['module' => $module['id'], 'def' => $viewer];
            }
        }
        ksort(self::$viewers);

        $result = SchemaLoader::loadResolvedTables(new ModulePathResolver([self::MODULES]), $moduleIds);
        self::assertSame([], $result['errors']);
        self::$tables = $result['tables'];
    }

    public function testRepositoryDeclaresViewers(): void
    {
        $this->assertGreaterThan(50, count(self::$viewers));
    }

    public function testEveryViewerClassExtendsTableViewer(): void
    {
        foreach (self::$viewers as $id => ['def' => $def]) {
            $class = $def['class'] ?? null;
            $this->assertIsString($class, "viewer {$id}: chybí class");
            $this->assertTrue(class_exists($class), "viewer {$id}: třída {$class} neexistuje");
            $this->assertTrue(is_subclass_of($class, TableViewer::class), "viewer {$id}: {$class} nedědí TableViewer");
        }
    }

    public function testRenderDetailIsFinalAndNoViewerOverridesIt(): void
    {
        $this->assertTrue(
            (new \ReflectionMethod(TableViewer::class, 'renderDetail'))->isFinal(),
            'TableViewer::renderDetail() je final — detail skládá builder, viewer dodává háčky (#136 task B)',
        );

        foreach (self::$viewers as $id => ['def' => $def]) {
            $declaring = (new \ReflectionMethod($def['class'], 'renderDetail'))->getDeclaringClass()->getName();
            $this->assertSame(TableViewer::class, $declaring, "viewer {$id} přepisuje renderDetail() v {$declaring}");
        }
    }

    public function testDetailHeaderPlaceholdersAreColumnsOfTheTable(): void
    {
        $checked = 0;
        foreach (self::$viewers as $id => ['def' => $def]) {
            $header = $def['detailHeader'] ?? null;
            if ($header === null) {
                continue;
            }
            $this->assertIsString($def['table'] ?? null, "viewer {$id}: detailHeader bez tabulky");
            $table = self::$tables[$def['table']] ?? null;
            $this->assertNotNull($table, "viewer {$id}: tabulka {$def['table']} nenalezena");
            $columns = ['id', ...array_map(static fn($c): string => $c->id, $table->columns)];

            foreach (['title', 'subtitle'] as $key) {
                if (!isset($header[$key])) {
                    continue;
                }
                $checked++;
                foreach ((new RecordPattern($header[$key]))->columns() as $column) {
                    $this->assertContains($column, $columns, "viewer {$id}: detailHeader.{$key} používá neznámý sloupec {$column}");
                }
            }
        }
        // Bez deklarací (před prvním převodem) test prošel bez kontroly.
        $this->addToAssertionCount($checked === 0 ? 1 : 0);
    }

    public function testViewerWithoutTableProvidesItsOwnHeader(): void
    {
        foreach (self::$viewers as $id => ['def' => $def]) {
            if (($def['table'] ?? null) !== null) {
                continue;
            }
            $declaring = (new \ReflectionMethod($def['class'], 'detailHeader'))->getDeclaringClass()->getName();
            $this->assertNotSame(TableViewer::class, $declaring, "viewer {$id} bez tabulky nemá displayPattern — musí přepsat detailHeader()");
        }
        $this->addToAssertionCount(1);
    }

    public function testDetailAttachmentsPlacementIsKnown(): void
    {
        foreach (self::$viewers as $id => ['def' => $def, 'module' => $module]) {
            $placement = $def['detailAttachments'] ?? ViewerDefinition::ATTACHMENTS_OVERVIEW;
            $this->assertContains(
                $placement,
                ViewerDefinition::ATTACHMENT_PLACEMENTS,
                "viewer {$id} ({$module}): neznámé detailAttachments '{$placement}'",
            );
        }
    }

    public function testContentTypesExcludeHtml(): void
    {
        $this->assertNotContains('html', DetailContent::TYPES, 'HTML se vzhledem backend neposílá (#137 D30)');
        $this->assertContains('untrusted-html', DetailContent::TYPES, 'Tělo e-mailu v sandboxu zůstává');
        $this->assertSame(array_values(array_unique(DetailContent::TYPES)), DetailContent::TYPES);
    }

    public function testNoPhpSourceEmitsHtmlContent(): void
    {
        $repo = realpath(__DIR__ . '/../../../..');
        $this->assertIsString($repo);
        $hits = [];
        foreach ([self::MODULES, $repo . '/src'] as $root) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $path = (string) $file->getPathname();
                if ($root === self::MODULES && !str_contains($path, '/src/')) {
                    continue;
                }
                foreach (file($path) ?: [] as $index => $line) {
                    if (preg_match('/[\'"]type[\'"]\s*=>\s*[\'"]html[\'"]/', $line) === 1) {
                        $hits[] = substr((string) realpath($path), strlen($repo) + 1) . ':' . ($index + 1);
                    }
                }
            }
        }
        $this->assertSame([], $hits, "Blok detailu typu html (#137 D30 — použít notice / badges / empty / text / code):\n" . implode("\n", $hits));
    }
}
