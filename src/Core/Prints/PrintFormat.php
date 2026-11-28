<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Výstupní formát běhu tisku. `json` jde u každého tisku; ostatní podle
 * `output` deklarace (#177 U35): `pdf` a `html` u tisku do PDF, `escpos`
 * (bajty pro účtenkovou tiskárnu) a `markup` (výstup šablony účtenky, pro
 * vývoj šablon z CLI) u tisku `output: escpos`. Co k sobě nepatří, odmítne
 * `PrintRunner` výjimkou `PrintFormatNotSupportedException`.
 */
enum PrintFormat: string
{
    case Pdf = 'pdf';
    case Json = 'json';
    case Html = 'html';
    case Escpos = 'escpos';
    case Markup = 'markup';

    /** Formáty, které dává jen tisk `output: escpos`. */
    public function isEscPos(): bool
    {
        return $this === self::Escpos || $this === self::Markup;
    }

    /** Jde formát vyrobit z tisku s daným `output`? */
    public function fits(PrintDefinition $definition): bool
    {
        return $this === self::Json || $this->isEscPos() === $definition->isEscPos();
    }
}
