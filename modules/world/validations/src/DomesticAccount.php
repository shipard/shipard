<?php

declare(strict_types=1);

namespace Shipard\Module\World\Validations;

/**
 * Tuzemské číslo účtu `[předčíslí-]číslo/kód banky` (cz, sk): bez mezer
 * a bez vedoucích nul; mod 11 zvlášť na předčíslí a čísle (váhy
 * 1, 2, 4, 8, 5, 10, 9, 7, 3, 6 od pravého kraje, prázdné předčíslí je
 * platné — `checks.py::_mod11`); BBAN = kód 4 + předčíslí 6 + číslo 10.
 */
final readonly class DomesticAccount
{
    /** Vedoucí nuly se nepočítají — po jejich odstranění předčíslí nejvýš 6 a číslo nejvýš 10 číslic. */
    private const SHAPE = '~^(?:([0-9]+)-)?([0-9]+)/([0-9]{4})$~';
    private const PREFIX_MAX = 6;
    private const NUMBER_MAX = 10;
    private const MOD11_WEIGHTS = [1, 2, 4, 8, 5, 10, 9, 7, 3, 6];

    /** @param string $prefix bez vedoucích nul, '' = bez předčíslí */
    public function __construct(
        public string $prefix,
        public string $number,
        public string $bank,
    ) {}

    /** Rozpozná tvar (mezery se ignorují); jiný tvar `null`. */
    public static function parse(string $value): ?self
    {
        $s = (string) preg_replace('/\s+/u', '', trim($value));
        if (preg_match(self::SHAPE, $s, $m) !== 1) {
            return null;
        }
        $prefix = ltrim($m[1], '0');
        $number = ltrim($m[2], '0');
        if (strlen($prefix) > self::PREFIX_MAX || strlen($number) > self::NUMBER_MAX) {
            return null;
        }
        return new self($prefix, $number === '' ? '0' : $number, $m[3]);
    }

    /** Kanonický tvar; hodnota jiného tvaru (zahraniční účet) jen bez mezer. */
    public static function normalize(string $value): string
    {
        $parsed = self::parse($value);
        return $parsed !== null ? (string) $parsed : (string) preg_replace('/\s+/u', '', trim($value));
    }

    public function isValid(): bool
    {
        return $this->number !== '0' && self::mod11($this->prefix) && self::mod11($this->number);
    }

    public static function mod11(string $digits): bool
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $sum += ((int) $digits[$len - 1 - $i]) * self::MOD11_WEIGHTS[$i];
        }
        return $sum % 11 === 0;
    }

    public function toBban(): string
    {
        return $this->bank
            . str_pad($this->prefix, 6, '0', STR_PAD_LEFT)
            . str_pad($this->number, 10, '0', STR_PAD_LEFT);
    }

    /** Z 20 číslic BBAN (cz, sk); jiný vstup `null`. */
    public static function fromBban(string $bban): ?self
    {
        if (preg_match('/^[0-9]{20}$/', $bban) !== 1) {
            return null;
        }
        $number = ltrim(substr($bban, 10, 10), '0');
        return new self(ltrim(substr($bban, 4, 6), '0'), $number === '' ? '0' : $number, substr($bban, 0, 4));
    }

    public function __toString(): string
    {
        return ($this->prefix !== '' ? $this->prefix . '-' : '') . $this->number . '/' . $this->bank;
    }
}
