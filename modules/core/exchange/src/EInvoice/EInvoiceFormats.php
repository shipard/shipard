<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\EInvoice;

use Shipard\Module\Core\Exchange\Isdoc\IsdocWriter;

/**
 * Registr formátů e-faktury (#119 D1): identifikátor → generátor. Výběr
 * formátu podle stran dokladu není věc generátoru (fáze 2); tady je jen
 * místo, kam další formáty (EN 16931 — UBL / CII, Peppol) přibudou.
 */
final class EInvoiceFormats
{
    /** @var array<string, class-string<EInvoiceWriter>> */
    private const WRITERS = [
        IsdocWriter::FORMAT => IsdocWriter::class,
    ];

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::WRITERS);
    }

    public static function forFormat(string $format): ?EInvoiceWriter
    {
        $class = self::WRITERS[$format] ?? null;
        return $class !== null ? new $class() : null;
    }
}
