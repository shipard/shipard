<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Jednobajtová kódová stránka nad vlastní tabulkou (#177 U32) — ne `iconv`,
 * které se chová podle knihovny systému. Stránka dodá jen horní polovinu
 * tabulky (bajty 0x80–0xFF); pravidla převodu jsou společná:
 *
 * 1. znak ASCII a znak z tabulky se tiskne, jak je;
 * 2. typografický znak mimo stránku má náhradu v ASCII (`€` → `EUR`,
 *    pomlčka → `-`, oblé uvozovky → rovné);
 * 3. písmeno s diakritikou mimo stránku se tiskne bez ní a znak se
 *    zápisem v jiných znacích rozloženě (`m²` → `m2`, `½` → `1/2`) — rozklad NFKD;
 * 4. cokoli jiného je `?`.
 */
abstract class SingleByteCodePage implements CodePage
{
    private const UNKNOWN = '?';

    private const SOFT_HYPHEN = "\u{00AD}";

    /** Znaky mimo jednobajtové stránky → zápis v ASCII. */
    private const REPLACEMENTS = [
        "\u{20AC}" => 'EUR',
        "\u{2010}" => '-',
        "\u{2011}" => '-',
        "\u{2012}" => '-',
        "\u{2013}" => '-',
        "\u{2014}" => '-',
        "\u{2212}" => '-',
        "\u{201E}" => '"',
        "\u{201C}" => '"',
        "\u{201D}" => '"',
        "\u{201A}" => "'",
        "\u{2018}" => "'",
        "\u{2019}" => "'",
        "\u{2026}" => '...',
        "\u{2044}" => '/',
        "\u{00B7}" => '*',
        "\u{2022}" => '*',
        "\u{00A9}" => '(c)',
        "\u{00AE}" => '(R)',
        "\u{2122}" => 'TM',
        // Mezery pevné šířky: nezlomitelné zůstávají nezlomitelné.
        "\u{2007}" => self::NBSP,
        "\u{202F}" => self::NBSP,
        "\u{2002}" => ' ',
        "\u{2003}" => ' ',
        "\u{2009}" => ' ',
    ];

    /** @var array<string, int>|null znak → bajt */
    private ?array $reverse = null;

    /** Znaky bajtů 0x80–0xFF, řetězec o 128 znacích. */
    abstract protected function highTable(): string;

    public function normalize(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_scrub($text, 'UTF-8');
        }
        // Rozložený zápis (písmeno + kombinující znak) nejdřív složit, aby
        // se našel v tabulce stránky.
        $composed = \Normalizer::normalize($text, \Normalizer::FORM_C);
        if (is_string($composed)) {
            $text = $composed;
        }

        $out = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $out .= $this->printable($char) ?? $this->substitute($char);
        }
        return $out;
    }

    public function encode(string $normalized): string
    {
        $reverse = $this->reverse();
        $bytes   = '';
        foreach (mb_str_split($normalized, 1, 'UTF-8') as $char) {
            if ($char === self::NBSP) {
                $bytes .= ' ';
            } elseif (strlen($char) === 1) {
                $bytes .= $char;
            } else {
                $bytes .= chr($reverse[$char] ?? ord(self::UNKNOWN));
            }
        }
        return $bytes;
    }

    /** Znak, jak se vytiskne bez náhrady; `null` = stránka ho nemá. */
    private function printable(string $char): ?string
    {
        if ($char === self::NBSP) {
            return $char;
        }
        if (strlen($char) === 1) {
            $code = ord($char);
            if ($code >= 0x20 && $code < 0x7F) {
                return $char;
            }
            // Řídicí znak v textu by tiskárna vzala jako příkaz.
            return in_array($char, ["\t", "\n", "\r"], true) ? ' ' : '';
        }
        if ($char === self::SOFT_HYPHEN) {
            return '';
        }
        return isset($this->reverse()[$char]) ? $char : null;
    }

    private function substitute(string $char): string
    {
        if (isset(self::REPLACEMENTS[$char])) {
            return self::REPLACEMENTS[$char];
        }
        // Osamocený kombinující znak nemá na účtence šířku.
        if (preg_match('/^\p{Mn}$/u', $char) === 1) {
            return '';
        }

        $decomposed = \Normalizer::normalize($char, \Normalizer::FORM_KD);
        if (!is_string($decomposed) || $decomposed === $char) {
            return self::UNKNOWN;
        }
        $base = preg_replace('/\p{Mn}+/u', '', $decomposed);
        if (!is_string($base) || $base === '') {
            return self::UNKNOWN;
        }

        $out = '';
        foreach (mb_str_split($base, 1, 'UTF-8') as $baseChar) {
            $out .= $this->printable($baseChar) ?? self::REPLACEMENTS[$baseChar] ?? self::UNKNOWN;
        }
        return $out;
    }

    /** @return array<string, int> */
    private function reverse(): array
    {
        if ($this->reverse !== null) {
            return $this->reverse;
        }
        $chars = mb_str_split($this->highTable(), 1, 'UTF-8');
        if (count($chars) !== 128) {
            throw new \LogicException("Code page '{$this->name()}' must define 128 characters");
        }
        $reverse = [];
        foreach ($chars as $index => $char) {
            $reverse[$char] ??= 0x80 + $index;
        }
        return $this->reverse = $reverse;
    }
}
