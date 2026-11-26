<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\SalesRegistration\Evaluation;

/**
 * Částky evidence tržeb v haléřích — rozdíly a součty se počítají v celých
 * číslech, do zprávy a do tabulky jde desetinný řetězec se dvěma místy
 * (`123.40`, `-0.50`).
 */
final class Money
{
    /** Částka z databáze nebo dokladu (číslo, řetězec, null) v haléřích. */
    public static function cents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }
        return (int) round(((float) $amount) * 100);
    }

    /**
     * Částka pro obrazovku — se znaménkem a měnou, oddělovače podle jazyka
     * požadavku (česky `1 234,50 CZK`, jinak `1,234.50 CZK`).
     */
    public static function display(int $cents, string $currency, string $language): string
    {
        $czech = $language === 'cs';
        $amount = number_format($cents / 100, 2, $czech ? ',' : '.', $czech ? ' ' : ',');
        return trim($amount . ' ' . strtoupper($currency));
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
