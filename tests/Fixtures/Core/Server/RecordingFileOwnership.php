<?php

declare(strict_types=1);

namespace Shipard\Tests\Fixtures\Core\Server;

use Shipard\Core\Server\FileOwnership;

/**
 * `FileOwnership` pro testy bez roota: vlastníka vybraných cest podvrhne,
 * `chown` / `chgrp` zaznamená a vrátí nastavený výsledek místo volání OS.
 */
final class RecordingFileOwnership extends FileOwnership
{
    /** @var array<string, array{uid: int, gid: int}|null> podvržený vlastník per cesta */
    public array $owners = [];

    /** @var list<string> cesty, na které se zapisovatel ptal (v pořadí) */
    public array $lookups = [];

    /** @var list<array{0: string, 1: string, 2: int}> [chown|chgrp, cesta, id] */
    public array $calls = [];

    public bool $chownResult = true;
    public bool $chgrpResult = true;

    protected function ownerOf(string $path): ?array
    {
        $this->lookups[] = $path;
        if (array_key_exists($path, $this->owners)) {
            return $this->owners[$path];
        }
        return parent::ownerOf($path);
    }

    protected function chown(string $path, int $uid): bool
    {
        $this->calls[] = ['chown', $path, $uid];
        return $this->chownResult;
    }

    protected function chgrp(string $path, int $gid): bool
    {
        $this->calls[] = ['chgrp', $path, $gid];
        return $this->chgrpResult;
    }
}
