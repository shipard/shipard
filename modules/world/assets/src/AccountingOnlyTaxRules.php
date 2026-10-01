<?php

declare(strict_types=1);

namespace Shipard\Module\World\Assets;

/**
 * Fallback pro stát bez konfigurace pravidel: daňové odpisy buď kopírují
 * účetní, nebo se neuplatňují (D31).
 */
final class AccountingOnlyTaxRules implements TaxDepreciationRules
{
    private const METHODS = [
        'accounting' => self::KIND_ACCOUNTING,
        'none' => self::KIND_NONE,
    ];

    public function __construct(private readonly string $country)
    {
    }

    public function country(): string
    {
        return $this->country;
    }

    public function availableMethods(?string $acquiredDate, bool $intangible): array
    {
        return array_keys(self::METHODS);
    }

    public function methodKind(string $method): ?string
    {
        return self::METHODS[$method] ?? null;
    }

    /** Bez konfigurace není co lokalizovat — anglický fallback. */
    public function methodName(string $method): string
    {
        return match ($method) {
            'accounting' => 'Same as accounting depreciation',
            'none' => 'Not depreciated for tax',
            default => $method,
        };
    }

    public function rules(string $method, ?string $acquiredDate): array
    {
        return [];
    }

    public function taxReturnGroup(string $method, ?string $rule): ?string
    {
        return $method === 'accounting' ? 'accounting' : null;
    }

    public function taxReturnGroups(): array
    {
        return ['accounting' => 'Accounting depreciation claimed for tax'];
    }

    public function isInterruptible(string $method): bool
    {
        return false;
    }

    public function allowsHalfYearOnDisposal(string $method): bool
    {
        return false;
    }

    public function allowsShortPeriodHalfYear(string $method): bool
    {
        return false;
    }

    public function allowsImprovement(string $method): bool
    {
        return true;
    }

    public function round(float $amount): float
    {
        return round($amount, 2);
    }

    public function annualAmount(TaxYearInput $in): TaxAmount
    {
        throw new \LogicException("Stát '{$this->country}' nemá pravidla daňových odpisů");
    }

    public function scheduleMonths(TaxScheduleInput $in): int
    {
        throw new \LogicException("Stát '{$this->country}' nemá pravidla daňových odpisů");
    }

    public function scheduleAmount(TaxScheduleInput $in): TaxAmount
    {
        throw new \LogicException("Stát '{$this->country}' nemá pravidla daňových odpisů");
    }
}
