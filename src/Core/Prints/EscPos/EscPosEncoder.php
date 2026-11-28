<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\EscPos;

/**
 * Vysázené řádky a příkazy → bajty ESC/POS. Bajty příkazů bere jen
 * z ovladače (#177 U28), text kóduje kódová stránka profilu (U32).
 *
 * Každá účtenka začíná inicializací a zapnutím kódové stránky (U27). Styl se
 * posílá jen při změně a před příkazem i na konci se vrací na výchozí.
 * Pořadí je písmo → tučně → velikost → zarovnání: příkaz písma (`ESC !`)
 * na tiskárně nastavuje i tučné a velikost, takže musí jít první.
 */
final class EscPosEncoder
{
    public function __construct(
        private readonly EscPosDriver $driver,
        private readonly CodePage $codePage,
    ) {
    }

    /** @param list<ReceiptLine|ReceiptCommand> $items */
    public function encode(array $items): string
    {
        $default = new ReceiptLine('');
        $state   = $default;
        $bytes   = $this->driver->init() . $this->driver->codePage($this->codePage->name());

        foreach ($items as $item) {
            if ($item instanceof ReceiptLine) {
                $bytes .= $this->style($state, $item)
                    . $this->codePage->encode($item->text)
                    . $this->driver->lineFeed();
                continue;
            }

            $bytes .= $this->style($state, $default);
            $bytes .= match ($item->type) {
                ReceiptCommand::FEED   => $this->driver->feed($item->lines),
                ReceiptCommand::QR     => $this->driver->align('center')
                    . $this->driver->qr($item->data)
                    . $this->driver->align('left'),
                ReceiptCommand::CUT    => $this->driver->cut(),
                ReceiptCommand::DRAWER => $this->driver->openCashDrawer(),
                default                => throw new \LogicException("Unknown receipt command '{$item->type}'"),
            };
        }

        return $bytes . $this->style($state, $default);
    }

    /** Příkazy, které převedou styl tiskárny ze `$state` na `$target`. */
    private function style(ReceiptLine &$state, ReceiptLine $target): string
    {
        $bytes  = '';
        $bold   = $state->bold;
        $width  = $state->width;
        $height = $state->height;

        if ($state->small !== $target->small) {
            $bytes .= $this->driver->font($target->small);
            // ESC ! n na tiskárně zruší tučné i velikost.
            $bold   = false;
            $width  = 1;
            $height = 1;
        }
        if ($bold !== $target->bold) {
            $bytes .= $this->driver->bold($target->bold);
        }
        if ($width !== $target->width || $height !== $target->height) {
            $bytes .= $this->driver->size($target->width, $target->height);
        }
        if ($state->align !== $target->align) {
            $bytes .= $this->driver->align($target->align);
        }

        $state = $target;

        return $bytes;
    }
}
