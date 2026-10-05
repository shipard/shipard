<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

/**
 * Barva jako `#rrggbb` — jediný tvar, ve kterém ji nastavení ukládá
 * a šablony tisku vkládají do stylů. Cokoli jiného (zkrácený zápis, název
 * barvy, `rgb()`) barva není: do CSS se nesmí dostat volný řetězec.
 */
final class HexColor
{
    private const PATTERN = '/^#[0-9a-f]{6}$/';

    /** Barva malými písmeny, nebo null, když hodnota barvou není. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $color = strtolower(trim($value));
        return preg_match(self::PATTERN, $color) === 1 ? $color : null;
    }
}
