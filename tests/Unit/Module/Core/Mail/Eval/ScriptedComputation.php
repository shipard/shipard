<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Eval;

use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Analysis\AnalysisRequest;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;
use Shipard\Module\Core\Mail\Eval\PathReporting;

/**
 * Skriptovaný výpočet: fronta výsledků (nebo výjimek) per volání, zachycené
 * požadavky; volitelně cesta per volání (`paths`, výchozí `ai`) jako
 * {@see PathReporting} routeru (#103 D76).
 */
final class ScriptedComputation implements AnalysisComputing, PathReporting
{
    /** @var list<AnalysisRequest> */
    public array $requests = [];

    private string $lastPath = EvalCallResult::PATH_AI;

    /**
     * @param list<AnalysisOutcome|\Throwable> $script
     * @param list<string>                     $paths  cesta per volání v pořadí skriptu (chybí = `ai`)
     */
    public function __construct(private array $script, private array $paths = []) {}

    public function lastPath(): string
    {
        return $this->lastPath;
    }

    public function compute(AnalysisRequest $request): AnalysisOutcome
    {
        $this->requests[] = $request;
        $this->lastPath = array_shift($this->paths) ?? EvalCallResult::PATH_AI;
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
