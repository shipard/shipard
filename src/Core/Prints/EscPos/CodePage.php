<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Kódová stránka účtenkové tiskárny (#177 U32). Převod má dva kroky, protože
 * náhrada znaku mění délku textu (`€` → `EUR`) a sazba počítá šířku řádku:
 *
 *   text → `normalize()` → sazba (1 znak = 1 sloupec) → `encode()` (1 znak = 1 bajt)
 */
interface CodePage
{
    public const NBSP = "\u{00A0}";

    /** Název stránky, jak ho nese profil tiskárny a ovladač (`cp852`). */
    public function name(): string;

    /**
     * Text jen ze znaků, které stránka umí vytisknout. Nezlomitelná mezera
     * zůstává, aby na ní sazba řádek nezalomila.
     */
    public function normalize(string $text): string;

    /** Normalizovaný text → bajty; nezlomitelná mezera se tiskne jako mezera. */
    public function encode(string $normalized): string;
}
