<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\WorkOrders\WorkOrderInvoicingSource;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Zakázky jako zdroj periodické fakturace (#175 D3): kandidáti, evidence
 * období, dimenze, texty a shoda s deklarací modulu.
 */
class WorkOrderInvoicingSourceTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../modules/economy/workOrders';

    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
        'rent'     => ['name' => 'Nájem', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Projekt', 'external' => true, 'oneOff' => true, 'invoicing' => null],
    ];

    /** @param array<string, mixed> $items */
    private function config(array $items = []): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $items[$id] ?? ($id === WorkOrderTypes::CFG_ITEM ? self::TYPES : null),
        );
        return $config;
    }

    /**
     * @param list<array<string, mixed>> $fetchAll
     * @param array<string, mixed>|null $fetch
     */
    private function source(array $fetchAll = [], ?array $fetch = null, ?ConfigRuntime $config = null): WorkOrderInvoicingSource
    {
        $source = new class($fetchAll, $fetch) extends WorkOrderInvoicingSource {
            /** @var list<array{0: string, 1: list<mixed>}> */
            public array $queries = [];

            public function __construct(private readonly array $fetchAllResult, private readonly ?array $fetchResult)
            {
            }

            protected function fetchAll(string $sql, mixed ...$args): array
            {
                $this->queries[] = [$sql, $args];
                return $this->fetchAllResult;
            }

            protected function fetch(string $sql, mixed ...$args): ?array
            {
                $this->queries[] = [$sql, $args];
                return $this->fetchResult;
            }
        };
        $source->setConfig($config ?? $this->config());
        return $source;
    }

    public function testIdentityMatchesTheModuleDeclaration(): void
    {
        $source = new WorkOrderInvoicingSource();
        $this->assertSame('workOrders', $source->id());
        $this->assertSame('economy_work_orders_heads', $source->table());
        $this->assertSame('economy_work_orders_periods', $source->periodsTable());
        $this->assertSame('work_order', $source->periodsRecordColumn());

        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/module.jsonc'));
        $this->assertSame(
            [['id' => 'workOrders', 'class' => WorkOrderInvoicingSource::class]],
            $module->periodicInvoicingSources,
        );
        $this->assertContains('economy.periodicInvoicing', $module->dependencies);
        $this->assertContains($source->periodsTable(), $module->tables);
        $this->assertContains($source->table(), $module->tables);

        // Sekce badge upozornění (#175 R3) = sekce vieweru zdroje z deklarace.
        $viewers = array_column(JsoncParser::parseFile(self::MODULE . '/module.jsonc')['viewers'], null, 'id');
        $this->assertArrayHasKey($source->viewerId(), $viewers);
        $this->assertSame($viewers[$source->viewerId()]['navSection'], $source->navSection());

        // Tabulka období má sloupec záznamu, na který se evidence ptá.
        $periods = JsoncParser::parseFile(self::MODULE . '/tables/economy_work_orders_periods.jsonc');
        $this->assertContains($source->periodsRecordColumn(), array_column($periods['columns'], 'id'));
    }

    public function testRegistryBuildsTheSourceFromItsRegistration(): void
    {
        $config = $this->config([InvoicingSources::CFG_ITEM => ['workOrders' => ['class' => WorkOrderInvoicingSource::class]]]);
        $sources = InvoicingSources::fromConfig($config);

        $this->assertInstanceOf(WorkOrderInvoicingSource::class, $sources->get('workOrders'));
        $this->assertSame($sources->get('workOrders'), $sources->forTable('economy_work_orders_heads'));
    }

    public function testCandidatesArePeriodicConfirmedWorkOrdersWithPrescription(): void
    {
        $source = $this->source([['id' => 6]]);

        $this->assertSame([['id' => 6]], $source->candidates(null));
        [$sql, $args] = $source->queries[0];
        $this->assertStringContainsString('FROM [economy_work_orders_heads]', $sql);
        $this->assertStringContainsString('[docState] = %i AND [type] IN %in AND [inv_from] IS NOT NULL AND [inv_periodicity] IS NOT NULL', $sql);
        $this->assertStringEndsWith('ORDER BY [id]', $sql);
        $this->assertSame([40, ['periodic', 'rent']], $args);

        $source->candidates(6);
        [$sql, $args] = $source->queries[1];
        $this->assertStringContainsString('AND [id] = %i', $sql);
        $this->assertSame([40, ['periodic', 'rent'], 6], $args);
    }

    public function testNoPeriodicTypeMeansNoCandidatesAndNoQuery(): void
    {
        $source = $this->source([['id' => 6]], null, $this->config([WorkOrderTypes::CFG_ITEM => ['project' => self::TYPES['project']]]));
        $this->assertSame([], $source->candidates(null));
        $this->assertSame([], $source->queries);
    }

    public function testRecordKindRowsAndNumberLookup(): void
    {
        $source = $this->source([['id' => 1, 'order_pos' => 1]], ['id' => 6, 'kind' => 4]);

        $this->assertSame(['id' => 6, 'kind' => 4], $source->record(6));
        $this->assertSame(6, $source->recordIdByNumber('S260001'));
        $this->assertSame(['id' => 6, 'kind' => 4], $source->kind(['id' => 6, 'kind' => 4]));
        $this->assertNull($source->kind(['id' => 6, 'kind' => null]), 'bez druhu se DB neptá');
        $this->assertSame([['id' => 1, 'order_pos' => 1]], $source->rows(['id' => 6]));

        $this->assertSame(['SELECT * FROM [economy_work_orders_heads] WHERE [id] = %i', [6]], $source->queries[0]);
        $this->assertSame(['SELECT [id] FROM [economy_work_orders_heads] WHERE [number] = %s', ['S260001']], $source->queries[1]);
        $this->assertSame(['SELECT * FROM [economy_work_orders_kinds] WHERE [id] = %i', [4]], $source->queries[2]);
        $this->assertSame(
            ['SELECT * FROM [economy_work_orders_rows] WHERE [work_order] = %i ORDER BY [order_pos], [id]', [6]],
            $source->queries[3],
        );
        $this->assertNull($this->source()->recordIdByNumber('S999'));
    }

    public function testDimensionsAreWorkOrderAndCostCenter(): void
    {
        $source = new WorkOrderInvoicingSource();
        $this->assertSame(['workOrder' => 6, 'costCenter' => 1], $source->dimensions(['id' => 6, 'cost_center' => 1]));
        $this->assertSame(['workOrder' => 6], $source->dimensions(['id' => 6, 'cost_center' => null]));
    }

    public function testLabelAndTextsOfTheRecord(): void
    {
        $source = new WorkOrderInvoicingSource();
        $this->assertSame('S260001 Nájem', $source->recordLabel(['id' => 6, 'number' => 'S260001', 'title' => 'Nájem']));
        $this->assertSame('Druhá', $source->recordLabel(['id' => 7, 'number' => '', 'title' => 'Druhá']));
        $this->assertSame('#8', $source->recordLabel(['id' => 8, 'number' => null, 'title' => ' ']));

        // Bez katalogu anglicky; fallback = holé `name` katalogu znak po znaku.
        $this->assertSame('Work order', $source->recordNoun());
        $this->assertSame('Open work order', $source->openActionLabel());
        $catalog = JsoncParser::parseFile(self::MODULE . '/config/viewerLabels.jsonc');
        $this->assertSame('Work order', $catalog['source.recordNoun']['name']);
        $this->assertSame('Open work order', $catalog['source.openAction']['name']);
        $this->assertSame('Zakázka', $catalog['source.recordNoun']['name:cs']);
        $this->assertSame('Otevřít zakázku', $catalog['source.openAction']['name:cs']);

        $source->setConfig($this->config(['economy.workOrders.viewerLabels' => [
            'source.recordNoun' => ['name' => 'Zakázka'],
            'source.openAction' => ['name' => 'Otevřít zakázku'],
        ]]));
        $this->assertSame('Zakázka', $source->recordNoun());
        $this->assertSame('Otevřít zakázku', $source->openActionLabel());
    }
}
