<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Compare;

/**
 * Stav jednoho listu projekce v režimu evaluace (#103 D37,
 * tasks/testing-phase4.md § 5): shoda beze změny, shoda až po normalizaci
 * tvaru (D15 — podklad pro #118) nebo podobnosti textu, očekávaná hodnota
 * chybí („model nenašel“), hodnota navíc („na dokladu není“), nebo obě
 * strany mají hodnotu a liší se. `notCarried` (#103 D76): pole, které
 * zdroj výstupu z principu nenese (ISDOC — `IsdocFieldCoverage`) — ani
 * shoda, ani odchylka, do počtů polí nejde.
 */
enum FieldState: string
{
    case Match = 'match';
    case MatchNormalized = 'matchNormalized';
    case Missing = 'missing';
    case Extra = 'extra';
    case Wrong = 'wrong';
    case NotCarried = 'notCarried';

    /** Shoda (beze změny nebo po normalizaci). */
    public function isHit(): bool
    {
        return $this === self::Match || $this === self::MatchNormalized;
    }

    /** Počítá se do polí (`n`, shoda, odchylky) — vše kromě `notCarried`. */
    public function isCounted(): bool
    {
        return $this !== self::NotCarried;
    }
}
