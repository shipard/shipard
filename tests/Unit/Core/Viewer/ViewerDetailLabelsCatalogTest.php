<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Viewer;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\JsoncParser;

/**
 * Úplnost katalogů `*.viewerDetailLabels` (#137 D30): sekce `tabs` /
 * `labels` / `texts` čtené přes `TableViewer::detailTabLabel()`,
 * `IncomingMessagesViewer::viewerLabel()` a `TableViewer::detailText()` —
 * každá položka má holé pole `name` i obě jazykové varianty a holé pole je
 * anglická varianta. Nový katalog se do testu přidá sám.
 */
class ViewerDetailLabelsCatalogTest extends TestCase
{
    private const SUFFIX = '.viewerDetailLabels';
    private const SECTIONS = ['tabs', 'labels', 'texts'];
    private const FIELDS = ['name', 'name:cs', 'name:en'];

    /** Katalogy, které v repozitáři musí existovat (viewery s texty prázdného stavu a tabu Kontext). */
    private const EXPECTED = [
        'base.persons', 'base.registry', 'core.mail', 'core.alerts', 'economy.accounting', 'core.system',
    ];

    /** @return array<string, array<string, mixed>> cfgItem id → surový katalog */
    private static function catalogs(): array
    {
        $resolver = new ModulePathResolver([__DIR__ . '/../../../../modules']);
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
            $this->assertNotEmpty($catalog, "{$id}: katalog není prázdný");
            foreach ($catalog as $section => $entries) {
                $this->assertContains($section, self::SECTIONS, "{$id}: neznámá sekce {$section}");
                $this->assertIsArray($entries, "{$id}.{$section}: sekce je objekt");
                $this->assertNotEmpty($entries, "{$id}.{$section}: sekce není prázdná");
                foreach ($entries as $key => $entry) {
                    $this->assertIsArray($entry, "{$id}.{$section}.{$key}: položka je objekt");
                    foreach (self::FIELDS as $field) {
                        $this->assertArrayHasKey($field, $entry, "{$id}.{$section}.{$key}: chybí {$field}");
                        $this->assertIsString($entry[$field], "{$id}.{$section}.{$key}.{$field}: text");
                        $this->assertNotSame('', trim($entry[$field]), "{$id}.{$section}.{$key}.{$field}: prázdný text");
                    }
                    $this->assertSame(
                        $entry['name:en'],
                        $entry['name'],
                        "{$id}.{$section}.{$key}: holé pole je anglická varianta",
                    );
                }
            }
        }
    }
}
