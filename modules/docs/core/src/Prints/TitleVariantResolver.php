<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

use Shipard\Core\Prints\PrintBuildException;

/**
 * Varianta titulku tištěného dokladu (#90 D16). Text titulku je
 * v překladovém katalogu tisku pod klíčem `title.<varianta>`.
 *
 * Plátce = hlavička dokladu má registraci k DPH. Opravný daňový doklad
 * (`correctiveVatPayer`, `correctiveNonVatPayer`) je rezervovaný pro #92.
 */
final class TitleVariantResolver
{
    public const INVOICE_VAT_PAYER     = 'invoiceVatPayer';
    public const INVOICE_NON_VAT_PAYER = 'invoiceNonVatPayer';
    public const PROFORMA              = 'proforma';

    /** @throws PrintBuildException Typ dokladu, pro který titulek neznáme. */
    public static function resolve(string $docType, bool $vatPayer): string
    {
        return match ($docType) {
            'invno' => $vatPayer ? self::INVOICE_VAT_PAYER : self::INVOICE_NON_VAT_PAYER,
            'invpo' => self::PROFORMA,
            default => throw new PrintBuildException(
                "Document type '{$docType}' has no print title variant",
            ),
        };
    }
}
