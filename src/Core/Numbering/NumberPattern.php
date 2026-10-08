<?php

declare(strict_types=1);

namespace Shipard\Core\Numbering;

/**
 * Vzorec čísla záznamu v číselné řadě (#110 D17) — společný dokladům
 * a zakázkám. Jádro zná obecné placeholdery; doménové (`%D` typ dokladu)
 * dodává volající: při validaci seznamem povolených znaků, při vyhodnocení
 * mapou hodnot v {@see NumberContext}.
 *
 * Obecné placeholdery: `%C` kód řady, `%y` / `%Y` popisek roku 2- a 4-místně,
 * `%3`–`%6` pořadí doplněné nulami na danou šířku. Neznámý placeholder
 * zůstává při vyhodnocení literálem (validace řady ho nepustí uložit).
 */
final class NumberPattern
{
    public const GENERAL_PLACEHOLDERS = ['C', 'y', 'Y', '3', '4', '5', '6'];

    /** Cíl chyby validace: vzorec, nebo kód řady (`%C` bez kódu). */
    public const TARGET_PATTERN = 'pattern';
    public const TARGET_CODE    = 'code';

    /**
     * Prázdný vzorec, `%C` bez kódu řady, neznámý placeholder (jen první).
     * Kódy a texty chyb jsou kontrakt s formulářem řady — neměnit.
     *
     * @param list<string> $domainPlaceholders
     * @return list<array{target: string, message: string, code: string}>
     */
    public static function validate(string $pattern, ?string $seriesCode, array $domainPlaceholders = []): array
    {
        $errors = [];

        if ($pattern === '') {
            $errors[] = [
                'target'  => self::TARGET_PATTERN,
                'message' => 'Vzorec čísla dokladu je povinný',
                'code'    => 'required',
            ];
        }

        if (str_contains($pattern, '%C') && ($seriesCode === null || $seriesCode === '')) {
            $errors[] = [
                'target'  => self::TARGET_CODE,
                'message' => 'Vzorec obsahuje %C — kód řady je povinný',
                'code'    => 'required_for_pattern',
            ];
        }

        if ($pattern !== '' && preg_match_all('/%([A-Za-z0-9])/', $pattern, $matches)) {
            $known = array_merge(self::GENERAL_PLACEHOLDERS, $domainPlaceholders);
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, $known, true)) {
                    $errors[] = [
                        'target'  => self::TARGET_PATTERN,
                        'message' => "Neznámý placeholder %{$placeholder}",
                        'code'    => 'unknown_placeholder',
                    ];
                    break;
                }
            }
        }

        return $errors;
    }

    public static function resolve(string $pattern, NumberContext $context): string
    {
        $keys  = array_merge(array_map('strval', array_keys($context->domain)), self::GENERAL_PLACEHOLDERS);
        $regex = '/%(' . implode('|', array_map(preg_quote(...), $keys)) . ')/';

        $yearLabel = null;
        $label = function () use ($context, &$yearLabel): string {
            if ($yearLabel === null) {
                $yearLabel = $context->yearLabel instanceof \Closure
                    ? (string) ($context->yearLabel)()
                    : $context->yearLabel;
            }
            return $yearLabel;
        };

        $resolved = preg_replace_callback(
            $regex,
            function (array $m) use ($context, $label): string {
                $key = $m[1];
                if (array_key_exists($key, $context->domain)) {
                    $value = $context->domain[$key];
                    return $value instanceof \Closure ? (string) $value() : (string) $value;
                }
                return match ($key) {
                    'C' => $context->seriesCode,
                    'y' => substr($label(), -2),
                    'Y' => $label(),
                    '3', '4', '5', '6' => str_pad((string) $context->sequence, (int) $key, '0', STR_PAD_LEFT),
                    default => $m[0],
                };
            },
            $pattern,
        );
        return $resolved ?? $pattern;
    }
}
