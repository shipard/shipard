<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Prints\Jobs\PrintJobService;

/**
 * Deklarace modulu core.prints nad skutečnými soubory: tabulka tiskových
 * úloh (#177 U39) — binární data mimo generické cesty, přesné porovnání
 * `job_id`, mimo `keepOnReset`.
 */
final class PrintsModuleTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/core/prints';

    private static function module(): ModuleDefinition
    {
        return ModuleDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/module.jsonc'));
    }

    private static function jobs(): TableDefinition
    {
        return TableDefinition::fromArray(
            JsoncParser::parseFile(self::MODULE . '/tables/' . PrintJobService::TABLE . '.jsonc'),
        );
    }

    public function testModuleDeclaresJobsTableAndDependsOnDevices(): void
    {
        $module = self::module();

        $this->assertContains('core.devices', $module->dependencies);
        $this->assertContains(PrintJobService::TABLE, $module->tables);
        $this->assertNotContains(PrintJobService::TABLE, $module->keepOnReset, 'Úlohy odkazují na záznamy, ds-reset je zahodí');
    }

    public function testJobsTableIsWrittenOnlyByTheService(): void
    {
        $table = self::jobs();

        $this->assertSame(475, $table->tableId);
        $this->assertTrue($table->adminOnly);
        $this->assertTrue($table->systemManaged);
        $raw = JsoncParser::parseFile(self::MODULE . '/tables/' . PrintJobService::TABLE . '.jsonc');
        $this->assertTrue($raw['hideFromNavigation'] ?? false);

        foreach ($table->columns as $column) {
            if ($column->id !== 'id') {
                $this->assertTrue($column->system, "Sloupec {$column->id} má být systémový");
            }
        }
    }

    public function testJobIdIsComparedExactlyAndDataAreBinary(): void
    {
        $columns = [];
        foreach (self::jobs()->columns as $column) {
            $columns[$column->id] = $column;
        }

        $this->assertSame('utf8mb4_bin', $columns['job_id']->collation);
        $this->assertSame('mediumblob', $columns['data']->type);
        $this->assertTrue($columns['data']->sensitive);

        $unique = array_filter(self::jobs()->indexes, static fn ($index): bool => $index->type === 'unique');
        $this->assertSame(['unq_job_id'], array_values(array_map(static fn ($index): string => $index->id, $unique)));
    }
}
