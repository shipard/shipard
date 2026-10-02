<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\PaymentQr;

/**
 * Platební údaje dokladu pro QR platbu — to, co generátor standardu
 * potřebuje, bez vazby na tvar hlavičky dokladu.
 */
final class PaymentQrInput
{
    public function __construct(
        public readonly ?string $iban,
        public readonly ?string $bic,
        /** Číslo účtu v národním tvaru (`předčíslí-číslo/kód banky`). */
        public readonly ?string $accountNumber,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?string $paymentReference,
        public readonly ?string $specificSymbol,
        public readonly ?string $constantSymbol,
        /** Splatnost `YYYY-MM-DD`. */
        public readonly ?string $dueDate,
        public readonly ?string $message,
    ) {}
}
