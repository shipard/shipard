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
 *
 * Pokladní doklad (D25): příjem je daňovým dokladem jen u plátce s tištěnou
 * rekapitulací DPH (prodej za hotové); výdej nikdy — daňový doklad k němu
 * vystavuje druhá strana.
 *
 * Prodejka (D26): titulek podle plátcovství jako u faktury; vratka (záporná
 * celková částka) má přednost — její správný titulek (opravný daňový
 * doklad) doladí #92.
 */
final class TitleVariantResolver
{
    public const INVOICE_VAT_PAYER     = 'invoiceVatPayer';
    public const INVOICE_NON_VAT_PAYER = 'invoiceNonVatPayer';
    public const PROFORMA              = 'proforma';
    public const CASH_IN_TAX_DOCUMENT  = 'cashInTaxDocument';
    public const CASH_IN               = 'cashIn';
    public const CASH_OUT              = 'cashOut';
    public const CASH_REGISTER_REFUND        = 'cashRegisterRefund';
    public const CASH_REGISTER_VAT_PAYER     = 'cashRegisterVatPayer';
    public const CASH_REGISTER_NON_VAT_PAYER = 'cashRegisterNonVatPayer';

    /** @throws PrintBuildException Typ dokladu, pro který titulek neznáme. */
    public static function resolve(DocTitleContext $context): string
    {
        return match ($context->docType) {
            'invno' => $context->vatPayer ? self::INVOICE_VAT_PAYER : self::INVOICE_NON_VAT_PAYER,
            'invpo' => self::PROFORMA,
            'cash'  => self::cash($context),
            'cashreg' => match (true) {
                $context->totalAmount < 0.0 => self::CASH_REGISTER_REFUND,
                $context->vatPayer          => self::CASH_REGISTER_VAT_PAYER,
                default                     => self::CASH_REGISTER_NON_VAT_PAYER,
            },
            default => throw new PrintBuildException(
                "Document type '{$context->docType}' has no print title variant",
            ),
        };
    }

    private static function cash(DocTitleContext $context): string
    {
        return match ($context->tradeDir) {
            1 => $context->vatPayer && $context->hasVatRecap ? self::CASH_IN_TAX_DOCUMENT : self::CASH_IN,
            2 => self::CASH_OUT,
            default => throw new PrintBuildException('Cash document has no direction — print title is unknown'),
        };
    }
}
