<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Action;

use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;

/**
 * Vystavit dlužná období (D24, Q4): běh jen pro tento záznam s `force` —
 * vystaví všechna splatná období i přes pojistku dohánění. Dostupná
 * u záznamu, který zdroj vrací mezi kandidáty fakturace.
 */
class IssueDueAction extends InvoicingActionBase
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        $source = $this->source($table, $context);
        return $source !== null && $source->candidates((int) ($record['id'] ?? 0)) !== [];
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        return new RecordActionDescriptor($this->texts($context)->text('action.issueDue', 'Issue due periods'));
    }

    public function execute(string $table, array $record, RecordActionInput $input, RecordActionContext $context): RecordActionResult
    {
        $source = $this->requireSource($table, $context);
        $report = $this->service($context)->run(RunOptions::today(
            sourceId: $source->id(),
            recordId: (int) $record['id'],
            force: true,
        ));

        $failed = $report->count(RunReport::OUTCOME_FAILED);
        $message = $this->texts($context)->text('result.issued', 'Periods issued: {issued}, failed: {failed}.', [
            'issued' => $report->count(RunReport::OUTCOME_ISSUED),
            'failed' => $failed,
        ]);
        return $failed > 0 ? RecordActionResult::warning($message) : RecordActionResult::success($message);
    }
}
