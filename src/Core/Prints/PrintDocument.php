<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

/**
 * Tisk vykreslený do HTML — to, co jde do render služby: stránka, volitelné
 * záhlaví a zápatí (samostatné HTML dokumenty) a assety referencované
 * relativně. Mezikrok mezi šablonou a PDF; testy šablon končí tady.
 */
final class PrintDocument
{
    /** @param array<string, string> $assets název souboru → obsah */
    public function __construct(
        public readonly string $html,
        public readonly ?string $header,
        public readonly ?string $footer,
        public readonly array $assets,
    ) {}
}
