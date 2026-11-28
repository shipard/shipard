<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Facility\Buildings;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\PeriodicInvoicing\Action\IssueDueAction;
use Shipard\Module\Economy\PeriodicInvoicing\Action\RegenerateAction;
use Shipard\Module\Economy\PeriodicInvoicing\Action\RestoreAction;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunService;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;
use Shipard\Module\Economy\WorkOrders\WorkOrderInvoicingSource;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;
use Shipard\Module\Facility\Buildings\ContractInvoicingSource;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeApplier;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeInvoiceBuilder;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakePeriodRepository;

/**
 * Skutečný zdroj smluv nad řádky v paměti: dotazy zdroje vrací řádky
 * fixture stejně, jako by je vrátila databáze (kandidáti = V pořádku
 * s periodicitou a „fakturovat od“).
 */
final class FixtureContractSource extends ContractInvoicingSource
{
    /** @var list<array<string, mixed>> */
    public array $records = [];
    /** @var array<int, array<string, mixed>> */
    public array $kinds = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $prescription = [];

    protected function fetchAll(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, '[' . \Shipard\Module\Facility\Buildings\ContractDocument::ROWS_TABLE . ']')) {
            return $this->prescription[(int) $args[0]] ?? [];
        }
        $recordId = $args[1] ?? null;
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => $r['docState'] === 40 && $r['inv_from'] !== null && $r['inv_periodicity'] !== null
                && ($recordId === null || $r['id'] === $recordId),
        ));
    }

    protected function fetch(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, '_contract_kinds]')) {
            return $this->kinds[(int) $args[0]] ?? null;
        }
        foreach ($this->records as $record) {
            if ($record['id'] === $args[0] || ($record['number'] ?? null) === $args[0]) {
                return $record;
            }
        }
        return null;
    }
}

/** Skutečný zdroj zakázek nad řádky v paměti — protějšek {@see FixtureContractSource}. */
final class FixtureWorkOrderSource extends WorkOrderInvoicingSource
{
    /** @var list<array<string, mixed>> */
    public array $records = [];
    /** @var array<int, array<string, mixed>> */
    public array $kinds = [];
    /** @var array<int, list<array<string, mixed>>> */
    public array $prescription = [];

    protected function fetchAll(string $sql, mixed ...$args): array
    {
        if (str_contains($sql, '[economy_work_orders_rows]')) {
            return $this->prescription[(int) $args[0]] ?? [];
        }
        $recordId = $args[2] ?? null;
        return array_values(array_filter(
            $this->records,
            static fn (array $r): bool => $r['docState'] === 40 && $r['inv_from'] !== null && $r['inv_periodicity'] !== null
                && ($recordId === null || $r['id'] === $recordId),
        ));
    }

    protected function fetch(string $sql, mixed ...$args): ?array
    {
        if (str_contains($sql, '[economy_work_orders_kinds]')) {
            return $this->kinds[(int) $args[0]] ?? null;
        }
        foreach ($this->records as $record) {
            if ($record['id'] === $args[0]) {
                return $record;
            }
        }
        return null;
    }
}

/**
 * Smlouvy jako zdroj periodické fakturace (#174 D34): identita zdroje
 * odpovídá deklaracím modulu, kandidáti jsou smlouvy V pořádku s periodicitou
 * a „fakturovat od“, dimenze je jen středisko. Běh enginu se dvěma
 * skutečnými zdroji (Zakázky + Smlouvy) drží evidence období odděleně.
 */
final class ContractInvoicingSourceTest extends TestCase
{
    use BuildingsConfig;

    private const MODULE = __DIR__ . '/../../../../../modules/facility/buildings';

    /**
     * @param list<array<string, mixed>> $fetchAll
     * @param array<string, mixed>|null $fetch
     */
    private function source(array $fetchAll = [], ?array $fetch = null): ContractInvoicingSource
    {
        return new class($fetchAll, $fetch) extends ContractInvoicingSource {
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
    }

    public function testIdentityMatchesTheModuleDeclaration(): void
    {
        $source = new ContractInvoicingSource();
        $this->assertSame('buildingContracts', $source->id());
        $this->assertSame('facility_buildings_contracts', $source->table());
        $this->assertSame('facility_buildings_contract_periods', $source->periodsTable());
        $this->assertSame('contract', $source->periodsRecordColumn());

        $raw = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $module = ModuleDefinition::fromArray($raw);
        $this->assertSame(
            [['id' => 'buildingContracts', 'class' => ContractInvoicingSource::class]],
            $module->periodicInvoicingSources,
        );
        $this->assertContains('economy.periodicInvoicing', $module->dependencies);
        $this->assertContains($source->periodsTable(), $module->tables);
        $this->assertContains($source->table(), $module->tables);

        // Sekce badge upozornění = sekce vieweru zdroje z deklarace.
        $viewers = array_column($raw['viewers'], null, 'id');
        $this->assertArrayHasKey($source->viewerId(), $viewers);
        $this->assertSame($viewers[$source->viewerId()]['navSection'], $source->navSection());
        $this->assertSame($source->table(), $viewers[$source->viewerId()]['table']);

        // Předmět nálezu upozornění = tableId tabulky smluv z její definice.
        $contracts = JsoncParser::parseFile(self::MODULE . '/tables/facility_buildings_contracts.jsonc');
        $this->assertSame($contracts['tableId'], $source->tableId());

        // Tabulka období má sloupec záznamu, na který se evidence ptá.
        $periods = JsoncParser::parseFile(self::MODULE . '/tables/facility_buildings_contract_periods.jsonc');
        $this->assertContains($source->periodsRecordColumn(), array_column($periods['columns'], 'id'));
    }

    public function testRegistryBuildsTheSourceFromItsRegistration(): void
    {
        $config = $this->buildingsConfig([
            InvoicingSources::CFG_ITEM => ['buildingContracts' => ['class' => ContractInvoicingSource::class]],
        ]);
        $sources = InvoicingSources::fromConfig($config);

        $this->assertInstanceOf(ContractInvoicingSource::class, $sources->get('buildingContracts'));
        $this->assertSame($sources->get('buildingContracts'), $sources->forTable('facility_buildings_contracts'));
    }

    public function testInvoicingActionsAreDeclaredOverTheContractsTable(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/module.jsonc'));
        $actions = [];
        foreach ($module->recordActions as $action) {
            $this->assertSame('facility_buildings_contracts', $action['table']);
            $actions[$action['id']] = $action['class'];
        }
        $this->assertSame(
            ['issueDue' => IssueDueAction::class, 'regenerate' => RegenerateAction::class, 'restore' => RestoreAction::class],
            $actions,
        );
    }

    public function testCandidatesAreConfirmedContractsWithPeriodicityAndInvoiceFrom(): void
    {
        $source = $this->source([['id' => 6]]);

        $this->assertSame([['id' => 6]], $source->candidates(null));
        [$sql, $args] = $source->queries[0];
        $this->assertStringContainsString('FROM [facility_buildings_contracts]', $sql);
        $this->assertStringContainsString('[docState] = %i AND [inv_from] IS NOT NULL AND [inv_periodicity] IS NOT NULL', $sql);
        $this->assertStringEndsWith('ORDER BY [id]', $sql);
        $this->assertSame([40], $args);

        $source->candidates(6);
        [$sql, $args] = $source->queries[1];
        $this->assertStringContainsString('AND [id] = %i', $sql);
        $this->assertSame([40, 6], $args);
    }

    public function testRecordKindRowsAndNumberLookup(): void
    {
        $source = $this->source([['id' => 1, 'order_pos' => 1]], ['id' => 6, 'kind' => 4]);

        $this->assertSame(['id' => 6, 'kind' => 4], $source->record(6));
        $this->assertSame(6, $source->recordIdByNumber('KA-2027-0001'));
        $this->assertSame(['id' => 6, 'kind' => 4], $source->kind(['id' => 6, 'kind' => 4]));
        $this->assertNull($source->kind(['id' => 6, 'kind' => null]), 'bez druhu se DB neptá');
        $this->assertSame([['id' => 1, 'order_pos' => 1]], $source->rows(['id' => 6]));

        $this->assertSame(['SELECT * FROM [facility_buildings_contracts] WHERE [id] = %i', [6]], $source->queries[0]);
        $this->assertSame(['SELECT [id] FROM [facility_buildings_contracts] WHERE [number] = %s', ['KA-2027-0001']], $source->queries[1]);
        $this->assertSame(['SELECT * FROM [facility_buildings_contract_kinds] WHERE [id] = %i', [4]], $source->queries[2]);
        $this->assertSame(
            ['SELECT * FROM [facility_buildings_contract_rows] WHERE [contract] = %i ORDER BY [order_pos], [id]', [6]],
            $source->queries[3],
        );
        $this->assertNull($this->source()->recordIdByNumber('KA-9999'));
    }

    public function testOnlyTheCostCenterIsADimension(): void
    {
        $source = new ContractInvoicingSource();
        $this->assertSame(['costCenter' => 9], $source->dimensions(['id' => 6, 'cost_center' => 9]));
        $this->assertSame([], $source->dimensions(['id' => 6, 'cost_center' => null]));
    }

    public function testLabelAndTextsOfTheRecord(): void
    {
        $source = new ContractInvoicingSource();
        $this->assertSame('KA-2027-0001 Nájem', $source->recordLabel(['id' => 6, 'number' => 'KA-2027-0001', 'title' => 'Nájem']));
        $this->assertSame('#8', $source->recordLabel(['id' => 8, 'number' => null, 'title' => ' ']));
        $this->assertSame('Contract', $source->recordNoun(), 'bez katalogu anglicky');
        $this->assertSame('Open contract', $source->openActionLabel());

        $source->setConfig($this->buildingsConfig([
            'facility.buildings.viewerLabels' => self::czech('facility/buildings/config/viewerLabels.jsonc'),
        ]));
        $this->assertSame('Smlouva', $source->recordNoun());
        $this->assertSame('Otevřít smlouvu', $source->openActionLabel());
    }

    // ── Běh enginu se dvěma skutečnými zdroji ───────────────────────────────

    /** @return array<string, mixed> */
    private static function record(array $overrides = []): array
    {
        return $overrides + [
            'id' => 6, 'number' => 'N-0006', 'title' => 'Nájem', 'customer' => 2, 'currency' => 'czk', 'kind' => 4,
            'type' => 'periodic', 'docState' => 40, 'date_start' => '2026-08-01', 'date_end' => null,
            'inv_periodicity' => 'month', 'inv_from' => '2026-08-01', 'inv_doc_text' => null, 'cost_center' => null,
            'payment_reference' => null, 'inv_doc_type' => null, 'inv_number_series' => null, 'inv_due_days' => null,
            'inv_timing' => null, 'inv_vat_mode' => null, 'inv_payment_method' => null, 'inv_bank_account' => null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $contracts
     * @return array{0: InvoicingRunService, 1: array<string, FakePeriodRepository>, 2: FakeApplier}
     */
    private function runService(array $contracts): array
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => match ($id) {
            'world.base.documentLanguages' => ['cs' => [], 'en' => []],
            'world.base.countries' => ['cz' => ['languages' => ['cs']]],
            WorkOrderTypes::CFG_ITEM => ['periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic']],
            default => null,
        });
        $db = $this->createMock(Connection::class);
        $db->method('fetchSingle')->willReturn(0);

        $kind = ['id' => 4, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 1, 'inv_timing' => 'start'];
        $row = ['id' => 1, 'description' => 'Nájem', 'quantity' => 1, 'unit_price' => 12000, 'valid_from' => null, 'valid_to' => null];

        $workOrders = new FixtureWorkOrderSource();
        $workOrders->setConfig($config);
        $workOrders->records = [self::record(['number' => 'S260006'])];
        $workOrders->kinds = [4 => $kind];
        $workOrders->prescription = [6 => [$row]];

        $buildings = new FixtureContractSource();
        $buildings->setConfig($config);
        $buildings->records = $contracts;
        $buildings->kinds = [4 => $kind];
        foreach ($contracts as $contract) {
            $buildings->prescription[$contract['id']] = [$row];
        }

        $repos = [];
        foreach ([$workOrders, $buildings] as $source) {
            $repos[$source->id()] = new FakePeriodRepository($db, $source->periodsTable(), $source->periodsRecordColumn());
        }
        $applier = new FakeApplier();
        $service = new class(
            $db,
            $config,
            new InvoicingSources([$workOrders, $buildings]),
            new FakeInvoiceBuilder($db, $config, 'cz'),
            $applier,
            InvoiceContributorRegistry::empty(),
            static fn (PeriodicInvoicingSource $source): PeriodRepository => $repos[$source->id()],
        ) extends InvoicingRunService {
            protected function contentHashOf(int $docId): string
            {
                return 'hash-' . $docId;
            }
        };
        return [$service, $repos, $applier];
    }

    /** @return array<string, list<string>> id zdroje → začátky vystavených období */
    private static function issuedBySource(RunReport $report): array
    {
        $out = [];
        foreach ($report->lines as $line) {
            if ($line->outcome === RunReport::OUTCOME_ISSUED) {
                $out[$line->sourceId][] = $line->recordId . ':' . $line->periodFrom;
            }
        }
        return $out;
    }

    public function testBothSourcesAreInvoicedAndTheirPeriodsDoNotMix(): void
    {
        // Zakázka i smlouva mají stejné id 6 — id záznamu platí jen ve zdroji.
        [$service, $repos, $applier] = $this->runService([self::record(['number' => 'KA-2026-0006', 'cost_center' => 9])]);
        $report = $service->run(new RunOptions('2026-10-08', now: '2026-10-08 03:17:00'));

        $periods = ['6:2026-08-01', '6:2026-09-01', '6:2026-10-01'];
        $this->assertSame(['workOrders' => $periods, 'buildingContracts' => $periods], self::issuedBySource($report));
        $this->assertSame(6, $applier->applied);

        $workOrderDocs = array_column($repos['workOrders']->periods[6], 'doc');
        $contractDocs = array_column($repos['buildingContracts']->periods[6], 'doc');
        $this->assertCount(3, $workOrderDocs);
        $this->assertCount(3, $contractDocs);
        $this->assertSame([], array_intersect($workOrderDocs, $contractDocs), 'každý zdroj má vlastní doklady');

        // Dimenze: zakázka nese sebe, smlouva jen středisko.
        $this->assertSame(['workOrder' => 6], $applier->canonicals[0]['dimensions']);
        $this->assertSame(['costCenter' => 9], $applier->canonicals[3]['dimensions']);

        // Druhý běh nic nevystaví — evidence obou zdrojů platí zvlášť.
        $this->assertSame([], $service->run(new RunOptions('2026-10-08'))->lines);
    }

    public function testRunOfOneSourceLeavesTheOtherUntouched(): void
    {
        [$service, $repos] = $this->runService([self::record(['number' => 'KA-2026-0006'])]);
        $report = $service->run(new RunOptions('2026-10-08', sourceId: ContractInvoicingSource::ID));

        $this->assertSame(['buildingContracts'], array_keys(self::issuedBySource($report)));
        $this->assertSame([], $repos['workOrders']->periods);
        $this->assertCount(3, $repos['buildingContracts']->periods[6]);

        // Jedna smlouva podle id — zakázka téhož id se nedotkne.
        $one = $service->run(new RunOptions('2026-11-01', sourceId: ContractInvoicingSource::ID, recordId: 6));
        $this->assertSame(['buildingContracts' => ['6:2026-11-01']], self::issuedBySource($one));
        $this->assertSame([], $repos['workOrders']->periods);
    }

    public function testEndedAndUnconfirmedContractsAreNotInvoiced(): void
    {
        [$service, $repos] = $this->runService([
            // Platnost do půlky září: říjnové období začíná po ukončení.
            self::record(['id' => 7, 'number' => 'KA-2026-0007', 'date_end' => '2026-09-15']),
            // Ukončená smlouva a smlouva bez periodicity mezi kandidáty nejsou.
            self::record(['id' => 8, 'number' => 'KA-2026-0008', 'docState' => 70, 'date_end' => '2026-08-31']),
            self::record(['id' => 9, 'number' => 'KA-2026-0009', 'inv_periodicity' => null]),
        ]);
        $report = $service->run(new RunOptions('2026-10-08', sourceId: ContractInvoicingSource::ID));

        $this->assertSame(['buildingContracts' => ['7:2026-08-01', '7:2026-09-01']], self::issuedBySource($report));
        $this->assertSame([7], array_keys($repos['buildingContracts']->periods));
    }
}
