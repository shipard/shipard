<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

/**
 * Pipeline stav AI analýzy zprávy (`analysis_state`, cfgItem
 * `core.mail.analysisStates`) — ortogonální k workflow stavu `docState`,
 * řídí ho výhradně pipeline (claim / result / failed / reaper) a reanalýza.
 *
 * Jediný zdroj hodnot pro služby analýzy (tasks/mail-analysis-inprocess.md
 * D13); `AnalysisController` je drží pod dnešními názvy jako aliasy.
 */
final class AnalysisStates
{
    public const NONE = 0;
    public const QUEUED = 10;
    public const ANALYZING = 20;
    public const ANALYZED = 30;
    public const FAILED = 70;

    /**
     * `preprocess_state` (core.mail.preprocessStates), ve kterých zpráva do
     * fronty nepatří — technické předzpracování ještě nedoběhlo (gate,
     * tasks/mail-preprocess.md D9). Ortogonální k analysis_state.
     */
    public const PREPROCESS_BLOCKING_STATES = [10, 20];
}
