<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Katalogy textů formulářů `*.formTexts` (#150 D87): jediná sekce `texts`,
 * každá položka má holé `name` i obě jazykové varianty a holé pole je
 * anglická varianta. Každé volání `TableForm::formText()` ve zdrojích má
 * literální id katalogu, klíč i fallback, klíč v katalogu existuje
 * a anglický fallback v PHP se s holým `name` shoduje znak po znaku —
 * formulář bez zkompilované konfigurace tak říká totéž co anglický katalog.
 * Nový katalog i nové volání se do testu přidají samy.
 */
class FormTextsCatalogTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';
    private const SUFFIX = '.formTexts';
    private const FIELDS = ['name', 'name:cs', 'name:en'];

    /** Katalogy, které v repozitáři musí existovat. */
    private const EXPECTED = ['core.ai', 'docs.core', 'economy.codebooks', 'economy.vat'];

    /** `->formText('<katalog>', '<klíč>', '<fallback>'` — tři literály v jednoduchých uvozovkách. */
    private const CALL = '/->formText\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'/s';

    /** @return array<string, array<string, mixed>> cfgItem id → surový katalog */
    private static function catalogs(): array
    {
        $resolver = new ModulePathResolver([self::ROOT . '/modules']);
        $out = [];
        foreach (ModuleLoader::loadAllModules($resolver) as $module) {
            foreach ($module->config as $entry) {
                $id = (string) ($entry['id'] ?? '');
                if (!str_ends_with($id, self::SUFFIX)) {
                    continue;
                }
                $out[$id] = JsoncParser::parseFile($resolver->getPath($module->id) . '/' . $entry['file']);
            }
        }
        ksort($out);
        return $out;
    }

    /** @return list<string> PHP zdroje jádra a modulů */
    private static function sources(): array
    {
        $files = [];
        foreach ([self::ROOT . '/src', ...(glob(self::ROOT . '/modules/*/*/src', GLOB_ONLYDIR) ?: [])] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    public function testRepositoryDeclaresCatalogs(): void
    {
        $ids = array_keys(self::catalogs());
        foreach (self::EXPECTED as $module) {
            $this->assertContains($module . self::SUFFIX, $ids);
        }
    }

    public function testEveryEntryHasNameInBothLanguages(): void
    {
        foreach (self::catalogs() as $id => $catalog) {
            $this->assertIsArray($catalog, "{$id}: katalog je objekt");
            $this->assertSame(['texts'], array_keys($catalog), "{$id}: jediná sekce je texts");
            $this->assertNotEmpty($catalog['texts'], "{$id}.texts: sekce není prázdná");
            foreach ($catalog['texts'] as $key => $entry) {
                $this->assertIsArray($entry, "{$id}.texts.{$key}: položka je objekt");
                foreach (self::FIELDS as $field) {
                    $this->assertArrayHasKey($field, $entry, "{$id}.texts.{$key}: chybí {$field}");
                    $this->assertIsString($entry[$field], "{$id}.texts.{$key}.{$field}: text");
                    $this->assertNotSame('', trim($entry[$field]), "{$id}.texts.{$key}.{$field}: prázdný text");
                }
                $this->assertSame($entry['name:en'], $entry['name'], "{$id}.texts.{$key}: holé name je anglická varianta");
            }
        }
    }

    public function testEveryCallUsesLiteralsAndMatchesCatalog(): void
    {
        $catalogs = self::catalogs();
        $used = [];
        $calls = 0;
        foreach (self::sources() as $file) {
            $source = (string) file_get_contents($file);
            $total = substr_count($source, '->formText(');
            if ($total === 0) {
                continue;
            }
            $where = substr($file, strlen((string) realpath(self::ROOT)) + 1);
            $this->assertSame(
                $total,
                preg_match_all(self::CALL, $source, $matches, PREG_SET_ORDER),
                "{$where}: formText() volat s literálním id katalogu, klíčem i anglickým fallbackem",
            );
            foreach ($matches as [, $catalogId, $key, $fallback]) {
                $calls++;
                $this->assertArrayHasKey($catalogId, $catalogs, "{$where}: neznámý katalog {$catalogId}");
                $entry = $catalogs[$catalogId]['texts'][$key] ?? null;
                $this->assertNotNull($entry, "{$where}: klíč {$key} chybí v {$catalogId}");
                $this->assertSame(
                    $entry['name'],
                    stripslashes($fallback),
                    "{$where}: fallback klíče {$key} se neshoduje s holým name v {$catalogId}",
                );
                $used[$catalogId][$key] = true;
            }
        }
        $this->assertGreaterThan(0, $calls);

        // Klíč, který nikdo nečte, je mrtvý text.
        foreach ($catalogs as $id => $catalog) {
            foreach (array_keys($catalog['texts']) as $key) {
                $this->assertArrayHasKey($key, $used[$id] ?? [], "{$id}.texts.{$key}: klíč žádný formulář nečte");
            }
        }
    }
}
