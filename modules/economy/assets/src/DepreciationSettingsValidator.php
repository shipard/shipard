<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

use Shipard\Module\Economy\Assets\Depreciation\DepreciationSettings;
use Shipard\Module\World\Assets\TaxDepreciationRules;

/**
 * Pravidla odpisového nastavení karty (D30) — jediné místo, které používá
 * uložení karty (`AssetDocument`) i potvrzení zařazení / počátečního stavu
 * (`AssetEventDocument`, kdy je poprvé známé datum zařazení).
 *
 * Bez data zařazení se platnost metody a pravidla k datu neřeší — karta
 * před zařazením smí nést i pravidlo platné jen pro starší zařazení
 * (mimořádné odpisy 2020–2023, časové odpisy nehmotného majetku do 2020).
 */
final class DepreciationSettingsValidator
{
    public function __construct(
        private readonly TaxDepreciationRules $rules,
        private readonly AssetCategories $categories,
    ) {
    }

    /**
     * @param array<string, mixed> $card
     * @return list<array{column: string, message: string, code: string}>
     */
    public function problems(array $card, ?string $acquiredDate): array
    {
        $category = (string) ($card['category'] ?? '');
        if (!$this->categories->isDepreciable($category)) {
            return [];
        }
        $intangible = $this->categories->isIntangible($category);
        $taxMethod = trim((string) ($card['tax_method'] ?? ''));
        $taxRule = trim((string) ($card['tax_rule'] ?? ''));
        $accMethod = trim((string) ($card['acc_method'] ?? ''));
        $accMonths = (int) ($card['acc_months'] ?? 0);

        $errors = [];
        $kind = null;

        if ($taxMethod === '') {
            $errors[] = self::e('tax_method', 'Odepisovaný majetek musí mít daňovou metodu.', 'required');
        } else {
            $kind = $this->rules->methodKind($taxMethod);
            if ($kind === null) {
                $errors[] = self::e('tax_method', 'Neznámá daňová metoda.', 'invalid');
            } elseif (!in_array($taxMethod, $this->rules->availableMethods($acquiredDate, $intangible), true)) {
                $errors[] = self::e(
                    'tax_method',
                    $acquiredDate === null
                        ? 'Daňovou metodu nelze u tohoto druhu majetku použít.'
                        : 'Daňovou metodu nelze použít pro majetek zařazený ' . self::czDate($acquiredDate) . '.',
                    'methodNotAvailable',
                );
            } elseif ($kind === TaxDepreciationRules::KIND_ANNUAL || $kind === TaxDepreciationRules::KIND_MONTHLY) {
                $codes = array_column($this->rules->rules($taxMethod, $acquiredDate), 'code');
                if ($taxRule === '') {
                    $errors[] = self::e('tax_rule', 'Daňová metoda vyžaduje odpisovou skupinu / pravidlo.', 'required');
                } elseif (!in_array($taxRule, $codes, true)) {
                    $errors[] = self::e(
                        'tax_rule',
                        $acquiredDate === null
                            ? 'Odpisová skupina / pravidlo neodpovídá daňové metodě.'
                            : 'Odpisová skupina / pravidlo neplatí pro majetek zařazený ' . self::czDate($acquiredDate) . '.',
                        'ruleNotValid',
                    );
                }
            }
        }

        if ($accMethod === '') {
            $errors[] = self::e('acc_method', 'Odepisovaný majetek musí mít účetní metodu.', 'required');
        } elseif (!in_array($accMethod, [DepreciationSettings::ACC_AS_TAX, DepreciationSettings::ACC_TIME], true)) {
            $errors[] = self::e('acc_method', 'Neznámá účetní metoda.', 'invalid');
        } else {
            if ($accMethod === DepreciationSettings::ACC_TIME && $accMonths <= 0) {
                $errors[] = self::e('acc_months', 'Časová účetní metoda vyžaduje dobu odpisování v měsících.', 'accMonthsMissing');
            }
            if ($kind === TaxDepreciationRules::KIND_ACCOUNTING && $accMethod !== DepreciationSettings::ACC_TIME) {
                $errors[] = self::e(
                    'acc_method',
                    'Daňová metoda podle účetních odpisů vyžaduje časovou účetní metodu.',
                    'accountingWithoutAccMethod',
                );
            } elseif ($accMethod === DepreciationSettings::ACC_AS_TAX && $kind === TaxDepreciationRules::KIND_NONE) {
                $errors[] = self::e(
                    'acc_method',
                    'Účetní metoda „stejně jako daňové“ potřebuje daňovou metodu s vlastním výpočtem.',
                    'asTaxWithoutFormula',
                );
            }
        }

        return $errors;
    }

    /** Pravidlo se u metody nezadává (metody bez vlastního výpočtu). */
    public function usesRule(?string $taxMethod): bool
    {
        if ($taxMethod === null || $taxMethod === '') {
            return false;
        }
        $kind = $this->rules->methodKind($taxMethod);

        return $kind === TaxDepreciationRules::KIND_ANNUAL || $kind === TaxDepreciationRules::KIND_MONTHLY;
    }

    /** @return array{column: string, message: string, code: string} */
    private static function e(string $column, string $message, string $code): array
    {
        return ['column' => $column, 'message' => $message, 'code' => $code];
    }

    private static function czDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('j. n. Y');
    }
}
