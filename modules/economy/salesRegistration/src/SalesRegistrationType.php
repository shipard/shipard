<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

/**
 * Typ evidence tržeb (#171 D2, D17): data z modulu země
 * (`world.<země>.salesRegistrationTypes`) + třída engine z doplňku.
 * Vzniká jen v {@see SalesRegistrationTypes} — tam se hlídá, že masky jsou
 * platné regulární výrazy a že typ má aspoň jedno prostředí.
 */
final readonly class SalesRegistrationType
{
    public const string ENV_PRODUCTION = 'production';
    public const string ENV_PLAYGROUND = 'playground';

    /**
     * @param array<string, string> $environments název prostředí → adresa služby
     * @param list<SaleRule>        $rules        kdy doklad do evidence spadá (D8a, D23); prázdné = nikdy
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $country,
        public ?string $validFrom,
        public string $currency,
        public array $environments,
        public string $taxpayerIdLabel,
        public string $taxpayerIdPattern,
        public string $unitIdPattern,
        public ?string $cashRegisterIdPattern,
        public ?string $serialNumberPattern,
        public string $engineClass,
        public array $rules = [],
        public ?string $timeZone = null,
        public ?string $amountPattern = null,
        public ?string $cashRegisterIdHint = null,
    ) {
    }

    /**
     * První pravidlo, které se dokladu s touto hlavičkou týká, nebo null —
     * doklad do evidence automaticky nespadá.
     */
    public function ruleFor(string $docType, int $paymentMethod, int $cashDir): ?SaleRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matchesHead($docType, $paymentMethod, $cashDir)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Typy dokladů, kterých se některé pravidlo týká — předvýběr dokladů
     * pro záchrannou síť.
     *
     * @return list<string>
     */
    public function ruleDocTypes(): array
    {
        $docTypes = [];
        foreach ($this->rules as $rule) {
            foreach ($rule->docTypes as $docType) {
                $docTypes[$docType] = true;
            }
        }
        return array_keys($docTypes);
    }

    /**
     * Časová zóna místa tržby (U10) — v ní jde čas tržby do zprávy. Typ bez
     * zóny (nebo s neznámou) bere zónu serveru.
     */
    public function saleTimeZone(): \DateTimeZone
    {
        if ($this->timeZone !== null) {
            try {
                return new \DateTimeZone($this->timeZone);
            } catch (\Throwable) {
                // neznámá zóna v datech typu — zóna serveru
            }
        }
        return new \DateTimeZone(date_default_timezone_get());
    }

    /** Částka jako desetinný řetězec (`123.40`, `-0.50`); typ bez masky ji neomezuje. */
    public function isValidAmount(string $value): bool
    {
        return $this->amountPattern === null || self::matches($this->amountPattern, $value);
    }

    /** Adresa služby prostředí, nebo null, když ho typ nemá. */
    public function environmentUrl(string $environment): ?string
    {
        return $this->environments[$environment] ?? null;
    }

    public function isValidTaxpayerId(string $value): bool
    {
        return self::matches($this->taxpayerIdPattern, $value);
    }

    public function isValidUnitId(string $value): bool
    {
        return self::matches($this->unitIdPattern, $value);
    }

    /** Typ bez masky pokladny hodnotu neomezuje. */
    public function isValidCashRegisterId(string $value): bool
    {
        return $this->cashRegisterIdPattern === null || self::matches($this->cashRegisterIdPattern, $value);
    }

    /** Typ bez masky pořadového čísla hodnotu neomezuje. */
    public function isValidSerialNumber(string $value): bool
    {
        return $this->serialNumberPattern === null || self::matches($this->serialNumberPattern, $value);
    }

    /**
     * Maska je regulární výraz bez oddělovačů (jak ho píše XSD); kontroluje
     * se celá hodnota bez koncového řádku (`D`).
     */
    public static function matches(string $pattern, string $value): bool
    {
        return @preg_match(self::delimited($pattern), $value) === 1;
    }

    /** Je maska použitelný regulární výraz? */
    public static function isUsablePattern(string $pattern): bool
    {
        return $pattern !== '' && @preg_match(self::delimited($pattern), '') !== false;
    }

    private static function delimited(string $pattern): string
    {
        return '~' . str_replace('~', '\~', $pattern) . '~D';
    }
}
