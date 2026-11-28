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

/**
 * Přegenerovat (D24, Q2): koncept vybraného období se nahradí z aktuálního
 * záznamu (`replaceConcept` — doklad si drží id, přílohy a autora). Dostupná,
 * když má záznam období s dokladem v Konceptu; dialog nabízí tato období
 * a upozorní, že ruční úpravy konceptu se ztratí.
 *
 * Stav, který běh odmítne (`\DomainException` — období mezitím změnil jiný
 * běh, doklad už není koncept, předpis nejde sestavit), je chyba pole
 * období (422), ne chyba serveru.
 */
class RegenerateAction extends InvoicingActionBase
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        $source = $this->source($table, $context);
        return $source !== null && $this->overview($source, $record, $context)->regenerable() !== [];
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $texts = $this->texts($context);
        $options = $this->overview($this->requireSource($table, $context), $record, $context)->regenerable();

        return new RecordActionDescriptor(
            $texts->text('action.regenerate', 'Regenerate'),
            dialog: new RecordActionDialog(
                $texts->text('dialog.regenerate', 'Regenerate draft'),
                [
                    RecordActionField::select(
                        self::FIELD_PERIOD,
                        $texts->text('field.period', 'Period'),
                        $options,
                        required: true,
                        default: $options[0]['value'],
                    ),
                    RecordActionField::notice('lostEdits', $texts->text('notice.regenerate', 'Manual edits of the draft will be lost.')),
                ],
                submitLabel: $texts->text('action.regenerate', 'Regenerate'),
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
            $this->service($context)->regenerate($source, $periodId, RunOptions::today());
        } catch (\DomainException $e) {
            throw RecordActionValidationException::field(self::FIELD_PERIOD, $e->getMessage());
        }

        return RecordActionResult::success($texts->text('result.regenerated', 'The draft was regenerated.'));
    }
}
