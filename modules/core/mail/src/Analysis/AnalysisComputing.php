<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Výpočet analýzy zprávy ({@see AnalysisComputation}) jako rozhraní —
 * evaluace (`ai-eval`, tasks/testing-phase4.md) ho v testech nahrazuje
 * skriptovaným výsledkem bez modelu.
 */
interface AnalysisComputing
{
    public function compute(AnalysisRequest $request): AnalysisOutcome;
}
