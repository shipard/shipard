<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\ValidationResult;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportApplier;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Applier nad pamětí: zápisy dokumentů se zaznamenávají, DB nahrazují pole.
 * `saveHead` napodobuje WorkOrderDocument jen v tom, co applier čte zpět:
 * id nové hlavičky a číslo přidělené při přechodu do V pořádku.
 */
class RecordingWorkOrderImportApplier extends WorkOrderImportApplier
{
    public TestableWorkOrderImportVerifier $spyVerifier;
    /** @var list<array<string, mixed>> */
    public array $savedHeads = [];
    /** @var list<array<string, mixed>> */
    public array $savedRows = [];
    /** @var list<string> */
    public array $log = [];
    /** @var \Closure(array<string, mixed>, int): ?ValidationResult|null odmítnutí n-tého uložení hlavičky */
    public ?\Closure $headFailure = null;
    /** @var \Closure(array<string, mixed>, int): ?ValidationResult|null odmítnutí n-tého uložení řádku */
    public ?\Closure $rowFailure = null;
    public ?string $headDomainError = null;
    public string $assignedNumber = 'P2026001';

    private int $nextId = 500;

    /** @param array<string, TableDefinition>|null $tables */
    public function __construct(?array $tables = null)
    {
        $def = static fn(int $tableId, string $name): TableDefinition => TableDefinition::fromArray([
            'tableId' => $tableId,
            'name'    => $name,
            'columns' => [['id' => 'id', 'name' => 'ID', 'type' => 'int', 'primaryKey' => true, 'autoIncrement' => true]],
        ]);
        $items = [
            WorkOrderTypes::CFG_ITEM => [
                'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
                'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
                'overhead' => ['name' => 'Interní průběžná', 'external' => false, 'oneOff' => false, 'invoicing' => null],
            ],
            'world.base.currencies' => ['czk' => ['name' => 'Kč'], 'eur' => ['name' => '€']],
        ];
        // Konstruktor ConfigRuntime je privátní — anonymní potomek nad pamětí (vzor TestAssetPlanService).
        $config = new class($items) extends ConfigRuntime {
            /** @param array<string, mixed> $items */
            public function __construct(private readonly array $items)
            {
            }

            public function cfgItem(string $id): mixed
            {
                return $this->items[$id] ?? null;
            }
        };
        $this->spyVerifier = new TestableWorkOrderImportVerifier(null, $config);
        $this->spyVerifier->series = [
            3 => ['id' => 3, 'kind' => 13, 'type' => 'periodic', 'reset_scope' => 'fiscal_year', 'docState' => 40],
            1 => ['id' => 1, 'kind' => 11, 'type' => 'project', 'reset_scope' => 'fiscal_year', 'docState' => 40],
            2 => ['id' => 2, 'kind' => 12, 'type' => 'overhead', 'reset_scope' => 'none', 'docState' => 40],
        ];
        $this->spyVerifier->kinds = [
            13 => ['id' => 13, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 5],
            11 => ['id' => 11, 'type' => 'project'],
            12 => ['id' => 12, 'type' => 'overhead'],
        ];
        $this->spyVerifier->docSeries = [5 => ['id' => 5, 'doc_type' => 'invno', 'docState' => 40]];
        $this->spyVerifier->references = [
            'economy_codebooks_cost_centers' => [2],
            'base_persons_persons'           => [41],
            'economy_items'                  => [15],
            'economy_codebooks_bank_accounts' => [1],
        ];
        $this->spyVerifier->units = ['pcs' => 7, 'hr' => 8];
        $this->spyVerifier->vatCodes = ['cz-110', 'cz-217'];
        $this->spyVerifier->workOrders = ['Z260001' => ['id' => 50, 'docState' => 40]];

        parent::__construct(
            null,
            $config,
            null,
            new DocumentRegistry(),
            $tables ?? [
                'economy_work_orders_heads' => $def(460, 'Work orders'),
                'economy_work_orders_rows'  => $def(461, 'Work order rows'),
            ],
            new SchemaValidator(SchemaLoader::default()),
            $this->spyVerifier,
        );
    }

    protected function saveHead(array $head): DocumentResult
    {
        $this->savedHeads[] = $head;
        $n = count($this->savedHeads);
        if ($this->headDomainError !== null) {
            return DocumentResult::domainError($this->headDomainError);
        }
        $failure = $this->headFailure !== null ? ($this->headFailure)($head, $n) : null;
        if ($failure !== null) {
            return DocumentResult::validationFailed($failure);
        }
        $data = $head;
        unset($data[WorkOrderDocument::IMPORT_SEQUENCE_KEY]);
        if (empty($data['id'])) {
            $data['id'] = $this->nextId++;
        } elseif ((int) $data['docState'] === WorkOrderDocument::STATE_CONFIRMED && empty($data['number'])) {
            $data['number'] = $this->assignedNumber;
        }
        return DocumentResult::ok($data);
    }

    protected function saveRow(array $row): DocumentResult
    {
        $this->savedRows[] = $row;
        $failure = $this->rowFailure !== null ? ($this->rowFailure)($row, count($this->savedRows)) : null;
        if ($failure !== null) {
            return DocumentResult::validationFailed($failure);
        }
        return DocumentResult::ok($row + ['id' => $this->nextId++]);
    }

    protected function begin(): void
    {
        $this->log[] = 'begin';
    }

    protected function commit(): void
    {
        $this->log[] = 'commit';
    }

    protected function rollback(): void
    {
        $this->log[] = 'rollback';
    }
}

/**
 * Import zakázky `shpd.workOrders.workOrder.v1` (tasks/work-orders-import.md
 * §2): hlavička v Konceptu → řádky → cílový stav přes V pořádku, převzaté
 * číslo s markerem čítače, číslo z řady bez `number`, přeskočení existující,
 * nadřazená podle čísla, dry-run s rollbackem, překlad odmítnutí dokumentu
 * na cesty payloadu. Pravidla dokumentu kryjí testy dokumentů.
 */
class WorkOrderImportApplierTest extends TestCase
{
    private RecordingWorkOrderImportApplier $applier;

    protected function setUp(): void
    {
        $this->applier = new RecordingWorkOrderImportApplier();
    }

    /**
     * @param array<string, mixed> $head
     * @param list<array<string, mixed>>|null $rows
     * @return array<string, mixed>
     */
    private function payload(array $head = [], ?array $rows = null): array
    {
        return [
            'format'    => 'shpd.workOrders.workOrder.v1',
            'workOrder' => $head + [
                'number'           => 'S260001',
                'sequenceNumber'   => 1,
                'numberSeries'     => 3,
                'title'            => 'Nájem kanceláře',
                'state'            => 'confirmed',
                'dateStart'        => '2026-01-01',
                'dateEnd'          => null,
                'costCenter'       => 2,
                'internalNote'     => 'poznámka',
                'customer'         => 41,
                'currency'         => 'CZK',
                'paymentReference' => '2026001',
                'parent'           => null,
                'invoicing'        => [
                    'periodicity' => 'month', 'invoiceFrom' => '2026-11-01', 'docText' => 'Nájem {období}',
                    'docType' => null, 'numberSeries' => 5, 'dueDays' => 10, 'timing' => 'start',
                    'vatMode' => 'fromBase', 'paymentMethod' => 'bankTransfer', 'bankAccount' => 1,
                ],
            ],
            'rows' => $rows ?? [
                ['item' => 15, 'description' => 'Nájem', 'quantity' => 1, 'unit' => 'pcs', 'unitPrice' => 4500,
                    'vatCode' => 'cz-110', 'operation' => null, 'validFrom' => null, 'validTo' => null],
                ['item' => null, 'description' => 'Služby', 'quantity' => 2, 'unit' => 'hr', 'unitPrice' => 800,
                    'vatCode' => 'cz-110', 'operation' => null, 'validFrom' => '2026-01-01', 'validTo' => null],
            ],
        ];
    }

    public function testCreatesConfirmedWorkOrderWithRowsAndImportedNumber(): void
    {
        $result = $this->applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->statusCode);
        $this->assertSame(['status' => 'created', 'workOrderId' => 500, 'number' => 'S260001', 'warnings' => []], $result->toArray());
        $this->assertSame(['begin', 'commit'], $this->applier->log);

        $heads = $this->applier->savedHeads;
        $this->assertCount(2, $heads, 'Koncept, pak V pořádku');

        $draft = $heads[0];
        $this->assertSame(10, $draft['docState']);
        $this->assertArrayNotHasKey('id', $draft);
        $this->assertSame('S260001', $draft['number']);
        $this->assertSame(1, $draft[WorkOrderDocument::IMPORT_SEQUENCE_KEY]);
        $this->assertArrayNotHasKey('sequence_number', $draft, 'pořadí a rok doplní dokument z markeru');
        $this->assertSame(3, $draft['number_series']);
        $this->assertSame('czk', $draft['currency'], 'měna malými písmeny');
        $this->assertSame('2026001', $draft['payment_reference']);
        $this->assertSame(2, $draft['cost_center']);
        $this->assertSame('poznámka', $draft['internal_note']);
        $this->assertNull($draft['parent']);
        $this->assertSame('month', $draft['inv_periodicity']);
        $this->assertSame('2026-11-01', $draft['inv_from']);
        $this->assertSame('Nájem {období}', $draft['inv_doc_text']);
        $this->assertNull($draft['inv_doc_type'], 'typ dokladu z druhu');
        $this->assertSame(5, $draft['inv_number_series']);
        $this->assertSame(10, $draft['inv_due_days']);
        $this->assertSame('start', $draft['inv_timing']);
        $this->assertSame(1, $draft['inv_vat_mode'], 'fromBase → 1');
        $this->assertSame(1, $draft['inv_payment_method'], 'bankTransfer → 1');
        $this->assertSame(1, $draft['inv_bank_account']);
        $this->assertArrayNotHasKey('state', $draft);
        $this->assertArrayNotHasKey('sequenceNumber', $draft);

        $confirm = $heads[1];
        $this->assertSame(500, $confirm['id']);
        $this->assertSame(40, $confirm['docState']);
        $this->assertArrayNotHasKey(WorkOrderDocument::IMPORT_SEQUENCE_KEY, $confirm);
        $this->assertSame('S260001', $confirm['number']);

        $rows = $this->applier->savedRows;
        $this->assertCount(2, $rows);
        $this->assertSame([500, 500], array_column($rows, 'work_order'));
        $this->assertSame([1, 2], array_column($rows, 'order_pos'));
        $this->assertSame([7, 8], array_column($rows, 'unit'), 'jednotky jako id z verifieru');
        $this->assertSame(15, $rows[0]['item']);
        $this->assertNull($rows[1]['item']);
        $this->assertSame(4500, $rows[0]['unit_price']);
        $this->assertSame('cz-110', $rows[0]['vat_code']);
        $this->assertSame('2026-01-01', $rows[1]['valid_from']);
        $this->assertArrayNotHasKey('unitPrice', $rows[0]);
    }

    public function testFinishedAndCancelledGoThroughConfirmed(): void
    {
        $result = $this->applier->apply($this->payload(['state' => 'finished', 'dateEnd' => '2026-12-31']));
        $this->assertTrue($result->success);
        $this->assertSame([10, 40, 70], array_column($this->applier->savedHeads, 'docState'));
        $this->assertSame('2026-12-31', $this->applier->savedHeads[2]['date_end']);

        $applier = new RecordingWorkOrderImportApplier();
        $applier->apply($this->payload(['state' => 'cancelled']));
        $this->assertSame([10, 40, 30], array_column($applier->savedHeads, 'docState'));
    }

    public function testDraftStaysDraftAndNeedsNothing(): void
    {
        $payload = $this->payload(['state' => 'draft', 'number' => null, 'sequenceNumber' => null, 'invoicing' => null], []);
        $result = $this->applier->apply($payload);

        $this->assertTrue($result->success);
        $this->assertSame(['status' => 'created', 'workOrderId' => 500, 'number' => null, 'warnings' => []], $result->toArray());
        $this->assertSame([10], array_column($this->applier->savedHeads, 'docState'));
        $this->assertArrayNotHasKey(WorkOrderDocument::IMPORT_SEQUENCE_KEY, $this->applier->savedHeads[0]);
        $this->assertNull($this->applier->savedHeads[0]['inv_periodicity']);
        $this->assertSame([], $this->applier->savedRows);
    }

    public function testMissingNumberIsAssignedBySeriesAtConfirmation(): void
    {
        $result = $this->applier->apply($this->payload(['number' => null, 'sequenceNumber' => null]));

        $this->assertTrue($result->success);
        $this->assertSame('P2026001', $result->number, 'číslo z přechodu Koncept → V pořádku');
        $this->assertSame([], $result->warnings);
        $this->assertNull($this->applier->savedHeads[0]['number']);
        $this->assertArrayNotHasKey(WorkOrderDocument::IMPORT_SEQUENCE_KEY, $this->applier->savedHeads[0]);
    }

    public function testNumberWithoutSequenceOnlyWarns(): void
    {
        $result = $this->applier->apply($this->payload(['sequenceNumber' => null]));

        $this->assertTrue($result->success);
        $this->assertSame(['counter_not_synced'], array_column($result->warnings, 'code'));
        $this->assertSame('workOrder.sequenceNumber', $result->warnings[0]['path']);
        $this->assertArrayNotHasKey(WorkOrderDocument::IMPORT_SEQUENCE_KEY, $this->applier->savedHeads[0]);
        $this->assertSame('S260001', $result->number);
    }

    public function testExistingNumberIsSkippedWithoutWriting(): void
    {
        $this->applier->spyVerifier->workOrders['S260001'] = ['id' => 31, 'docState' => 40];

        $result = $this->applier->apply($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame(200, $result->statusCode);
        $this->assertSame('skipped', $result->status);
        $this->assertSame(31, $result->workOrderId);
        $this->assertSame('S260001', $result->number);
        $this->assertSame(['work_order_exists'], array_column($result->warnings, 'code'));
        $this->assertSame([], $this->applier->log, 'bez transakce');
        $this->assertSame([], $this->applier->savedHeads);
    }

    public function testParentIsResolvedByNumber(): void
    {
        $payload = $this->payload([
            'numberSeries' => 1, 'parent' => 'Z260001', 'invoicing' => null,
            'number' => 'Z260002', 'sequenceNumber' => 2,
        ], []);
        $result = $this->applier->apply($payload);

        $this->assertTrue($result->success, json_encode($result->issues));
        $this->assertSame(50, $this->applier->savedHeads[0]['parent']);
    }

    public function testValidateRunsWholeFlowAndRollsBack(): void
    {
        $result = $this->applier->validate($this->payload());

        $this->assertTrue($result->success);
        $this->assertSame(201, $result->statusCode);
        $this->assertSame('created', $result->status);
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
        $this->assertCount(2, $this->applier->savedHeads);
        $this->assertCount(2, $this->applier->savedRows);
    }

    public function testSchemaAndVerifierErrors(): void
    {
        $payload = $this->payload();
        $payload['workOrder']['state'] = 'weird';
        $result = $this->applier->apply($payload);
        $this->assertFalse($result->success);
        $this->assertSame(400, $result->statusCode);
        $this->assertSame('schema_invalid', $result->errorCode);
        $this->assertSame('workOrder.state', $result->issues[0]['path']);
        $this->assertSame([], $this->applier->log);

        $result = $this->applier->apply($this->payload(['customer' => 98]));
        $this->assertFalse($result->success);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame([['workOrder.customer', 'customer_not_found']], array_map(static fn(array $i): array => [$i['path'], $i['code']], $result->issues));
        $this->assertSame([], $this->applier->log);
    }

    public function testUnavailableWithoutWorkOrderTables(): void
    {
        $applier = new RecordingWorkOrderImportApplier([]);
        $result = $applier->apply($this->payload());
        $this->assertFalse($result->success);
        $this->assertSame(500, $result->statusCode);
        $this->assertSame('work_orders_unavailable', $result->errorCode);
    }

    public function testHeadRejectionIsMappedToPayloadPaths(): void
    {
        $this->applier->headFailure = static function (array $head, int $n): ?ValidationResult {
            if ($n !== 2) {
                return null;
            }
            $v = new ValidationResult();
            $v->addError('inv_periodicity', 'Periodicita je povinná', 'required');
            $v->addError('_form', 'Chybí řádky', 'rows_required');
            $v->addError('customer', 'Zákazník je povinný', 'required');
            $v->addError('kind', 'x', 'y');
            $v->addError('number', 'Duplicitní', 'duplicate');
            return $v;
        };

        $result = $this->applier->apply($this->payload());

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame('validation_failed', $result->errorCode);
        $this->assertSame(
            [
                ['workOrder.invoicing.periodicity', 'required'],
                ['workOrder', 'rows_required'],
                ['workOrder.customer', 'required'],
                ['workOrder.numberSeries', 'y'],
                ['workOrder.number', 'duplicate'],
            ],
            array_map(static fn(array $i): array => [$i['path'], $i['code']], $result->issues),
        );
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
    }

    public function testRowRejectionIsMappedToRowPath(): void
    {
        $this->applier->rowFailure = static function (array $row, int $n): ?ValidationResult {
            if ($n !== 2) {
                return null;
            }
            $v = new ValidationResult();
            $v->addError('valid_to', 'Platnost do nesmí být dříve než od', 'invalid_range');
            $v->addError('_form', 'Zamčeno', 'locked');
            return $v;
        };

        $result = $this->applier->apply($this->payload());

        $this->assertFalse($result->success);
        $this->assertSame(
            [['rows.1.validTo', 'invalid_range'], ['rows.1', 'locked']],
            array_map(static fn(array $i): array => [$i['path'], $i['code']], $result->issues),
        );
        $this->assertCount(1, $this->applier->savedHeads, 'potvrzení už neproběhlo');
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
    }

    public function testDomainErrorBecomesIssue(): void
    {
        $this->applier->headDomainError = 'Pro datum zahájení není založený fiskální rok — čítač řady nejde srovnat.';
        $result = $this->applier->apply($this->payload());

        $this->assertFalse($result->success);
        $this->assertSame(422, $result->statusCode);
        $this->assertSame('workOrder', $result->issues[0]['path']);
        $this->assertSame('error', $result->issues[0]['code']);
        $this->assertStringContainsString('fiskální rok', $result->issues[0]['message']);
        $this->assertSame(['begin', 'rollback'], $this->applier->log);
    }

    public function testInvoicingCodesAreTranslated(): void
    {
        $payload = $this->payload();
        $payload['workOrder']['invoicing']['vatMode'] = 'none';
        $payload['workOrder']['invoicing']['paymentMethod'] = 'cash';
        $this->applier->apply($payload);
        $this->assertSame(0, $this->applier->savedHeads[0]['inv_vat_mode']);
        $this->assertSame(0, $this->applier->savedHeads[0]['inv_payment_method']);

        $applier = new RecordingWorkOrderImportApplier();
        $payload['workOrder']['invoicing']['vatMode'] = 'fromTotal';
        $payload['workOrder']['invoicing']['paymentMethod'] = 'paymentGateway';
        $applier->apply($payload);
        $this->assertSame(2, $applier->savedHeads[0]['inv_vat_mode']);
        $this->assertSame(5, $applier->savedHeads[0]['inv_payment_method']);
    }

    public function testStatePath(): void
    {
        $this->assertSame([], WorkOrderImportApplier::statePath(10));
        $this->assertSame([40], WorkOrderImportApplier::statePath(40));
        $this->assertSame([40, 70], WorkOrderImportApplier::statePath(70));
        $this->assertSame([40, 30], WorkOrderImportApplier::statePath(30));
    }
}
