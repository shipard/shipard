<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints\PaymentQr;

/**
 * Generátor obsahu QR platby jednoho standardu (#90 D17). Vrací jen textový
 * payload — obrázek z něj kreslí až šablona.
 */
interface PaymentQrGenerator
{
    /** Identifikátor standardu v `PrintData` (`payment.qr.standard`). */
    public function standard(): string;

    /** Payload QR kódu; null, když ho z údajů nejde sestavit (chybí účet). */
    public function generate(PaymentQrInput $input): ?string;

    /**
     * Kódy údajů, které generátor při posledním `generate()` vynechal,
     * protože je standard v dané podobě neunese (`paymentReference`,
     * `specificSymbol`, `constantSymbol`).
     *
     * @return list<string>
     */
    public function skippedFields(): array;
}
