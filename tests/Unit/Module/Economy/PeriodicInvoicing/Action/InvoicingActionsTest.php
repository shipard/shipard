<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\Action;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\PeriodicInvoicing\Action\InvoicingActionBase;
use Shipard\Module\Economy\PeriodicInvoicing\Action\IssueDueAction;
use Shipard\Module\Economy\PeriodicInvoicing\Action\RegenerateAction;
use Shipard\Module\Economy\PeriodicInvoicing\Action\RestoreAction;
use Shipard\Module\Economy\PeriodicInvoicing\Contributor\InvoiceContributorRegistry;
use Shipard\Module\Economy\PeriodicInvoicing\InvoicingRunService;
use Shipard\Module\Economy\PeriodicInvoicing\PeriodRepository;
use Shipard\Module\Economy\PeriodicInvoicing\RunLine;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;
use Shipard\Module\Economy\PeriodicInvoicing\Source\PeriodicInvoicingSource;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeApplier;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeInvoiceBuilder;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakeInvoicingSource;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\FakePeriodRepository;
use Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing\TestableInvoicingRunService;

/**
 * Akce záznamu periodické fakturace (#175 D8): Vystavit dlužná období,
 * Přegenerovat, Obnovit — dostupnost, dialog, výsledek a chyba období.
 * Běh je skutečný engine nad zdroji a evidencí období v paměti; druhý
 * zdroj ověřuje, že akce najde zdroj podle tabulky a sahá jen do jeho
 * evidence.
 */
class InvoicingActionsTest extends TestCase
{
    private const WORK_ORDERS = 'economy_work_orders_heads';
    private const CONTRACTS = 'test_contracts_heads';
    private const KIND = ['id' => 4, 'inv_doc_type' => 'invno', 'inv_number_series' => 1, 'inv_timing' => 'start'];

    private TestableInvoicingRunService $engine;
    private FakeInvoicingSource $workOrders;
    private FakeInvoicingSource $contracts;

    protected function setUp(): void
    {
        $this->workOrders = new FakeInvoicingSource();
        $this->workOrders->records = [$this->record()];
        $this->contracts = new FakeInvoicingSource(
            id: 'contracts',
            table: self::CONTRACTS,
            periodsTable: 'test_contracts_periods',
            recordColumn: 'contract',
            dimensions: static fn(array $record): array => ['place' => 9],
        );
        $this->contracts->records = [$this->record(['number' => 'N-1', 'title' => 'Byt 12'])];
        foreach ([$this->workOrders, $this->contracts] as $source) {
            $source->kinds = [4 => self::KIND];
            $source->rows = [['id' => 1, 'description' => 'Nájem', 'quantity' => 1, 'unit_price' => 12000, 'valid_from' => null, 'valid_to' => null]];
        }

        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => match ($id) {
            'world.base.documentLanguages' => ['cs' => [], 'en' => []],
            'world.base.countries' => ['cz' => ['languages' => ['cs']]],
            default => null,
        });
        $db = $this->createMock(Connection::class);
        $db->method('fetchSingle')->willReturn(0);
        $repos = [];
        foreach ([$this->workOrders, $this->contracts] as $source) {
            $repos[$source->id()] = new FakePeriodRepository($db, $source->periodsTable(), $source->periodsRecordColumn());
        }
        $this->engine = new TestableInvoicingRunService(
            $db,
            $config,
            [$this->workOrders, $this->contracts],
            $repos,
            new FakeInvoiceBuilder($db, $config, 'cz'),
            new FakeApplier(),
            InvoiceContributorRegistry::empty(),
        );
    }

    /** @return array<string, mixed> */
    private function record(array $overrides = []): array
    {
        return array_merge([
            'id' => 6, 'number' => 'S260001', 'title' => 'Nájem', 'customer' => 2, 'currency' => 'czk', 'kind' => 4,
            'docState' => 40, 'date_end' => null, 'inv_periodicity' => 'month', 'inv_from' => self::monthsAgo(2),
            'inv_doc_text' => null, 'cost_center' => null, 'payment_reference' => null, 'inv_doc_type' => null,
            'inv_number_series' => null, 'inv_due_days' => null, 'inv_timing' => null, 'inv_vat_mode' => null,
            'inv_payment_method' => null, 'inv_bank_account' => null,
        ], $overrides);
    }

    /** První den měsíce před `$months` měsíci — akce běží k dnešku (`RunOptions::today`). */
    private static function monthsAgo(int $months): string
    {
        return (new \DateTimeImmutable('first day of this month'))->modify("-{$months} months")->format('Y-m-d');
    }

    /**
     * Akce se švy na zdroje, evidenci období a službu testu.
     *
     * @template T of InvoicingActionBase
     * @param class-string<T> $class
     * @return T
     */
    private function action(string $class, ?InvoicingRunService $service = null): InvoicingActionBase
    {
        $engine = $service ?? $this->engine;
        $repos = $this->engine->repos;
        $sources = $this->engine->sources();
        return match ($class) {
            IssueDueAction::class => new class($sources, $repos, $engine) extends IssueDueAction {
                use ActionSeams;
            },
            RegenerateAction::class => new class($sources, $repos, $engine) extends RegenerateAction {
                use ActionSeams;
            },
            RestoreAction::class => new class($sources, $repos, $engine) extends RestoreAction {
                use ActionSeams;
            },
        };
    }

    private function context(string $table, ?ConfigRuntime $config = null): RecordActionContext
    {
        return new RecordActionContext(
            $table,
            $this->createMock(DataSourceConnection::class),
            $config,
            $this->createMock(DataSourceConfig::class),
            'cs',
            null,
            1,
            false,
        );
    }

    private function input(mixed $period): RecordActionInput
    {
        return new RecordActionInput([InvoicingActionBase::FIELD_PERIOD => $period]);
    }

    /** Běh k dnešku: tři splatná období prvního zdroje a tři druhého. */
    private function issueAll(): void
    {
        $this->engine->run(RunOptions::today());
    }

    // ── Vystavit dlužná období ──────────────────────────────────────────────

    public function testIssueDueIsAvailableForCandidatesOfTheTableSource(): void
    {
        $action = $this->action(IssueDueAction::class);
        $ctx = $this->context(self::WORK_ORDERS);

        $this->assertTrue($action->available(self::WORK_ORDERS, $this->record(), $ctx));
        $this->assertSame('Issue due periods', $action->describe(self::WORK_ORDERS, $this->record(), $ctx)->label);
        $this->assertNull($action->describe(self::WORK_ORDERS, $this->record(), $ctx)->dialog);

        // Záznam mimo kandidáty (zakázka V opravě) a tabulka bez zdroje.
        $this->workOrders->records[0]['docState'] = 80;
        $this->assertFalse($action->available(self::WORK_ORDERS, $this->record(), $ctx));
        $this->assertFalse($action->available('base_persons_persons', ['id' => 6], $this->context('base_persons_persons')));
    }

    public function testIssueDueRunsForcedRunOfOneRecordInItsSource(): void
    {
        // Šest dlužných období = nad pojistkou dohánění; akce je vystaví.
        $this->contracts->records[0]['inv_from'] = self::monthsAgo(5);
        $action = $this->action(IssueDueAction::class);

        $result = $action->execute(self::CONTRACTS, $this->contracts->records[0], new RecordActionInput(), $this->context(self::CONTRACTS));

        $this->assertSame(['message' => 'Periods issued: 6, failed: 0.', 'level' => RecordActionResult::SUCCESS], $result->toArray());
        $this->assertCount(6, $this->engine->repos['contracts']->periods[6]);
        $this->assertSame([], $this->engine->fakeRepo->periods, 'evidence zakázek beze změny');
        $this->assertSame(['place' => 9], $this->engine->fakeApplier->canonicals[0]['dimensions']);
    }

    public function testIssueDueWithFailedPeriodIsAWarning(): void
    {
        $this->engine->fakeApplier->failFor = [self::monthsAgo(1)];
        $result = $this->action(IssueDueAction::class)
            ->execute(self::WORK_ORDERS, $this->record(), new RecordActionInput(), $this->context(self::WORK_ORDERS));

        $this->assertSame(['message' => 'Periods issued: 2, failed: 1.', 'level' => RecordActionResult::WARNING], $result->toArray());
    }

    public function testResultTextComesFromTheCatalog(): void
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn(string $id): mixed => $id === 'economy.periodicInvoicing.viewerLabels'
            ? ['result.issued' => ['name' => 'Vystaveno období: {issued}, selhalo: {failed}.'], 'action.issueDue' => ['name' => 'Vystavit dlužná období']]
            : null);
        $action = $this->action(IssueDueAction::class);
        $ctx = $this->context(self::WORK_ORDERS, $config);

        $this->assertSame('Vystavit dlužná období', $action->describe(self::WORK_ORDERS, $this->record(), $ctx)->label);
        $this->assertSame('Vystaveno období: 3, selhalo: 0.', $action->execute(self::WORK_ORDERS, $this->record(), new RecordActionInput(), $ctx)->message);
    }

    // ── Přegenerovat ────────────────────────────────────────────────────────

    public function testRegenerateOffersPeriodsWithADraft(): void
    {
        $action = $this->action(RegenerateAction::class);
        $ctx = $this->context(self::WORK_ORDERS);
        $this->assertFalse($action->available(self::WORK_ORDERS, $this->record(), $ctx), 'bez období');

        $this->issueAll();
        // Nejnovější období koncept, starší potvrzený doklad, nejstarší v koši.
        [$new, $mid, $old] = array_values(array_reverse($this->engine->fakeRepo->periods[6]));
        $this->engine->fakeRepo->docs = [
            $new['doc'] => ['id' => $new['doc'], 'docState' => 10, 'doc_type' => 'invno', 'doc_number' => '!0000001003'],
            $mid['doc'] => ['id' => $mid['doc'], 'docState' => 40, 'doc_type' => 'invno', 'doc_number' => 'FV260002'],
            $old['doc'] => ['id' => $old['doc'], 'docState' => 90, 'doc_type' => 'invno', 'doc_number' => null],
        ];
        $action = $this->action(RegenerateAction::class);
        $this->assertTrue($action->available(self::WORK_ORDERS, $this->record(), $ctx));

        $descriptor = $action->describe(self::WORK_ORDERS, $this->record(), $ctx);
        $this->assertSame('Regenerate', $descriptor->label);
        $this->assertNull($descriptor->confirm);
        $this->assertSame('Regenerate draft', $descriptor->dialog->title);
        $this->assertSame(['period', 'lostEdits'], array_column($descriptor->dialog->toArray()['fields'], 'id'));
        [$select, $notice] = $descriptor->dialog->fields;
        $this->assertSame('select', $select->type);
        $this->assertTrue($select->required);
        $label = \Shipard\Core\I18n\DisplayDate::date($new['period_from'], 'cs') . ' – ' . \Shipard\Core\I18n\DisplayDate::date($new['period_to'], 'cs');
        $this->assertSame([['value' => (string) $new['id'], 'label' => $label . ' — draft #' . $new['doc']]], $select->options);
        $this->assertSame((string) $new['id'], $select->default);
        $this->assertSame('notice', $notice->type);
        $this->assertSame('Manual edits of the draft will be lost.', $notice->label);

        // Druhý zdroj má vlastní evidenci — jeho koncepty tu nejsou.
        $this->assertFalse($this->action(RegenerateAction::class)->available(self::CONTRACTS, $this->contracts->records[0], $this->context(self::CONTRACTS)));
    }

    public function testRegenerateReplacesTheDraftOfTheChosenPeriod(): void
    {
        $this->issueAll();
        $period = end($this->engine->fakeRepo->periods[6]);
        $this->engine->fakeRepo->docs = [$period['doc'] => ['id' => $period['doc'], 'docState' => 10, 'doc_type' => 'invno']];
        $before = $this->engine->fakeApplier->applied;

        $result = $this->action(RegenerateAction::class)
            ->execute(self::WORK_ORDERS, $this->record(), $this->input((string) $period['id']), $this->context(self::WORK_ORDERS));

        $this->assertSame(['message' => 'The draft was regenerated.', 'level' => RecordActionResult::SUCCESS], $result->toArray());
        $this->assertSame($before + 1, $this->engine->fakeApplier->applied);
        $last = end($this->engine->fakeApplier->canonicals);
        $this->assertSame($period['doc'], $last['applyOptions']['replaceConcept']);
        $this->assertSame(['workOrder' => 6], $last['dimensions'], 'zdroj podle tabulky akce');
    }

    public function testRegenerateRefusalIsAnErrorOfThePeriodField(): void
    {
        $this->issueAll();
        $period = end($this->engine->fakeRepo->periods[6]);
        $action = $this->action(RegenerateAction::class);
        $ctx = $this->context(self::WORK_ORDERS);

        // Doklad už není koncept (409), období neexistuje (404), nevybráno.
        $this->engine->fakeRepo->docs = [$period['doc'] => ['id' => $period['doc'], 'docState' => 40, 'doc_type' => 'invno']];
        foreach ([
            [(string) $period['id'], 'Přegenerovat jde jen období s dokladem v Konceptu.'],
            ['999', 'Období neexistuje.'],
            [null, 'Choose a period.'],
            ['abc', 'Choose a period.'],
        ] as [$value, $message]) {
            try {
                $action->execute(self::WORK_ORDERS, $this->record(), $this->input($value), $ctx);
                $this->fail($message);
            } catch (RecordActionValidationException $e) {
                $this->assertSame(['period' => $message], $e->fieldErrors());
            }
        }
    }

    public function testBuildFailureDuringRegenerateIsAFieldErrorToo(): void
    {
        $this->issueAll();
        $period = end($this->engine->fakeRepo->periods[6]);
        $this->engine->fakeRepo->docs = [$period['doc'] => ['id' => $period['doc'], 'docState' => 10, 'doc_type' => 'invno']];
        // Předpis mezitím přišel o řádky → 422 z běhu.
        $this->workOrders->rows = [];

        $this->expectException(RecordActionValidationException::class);
        $this->action(RegenerateAction::class)
            ->execute(self::WORK_ORDERS, $this->record(), $this->input((string) $period['id']), $this->context(self::WORK_ORDERS));
    }

    // ── Obnovit ─────────────────────────────────────────────────────────────

    public function testRestoreOffersStoppedPeriodsAndIssuesANewDraft(): void
    {
        $this->issueAll();
        $action = $this->action(RestoreAction::class);
        $ctx = $this->context(self::WORK_ORDERS);
        $periods = array_values($this->engine->fakeRepo->periods[6]);
        $this->engine->fakeRepo->docs = [];
        foreach ($periods as $period) {
            $this->engine->fakeRepo->docs[$period['doc']] = ['id' => $period['doc'], 'docState' => 10, 'doc_type' => 'invno'];
        }
        $this->assertFalse($action->available(self::WORK_ORDERS, $this->record(), $ctx), 'žádné zastavené období');

        // Doklad prostředního období v koši = zastaveno.
        $stopped = $periods[1];
        $this->engine->fakeRepo->docs[$stopped['doc']]['docState'] = 90;
        $action = $this->action(RestoreAction::class);
        $this->assertTrue($action->available(self::WORK_ORDERS, $this->record(), $ctx));

        $descriptor = $action->describe(self::WORK_ORDERS, $this->record(), $ctx);
        $this->assertSame(['Restore', 'Restore period'], [$descriptor->label, $descriptor->dialog->title]);
        $this->assertCount(1, $descriptor->dialog->fields);
        $label = \Shipard\Core\I18n\DisplayDate::date($stopped['period_from'], 'cs') . ' – ' . \Shipard\Core\I18n\DisplayDate::date($stopped['period_to'], 'cs');
        $this->assertSame([['value' => (string) $stopped['id'], 'label' => $label]], $descriptor->dialog->fields[0]->options);

        $result = $action->execute(self::WORK_ORDERS, $this->record(), $this->input((string) $stopped['id']), $ctx);
        $this->assertSame(RecordActionResult::SUCCESS, $result->level);
        $this->assertSame('The period was restored — a new draft was issued.', $result->message);
        $restored = $this->engine->fakeRepo->periods[6][$stopped['period_from']];
        $this->assertSame('issued', $restored['state']);
        $this->assertNotSame($stopped['doc'], $restored['doc']);
    }

    public function testRestoreOutcomeOtherThanIssuedIsAWarningWithTheRunMessage(): void
    {
        $service = new class extends InvoicingRunService {
            public function __construct()
            {
            }

            public function restore(PeriodicInvoicingSource $source, int $periodId, RunOptions $options): RunLine
            {
                return new RunLine($source->id(), 6, 'S260001 Nájem', '2026-09-01', '2026-09-30', RunReport::OUTCOME_WAITING, 605, 'Čeká na odečty.', $periodId);
            }
        };
        $result = $this->action(RestoreAction::class, $service)
            ->execute(self::WORK_ORDERS, $this->record(), $this->input('2'), $this->context(self::WORK_ORDERS));

        $this->assertSame(['message' => 'Čeká na odečty.', 'level' => RecordActionResult::WARNING], $result->toArray());
    }

    public function testRestoreRefusalIsAnErrorOfThePeriodField(): void
    {
        $this->issueAll();
        $period = end($this->engine->fakeRepo->periods[6]);
        $this->engine->fakeRepo->docs = [$period['doc'] => ['id' => $period['doc'], 'docState' => 10, 'doc_type' => 'invno']];

        try {
            $this->action(RestoreAction::class)
                ->execute(self::WORK_ORDERS, $this->record(), $this->input((string) $period['id']), $this->context(self::WORK_ORDERS));
            $this->fail('vystavené období s živým dokladem');
        } catch (RecordActionValidationException $e) {
            $this->assertSame(['period' => 'Obnovit jde jen zastavené období — doklad je smazaný nebo chybí.'], $e->fieldErrors());
        }
    }

    // ── kontext bez služeb dokladů ──────────────────────────────────────────

    public function testExecuteWithoutDocumentServicesInTheContextFails(): void
    {
        // Mimo endpoint akce (detail vieweru) kontext registr dokumentů
        // nenese — provedení skončí výjimkou, endpoint z ní udělá 500.
        $sources = $this->engine->sources();
        $action = new class($sources) extends IssueDueAction {
            public function __construct(private readonly InvoicingSources $fakeSources)
            {
            }

            protected function sources(RecordActionContext $context): InvoicingSources
            {
                return $this->fakeSources;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('document registry');
        $action->execute(self::WORK_ORDERS, $this->record(), new RecordActionInput(), $this->context(self::WORK_ORDERS));
    }

    public function testActionsAreCreatableWithoutArguments(): void
    {
        foreach ([IssueDueAction::class, RegenerateAction::class, RestoreAction::class] as $class) {
            $this->assertInstanceOf(InvoicingActionBase::class, new $class());
        }
    }
}

/** Švy akce: zdroje, evidence období a služba běhu z testu. */
trait ActionSeams
{
    /**
     * @param array<string, PeriodRepository> $repos
     */
    public function __construct(
        private readonly InvoicingSources $fakeSources,
        private readonly array $repos,
        private readonly InvoicingRunService $fakeService,
    ) {
    }

    protected function sources(RecordActionContext $context): InvoicingSources
    {
        return $this->fakeSources;
    }

    protected function periods(PeriodicInvoicingSource $source, RecordActionContext $context): PeriodRepository
    {
        return $this->repos[$source->id()];
    }

    protected function service(RecordActionContext $context): InvoicingRunService
    {
        return $this->fakeService;
    }
}
