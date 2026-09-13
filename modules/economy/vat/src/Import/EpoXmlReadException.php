<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Import;

/**
 * Chyba čtení souboru pro EPO při importu starého podání (#55 D33).
 * `reason` je rovnou kód chyby endpointu: `XML_UNREADABLE` (soubor se nedá
 * rozebrat — runner ho pošle bez XML a ohlásí), `XML_TYPE_MISMATCH`
 * (písemnost jiného typu, než jaké je tvrzení).
 */
final class EpoXmlReadException extends \RuntimeException
{
    public const REASON_UNREADABLE    = 'XML_UNREADABLE';
    public const REASON_TYPE_MISMATCH = 'XML_TYPE_MISMATCH';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unreadable(string $detail): self
    {
        return new self(self::REASON_UNREADABLE, "Soubor pro EPO se nedá přečíst: {$detail}");
    }

    public static function typeMismatch(string $expected, string $actual): self
    {
        return new self(
            self::REASON_TYPE_MISMATCH,
            "Soubor pro EPO je písemnost {$actual}, tvrzení čeká {$expected}.",
        );
    }
}
