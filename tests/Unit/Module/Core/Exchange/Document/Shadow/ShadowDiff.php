<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document\Shadow;

/**
 * Rozdíl dvou struktur jako seznam vět „cesta: dnešní cesta × nová cesta“
 * — stínový test (#166 U5) z něj skládá hlášení. Porovnává striktně (typ
 * i hodnota); normalizace jsou jen vyjmenované v {@see ShadowRunner}.
 */
final class ShadowDiff
{
    private const LIMIT = 40;

    /**
     * @return list<string>
     */
    public static function arrays(string $path, mixed $expected, mixed $actual): array
    {
        $out = [];
        self::walk($path, $expected, $actual, $out);
        return $out;
    }

    /**
     * @param list<string> $out
     */
    private static function walk(string $path, mixed $expected, mixed $actual, array &$out): void
    {
        if (count($out) >= self::LIMIT || $expected === $actual) {
            return;
        }
        if (is_array($expected) && is_array($actual)) {
            foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
                $child = $path === '' ? (string) $key : "{$path}.{$key}";
                $inExpected = array_key_exists($key, $expected);
                $inActual = array_key_exists($key, $actual);
                if ($inExpected && $inActual) {
                    self::walk($child, $expected[$key], $actual[$key], $out);
                } elseif ($inExpected) {
                    $out[] = "{$child}: dnes " . self::text($expected[$key]) . ' × nově chybí';
                } else {
                    $out[] = "{$child}: dnes chybí × nově " . self::text($actual[$key]);
                }
            }
            return;
        }
        $out[] = "{$path}: dnes " . self::text($expected) . ' × nově ' . self::text($actual);
    }

    private static function text(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $json = $json === false ? gettype($value) : $json;
        return mb_strlen($json) > 160 ? mb_substr($json, 0, 160) . '…' : $json;
    }
}
