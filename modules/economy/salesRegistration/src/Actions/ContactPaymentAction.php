<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Actions;

use Shipard\Core\RecordAction\RecordActionContext;
use Shipard\Core\RecordAction\RecordActionDescriptor;
use Shipard\Core\RecordAction\RecordActionDialog;
use Shipard\Core\RecordAction\RecordActionField;
use Shipard\Core\RecordAction\RecordActionInput;
use Shipard\Core\RecordAction\RecordActionResult;
use Shipard\Core\RecordAction\RecordActionValidationException;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\CashPaymentLookup;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\ContactPaymentOutcome;
use Shipard\Module\Economy\SalesRegistration\ContactPayment\PaymentTime;
use Shipard\Module\Economy\SalesRegistration\Evaluation\ReasonTexts;
use Shipard\Module\Economy\SalesRegistration\Sales\SaleSync;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Akce **Kontaktní platba** na dokladu V pořádku (#171 D8b, D8c, U17):
 * fakturu vystavenou Převodem zákazník zaplatil na místě (QR, karta na
 * terminálu). Dialog se ptá na čas platby (výchozí teď, ne v budoucnu);
 * akce příznak zapíše bez přechodu přes V opravě, hned založí tržbu celou
 * částkou dokladu a zkusí ji odeslat.
 *
 * Nabízí se jen tam, kde doklad nespadá už pravidlem a data typu evidence
 * příznak dovolují (U14), a jen s registrací V pořádku. Hradí-li fakturu už
 * pokladní doklad, který do evidence spadá, dialog na to upozorní (D8c) —
 * tržba by se zaevidovala dvakrát.
 */
class ContactPaymentAction extends ContactPaymentActionBase
{
    public const string FIELD_PAID_AT = 'paid_at';

    public function available(string $table, array $record, RecordActionContext $context): bool
    {
        if (!$this->isConfirmed($record) || !empty($record['contact_payment'])) {
            return false;
        }
        return $this->policy($context)->allows((string) ($record['doc_type'] ?? ''), (int) ($record['payment_method'] ?? -1));
    }

    public function describe(string $table, array $record, RecordActionContext $context): RecordActionDescriptor
    {
        $t = Texts::of($context->config);

        $fields = [];
        $paying = $this->payingDocuments($record, $context);
        if ($paying !== []) {
            $fields[] = RecordActionField::notice('paidByCashDocument', $t->t(
                'contactPayment.paidByCashDocument',
                'Warning: the document is already paid by cash document {numbers}, which is registered as a sale. A contact payment would register the same sale twice.',
                ['numbers' => implode(', ', $paying)],
            ));
        }
        $fields[] = RecordActionField::datetime(
            self::FIELD_PAID_AT,
            $t->t('contactPayment.paidAt', 'Payment time'),
            required: true,
            default: RecordActionField::NOW,
            hint: $t->t('contactPayment.paidAtHint', 'When the customer paid. It must not be in the future.'),
        );

        return new RecordActionDescriptor(
            $t->t('contactPayment.label', 'Contact payment'),
            RecordActionDescriptor::SECONDARY,
            dialog: new RecordActionDialog(
                $t->t('contactPayment.title', 'Contact payment'),
                $fields,
                $t->t(
                    'contactPayment.intro',
                    'The customer paid the document on the spot — by QR code or by card on a terminal. The whole amount of the document is registered as a sale and sent right away. The payment method and the accounting of the document do not change.',
                ),
                $t->t('contactPayment.submit', 'Register the sale'),
            ),
        );
    }

    public function execute(
        string $table,
        array $record,
        RecordActionInput $input,
        RecordActionContext $context,
    ): RecordActionResult {
        $t = Texts::of($context->config);
        $service = $this->service($context);
        if ($service === null) {
            throw RecordActionValidationException::form($t->t('contactPayment.errorNoDataSource', 'Contact payment cannot be set outside a data source.'));
        }
        $paidAt = $input->dateTime(self::FIELD_PAID_AT);
        if ($paidAt === null) {
            throw RecordActionValidationException::field(self::FIELD_PAID_AT, $t->t('contactPayment.errorTimeRequired', 'Enter the payment time.'));
        }

        // Hodiny, jak je uživatel zadal — zónu místa tržby jim dá služba (U23).
        $outcome = $service->set((int) ($record['id'] ?? 0), $paidAt->format(PaymentTime::FORMAT));

        return match ($outcome->refusal) {
            null => $this->result($outcome, $context),
            ContactPaymentOutcome::REFUSAL_TIME => throw RecordActionValidationException::field(
                self::FIELD_PAID_AT,
                $t->t('contactPayment.errorTimeRequired', 'Enter the payment time.'),
            ),
            ContactPaymentOutcome::REFUSAL_FUTURE => throw RecordActionValidationException::field(
                self::FIELD_PAID_AT,
                $t->t('contactPayment.errorFuture', 'The payment time must not be in the future.'),
            ),
            ContactPaymentOutcome::REFUSAL_NOT_APPLICABLE => throw RecordActionValidationException::field(
                self::FIELD_PAID_AT,
                $t->t(
                    'contactPayment.errorNotApplicable',
                    'The document would not fall under sales registration: {reason}',
                    ['reason' => ReasonTexts::of($context->config)->text((string) $outcome->evaluationReason)],
                ),
            ),
            default => throw $this->conflict($context),
        };
    }

    /**
     * Čísla pokladních dokladů, které doklad už hradí a do evidence spadají
     * (D8c; přepsatelné v testech).
     *
     * @param array<string, mixed> $record
     * @return list<string>
     */
    protected function payingDocuments(array $record, RecordActionContext $context): array
    {
        $db = $context->db->getDibiConnection();
        $loader = $this->loader($context);
        return (new CashPaymentLookup($db, $loader, new SaleSync($db, $loader, $context->config)))->payingDocuments($record);
    }
}
