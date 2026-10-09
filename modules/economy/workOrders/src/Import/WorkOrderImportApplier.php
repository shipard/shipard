<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Import;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Document\DocumentRegistry;
use Shipard\Core\Document\DocumentResult;
use Shipard\Core\Document\TableGateway;
use Shipard\Core\Document\ValidationError;
use Shipard\Module\Core\Exchange\Common\TransactionlessTableGateway;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderRowDocument;

/**
 * Import zakázky — formát `shpd.workOrders.workOrder.v1` (docs/work-orders.md
 * §6, tasks/work-orders-import.md, #110 D9, D25, D26). Jeden request = jedna
 * zakázka včetně řádků předpisu a cílového stavu.
 *
 * Jen založení (I2): zakázka se stejným číslem (libovolný stav) → `skipped`
 * s varováním `work_order_exists`, nic se nemění — reimport jde do
 * resetovaného zdroje. Koncept bez čísla klíč nemá a opakovaný import ho
 * založí znovu.
 *
 * Průběh v jedné transakci, zápisy přes `WorkOrderDocument`
 * a `WorkOrderRowDocument` se stejnými pravidly a zámky jako formulář:
 * hlavička v Konceptu → řádky (zámek řádků potvrzené zakázky ještě neplatí)
 * → přechod do cílového stavu uložením hlavičky znovu; `finished` /
 * `cancelled` projdou přes V pořádku, takže číslo i datum ukončení vznikají
 * stejnou cestou jako ve formuláři. Číslo (I4): `number` se převezme beze
 * změny, `sequenceNumber` jde dokumentu jako `_importSequence` a srovná
 * čítač řady; bez čísla přidělí řada při potvrzení. Odmítnutí dokumentu
 * se překládá na cesty payloadu (`inv_periodicity` →
 * `workOrder.invoicing.periodicity`, `_form` → `workOrder`).
 *
 * `validate` = celý průběh s rollbackem (vzor importu majetku): pravidla
 * potvrzení závisejí na uložené hlavičce a řádcích, dry-run proto dává
 * totožný výsledek jako `apply`. Kontrolu payloadu před zápisem dělá
 * {@see WorkOrderImportVerifier}.
 *
 * DB přístup je v protected metodách (přepsatelné v testech).
 */
class WorkOrderImportApplier
{
    public const FORMAT_ID = 'shpd.workOrders.workOrder';
    public const FORMAT_VERSION = '1';

    public const STATUS_CREATED = 'created';
    public const STATUS_SKIPPED = 'skipped';

    public const WARNING_EXISTS = 'work_order_exists';

    /** Cílový stav payloadu → docState. */
    public const STATES = [
        'draft'     => WorkOrderDocument::STATE_DRAFT,
        'confirmed' => WorkOrderDocument::STATE_CONFIRMED,
        'finished'  => WorkOrderDocument::STATE_FINISHED,
        'cancelled' => WorkOrderDocument::STATE_CANCELLED,
    ];

    /** Klíč hlavičky payloadu → sloupec zakázky (bez `parent`, `sequenceNumber`, `state` — ty řeší import). */
    private const HEAD_COLUMNS = [
        'number'           => 'number',
        'numberSeries'     => 'number_series',
        'title'            => 'title',
        'dateStart'        => 'date_start',
        'dateEnd'          => 'date_end',
        'costCenter'       => 'cost_center',
        'internalNote'     => 'internal_note',
        'customer'         => 'customer',
        'currency'         => 'currency',
        'paymentReference' => 'payment_reference',
    ];

    /** Klíč `invoicing` payloadu → sloupec zakázky. */
    private const INVOICING_COLUMNS = [
        'periodicity'   => 'inv_periodicity',
        'invoiceFrom'   => 'inv_from',
        'docText'       => 'inv_doc_text',
        'docType'       => 'inv_doc_type',
        'numberSeries'  => 'inv_number_series',
        'dueDays'       => 'inv_due_days',
        'timing'        => 'inv_timing',
        'vatMode'       => 'inv_vat_mode',
        'paymentMethod' => 'inv_payment_method',
        'bankAccount'   => 'inv_bank_account',
    ];

    /** Klíč řádku payloadu → sloupec řádku (`unit` jde přes id dohledané verifierem). */
    private const ROW_COLUMNS = [
        'item'        => 'item',
        'description' => 'description',
        'quantity'    => 'quantity',
        'unit'        => 'unit',
        'unitPrice'   => 'unit_price',
        'vatCode'     => 'vat_code',
        'operation'   => 'operation',
        'validFrom'   => 'valid_from',
        'validTo'     => 'valid_to',
    ];

    /** Sloupce, které dokument hlásí mimo mapu payloadu → cesta. */
    private const HEAD_EXTRA_PATHS = [
        'kind'            => 'workOrder.numberSeries',
        'type'            => 'workOrder.numberSeries',
        'parent'          => 'workOrder.parent',
        'sequence_number' => 'workOrder.sequenceNumber',
        'fiscal_year'     => 'workOrder.dateStart',
        'docState'        => 'workOrder.state',
    ];

    /** @param array<string, TableDefinition> $tables */
    public function __construct(
        protected readonly ?\Dibi\Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly ?DataSourceConfig $dsConfig,
        protected readonly ?DocumentRegistry $documents,
        protected readonly array $tables,
        private readonly SchemaValidator $schemaValidator,
        private readonly WorkOrderImportVerifier $verifier,
    ) {
    }

    /** @param array<string, TableDefinition> $tables */
    public static function create(
        \Dibi\Connection $db,
        ?ConfigRuntime $config,
        ?DataSourceConfig $dsConfig,
        ?DocumentRegistry $documents,
        array $tables,
    ): self {
        return new self(
            $db,
            $config,
            $dsConfig,
            $documents,
            $tables,
            new SchemaValidator(SchemaLoader::default()),
            new WorkOrderImportVerifier($db, $config),
        );
    }

    /**
     * Kontrola bez trvalého zápisu — průběh `apply` s rollbackem.
     *
     * @param array<string, mixed> $payload
     */
    public function validate(array $payload): WorkOrderImportResult
    {
        return $this->run($payload, true);
    }

    /**
     * Status 201 = zakázka založena, 200 = přeskočena.
     *
     * @param array<string, mixed> $payload
     */
    public function apply(array $payload): WorkOrderImportResult
    {
        return $this->run($payload, false);
    }

    /** @param array<string, mixed> $payload */
    private function run(array $payload, bool $dryRun): WorkOrderImportResult
    {
        $schemaIssues = $this->schemaValidator->validate($payload, self::FORMAT_ID, self::FORMAT_VERSION);
        if ($schemaIssues !== []) {
            return WorkOrderImportResult::error('schema_invalid', 'Struktura zakázky neodpovídá schématu.', $schemaIssues, 400);
        }
        if ($this->documents === null
            || !isset($this->tables[WorkOrderDocument::TABLE], $this->tables[WorkOrderDocument::ROWS_TABLE])
        ) {
            return WorkOrderImportResult::error('work_orders_unavailable', 'Zakázky nejsou na tomto zdroji dat dostupné — spusťte ds-upgrade.', [], 500);
        }

        $check = $this->verifier->verify($payload);
        if (!$check->isValid()) {
            return WorkOrderImportResult::error('validation_failed', 'Validace zakázky selhala.', $check->errors(), 422);
        }

        $number = trim((string) ($payload['workOrder']['number'] ?? ''));
        if ($check->existingId !== null) {
            return WorkOrderImportResult::ok(self::STATUS_SKIPPED, $check->existingId, $number, [[
                'code'    => self::WARNING_EXISTS,
                'message' => "Zakázka {$number} už v tomto zdroji dat je — import ji nemění.",
                'path'    => 'workOrder.number',
            ]]);
        }

        $this->begin();
        try {
            $result = $this->import($payload, $check);
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
        if ($result->success && !$dryRun) {
            $this->commit();
        } else {
            $this->rollback();
        }
        return $result;
    }

    // ── Průběh importu ──────────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function import(array $payload, WorkOrderImportCheck $check): WorkOrderImportResult
    {
        $wo = $payload['workOrder'];
        $rows = is_array($payload['rows'] ?? null) ? array_values($payload['rows']) : [];
        $targetState = self::STATES[(string) $wo['state']];
        $warnings = $check->warnings();

        // 1. Hlavička v Konceptu — řádky se smí zapsat jen do nepotvrzené zakázky.
        $head = $this->headRow($wo, $check);
        $head['docState'] = WorkOrderDocument::STATE_DRAFT;
        $sequence = $wo['sequenceNumber'] ?? null;
        if ($sequence !== null && self::hasValue($head['number'] ?? null)) {
            $head[WorkOrderDocument::IMPORT_SEQUENCE_KEY] = (int) $sequence;
        }
        $saved = $this->saveHead($head);
        if (!$saved->isSuccess()) {
            return $this->failure('Validace zakázky selhala.', $saved, fn(string $c): string => $this->headPath($c));
        }
        $id = (int) ($saved->getData()['id'] ?? 0);
        $number = $saved->getData()['number'] ?? null;

        // 2. Řádky předpisu v pořadí payloadu.
        foreach ($rows as $index => $row) {
            $saved = $this->saveRow($this->rowRow($row, $id, $index, $check));
            if (!$saved->isSuccess()) {
                return $this->failure(
                    'Validace řádku předpisu selhala.',
                    $saved,
                    static fn(string $c): string => "rows.{$index}." . (array_flip(self::ROW_COLUMNS)[$c] ?? $c),
                    "rows.{$index}",
                );
            }
        }

        // 3. Cílový stav přes V pořádku — číslo a datum ukončení vznikají jako ve formuláři.
        $head['id'] = $id;
        unset($head[WorkOrderDocument::IMPORT_SEQUENCE_KEY]);
        foreach (self::statePath($targetState) as $state) {
            $head['docState'] = $state;
            $saved = $this->saveHead($head);
            if (!$saved->isSuccess()) {
                return $this->failure('Validace zakázky selhala.', $saved, fn(string $c): string => $this->headPath($c));
            }
            $number = $saved->getData()['number'] ?? $number;
        }

        return WorkOrderImportResult::ok(
            self::STATUS_CREATED,
            $id,
            self::hasValue($number) ? (string) $number : null,
            $warnings,
            201,
        );
    }

    /**
     * Stavy, kterými hlavička projde z Konceptu do cílového stavu:
     * koncové stavy přes V pořádku (sada stavů vede do 70 / 30 jen z 40 / 80).
     *
     * @return list<int>
     */
    public static function statePath(int $targetState): array
    {
        return match ($targetState) {
            WorkOrderDocument::STATE_DRAFT     => [],
            WorkOrderDocument::STATE_CONFIRMED => [WorkOrderDocument::STATE_CONFIRMED],
            default                            => [WorkOrderDocument::STATE_CONFIRMED, $targetState],
        };
    }

    /**
     * Řádek hlavičky z payloadu: sloupce podle mapy, měna malými písmeny,
     * nadřazená podle id z verifieru, předpis s přeloženými kódy.
     *
     * @param array<string, mixed> $wo
     * @return array<string, mixed>
     */
    private function headRow(array $wo, WorkOrderImportCheck $check): array
    {
        $row = [];
        foreach (self::HEAD_COLUMNS as $key => $column) {
            if (array_key_exists($key, $wo)) {
                $row[$column] = $wo[$key];
            }
        }
        if (isset($row['currency']) && is_string($row['currency'])) {
            $row['currency'] = strtolower($row['currency']);
        }
        $row['parent'] = $check->parentId;

        $invoicing = is_array($wo['invoicing'] ?? null) ? $wo['invoicing'] : [];
        foreach (self::INVOICING_COLUMNS as $key => $column) {
            $value = $invoicing[$key] ?? null;
            $row[$column] = match ($key) {
                'vatMode'       => $value !== null ? DocumentApplier::vatModeFromCanonical((string) $value) : null,
                'paymentMethod' => $value !== null ? DocumentApplier::paymentMethodFromCanonical((string) $value) : null,
                default         => $value,
            };
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function rowRow(array $row, int $workOrderId, int $index, WorkOrderImportCheck $check): array
    {
        $out = ['work_order' => $workOrderId, 'order_pos' => $index + 1];
        foreach (self::ROW_COLUMNS as $key => $column) {
            if ($key === 'unit') {
                continue;
            }
            if (array_key_exists($key, $row)) {
                $out[$column] = $row[$key];
            }
        }
        $out['unit'] = $check->unitIds[$index] ?? null;
        return $out;
    }

    /** Cesta payloadu pro sloupec hlavičky hlášený dokumentem. */
    private function headPath(string $column): string
    {
        if ($column === ValidationError::FIELD_FORM) {
            return 'workOrder';
        }
        $head = array_flip(self::HEAD_COLUMNS);
        if (isset($head[$column])) {
            return 'workOrder.' . $head[$column];
        }
        $invoicing = array_flip(self::INVOICING_COLUMNS);
        if (isset($invoicing[$column])) {
            return 'workOrder.invoicing.' . $invoicing[$column];
        }
        return self::HEAD_EXTRA_PATHS[$column] ?? 'workOrder.' . $column;
    }

    /**
     * Odmítnutý zápis dokumentu → nálezy s cestou do payloadu.
     *
     * @param \Closure(string): string $path sloupec → cesta
     */
    private function failure(string $message, DocumentResult $saved, \Closure $path, string $formPath = 'workOrder'): WorkOrderImportResult
    {
        $issues = [];
        $validation = $saved->getValidation();
        if ($validation !== null) {
            foreach ($validation->getErrors() as $error) {
                $column = $error->column;
                $issues[] = [
                    'severity' => 'error',
                    'path'     => $column === ValidationError::FIELD_FORM ? $formPath : $path($column),
                    'code'     => $error->code !== '' ? $error->code : 'invalid',
                    'message'  => $error->message,
                ];
            }
        } else {
            $issues[] = [
                'severity' => 'error',
                'path'     => $formPath,
                'code'     => $saved->getDomainErrorCode() ?: 'error',
                'message'  => (string) ($saved->getErrorMessage() ?? 'Uložení selhalo.'),
            ];
        }
        return WorkOrderImportResult::error('validation_failed', $message, $issues, 422);
    }

    private static function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== false;
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    protected function gateway(string $table): TableGateway
    {
        $def = $this->tables[$table] ?? null;
        if ($this->documents === null || $def === null || $this->db === null) {
            throw new \RuntimeException("Tabulka {$table} není dostupná — spusťte ds-upgrade.");
        }
        return new TransactionlessTableGateway(
            $table,
            $this->db,
            $this->documents,
            $def->childTables,
            $this->config,
            $this->dsConfig,
            null,
            $def->docStates,
            $def,
        );
    }

    /**
     * Uložení přes gateway; DomainException z hooků před transakcí gateway
     * (chybějící fiskální rok čítače) se vrací jako doménová chyba, ne 500.
     *
     * @param array<string, mixed> $data
     */
    private function save(string $table, array $data): DocumentResult
    {
        try {
            return $this->gateway($table)->saveDocument($data);
        } catch (\DomainException $e) {
            return DocumentResult::domainError($e->getMessage(), $e->getCode() !== 0 ? (string) $e->getCode() : null);
        }
    }

    /** @param array<string, mixed> $head */
    protected function saveHead(array $head): DocumentResult
    {
        return $this->save(WorkOrderDocument::TABLE, $head);
    }

    /** @param array<string, mixed> $row */
    protected function saveRow(array $row): DocumentResult
    {
        return $this->save(WorkOrderRowDocument::TABLE, $row);
    }

    protected function begin(): void
    {
        $this->db?->begin();
    }

    protected function commit(): void
    {
        $this->db?->commit();
    }

    protected function rollback(): void
    {
        $this->db?->rollback();
    }
}
