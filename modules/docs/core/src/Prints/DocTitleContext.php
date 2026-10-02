<?php

declare(strict_types=1);

namespace Shipard\Module\Docs\Core\Prints;

/**
 * Co o dokladu potřebuje `TitleVariantResolver` k volbě titulku (#90 D16,
 * D25, D26). Staví ho `DocPrintContext::titleContext()`.
 */
final class DocTitleContext
{
    /**
     * @param bool $vatPayer Hlavička má registraci k DPH.
     * @param ?int $tradeDir 1 výstup, 2 vstup, null bez směru.
     * @param bool $hasVatRecap Doklad má tištěnou rekapitulaci DPH.
     * @param float $totalAmount Celková částka — záporná = vratka.
     */
    public function __construct(
        public readonly string $docType,
        public readonly bool $vatPayer,
        public readonly ?int $tradeDir = null,
        public readonly bool $hasVatRecap = false,
        public readonly float $totalAmount = 0.0,
    ) {}
}
