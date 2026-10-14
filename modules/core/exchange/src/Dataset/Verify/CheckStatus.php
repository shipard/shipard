<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Verify;

/** Stav jedné kontroly `dataset-verify` (tasks/testing-phase3.md D26). */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Diff = 'diff';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::Diff => 'rozdíl',
            self::Skipped => 'přeskočeno',
        };
    }
}
