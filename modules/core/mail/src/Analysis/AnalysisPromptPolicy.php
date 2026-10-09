<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Analysis;

use Twig\Sandbox\SecurityPolicy;

/**
 * Politika Twig sandboxu pro `prompt_template` AI profilu
 * (tasks/mail-analysis-inprocess.md D17): šablona jen skládá text z polí
 * kontextu — jen tagy `if`, `for`, `set` a formátovací filtry; žádné
 * funkce, metody ani vlastnosti objektů (kontext jsou pole). Striktní:
 * co není ve výčtu, je zakázané.
 */
final class AnalysisPromptPolicy
{
    public const TAGS = ['if', 'for', 'set'];

    public const FILTERS = ['length', 'default', 'join', 'trim', 'lower', 'upper'];

    public static function create(): SecurityPolicy
    {
        $policy = new SecurityPolicy(
            allowedTags: self::TAGS,
            allowedFilters: self::FILTERS,
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: [],
            allowedTests: [],
        );
        $policy->setStrict(true);
        return $policy;
    }
}
