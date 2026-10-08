<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\WorkOrders\Invoicing;

use Dibi\Connection;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\NestedTransaction;
use Shipard\Module\Core\Exchange\Common\ApplyResult;
use Shipard\Module\Core\Exchange\Document\DocumentApplier;
use Shipard\Module\Economy\WorkOrders\KindDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderDocument;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Běh periodické fakturace (docs/work-orders.md D5, Q4; tasks §3).
 *
 * Pro každou periodickou zakázku V pořádku s „fakturovat od“ založí
 * chybějící splatná období (`planned`) a vystaví všechna `planned`
 * a `waiting`: InvoiceBuilder → DocumentApplier (koncept, Q1/Q7) → zápis
 * dokladu do období. Zakázka V opravě se přeskočí — její období dožene
 * první běh po návratu do V pořádku.
 *
 *  - Atomicita: zámek řádku období (`FOR UPDATE`), apply a zápis `doc` v
 *    jedné transakci (NestedTransaction; applier uvnitř použije SAVEPOINT).
 *    Nikdy doklad bez vazby na období. Souběh hlídá UNIQUE klíč a zámek.
 *  - Pojistka dohánění (Q4): víc než MAX_CATCHUP dlužných období jedné
 *    zakázky běh nevystaví (`result = catchup`); vystaví je `force`
 *    (akce Vystavit dlužná období, CLI `--work-order`).
 *  - Chyba jedné zakázky / období zastaví jen ji: období zůstane bez
 *    dokladu s `result` a `message`, běh pokračuje.
 *  - `issued` období se nikdy znovu nevystaví — zastavené (doklad v koši)
 *    odzastaví jen Obnovit nebo obnovení dokladu z koše.
 *  - Dry-run nic nezapíše, ani období nezaloží.
 */
class InvoicingRunService
{
    public const MAX_CATCHUP = 3;

    public function __construct(
        protected readonly Connection $db,
        protected readonly ?ConfigRuntime $config,
        protected readonly PeriodRepository $periods,
        protected readonly InvoiceBuilder $builder,
        protected readonly DocumentApplier $applier,
        protected readonly WorkOrderTypes $types,
    ) {
    }

    public function run(RunOptions $options): RunReport
    {
        $report = new RunReport($options);
        foreach ($this->candidates($options->workOrderId) as $workOrder) {
            $this->runWorkOrder($workOrder, $options, $report);
        }
        return $report;
    }

    /**
     * @param array<string, mixed> $workOrder
     */
    private function runWorkOrder(array $workOrder, RunOptions $options, RunReport $report): void
    {
        $workOrderId = (int) $workOrder['id'];
        $kind = $this->kind((int) ($workOrder['kind'] ?? 0));
        $settings = InvoicingSettingsResolver::resolve($workOrder, $kind);
        $periodicity = (string) ($workOrder['inv_periodicity'] ?? '');
        $invoiceFrom = self::isoDate($workOrder['inv_from'] ?? null);
        if (!InvoicingSettings::isPeriodicity($periodicity) || $invoiceFrom === null) {
            $report->add($this->line($workOrder, null, RunReport::OUTCOME_FAILED, message: 'Zakázka nemá periodicitu nebo datum „fakturovat od“.'));
            return;
        }

        $due = PeriodCalendar::duePeriods(
            $invoiceFrom,
            self::isoDate($workOrder['date_end'] ?? null),
            $options->date,
            $periodicity,
            $settings->timing,
        );
        if (!$options->dryRun && $due !== []) {
            $this->periods->ensurePlanned($workOrderId, $due, $options->timestamp());
        }

        $existing = [];
        foreach ($this->periods->listFor($workOrderId) as $row) {
            $existing[(string) self::isoDate($row['period_from'])] = $row;
        }
        // Otevřená období: uložená planned / waiting + (dry-run) splatná,
        // která by běh teprve založil. Od nejstaršího.
        $open = [];
        foreach ($existing as $row) {
            if (in_array((string) $row['state'], [PeriodRepository::STATE_PLANNED, PeriodRepository::STATE_WAITING], true)) {
                $open[(string) self::isoDate($row['period_from'])] = $row;
            }
        }
        if ($options->dryRun) {
            foreach ($due as $period) {
                if (!isset($existing[$period->from])) {
                    $open[$period->from] = ['id' => null, 'period_from' => $period->from, 'period_to' => $period->to, 'state' => PeriodRepository::STATE_PLANNED];
                }
            }
        }
        ksort($open);

        $planned = array_filter($open, static fn(array $row): bool => (string) $row['state'] === PeriodRepository::STATE_PLANNED);
        $blocked = !$options->force && count($planned) > self::MAX_CATCHUP;

        $rows = $this->rowsOf($workOrderId);
        foreach ($open as $periodRow) {
            if ($blocked && (string) $periodRow['state'] === PeriodRepository::STATE_PLANNED) {
                $message = sprintf('Pojistka dohánění: %d dlužných období — vystav je akcí Vystavit dlužná období.', count($planned));
                if (!$options->dryRun && $periodRow['id'] !== null) {
                    $this->periods->markResult((int) $periodRow['id'], PeriodRepository::RESULT_CATCHUP, $message, $options->timestamp());
                }
                $report->add($this->line($workOrder, $periodRow, RunReport::OUTCOME_CATCHUP, message: $message));
                continue;
            }
            $this->issuePeriod($workOrder, $kind, $periodRow, $rows, $options, $report);
        }
    }

    /**
     * Jedno období: v dry-run jen sestavení dokladu, jinak zámek řádku +
     * apply + zápis dokladu v jedné transakci. Výjimka = rollback a zápis
     * výsledku mimo transakci.
     *
     * @param array<string, mixed> $workOrder
     * @param array<string, mixed>|null $kind
     * @param array<string, mixed> $periodRow
     * @param list<array<string, mixed>> $rows
     */
    private function issuePeriod(array $workOrder, ?array $kind, array $periodRow, array $rows, RunOptions $options, RunReport $report): void
    {
        $period = new Period((string) self::isoDate($periodRow['period_from']), (string) self::isoDate($periodRow['period_to']));

        if ($options->dryRun) {
            try {
                $built = $this->builder->build($workOrder, $kind, $period, $rows);
                $report->add($this->line($workOrder, $periodRow, RunReport::OUTCOME_PLANNED, message: $built->canonical['docText'] ?? null));
            } catch (InvoiceBuildException $e) {
                $report->add($this->line($workOrder, $periodRow, RunReport::OUTCOME_FAILED, message: $e->getMessage()));
            }
            return;
        }

        $periodId = (int) $periodRow['id'];
        try {
            $line = NestedTransaction::run($this->db, function () use ($workOrder, $kind, $period, $periodId, $rows, $options): RunLine {
                $locked = $this->periods->lockForUpdate($periodId);
                if ($locked === null || !in_array((string) $locked['state'], [PeriodRepository::STATE_PLANNED, PeriodRepository::STATE_WAITING], true)) {
                    return $this->line($workOrder, ['id' => $periodId, 'period_from' => $period->from, 'period_to' => $period->to], RunReport::OUTCOME_SKIPPED, message: 'Období mezitím vystavil jiný běh.');
                }
                return $this->issueLocked($workOrder, $kind, $period, $locked, $rows, $options);
            });
            $report->add($line);
        } catch (InvoiceBuildException $e) {
            $this->recordFailure($periodRow, $e->reason, $e->getMessage(), $options);
            $report->add($this->line($workOrder, $periodRow, RunReport::OUTCOME_FAILED, message: $e->getMessage()));
        } catch (\Throwable $e) {
            $this->recordFailure($periodRow, PeriodRepository::RESULT_FAILED, $e->getMessage(), $options);
            $report->add($this->line($workOrder, $periodRow, RunReport::OUTCOME_FAILED, message: $e->getMessage()));
        }
    }

    /**
     * Vystavení zamčeného období uvnitř transakce: sestavení, apply,
     * zápis dokladu a otisku obsahu. Období `waiting` (D10) se znovu
     * sestaví podle přispěvatelů — rozšíření v další fázi.
     *
     * @param array<string, mixed> $workOrder
     * @param array<string, mixed>|null $kind
     * @param array<string, mixed> $locked
     * @param list<array<string, mixed>> $rows
     */
    protected function issueLocked(array $workOrder, ?array $kind, Period $period, array $locked, array $rows, RunOptions $options): RunLine
    {
        $built = $this->builder->build($workOrder, $kind, $period, $rows);
        $docId = $this->applyCanonical($built->canonical);
        $hash = $this->contentHashOf($docId);
        $this->periods->markIssued((int) $locked['id'], $docId, $hash, $options->timestamp());

        return $this->line($workOrder, $locked, RunReport::OUTCOME_ISSUED, $docId, $built->canonical['docText'] ?? null);
    }

    /**
     * Uložení kanonického dokladu applierem; neúspěch = výjimka (rollback
     * období).
     *
     * @param array<string, mixed> $canonical
     */
    protected function applyCanonical(array $canonical): int
    {
        $result = $this->applier->apply($canonical);
        if (!$result->success || $result->savedId === null) {
            throw new \RuntimeException(self::applyErrorMessage($result));
        }
        return (int) $result->savedId;
    }

    private static function applyErrorMessage(ApplyResult $result): string
    {
        $message = (string) ($result->errorCode ?? 'apply_failed');
        if ($result->errorMessage !== null && $result->errorMessage !== '') {
            $message .= ': ' . $result->errorMessage;
        }
        $issues = [];
        foreach ($result->canonical['_resolve']['issues'] ?? $result->canonical['issues'] ?? [] as $issue) {
            if (is_array($issue) && ($issue['severity'] ?? '') === 'error') {
                $issues[] = (string) ($issue['path'] ?? '') . ' ' . (string) ($issue['message'] ?? $issue['code'] ?? '');
            }
        }
        if ($issues !== []) {
            $message .= ' (' . implode('; ', array_slice($issues, 0, 5)) . ')';
        }
        return mb_substr($message, 0, 500);
    }

    /**
     * Výsledek selhání mimo transakci: planned období zůstane bez dokladu,
     * waiting si doklad nechá.
     *
     * @param array<string, mixed> $periodRow
     */
    private function recordFailure(array $periodRow, string $result, string $message, RunOptions $options): void
    {
        $id = (int) ($periodRow['id'] ?? 0);
        if ($id <= 0) {
            return;
        }
        $message = mb_substr($message, 0, 500);
        if ((string) ($periodRow['state'] ?? '') === PeriodRepository::STATE_WAITING) {
            $this->periods->markResult($id, $result, $message, $options->timestamp());
        } else {
            $this->periods->markPlanned($id, $result, $message, $options->timestamp());
        }
    }

    /**
     * @param array<string, mixed> $workOrder
     * @param array<string, mixed>|null $periodRow
     */
    protected function line(array $workOrder, ?array $periodRow, string $outcome, ?int $docId = null, ?string $message = null): RunLine
    {
        return new RunLine(
            workOrderId: (int) $workOrder['id'],
            workOrderNumber: (string) ($workOrder['number'] ?? ''),
            workOrderTitle: (string) ($workOrder['title'] ?? ''),
            periodFrom: $periodRow !== null ? self::isoDate($periodRow['period_from'] ?? null) : null,
            periodTo: $periodRow !== null ? self::isoDate($periodRow['period_to'] ?? null) : null,
            outcome: $outcome,
            docId: $docId,
            message: $message,
            periodId: isset($periodRow['id']) ? (int) $periodRow['id'] : null,
        );
    }

    // ── DB přístup (přepsatelný v testech) ──────────────────────────────────

    /**
     * Periodické zakázky V pořádku s „fakturovat od“; `$workOrderId`
     * omezí na jednu (i mimo V pořádku se nevystavuje — výběr ji vynechá).
     *
     * @return list<array<string, mixed>>
     */
    protected function candidates(?int $workOrderId): array
    {
        $periodicTypes = array_values(array_filter(
            array_map('strval', array_keys($this->types->all())),
            fn(string $type): bool => $this->types->invoicing($type) === WorkOrderTypes::INVOICING_PERIODIC,
        ));
        if ($periodicTypes === []) {
            return [];
        }
        $sql = 'SELECT * FROM [' . WorkOrderDocument::TABLE . ']'
            . ' WHERE [docState] = %i AND [type] IN %in AND [inv_from] IS NOT NULL AND [inv_periodicity] IS NOT NULL';
        $args = [WorkOrderDocument::STATE_CONFIRMED, $periodicTypes];
        if ($workOrderId !== null) {
            $sql .= ' AND [id] = %i';
            $args[] = $workOrderId;
        }
        $sql .= ' ORDER BY [id]';
        $rows = $this->db->fetchAll($sql, ...$args);
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** @return array<string, mixed>|null */
    protected function kind(int $kindId): ?array
    {
        if ($kindId <= 0) {
            return null;
        }
        $row = $this->db->fetch('SELECT * FROM [' . KindDocument::TABLE . '] WHERE [id] = %i', $kindId);
        return $row === null ? null : (is_array($row) ? $row : $row->toArray());
    }

    /** @return list<array<string, mixed>> řádky předpisu, order_pos ASC */
    protected function rowsOf(int $workOrderId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM [' . WorkOrderDocument::ROWS_TABLE . '] WHERE [work_order] = %i ORDER BY [order_pos], [id]',
            $workOrderId,
        );
        return array_map(static fn($r): array => is_array($r) ? $r : $r->toArray(), $rows);
    }

    /** Otisk obsahu uloženého dokladu (hlavička + řádky z DB). */
    protected function contentHashOf(int $docId): string
    {
        $head = $this->db->fetch('SELECT * FROM [docs_core_heads] WHERE [id] = %i', $docId);
        $head = $head === null ? [] : (is_array($head) ? $head : $head->toArray());
        $rows = array_map(
            static fn($r): array => is_array($r) ? $r : $r->toArray(),
            $this->db->fetchAll('SELECT * FROM [docs_core_rows] WHERE [doc_head] = %i ORDER BY [order_pos], [id]', $docId),
        );
        return ContentHash::ofDocument($head, $rows);
    }

    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $string = trim((string) ($value ?? ''));
        return $string !== '' ? substr($string, 0, 10) : null;
    }
}
