<?php

declare(strict_types=1);

namespace Shipard\Module\World\Vat;

use Shipard\Core\Config\ConfigRuntime;

/**
 * Resolves VAT codes and rates from cfgItem world.vat.{country}.
 */
final class VatRateResolver
{
    /** @var array<string, array> per-country cache of full cfgItem data */
    private array $cache = [];

    /** Povolené hodnoty `vatCodes[].supplyKind` (zboží / služby). */
    public const SUPPLY_KINDS = ['goods', 'services'];

    public function __construct(
        private readonly ConfigRuntime $config,
    ) {}

    /**
     * Resolve VAT percentage for a given code on a given date.
     *
     * @throws \LogicException if code unknown or no rate valid for date
     */
    public function resolveVatPct(string $countryCode, string $vatCode, string $date): float
    {
        $cfg = $this->loadCountryConfig($countryCode);

        if (!isset($cfg['vatCodes'][$vatCode])) {
            throw new \LogicException(
                "Unknown VAT code '{$vatCode}' in country '{$countryCode}'",
            );
        }

        foreach ($cfg['vatPercents'] ?? [] as $entry) {
            if (($entry['code'] ?? null) !== $vatCode) {
                continue;
            }
            if ($entry['from'] !== '0000-00-00' && $date < $entry['from']) {
                continue;
            }
            if ($entry['to'] !== '0000-00-00' && $date > $entry['to']) {
                continue;
            }
            return (float) $entry['value'];
        }

        throw new \LogicException(
            "No VAT percentage defined for code '{$vatCode}' on date '{$date}' in country '{$countryCode}'",
        );
    }

    /**
     * Get filtered list of VAT codes (for UI dropdown on document row).
     *
     * @return array<string, array> keyed by VAT code slug
     */
    public function getVatCodes(
        string $countryCode,
        ?string $direction = null,
        ?string $place = null,
        bool $includeHidden = false,
    ): array {
        $cfg = $this->loadCountryConfig($countryCode);

        $result = [];
        foreach ($cfg['vatCodes'] ?? [] as $key => $code) {
            if (!$includeHidden && !empty($code['hidden'])) {
                continue;
            }
            if ($direction !== null && ($code['direction'] ?? null) !== $direction) {
                continue;
            }
            if ($place !== null && ($code['place'] ?? 'domestic') !== $place) {
                continue;
            }
            $result[$key] = $code;
        }
        return $result;
    }

    /** Get details of a single VAT code; null if not found. */
    public function getVatCode(string $countryCode, string $vatCode): ?array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        return $cfg['vatCodes'][$vatCode] ?? null;
    }

    /** @return array<string, array> */
    public function getVatCategories(string $countryCode): array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        return $cfg['vatCategories'] ?? [];
    }

    /** @return array<string, array> */
    public function getVatNotes(string $countryCode): array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        return $cfg['vatNotes'] ?? [];
    }

    /**
     * Validate cfgItem integrity — every reference between sections must
     * resolve. Returns list of error messages (empty array = OK).
     *
     * @return array<string>
     */
    public function validateCountryConfig(string $countryCode): array
    {
        $cfg = $this->loadCountryConfig($countryCode);
        $errors = [];

        $codeKeys = array_keys($cfg['vatCodes'] ?? []);
        $categoryKeys = array_keys($cfg['vatCategories'] ?? []);
        $noteKeys = array_keys($cfg['vatNotes'] ?? []);

        foreach ($cfg['vatPercents'] ?? [] as $i => $entry) {
            $code = $entry['code'] ?? null;
            if (!in_array($code, $codeKeys, true)) {
                $errors[] = "vatPercents[{$i}]: unknown code '{$code}'";
            }
        }

        foreach ($cfg['vatCodes'] ?? [] as $key => $code) {
            if (isset($code['reverseVatCode'])
                && !in_array($code['reverseVatCode'], $codeKeys, true)) {
                $errors[] = "vatCodes['{$key}']: reverseVatCode '{$code['reverseVatCode']}' not found";
            }
            if (isset($code['category'])
                && !in_array($code['category'], $categoryKeys, true)) {
                $errors[] = "vatCodes['{$key}']: unknown category '{$code['category']}'";
            }
            if (isset($code['note'])
                && !in_array($code['note'], $noteKeys, true)) {
                $errors[] = "vatCodes['{$key}']: unknown note '{$code['note']}'";
            }
            // Druh plnění pro odvození kódu z přijatého dokladu
            // (VatCodeDerivation) — jen goods / services.
            if (isset($code['supplyKind'])
                && !in_array($code['supplyKind'], self::SUPPLY_KINDS, true)) {
                $errors[] = "vatCodes['{$key}']: unknown supplyKind '{$code['supplyKind']}'";
            }
        }

        return $errors;
    }

    private function loadCountryConfig(string $countryCode): array
    {
        if (isset($this->cache[$countryCode])) {
            return $this->cache[$countryCode];
        }

        $data = $this->config->cfgItem("world.vat.{$countryCode}");
        if (!is_array($data)) {
            throw new \LogicException(
                "VAT configuration for country '{$countryCode}' not found",
            );
        }

        return $this->cache[$countryCode] = $data;
    }
}
