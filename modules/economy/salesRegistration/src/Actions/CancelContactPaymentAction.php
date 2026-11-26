<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Actions;

use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Akce **Zrušit kontaktní platbu** na dokladu V pořádku (#171 D8b, U17):
 * příznak byl nastaven omylem. Jen s potvrzením — tržbu dokladu vyrovná
 * záporná tržba s časem zrušení (přechod stavu tu není, čas je
 * `contact_payment_changed_at`, U21). Součet tržeb dokladu je pak nula.
 *
 * V Konceptu a V opravě se příznak ruší zaškrtávátkem ve formuláři; tržba
 * se tam vyrovná až přechodem dokladu.
 */
class CancelContactPaymentAction extends ContactPaymentActionBase
{
    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        return $this->isConfirmed($record) && !empty($record['contact_payment']);
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $t = Texts::of($context->config);

        return new RecordActionDescriptor(
            $t->t('contactPaymentCancel.label', 'Cancel contact payment'),
            RecordActionDescriptor::SECONDARY,
            confirm: $t->t(
                'contactPaymentCancel.confirm',
                'The contact payment of the document is cancelled and a negative sale is sent to the sales registration service to settle the registered one. Continue?',
            ),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        $service = $this->service($context);
        if ($service === null) {
            throw RecordActionValidationException::form(Texts::of($context->config)->t(
                'contactPaymentCancel.errorNoDataSource',
                'Contact payment cannot be cancelled outside a data source.',
            ));
        }
        $outcome = $service->cancel((int) ($record['id'] ?? 0));
        if (!$outcome->isWritten()) {
            throw $this->conflict($context);
        }
        return $this->result($outcome, $context);
    }
}
