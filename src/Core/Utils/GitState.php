<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

use Shipard\Core\DevUpdate\Git;

/**
 * Stav git checkoutu pro soubory výsledků (evaluace AI, D36): krátký
 * hash HEAD a příznak neuložených změn. Git běží přes {@see Git} — argv
 * bez shellu, binárka z pevného `PATH`, ne z prostředí procesu (#103
 * D135). Bez git binárky nebo `.git` obojí null — nikdy výjimka.
 */
final class GitState
{
    private function __construct()
    {
    }

    /**
     * `$git` je vstřik pro testy (výchozí {@see Git} nad `$root`, resp. nad
     * kořenem checkoutu). `dirty` = necommitnuté změny sledovaných souborů;
     * null, když `git status` selže.
     *
     * @return array{commit: ?string, dirty: ?bool}
     */
    public static function describe(?string $root = null, ?Git $git = null): array
    {
        $git ??= new Git($root ?? dirname(__DIR__, 3));
        $commit = $git->shortHash();
        if ($commit === null) {
            return ['commit' => null, 'dirty' => null];
        }
        return ['commit' => $commit, 'dirty' => $git->isDirty()];
    }
}
