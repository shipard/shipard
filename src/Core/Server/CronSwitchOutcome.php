<?php

declare(strict_types=1);

namespace Shipard\Core\Server;

/**
 * Výsledek přepnutí periodických úloh jednoho zdroje dat (#115 D14).
 * `Changed` a `Unchanged` jsou v pořádku; ostatní hodnoty říkají, proč se
 * `state.json` nezapsal.
 */
enum CronSwitchOutcome: string
{
    case Changed = 'changed';
    case Unchanged = 'unchanged';
    case InvalidId = 'invalid_id';
    case Unknown = 'unknown';
    case Corrupted = 'corrupted';
    case WriteFailed = 'write_failed';

    public function isOk(): bool
    {
        return $this === self::Changed || $this === self::Unchanged;
    }
}
