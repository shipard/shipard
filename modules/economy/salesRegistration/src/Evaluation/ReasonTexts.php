<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\SalesRegistration\Texts;

/**
 * Důvody vyhodnocení dokladu ({@see DocEvaluation}) srozumitelně — proč
 * doklad do evidence nespadá a proč tržbu založit nejde (#171 U9, U18).
 * Jedno místo pro upozornění, výsledek akce Kontaktní platba i záložku
 * Evidence tržeb v detailu dokladu; kódy důvodů zůstávají pro log a testy.
 */
final class ReasonTexts
{
    private function __construct(private readonly Texts $t)
    {
    }

    public static function of(?ConfigRuntime $config): self
    {
        return new self(Texts::of($config));
    }

    public function text(string $reason): string
    {
        $t = $this->t;
        return match ($reason) {
            // Nespadá
            DocEvaluation::REASON_IMPORTED => $t->t('reason.imported', 'The document was taken over by import — it does not enter sales registration.'),
            DocEvaluation::REASON_STATE => $t->t('reason.state', 'The document is not confirmed.'),
            DocEvaluation::REASON_CANCELLED => $t->t('reason.cancelled', 'The document is cancelled — nothing of it falls under sales registration.'),
            DocEvaluation::REASON_NO_REGISTRATION => $t->t('reason.noRegistration', 'No sales registration was valid for the country of the document at the time of the sale.'),
            DocEvaluation::REASON_NO_RULE => $t->t('reason.noRule', 'The document type and payment method do not fall under sales registration.'),
            DocEvaluation::REASON_BEFORE_REGISTRATION => $t->t('reason.beforeRegistration', 'The document was issued before the sales registration became valid.'),
            DocEvaluation::REASON_NO_MATCHING_ROWS => $t->t('reason.noMatchingRows', 'No row of the document is a sale.'),
            DocEvaluation::REASON_ZERO_AMOUNT => $t->t('reason.zeroAmount', 'The amount that falls under sales registration is zero.'),
            DocEvaluation::REASON_PAYMENT_OUTSIDE_REGISTRATION => $t->t('reason.paymentOutsideRegistration', 'No sales registration was valid at the time of the payment.'),
            // Spadá, ale nejde založit
            DocEvaluation::REASON_MULTIPLE_REGISTRATIONS => $t->t('alert.reasonMultipleRegistrations', 'More than one sales registration is valid for the document — only one may be valid at a time for a country.'),
            DocEvaluation::REASON_REGISTRATION_GONE => $t->t('alert.reasonRegistrationGone', 'The registration under which the document was registered is no longer confirmed.'),
            DocEvaluation::REASON_NO_UNIT => $t->t('alert.reasonNoUnit', 'Neither the number series of the document nor the registration has a registration unit — set the default unit of the registration or a unit on the number series.'),
            DocEvaluation::REASON_FOREIGN_UNIT => $t->t('alert.reasonForeignUnit', 'The registration unit set on the number series belongs to a different registration.'),
            DocEvaluation::REASON_UNIT_ID => $t->t('alert.reasonUnitId', 'The number of the registration unit does not have the form the registration type prescribes.'),
            DocEvaluation::REASON_CASH_REGISTER_ID => $t->t('alert.reasonCashRegisterId', 'The code of the number series cannot be used as a cash register identifier — change the code of the series.'),
            DocEvaluation::REASON_SERIAL_NUMBER => $t->t('alert.reasonSerialNumber', 'The document number is too long or contains characters that the serial number of a sale does not allow.'),
            DocEvaluation::REASON_AMOUNT => $t->t('alert.reasonAmount', 'The amount is outside the range the sales registration service accepts.'),
            DocEvaluation::REASON_CURRENCY => $t->t('alert.reasonCurrency', 'The home currency of the document differs from the currency of the registration type.'),
            default => $t->t('alert.reasonOther', 'The sale cannot be created ({reason}).', ['reason' => $reason]),
        };
    }
}
