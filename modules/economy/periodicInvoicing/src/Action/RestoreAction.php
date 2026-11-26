<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\PeriodicInvoicing\Action;

use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionDialog;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\PeriodicInvoicing\RunOptions;
use Shipard\Module\Economy\PeriodicInvoicing\RunReport;

/**
 * Obnovit (D24): zastavené období (doklad v koši nebo chybí) se vrátí do
 * naplánováno a hned se vystaví během pro tento záznam. Dostupná se
 * zastaveným obdobím; dialog nabízí zastavená období. Výsledek jiný než
 * vystaveno (čeká na podklady, selhalo) je varování se zprávou běhu;
 * `\DomainException` z běhu je chyba pole období (422).
 */
class RestoreAction extends InvoicingActionBase
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        $source = $this->source($table, $context);
        return $source !== null && $this->overview($source, $record, $context)->stopped() !== [];
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $texts = $this->texts($context);
        $options = $this->overview($this->requireSource($table, $context), $record, $context)->stopped();

        return new RecordActionDescriptor(
            $texts->text('action.restore', 'Restore'),
            dialog: new RecordActionDialog(
                $texts->text('dialog.restore', 'Restore period'),
                [
                    RecordActionField::select(
                        self::FIELD_PERIOD,
                        $texts->text('field.period', 'Period'),
                        $options,
                        required: true,
                        default: $options[0]['value'],
                    ),
                ],
                submitLabel: $texts->text('action.restore', 'Restore'),
            ),
        );
    }

    public function execute(string $table, array $record, RecordActionInput $input, RecordActionContext $context): RecordActionResult
    {
        $source = $this->requireSource($table, $context);
        $texts = $this->texts($context);
        $value = $input->value(self::FIELD_PERIOD);
        $periodId = is_scalar($value) ? (int) $value : 0;
        if ($periodId <= 0) {
            throw RecordActionValidationException::field(self::FIELD_PERIOD, $texts->text('error.periodRequired', 'Choose a period.'));
        }

        try {
            $line = $this->service($context)->restore($source, $periodId, RunOptions::today());
        } catch (\DomainException $e) {
            throw RecordActionValidationException::field(self::FIELD_PERIOD, $e->getMessage());
        }

        if ($line->outcome === RunReport::OUTCOME_ISSUED) {
            return RecordActionResult::success($texts->text('result.restored', 'The period was restored — a new draft was issued.'));
        }
        $message = trim((string) $line->message);
        return RecordActionResult::warning($message !== '' ? $message : $texts->text('result.notIssued', 'The period was not issued.'));
    }
}
