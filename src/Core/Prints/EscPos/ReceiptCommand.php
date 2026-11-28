<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/** Příkaz mezi řádky účtenky: posun papíru, QR kód, ořez, šuplík. */
final class ReceiptCommand
{
    public const FEED   = 'feed';
    public const QR     = 'qr';
    public const CUT    = 'cut';
    public const DRAWER = 'drawer';

    private function __construct(
        public readonly string $type,
        public readonly int $lines = 0,
        public readonly string $data = '',
    ) {
    }

    public static function feed(int $lines): self
    {
        return new self(self::FEED, $lines);
    }

    public static function qr(string $data): self
    {
        return new self(self::QR, 0, $data);
    }

    public static function cut(): self
    {
        return new self(self::CUT);
    }

    public static function drawer(): self
    {
        return new self(self::DRAWER);
    }
}
