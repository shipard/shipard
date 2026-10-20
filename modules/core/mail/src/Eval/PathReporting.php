<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Eval;

/**
 * Výpočet, který umí říct, kterou cestou šel poslední výsledek (#103 D76):
 * `ai` = extrakce modelem, `isdoc` = deterministický import ISDOC + štítky.
 * {@see EvalRunner} ji čte po každém volání; výpočet bez rozhraní je `ai`.
 */
interface PathReporting
{
    /** Cesta posledního výpočtu: {@see EvalCallResult::PATH_AI} / {@see EvalCallResult::PATH_ISDOC}. */
    public function lastPath(): string;
}
