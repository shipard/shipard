<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Sazba účtenky (#177 U31): prvky → řádky se stylem a příkazy. Šířku řádku
 * dává profil (`columns`, malým písmem `columnsSmall`) vydělený násobkem
 * šířky znaku; počítá se nad normalizovaným textem, kde je jeden znak jeden
 * sloupec.
 *
 * Řádek se láme jen na obyčejné mezeře — nezlomitelná (z formátovacích
 * filtrů: `1 210,00 CZK`, `2. 10. 2026`) drží text pohromadě. Slovo delší
 * než řádek se rozdělí.
 *
 * Funkce, které profil nemá, se nahrazují tady: QR se vytiskne jako text,
 * ořez jako posun papíru, šuplík nic.
 */
final class ReceiptLayout
{
    /** Posun papíru místo ořezu, aby šla účtenka utrhnout. */
    public const FEED_INSTEAD_OF_CUT = 3;

    public function __construct(
        private readonly PrinterProfile $profile,
        private readonly CodePage $codePage,
    ) {
    }

    /**
     * @param list<ReceiptElement> $elements
     * @return list<ReceiptLine|ReceiptCommand>
     */
    public function layout(array $elements): array
    {
        $items = [];
        foreach ($elements as $element) {
            array_push($items, ...$this->element($element));
        }
        return $items;
    }

    /** @return list<ReceiptLine|ReceiptCommand> */
    private function element(ReceiptElement $element): array
    {
        $width = $this->profile->lineWidth($element->small, $element->width);

        switch ($element->type) {
            case ReceiptElement::TEXT:
                return $this->lines(
                    self::wrap($this->codePage->normalize($element->text), $width),
                    $element,
                );

            case ReceiptElement::ROW:
                return $this->lines(
                    self::row(
                        $this->codePage->normalize($element->text),
                        $element->right === null ? '' : $this->codePage->normalize($element->right),
                        $width,
                    ),
                    $element,
                );

            case ReceiptElement::LINE:
                $char = mb_substr($this->codePage->normalize($element->char), 0, 1, 'UTF-8');
                return [new ReceiptLine(str_repeat($char === '' ? '-' : $char, $width))];

            case ReceiptElement::FEED:
                return [ReceiptCommand::feed($element->lines)];

            case ReceiptElement::QR:
                if ($this->profile->hasFeature(PrinterProfile::FEATURE_QR)) {
                    return [ReceiptCommand::qr($element->text)];
                }
                return $this->lines(self::wrap($this->codePage->normalize($element->text), $width), $element);

            case ReceiptElement::CUT:
                return [$this->profile->hasFeature(PrinterProfile::FEATURE_CUT)
                    ? ReceiptCommand::cut()
                    : ReceiptCommand::feed(self::FEED_INSTEAD_OF_CUT)];

            case ReceiptElement::DRAWER:
                return $this->profile->hasFeature(PrinterProfile::FEATURE_DRAWER)
                    ? [ReceiptCommand::drawer()]
                    : [];
        }

        throw new \LogicException("Unknown receipt element '{$element->type}'");
    }

    /**
     * @param list<string> $texts
     * @return list<ReceiptLine>
     */
    private function lines(array $texts, ReceiptElement $element): array
    {
        return array_map(
            static fn(string $text): ReceiptLine => new ReceiptLine(
                $text,
                $element->align,
                $element->bold,
                $element->width,
                $element->height,
                $element->small,
            ),
            $texts,
        );
    }

    /**
     * Zalomení textu po slovech. Odsazení (mezery na začátku) zůstává na
     * prvním řádku; víc mezer mezi slovy je jedna.
     *
     * @return list<string> aspoň jeden řádek, žádný delší než `$width`
     */
    public static function wrap(string $text, int $width): array
    {
        $width  = max(1, $width);
        $indent = strspn($text, ' ');
        $words  = preg_split('/ +/', substr($text, $indent), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === []) {
            return [''];
        }

        $lines   = [];
        $current = $indent < $width ? str_repeat(' ', $indent) : '';
        $started = false;

        foreach ($words as $word) {
            $length = mb_strlen($word, 'UTF-8');
            if ($started && mb_strlen($current, 'UTF-8') + 1 + $length <= $width) {
                $current .= ' ' . $word;
                continue;
            }
            if ($started) {
                $lines[] = $current;
                $current = '';
            }
            // Odsazení se obětuje dřív, než by rozdělilo slovo, které se na řádek vejde.
            if ($current !== '' && mb_strlen($current, 'UTF-8') + $length > $width && $length <= $width) {
                $current = '';
            }
            $rest = $current . $word;
            while (mb_strlen($rest, 'UTF-8') > $width) {
                $lines[] = mb_substr($rest, 0, $width, 'UTF-8');
                $rest    = mb_substr($rest, $width, null, 'UTF-8');
            }
            $current = $rest;
            $started = true;
        }
        $lines[] = $current;

        return $lines;
    }

    /**
     * Dvojice vlevo / vpravo: pravý text patří na poslední řádek levého;
     * když se tam nevejde (s mezerou mezi nimi), jde na vlastní řádek vpravo.
     *
     * @return list<string>
     */
    public static function row(string $left, string $right, int $width): array
    {
        $width = max(1, $width);
        $lines = self::wrap($left, $width);
        $right = trim($right, ' ');
        if ($right === '') {
            return $lines;
        }

        $last = (string) array_pop($lines);
        if (trim($last, ' ') === '') {
            // Prázdná levá část: pravý text stojí na řádku sám.
            $last = '';
        }
        $lastLength  = mb_strlen($last, 'UTF-8');
        $rightLength = mb_strlen($right, 'UTF-8');
        $gap         = $last === '' ? 0 : 1;

        if ($lastLength + $gap + $rightLength <= $width) {
            $lines[] = $last . str_repeat(' ', $width - $lastLength - $rightLength) . $right;
            return $lines;
        }

        if ($last !== '') {
            $lines[] = $last;
        }
        // Pravý text delší než řádek se rozdělí; zbytek zůstává vpravo.
        while (mb_strlen($right, 'UTF-8') > $width) {
            $lines[] = mb_substr($right, 0, $width, 'UTF-8');
            $right   = mb_substr($right, $width, null, 'UTF-8');
        }
        $lines[] = str_repeat(' ', $width - mb_strlen($right, 'UTF-8')) . $right;

        return $lines;
    }
}
