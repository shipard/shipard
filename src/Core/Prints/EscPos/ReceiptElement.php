<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Prvek účtenky po přečtení a ověření značek (#177 U31). Atributy jsou už
 * převedené na hodnoty; co prvek nemá, nese výchozí hodnotu.
 */
final class ReceiptElement
{
    public const TEXT   = 'text';
    public const ROW    = 'row';
    public const LINE   = 'line';
    public const FEED   = 'feed';
    public const QR     = 'qr';
    public const CUT    = 'cut';
    public const DRAWER = 'drawer';

    /**
     * @param string      $text   obsah `text` a `qr`, levá část `row`
     * @param string|null $right  pravá část `row`
     * @param string      $char   znak dělicí čáry
     * @param int         $lines  počet řádků posunu
     */
    public function __construct(
        public readonly string $type,
        public readonly int $markupLine,
        public readonly string $text = '',
        public readonly ?string $right = null,
        public readonly string $align = 'left',
        public readonly bool $bold = false,
        public readonly int $width = 1,
        public readonly int $height = 1,
        public readonly bool $small = false,
        public readonly string $char = '-',
        public readonly int $lines = 1,
    ) {
    }
}
