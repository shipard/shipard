<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Exchange\Dataset\Round;

/**
 * Rozvrh kola z výběru ({@see RoundSelection}) a stavu serveru
 * ({@see RoundPlanner}): kroky vrstvy kódu, sady se zdroji dat, demo sady
 * se scénáři a zdroji dat pro videa, neplatné scénáře a problémy nástrojů
 * (proklikání / videa) — ty kolo zapíše jako `failed` kroky bez spouštění
 * dílčích úloh (N9).
 */
final readonly class RoundPlan
{
    /**
     * @param list<CodeStep>          $codeSteps
     * @param list<RoundDatasetPlan>  $datasets
     * @param list<RoundVideoPlan>    $videos
     * @param list<RoundScenarioPlan> $invalidScenarios
     * @param list<string>            $e2eProblems
     * @param list<string>            $videoProblems
     */
    public function __construct(
        public array $codeSteps,
        public array $datasets,
        public array $videos,
        public array $invalidScenarios,
        public array $e2eProblems,
        public array $videoProblems,
    ) {}

    /** @return list<RoundDatasetPlan> sady, kterým chybí zdroj dat kola */
    public function datasetsToCreate(): array
    {
        return array_values(array_filter($this->datasets, static fn (RoundDatasetPlan $d): bool => $d->needsCreate()));
    }
}
