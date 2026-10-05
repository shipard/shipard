<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Texts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Core\Prints\Texts\PrintTextCompiler;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedTagError;

/**
 * Úzká politika sandboxu pro texty na tiscích (#90 D50): uživatel smí
 * vypsat proměnnou, použít `if` a formátovací filtry tisku — nic dalšího.
 */
class PrintTextCompilerTest extends TestCase
{
    private const NBSP = "\u{00A0}";

    private const CONTEXT = [
        'data' => [
            'document' => ['number' => '2026000123', 'title' => 'Faktura'],
            'dates'    => ['due' => '2026-10-16'],
            'payment'  => ['amountToPay' => 1210.5, 'currency' => 'CZK', 'reference' => null],
            'rows'     => [['text' => 'a'], ['text' => 'b']],
        ],
        'meta'     => ['title' => 'Faktura 2026000123'],
        'language' => 'cs',
    ];

    private static function render(string $text, string $language = 'cs'): string
    {
        return (new PrintTextCompiler(new PrintTranslator([], $language)))->compile($text)->render(self::CONTEXT);
    }

    public function testVariablesConditionsAndFormatFilters(): void
    {
        $this->assertSame('Doklad 2026000123', self::render('Doklad {{ data.document.number }}'));
        $this->assertSame(
            'Splatnost 16.' . self::NBSP . '10.' . self::NBSP . '2026, částka 1' . self::NBSP . '210,50' . self::NBSP . 'CZK',
            self::render('Splatnost {{ data.dates.due|date }}, částka {{ data.payment.amountToPay|money(data.payment.currency) }}'),
        );
        $this->assertSame(
            'k úhradě',
            self::render('{% if data.payment.amountToPay > 0 %}k úhradě{% else %}uhrazeno{% endif %}'),
        );
        $this->assertSame('FAKTURA faktura', self::render('{{ data.document.title|upper }} {{ data.document.title|lower }}'));
        $this->assertSame('1,5 21' . self::NBSP . '%', self::render('{{ 1.5|qty }} {{ 21|pct }}'));
    }

    public function testFormatFollowsPrintLanguage(): void
    {
        $this->assertSame('1,210.50', self::render('{{ data.payment.amountToPay|money }}', 'en'));
        $this->assertSame('16' . self::NBSP . 'Oct' . self::NBSP . '2026', self::render('{{ data.dates.due|date }}', 'en'));
    }

    public function testDefaultAndDefinedHandleMissingValues(): void
    {
        $this->assertSame('—', self::render('{{ data.payment.reference|default("—") }}'));
        $this->assertSame('—', self::render('{{ data.payment.missing|default("—") }}'));
        $this->assertSame('ne', self::render('{% if data.customer is defined %}ano{% else %}ne{% endif %}'));
        $this->assertSame('prázdné', self::render('{% if data.payment.reference is empty %}prázdné{% endif %}'));
    }

    public function testMarkdownModeEscapesValuesButNotUserText(): void
    {
        $compiler = new PrintTextCompiler(new PrintTranslator([], 'cs'));
        $context  = ['data' => ['name' => 'Firma *Hvězda*', 'amount' => 1210.5]] + self::CONTEXT;
        $text     = '**{{ data.name }}** {{ data.name|upper }} {{ data.amount|money }} {{ "*x*" }}';

        // Slot stránky: hodnoty i výstup filtrů se escapují, text uživatele a jeho literály ne.
        $this->assertSame(
            '**Firma \\*Hvězda\\*** FIRMA \\*HVĚZDA\\* 1' . self::NBSP . '210\\,50 *x*',
            $compiler->compile($text, markdown: true)->render($context),
        );
        // E-mailový slot: prostý text, nic se neescapuje.
        $this->assertSame(
            '**Firma *Hvězda*** FIRMA *HVĚZDA* 1' . self::NBSP . '210,50 *x*',
            $compiler->compile($text)->render($context),
        );
    }

    public function testMarkdownModeKeepsTheSamePolicy(): void
    {
        $compiler = new PrintTextCompiler(new PrintTranslator([], 'cs'));

        $this->expectException(SecurityNotAllowedFilterError::class);
        $compiler->compile('{{ data.document.number|raw }}', markdown: true);
    }

    public function testUnknownVariableIsARuntimeError(): void
    {
        // Překlep v proměnné nesmí skončit prázdným místem na faktuře.
        $template = (new PrintTextCompiler(new PrintTranslator([], 'cs')))->compile('{{ data.document.numbr }}');

        $this->expectException(RuntimeError::class);
        $template->render(self::CONTEXT);
    }

    /** @return array<string, array{string, class-string<\Throwable>}> */
    public static function forbidden(): array
    {
        return [
            'cyklus'              => ['{% for row in data.rows %}{{ row.text }}{% endfor %}', SecurityNotAllowedTagError::class],
            'přiřazení'           => ['{% set x = 1 %}', SecurityNotAllowedTagError::class],
            'vložení šablony'     => ["{% include '@docs.core/_layout/doc-base.html.twig' %}", SecurityNotAllowedTagError::class],
            'dědění'              => ["{% extends '@docs.core/_layout/doc-base.html.twig' %}", SecurityNotAllowedTagError::class],
            'blok'                => ['{% block b %}x{% endblock %}', SecurityNotAllowedTagError::class],
            'makro'               => ['{% macro m() %}x{% endmacro %}', SecurityNotAllowedTagError::class],
            'apply'               => ['{% apply upper %}x{% endapply %}', SecurityNotAllowedTagError::class],
            'funkce include'      => ["{{ include('x') }}", SecurityNotAllowedFunctionError::class],
            'funkce source'       => ["{{ source('x') }}", SecurityNotAllowedFunctionError::class],
            'funkce constant'     => ["{{ constant('PHP_VERSION') }}", SecurityNotAllowedFunctionError::class],
            'překlad t()'         => ["{{ t('label.dueDate') }}", SecurityNotAllowedFunctionError::class],
            'QR kód'              => ['{{ qr_svg(data.payment) }}', SecurityNotAllowedFunctionError::class],
            // Operátor rozsahu je funkce `range` — bez ní nejde vyrobit obří pole.
            'rozsah'              => ['{% if 3 in 1..100000000 %}x{% endif %}', SecurityNotAllowedFunctionError::class],
            'filtr raw'           => ['{{ data.document.number|raw }}', SecurityNotAllowedFilterError::class],
            'filtr join'          => ["{{ data.rows|join(',') }}", SecurityNotAllowedFilterError::class],
            'filtr length'        => ['{{ data.rows|length }}', SecurityNotAllowedFilterError::class],
            'filtr nl2br'         => ['{{ data.document.title|nl2br }}', SecurityNotAllowedFilterError::class],
            'filtr map'           => ['{{ data.rows|map(r => r.text)|first }}', SecurityNotAllowedFilterError::class],
            'filtr format'        => ["{{ '%s'|format(data.document.number) }}", SecurityNotAllowedFilterError::class],
            'neuzavřená proměnná' => ['Doklad {{ data.document.number', SyntaxError::class],
            'neuzavřený if'       => ['{% if data.payment.amountToPay > 0 %}k úhradě', SyntaxError::class],
        ];
    }

    /** @param class-string<\Throwable> $error */
    #[DataProvider('forbidden')]
    public function testCompileRejects(string $text, string $error): void
    {
        $this->expectException($error);
        (new PrintTextCompiler(new PrintTranslator([], 'cs')))->compile($text);
    }

    public function testTextHasNoAccessToModuleTemplates(): void
    {
        // Žádný loader souborů — i kdyby politika `include` propustila,
        // šablony modulů prostředí textů nezná.
        try {
            (new PrintTextCompiler(new PrintTranslator([], 'cs')))->compile("{{ include('@docs.core/_layout/header.html.twig') }}");
            $this->fail('SecurityError expected');
        } catch (SecurityError $e) {
            $this->assertStringContainsString('include', $e->getMessage());
        }
    }

    public function testCheckDescribesProblemForUser(): void
    {
        $this->assertNull(PrintTextCompiler::check('Příští týden máme **dovolenou**.'));
        $this->assertNull(PrintTextCompiler::check('Doklad {{ data.document.number }} je splatný {{ data.dates.due|date }}.'));

        $this->assertSame(
            'Tag "for" is not allowed (řádek 2)',
            PrintTextCompiler::check("Řádky:\n{% for row in data.rows %}x{% endfor %}"),
        );
        $this->assertSame('Filter "raw" is not allowed (řádek 1)', PrintTextCompiler::check('{{ data.document.number|raw }}'));

        $syntax = (string) PrintTextCompiler::check('Doklad {{ data.document.number');
        $this->assertStringContainsString('řádek 1', $syntax);
        // Interní název šablony uživateli nic neřekne.
        $this->assertStringNotContainsString('__string_template__', $syntax);
    }
}
