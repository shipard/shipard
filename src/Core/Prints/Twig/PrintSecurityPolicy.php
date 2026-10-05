<?php

declare(strict_types=1);

namespace Shipard\Core\Prints\Twig;

use Twig\Sandbox\SecurityPolicy;

/**
 * Politiky Twig sandboxu pro tisky (#90 D6). Šablona jen formátuje a skládá
 * — logika patří do builderu — a dostává výhradně pole z `PrintData`,
 * proto nejsou povolené žádné metody ani vlastnosti objektů.
 *
 * `templates()` je široká politika pro systémové šablony z modulů.
 * `userTexts()` je úzká politika pro texty na tiscích, které píše uživatel
 * v Nastavení (#90 D9, D50): výpis proměnné, `if` a formátovací filtry —
 * žádné cykly, proměnné, vkládání šablon ani funkce.
 */
final class PrintSecurityPolicy
{
    public const TEMPLATE_TAGS = ['if', 'for', 'set', 'block', 'extends', 'include', 'apply'];

    /**
     * `raw` je jen pro sloty uživatelských textů (`texts.*`) — jejich HTML
     * vyrobil `PrintTextMarkdown` z escapovaného vstupu. Na nic jiného ho
     * šablona použít nesmí; hlídá `PrintTemplateRawRuleTest`.
     */
    public const TEMPLATE_FILTERS = [
        'escape', 'e', 'raw', 'default', 'length', 'join', 'upper', 'lower', 'nl2br',
        'first', 'last', 'keys', 'merge',
        // PrintTwigExtension
        'money', 'qty', 'pct', 'date',
    ];

    public const TEMPLATE_FUNCTIONS = ['t', 'qr_svg', 'block', 'parent', 'include'];

    public const TEMPLATE_TESTS = ['defined', 'null', 'none', 'empty', 'same as', 'even', 'odd', 'iterable'];

    public const USER_TEXT_TAGS = ['if'];

    /**
     * `escape` nepíše uživatel, ale autoescape Twigu: hodnoty v textech pro
     * stránku tisku se escapují pro Markdown a sandbox vidí i tento filtr.
     */
    public const USER_TEXT_FILTERS = [
        'default', 'upper', 'lower', 'escape',
        // PrintTwigExtension
        'money', 'qty', 'pct', 'date',
    ];

    public const USER_TEXT_TESTS = ['defined', 'empty', 'null', 'none'];

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

    public static function userTexts(): SecurityPolicy
    {
        $policy = new SecurityPolicy(
            allowedTags: self::USER_TEXT_TAGS,
            allowedFilters: self::USER_TEXT_FILTERS,
            allowedMethods: [],
            allowedProperties: [],
            allowedFunctions: [],
            allowedTests: self::USER_TEXT_TESTS,
        );
        $policy->setStrict(true);
        return $policy;
    }
}
