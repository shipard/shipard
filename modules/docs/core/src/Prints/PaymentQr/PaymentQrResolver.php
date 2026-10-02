<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\PaymentQr;

/**
 * Volba standardu QR platby podle země odběratele — rozhoduje bankovnictví
 * plátce (#90 D17). Ve v1 se pro všechny země vrací SPAYD; další standardy
 * (EPC QR pro SEPA, PAY by square) přibudou tady (#91).
 */
final class PaymentQrResolver
{
    public function forCustomerCountry(?string $country): PaymentQrGenerator
    {
        return new SpaydGenerator();
    }
}
