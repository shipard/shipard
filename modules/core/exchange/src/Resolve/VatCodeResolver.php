<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Resolve;

use Shipard\Module\World\Vat\VatRateResolver;

/**
 * Validates a VAT code reference from a row (`row.vat.code`) against the
 * world.vat cfgItem for the document's VAT registration country, and
 * fills in the percentage if missing.
 *
 * Output is intentionally narrow: matched id is encoded as 0 (codes are
 * string keys in cfgItem, not DB rows), `matchedBy` carries the source
 * (`"cfgItem"`), and the resolved metadata (pct, reverseVatCode flags)
 * lives in `createPayload` — Applier copies it onto the row before
 * passing to TableGateway.
 */
class VatCodeResolver
{
    public function __construct(
        private readonly VatRateResolver $vat,
    ) {}

    /**
     * @param string|null $code     VAT code key (e.g. "cz-110", "cz-217").
     * @param string|null $country  ISO 3166-1 alpha-2, uppercase or lowercase.
     * @param string|null $date     Tax point date for percentage resolution.
     * @param float|null  $declaredPct Optional explicit pct from canonical;
     *                                 used only as fallback if cfgItem lookup fails.
     */
    public function resolve(
        ?string $code,
        ?string $country,
        ?string $date,
        ?float $declaredPct = null,
    ): ResolveResult {
        if ($code === null || $code === '' || $country === null || $country === '') {
            return ResolveResult::notFound();
        }

        $countryCode = strtolower(trim($country));

        try {
            $codeDef = $this->vat->getVatCode($countryCode, $code);
        } catch (\LogicException) {
            // Country not configured at all.
            return ResolveResult::notFound();
        }

        if ($codeDef === null) {
            return ResolveResult::notFound();
        }

        $pct = $declaredPct;
        if ($date !== null && $date !== '') {
            try {
                $pct = $this->vat->resolveVatPct($countryCode, $code, $date);
            } catch (\LogicException) {
                // Code exists but no percentage valid for this date — keep
                // declaredPct if we have one, otherwise fall through with null.
            }
        }

        return new ResolveResult(
            status: ResolveStatus::Matched,
            matchedId: 0,
            matchedBy: 'cfgItem',
            createPayload: [
                'code'           => $code,
                'pct'            => $pct,
                'reverseVatCode' => $codeDef['reverseVatCode'] ?? null,
                'noPayTax'       => !empty($codeDef['noPayTax']),
            ],
        );
    }
}
