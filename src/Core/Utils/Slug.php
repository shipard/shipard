<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

/**
 * ASCII slug pro názvy souborů: transliterace diakritiky, lowercase,
 * `[^a-z0-9]+` → `-`. Diakritika ručně, ne přes iconv//TRANSLIT — ten je
 * závislý na locale a umí vracet „?".
 */
final class Slug
{
    private const TRANSLIT = [
        'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i', 'ň' => 'n',
        'ó' => 'o', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y',
        'ž' => 'z', 'ä' => 'a', 'ľ' => 'l', 'ĺ' => 'l', 'ô' => 'o', 'ŕ' => 'r', 'ö' => 'o',
        'ü' => 'u', 'ß' => 'ss', 'ł' => 'l', 'ą' => 'a', 'ę' => 'e', 'ś' => 's', 'ź' => 'z',
        'ż' => 'z', 'ć' => 'c', 'ń' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o',
        'ù' => 'u', 'â' => 'a', 'ê' => 'e', 'î' => 'i', 'û' => 'u', 'ç' => 'c',
        'ñ' => 'n', 'ø' => 'o', 'å' => 'a', 'æ' => 'ae', 'œ' => 'oe',
    ];

    /**
     * @param int $maxLength Ořez výsledku (bez koncové pomlčky).
     * @param string $fallback Výsledek pro vstup bez jediného `[a-z0-9]`.
     */
    public static function make(string $text, int $maxLength = 60, string $fallback = 'record'): string
    {
        $lower = mb_strtolower($text, 'UTF-8');
        $ascii = strtr($lower, self::TRANSLIT);
        $ascii = (string) preg_replace('/[^a-z0-9]+/', '-', $ascii);
        $ascii = trim($ascii, '-');
        if (strlen($ascii) > $maxLength) {
            $ascii = rtrim(substr($ascii, 0, $maxLength), '-');
        }
        return $ascii === '' ? $fallback : $ascii;
    }
}
