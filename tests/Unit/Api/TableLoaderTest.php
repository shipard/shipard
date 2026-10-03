<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Module\ModulePathResolver;

/**
 * TableLoader nad dočasným stromem modulů: definice tabulek lokalizované do
 * jazyka requestu včetně sloupců, které do tabulky přidává extension jiného
 * modulu.
 */
class TableLoaderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_tableloader_' . uniqid('', true);
        mkdir($this->tmpDir . '/ds/config', 0755, true);
        mkdir($this->tmpDir . '/modules', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param array<string, array<string, mixed>> $tables
     * @param array<string, array<string, mixed>> $extensions
     * @param list<string> $dependencies
     */
    private function writeModule(string $id, array $tables = [], array $extensions = [], array $dependencies = []): void
    {
        [$group, $name] = explode('.', $id, 2);
        $moduleDir = $this->tmpDir . '/modules/' . $group . '/' . $name;
        mkdir($moduleDir . '/tables', 0755, true);
        mkdir($moduleDir . '/extensions', 0755, true);

        file_put_contents($moduleDir . '/module.jsonc', json_encode([
            'id'           => $id,
            'name'         => $name,
            'dependencies' => $dependencies,
            'tables'       => array_keys($tables),
            'extensions'   => array_keys($extensions),
            'config'       => [],
        ]));
        foreach ($tables as $file => $data) {
            file_put_contents($moduleDir . '/tables/' . $file . '.jsonc', json_encode($data));
        }
        foreach ($extensions as $file => $data) {
            file_put_contents($moduleDir . '/extensions/' . $file . '.jsonc', json_encode($data));
        }
    }

    /** @return array<string, string> id sloupce → popisek */
    private function columnLabels(string $language): array
    {
        file_put_contents($this->tmpDir . '/ds/config/main.json', json_encode([
            'id'                => 'abcd-efgh-ijkl-mnop',
            'name'              => 'Test DS',
            'database_name'     => 'abcd_efgh_ijkl_mnop',
            'database_user'     => 'shpd_abcdefgh',
            'database_password' => 'secret',
            'created'           => '2026-10-03T10:00:00+02:00',
            'modules'           => ['test.docs'],
        ]));

        $tables = TableLoader::load(
            new DataSourceConfig($this->tmpDir . '/ds'),
            new ModulePathResolver([$this->tmpDir . '/modules']),
            $language,
        );

        $labels = [];
        foreach ($tables['test_base_persons']->columns as $column) {
            $labels[$column->id] = $column->name;
        }
        return $labels;
    }

    public function testExtensionColumnsAreLocalizedLikeTableColumns(): void
    {
        $this->writeModule('test.base', tables: [
            'test_base_persons' => [
                'tableId' => 1,
                'name'    => 'Persons',
                'columns' => [
                    ['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true],
                    ['id' => 'full_name', 'name' => 'Name', 'name:cs' => 'Název', 'type' => 'varchar', 'length' => 100],
                ],
            ],
        ]);
        $this->writeModule('test.docs', extensions: [
            'test_base_persons' => [
                'table'   => 'test_base_persons',
                'columns' => [[
                    'id' => 'payment_term_days', 'name' => 'Payment term (days)', 'name:cs' => 'Splatnost (dny)',
                    'type' => 'smallint', 'nullable' => true,
                ]],
            ],
        ], dependencies: ['test.base']);

        $this->assertSame(
            ['id' => 'ID', 'full_name' => 'Název', 'payment_term_days' => 'Splatnost (dny)'],
            $this->columnLabels('cs'),
        );
        $this->assertSame(
            ['id' => 'ID', 'full_name' => 'Name', 'payment_term_days' => 'Payment term (days)'],
            $this->columnLabels('en'),
        );
    }
}
