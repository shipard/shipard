<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Assets;

/**
 * Přidělení inventárního čísla (docs/assets.md D22) — čistá funkce bez DB.
 *
 * Číslo = prefix + číselná část: maximum číselných částí mezi existujícími
 * čísly se stejným prefixem (`^<prefix>[0-9]+$`) + 1, doplněné nulami
 * aspoň na MIN_DIGITS míst (`MA0001`). Delší existující číslo (`MA12345`)
 * se pokračuje beze ztráty cifer (`MA12346`). Čísla s jiným prefixem
 * ani ručně zadaná mimo vzor (`MA-7`, `MAX0001`) se do maxima nepočítají.
 *
 * Načtení existujících čísel (se zámkem) a zápis dělá AssetDocument.
 */
final class AssetNumberAllocator
{
    public const MIN_DIGITS = 4;

    /** Limit sloupce `asset_number` (varchar 20). */
    public const MAX_LENGTH = 20;

    /**
     * @param list<string|null> $existingNumbers inventární čísla karet se stejným prefixem
     * @throws \DomainException prefix je tak dlouhý, že se číslo nevejde do sloupce
     */
    public static function next(string $prefix, array $existingNumbers): string
    {
        $pattern = '/^' . preg_quote($prefix, '/') . '([0-9]+)$/';

        $max = 0;
        $maxDigits = '';
        foreach ($existingNumbers as $number) {
            if ($number === null || !preg_match($pattern, (string) $number, $m)) {
                continue;
            }
            $value = (int) $m[1];
            if ($value > $max || ($value === $max && strlen($m[1]) > strlen($maxDigits))) {
                $max = $value;
                $maxDigits = $m[1];
            }
        }

        $width = max(self::MIN_DIGITS, strlen($maxDigits));
        $result = $prefix . str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);

        if (strlen($result) > self::MAX_LENGTH) {
            throw new \DomainException(
                "Inventární číslo '{$result}' přesahuje " . self::MAX_LENGTH . ' znaků — zkrať prefix v Nastavení → Majetek.',
            );
        }

        return $result;
    }
}
