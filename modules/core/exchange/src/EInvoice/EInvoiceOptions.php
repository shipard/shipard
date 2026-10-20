<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\EInvoice;

/**
 * Volby generátoru e-faktury (#119 D1, D7): hodnoty, které canonical nenese
 * a generátor si je nesmí vymyslet ani načíst sám.
 *
 *   - `uuid` — identifikátor dokladu ve formátu; deterministický
 *     ({@see EInvoiceUuid::fromName()}), takže stejný doklad dává při
 *     každém odeslání stejný soubor,
 *   - `countryNames` — názvy zemí pro adresy stran (ISDOC chce kód i název),
 *   - `issuingSystem` — jméno vystavujícího systému bez verze a commitu
 *     (stejný vstup = stejné bajty).
 */
final readonly class EInvoiceOptions
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        public string $uuid,
        public CountryNames $countryNames,
        public string $issuingSystem = 'Shipard',
    ) {
        if (preg_match(self::UUID_RE, $uuid) !== 1) {
            throw new \InvalidArgumentException("UUID '{$uuid}' není ve tvaru 8-4-4-4-12");
        }
        $system = trim($issuingSystem);
        if ($system === '' || mb_strlen($system) > 80) {
            throw new \InvalidArgumentException('issuingSystem musí být neprázdný text do 80 znaků');
        }
    }
}
