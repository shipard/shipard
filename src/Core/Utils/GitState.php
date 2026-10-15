<?php

declare(strict_types=1);

namespace Shipard\Core\Utils;

/**
 * Stav git checkoutu pro soubory výsledků (evaluace AI, D36): krátký
 * hash HEAD a příznak neuložených změn. Bez git binárky nebo `.git`
 * obojí null — nikdy výjimka.
 */
final class GitState
{
    private function __construct()
    {
    }

    /** @return array{commit: ?string, dirty: ?bool} */
    public static function describe(?string $root = null): array
    {
        $root ??= dirname(__DIR__, 3);
        $commit = self::exec($root, 'rev-parse --short HEAD');
        if ($commit === null || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
            return ['commit' => null, 'dirty' => null];
        }
        $status = self::exec($root, 'status --porcelain --untracked-files=no');
        return ['commit' => $commit, 'dirty' => $status === null ? null : $status !== ''];
    }

    private static function exec(string $root, string $args): ?string
    {
        $lines = [];
        $exitCode = 1;
        @exec('git -C ' . escapeshellarg($root) . ' ' . $args . ' 2>/dev/null', $lines, $exitCode);
        return $exitCode === 0 ? trim(implode("\n", $lines)) : null;
    }
}
