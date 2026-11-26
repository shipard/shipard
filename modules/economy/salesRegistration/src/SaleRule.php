<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration;

/**
 * Pravidlo typu evidence, kdy doklad do evidence tržeb spadá (#171 D8a, D23).
 * Pravidla jsou data země (`rules` u typu ve
 * `world.<země>.salesRegistrationTypes`) — nový způsob úhrady je úprava dat,
 * ne kódu; vyhodnocuje je obecný kód doplňku.
 *
 *  - `docTypes` — typy dokladů, kterých se pravidlo týká (povinné);
 *  - `paymentMethods` — způsoby úhrady hlavičky; chybí = jakýkoli;
 *  - `cashDir` — směr pokladního dokladu; chybí = jakýkoli;
 *  - `rowOperations` — pravidlo nad řádky: do evidence jdou jen řádky s těmito
 *    pohyby (`sale.*` = každý pohyb začínající `sale.`); chybí = pravidlo
 *    nad hlavičkou, do evidence jde celá částka dokladu.
 */
final readonly class SaleRule
{
    /**
     * @param list<string>      $docTypes
     * @param list<int>|null    $paymentMethods
     * @param list<string>|null $rowOperations
     */
    public function __construct(
        public array $docTypes,
        public ?array $paymentMethods = null,
        public ?int $cashDir = null,
        public ?array $rowOperations = null,
    ) {
    }

    /** Pravidlo z dat typu; null = položka nemá platný tvar. */
    public static function fromArray(mixed $raw): ?self
    {
        if (!is_array($raw)) {
            return null;
        }
        $docTypes = self::strings($raw['docTypes'] ?? null);
        if ($docTypes === null || $docTypes === []) {
            return null;
        }

        $paymentMethods = null;
        if (array_key_exists('paymentMethods', $raw)) {
            if (!is_array($raw['paymentMethods']) || $raw['paymentMethods'] === []) {
                return null;
            }
            $paymentMethods = [];
            foreach ($raw['paymentMethods'] as $method) {
                if (!is_int($method)) {
                    return null;
                }
                $paymentMethods[] = $method;
            }
        }

        $cashDir = null;
        if (array_key_exists('cashDir', $raw)) {
            if (!is_int($raw['cashDir'])) {
                return null;
            }
            $cashDir = $raw['cashDir'];
        }

        $rowOperations = null;
        if (array_key_exists('rowOperations', $raw)) {
            $rowOperations = self::strings($raw['rowOperations']);
            if ($rowOperations === null || $rowOperations === []) {
                return null;
            }
        }

        return new self($docTypes, $paymentMethods, $cashDir, $rowOperations);
    }

    /** @return list<string>|null */
    private static function strings(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $values = [];
        foreach ($raw as $value) {
            if (!is_string($value) || trim($value) === '') {
                return null;
            }
            $values[] = trim($value);
        }
        return $values;
    }

    /** Týká se pravidlo dokladu s touto hlavičkou? */
    public function matchesHead(string $docType, int $paymentMethod, int $cashDir): bool
    {
        return in_array($docType, $this->docTypes, true)
            && ($this->paymentMethods === null || in_array($paymentMethod, $this->paymentMethods, true))
            && ($this->cashDir === null || $this->cashDir === $cashDir);
    }

    /** Pravidlo nad řádky — do evidence jde jen součet vyhovujících řádků. */
    public function isRowRule(): bool
    {
        return $this->rowOperations !== null;
    }

    /** Vyhovuje pohyb řádku pravidlu? Pravidlo nad hlavičkou bere každý řádek. */
    public function matchesOperation(?string $operation): bool
    {
        if ($this->rowOperations === null) {
            return true;
        }
        $operation = trim((string) $operation);
        if ($operation === '') {
            return false;
        }
        foreach ($this->rowOperations as $pattern) {
            if ($pattern === $operation
                || (str_ends_with($pattern, '.*') && str_starts_with($operation, substr($pattern, 0, -1)))
            ) {
                return true;
            }
        }
        return false;
    }
}
