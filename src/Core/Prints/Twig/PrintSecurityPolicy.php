<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Twig;

use Twig\Sandbox\SecurityPolicy;

/**
 * Politiky Twig sandboxu pro tisky (#90 D6). Šablona jen formátuje a skládá
 * — logika patří do builderu — a dostává výhradně pole z `PrintData`,
 * proto nejsou povolené žádné metody ani vlastnosti objektů.
 *
 * `templates()` je široká politika pro systémové šablony z modulů. Úzkou
 * politiku pro uživatelské texty na tiscích (`userTexts()`: výpis proměnné,
 * `if`, formátovací filtry — #90 D9) zavede fáze 3.
 */
final class PrintSecurityPolicy
{
    public const TEMPLATE_TAGS = ['if', 'for', 'set', 'block', 'extends', 'include', 'apply'];

    public const TEMPLATE_FILTERS = [
        'escape', 'e', 'default', 'length', 'join', 'upper', 'lower', 'nl2br',
        'first', 'last', 'keys', 'merge',
        // PrintTwigExtension
        'money', 'qty', 'pct', 'date',
    ];

    public const TEMPLATE_FUNCTIONS = ['t', 'qr_svg', 'block', 'parent', 'include'];

    public const TEMPLATE_TESTS = ['defined', 'null', 'none', 'empty', 'same as', 'even', 'odd', 'iterable'];

    public static function templates(): SecurityPolicy
    {
        $policy = new SecurityPolicy(
            allowedTags: self::TEMPLATE_TAGS,
            allowedFilters: self::TEMPLATE_FILTERS,
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: self::TEMPLATE_FUNCTIONS,
            allowedTests: self::TEMPLATE_TESTS,
        );
        // Nic není povolené mlčky (`extends`, `block()`, testy) — jen to,
        // co je ve výčtech výše.
        $policy->setStrict(true);
        return $policy;
    }
}
