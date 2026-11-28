<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Výstup šablony účtenky (XML) → prvky (#177 U31). Jazyk je záměrně malý
 * a přísný: neznámá značka, atribut nebo hodnota je chyba s číslem řádku,
 * ne tiché přeskočení — jinak by se překlep v šabloně poznal až na papíře.
 *
 * Bílé znaky: mezery a prázdné řádky mezi značkami se ignorují. V obsahu
 * značky se zalomení šablony i s odsazením čte jako jedna mezera (na okrajích
 * obsahu se zahodí); mezery na témže řádku jako text zůstávají, takže
 * `<l>  2 × 3,50</l>` je odsazené.
 */
final class ReceiptMarkupParser
{
    public const ROOT = 'receipt';

    private const STYLE_ATTRIBUTES = ['bold', 'size', 'font'];

    /** Značka → povolené atributy. */
    private const ELEMENTS = [
        ReceiptElement::TEXT   => ['align', ...self::STYLE_ATTRIBUTES],
        ReceiptElement::ROW    => self::STYLE_ATTRIBUTES,
        ReceiptElement::LINE   => ['char'],
        ReceiptElement::FEED   => ['lines'],
        ReceiptElement::QR     => [],
        ReceiptElement::CUT    => [],
        ReceiptElement::DRAWER => [],
    ];

    public const MAX_FEED_LINES = 10;

    /**
     * @return list<ReceiptElement>
     * @throws ReceiptMarkupException
     */
    public function parse(string $markup): array
    {
        $root = $this->load($markup);
        if ($root->localName !== self::ROOT) {
            throw new ReceiptMarkupException("root element must be <" . self::ROOT . ">", $root->getLineNo());
        }
        $this->attributes($root, []);

        $elements = [];
        foreach ($this->childElements($root) as $node) {
            $elements[] = $this->element($node);
        }
        return $elements;
    }

    private function load(string $markup): \DOMElement
    {
        $dom      = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $loaded = trim($markup) !== '' && $dom->loadXML($markup, LIBXML_NONET | LIBXML_COMPACT);
            $error  = libxml_get_last_error();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $dom->documentElement === null) {
            throw new ReceiptMarkupException(
                $error instanceof \LibXMLError ? trim($error->message) : 'markup is empty',
                $error instanceof \LibXMLError ? max(1, $error->line) : 1,
            );
        }
        // Šablona DTD nepotřebuje a entity z něj by obešly escapování hodnot.
        if ($dom->doctype !== null) {
            throw new ReceiptMarkupException('DOCTYPE is not allowed', 1);
        }
        return $dom->documentElement;
    }

    private function element(\DOMElement $node): ReceiptElement
    {
        $type = $node->localName;
        $line = $node->getLineNo();
        if (!isset(self::ELEMENTS[$type])) {
            throw new ReceiptMarkupException("unknown element <{$node->nodeName}>", $line);
        }
        $attributes = $this->attributes($node, self::ELEMENTS[$type]);
        $style      = $this->style($attributes, $line);

        switch ($type) {
            case ReceiptElement::TEXT:
                $align = $attributes['align'] ?? 'left';
                if (!in_array($align, EscPosDriver::ALIGNMENTS, true)) {
                    throw new ReceiptMarkupException("invalid value align=\"{$align}\"", $line);
                }
                return new ReceiptElement($type, $line, $this->text($node), null, $align, ...$style);

            case ReceiptElement::ROW:
                [$left, $right] = $this->rowParts($node);
                return new ReceiptElement($type, $line, $left, $right, 'left', ...$style);

            case ReceiptElement::LINE:
                $this->assertEmpty($node);
                $char = $attributes['char'] ?? '-';
                if (mb_strlen($char, 'UTF-8') !== 1 || trim($char) === '') {
                    throw new ReceiptMarkupException('attribute char must be exactly one character', $line);
                }
                return new ReceiptElement($type, $line, char: $char);

            case ReceiptElement::FEED:
                $this->assertEmpty($node);
                $lines = $attributes['lines'] ?? '1';
                if (preg_match('/^\d{1,2}$/', $lines) !== 1 || (int) $lines < 1 || (int) $lines > self::MAX_FEED_LINES) {
                    throw new ReceiptMarkupException(
                        "invalid value lines=\"{$lines}\" (1-" . self::MAX_FEED_LINES . ')',
                        $line,
                    );
                }
                return new ReceiptElement($type, $line, lines: (int) $lines);

            case ReceiptElement::QR:
                $data = trim($this->text($node));
                if ($data === '') {
                    throw new ReceiptMarkupException('<qr> has no data', $line);
                }
                return new ReceiptElement($type, $line, $data);

            default:
                $this->assertEmpty($node);
                return new ReceiptElement($type, $line);
        }
    }

    /**
     * @param array<string, string> $attributes
     * @return array{bold: bool, width: int, height: int, small: bool}
     */
    private function style(array $attributes, int $line): array
    {
        $bold = $attributes['bold'] ?? '0';
        if ($bold !== '0' && $bold !== '1') {
            throw new ReceiptMarkupException("invalid value bold=\"{$bold}\" (0 or 1)", $line);
        }

        $size = $attributes['size'] ?? '1x1';
        if (preg_match('/^([1-8])x([1-8])$/', $size, $match) !== 1) {
            throw new ReceiptMarkupException("invalid value size=\"{$size}\" (WxH, 1-8)", $line);
        }

        $font = $attributes['font'] ?? 'normal';
        if ($font !== 'normal' && $font !== 'small') {
            throw new ReceiptMarkupException("invalid value font=\"{$font}\" (normal or small)", $line);
        }

        return [
            'bold'   => $bold === '1',
            'width'  => (int) $match[1],
            'height' => (int) $match[2],
            'small'  => $font === 'small',
        ];
    }

    /**
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private function attributes(\DOMElement $node, array $allowed): array
    {
        $attributes = [];
        foreach ($node->attributes ?? [] as $attribute) {
            if (!in_array($attribute->nodeName, $allowed, true)) {
                throw new ReceiptMarkupException(
                    "unknown attribute {$attribute->nodeName} of <{$node->nodeName}>",
                    $node->getLineNo(),
                );
            }
            $attributes[$attribute->nodeName] = (string) $attribute->nodeValue;
        }
        return $attributes;
    }

    /** @return array{string, string|null} levý a pravý text */
    private function rowParts(\DOMElement $row): array
    {
        $left  = null;
        $right = null;
        foreach ($this->childElements($row) as $part) {
            $this->attributes($part, []);
            if ($part->localName === 'l' && $left === null) {
                $left = $this->text($part);
            } elseif ($part->localName === 'r' && $right === null) {
                $right = trim($this->text($part));
            } else {
                throw new ReceiptMarkupException(
                    "<row> takes one <l> and at most one <r>, found <{$part->nodeName}>",
                    $part->getLineNo(),
                );
            }
        }
        if ($left === null) {
            throw new ReceiptMarkupException('<row> has no <l>', $row->getLineNo());
        }
        return [$left, $right === '' ? null : $right];
    }

    /**
     * Podřízené značky; text mimo ně smí být jen bílé znaky.
     *
     * @return list<\DOMElement>
     */
    private function childElements(\DOMElement $node): array
    {
        $elements = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
            } elseif ($child instanceof \DOMCharacterData && !$child instanceof \DOMComment) {
                if (trim((string) $child->nodeValue, " \t\r\n") !== '') {
                    throw new ReceiptMarkupException(
                        "text outside of an element in <{$node->nodeName}>",
                        $child->getLineNo(),
                    );
                }
            }
        }
        return $elements;
    }

    private function assertEmpty(\DOMElement $node): void
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMComment) {
                continue;
            }
            if ($child instanceof \DOMElement || trim((string) $child->nodeValue, " \t\r\n") !== '') {
                throw new ReceiptMarkupException("<{$node->nodeName}> must be empty", $node->getLineNo());
            }
        }
    }

    /** Textový obsah značky; podřízená značka v textu je chyba. */
    private function text(\DOMElement $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                throw new ReceiptMarkupException(
                    "<{$node->nodeName}> must contain text only, found <{$child->nodeName}>",
                    $child->getLineNo(),
                );
            }
            if ($child instanceof \DOMCharacterData && !$child instanceof \DOMComment) {
                $text .= (string) $child->nodeValue;
            }
        }

        // Bez modifikátoru /u: nezlomitelná mezera není bílý znak a zůstává.
        $text = (string) preg_replace('/^[ \t]*\r?\n[ \t\r\n]*/', '', $text);
        $text = (string) preg_replace('/[ \t\r\n]*\r?\n[ \t]*$/', '', $text);
        $text = (string) preg_replace('/[ \t]*\r?\n[ \t\r\n]*/', ' ', $text);

        return rtrim(str_replace("\t", ' ', $text), ' ');
    }
}
