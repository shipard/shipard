<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Prints\Jobs;

/**
 * Stav tiskové úlohy (kontrakt docs/native-bridge.md §5): `created` →
 * `sent` | `failed` podle posledního nahlášeného výsledku. Úloha bez
 * výsledku (`created`) je pro uživatele „neověřeno“.
 */
enum PrintJobState: string
{
    case Created = 'created';
    case Sent    = 'sent';
    case Failed  = 'failed';

    /** Stavy, které smí nahlásit klient jako výsledek `print.submit`. */
    public static function reportable(mixed $value): ?self
    {
        $state = is_string($value) ? self::tryFrom($value) : null;
        return $state === self::Sent || $state === self::Failed ? $state : null;
    }
}
