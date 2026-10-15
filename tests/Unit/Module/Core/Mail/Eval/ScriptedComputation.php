<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;

/** Skriptovaný výpočet: fronta výsledků (nebo výjimek) per volání, zachycené požadavky. */
final class ScriptedComputation implements AnalysisComputing
{
    /** @var list<AnalysisRequest> */
    public array $requests = [];

    /** @param list<AnalysisOutcome|\Throwable> $script */
    public function __construct(private array $script) {}

    public function compute(AnalysisRequest $request): AnalysisOutcome
    {
        $this->requests[] = $request;
        $next = array_shift($this->script);
        if ($next === null) {
            throw new \LogicException('ScriptedComputation: no more scripted outcomes');
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }
}
