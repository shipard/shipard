<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\PrintTranslator;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Twig\Error\RuntimeError;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;

/**
 * Twig prostředí tisků (#90 D6): sandbox zapnutý globálně, šablona dostává
 * jen pole. Šablony jsou v dočasném stromu modulů.
 */
class PrintTwigSandboxTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shpd_printtwig_' . uniqid('', true);
        mkdir($this->root . '/test/prints/prints/sample', 0755, true);
        mkdir($this->root . '/test/shared/prints/_layout', 0755, true);
        file_put_contents($this->root . '/test/prints/module.jsonc', '{"id": "test.prints", "name": "Prints"}');
        file_put_contents($this->root . '/test/shared/module.jsonc', '{"id": "test.shared", "name": "Shared"}');
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->root);
    }

    private function rmTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, array $context = [], ?string $cacheDir = null): string
    {
        file_put_contents($this->root . '/test/prints/prints/sample/page.html.twig', $template);

        $factory = new PrintTwigFactory(
            new PrintTemplatePaths(new ModulePathResolver([$this->root])),
            $cacheDir,
        );
        $twig = $factory->create(new PrintTranslator(['hello' => ['cs' => 'Ahoj {name}']], 'cs'));

        return $twig->render('@test.prints/sample/page.html.twig', $context);
    }

    public function testAllowedConstructsRender(): void
    {
        file_put_contents(
            $this->root . '/test/shared/prints/_layout/base.html.twig',
            '<main>{% block body %}base{% endblock %}</main>',
        );
        file_put_contents(
            $this->root . '/test/shared/prints/_layout/part.html.twig',
            '<i>{{ label }}</i>',
        );

        $html = $this->render(
            <<<'TWIG'
            {% extends '@test.shared/_layout/base.html.twig' %}
            {% block body %}
                {{ parent() }}
                {% set total = 0 %}
                {% for row in rows %}
                    {% if row.amount is not null %}{{ row.name|upper }}={{ row.amount|money('CZK') }};{% endif %}
                {% endfor %}
                {{ t('hello', { name: who }) }}
                {{ missing|default('výchozí') }}
                {{ note|nl2br }}
                {% include '@test.shared/_layout/part.html.twig' with { label: rows|length } only %}
                {{ rows is empty ? 'nic' : (rows|first).name }}
            {% endblock %}
            TWIG,
            [
                'rows' => [['name' => 'a', 'amount' => 1210.5], ['name' => 'b', 'amount' => null]],
                'who'  => '<b>světe</b>',
                'note' => "první\ndruhý",
            ],
        );

        $this->assertStringContainsString('<main>', $html);
        $this->assertStringContainsString('base', $html);
        $this->assertStringContainsString("A=1\u{00A0}210,50\u{00A0}CZK;", $html);
        $this->assertStringNotContainsString('B=', $html);
        $this->assertStringContainsString('Ahoj &lt;b&gt;světe&lt;/b&gt;', $html, 'autoescape');
        $this->assertStringContainsString('výchozí', $html);
        $this->assertStringContainsString("první<br />\ndruhý", $html);
        $this->assertStringContainsString('<i>2</i>', $html);
    }

    public function testRawIsAllowedForUserTextSlots(): void
    {
        // Jediné použití: sloty uživatelských textů, jejichž HTML vyrobil
        // Markdown z escapovaného vstupu (#90 D50). Že šablony `|raw` nikde
        // jinde nepoužívají, hlídá PrintTemplateRawRuleTest.
        $html = $this->render(
            "{{ texts.footer|default('')|raw }}{{ texts.header|default('')|raw }}",
            ['texts' => ['footer' => '<div class="print-text"><p>Děkujeme.</p></div>']],
        );

        $this->assertSame('<div class="print-text"><p>Děkujeme.</p></div>', $html);
    }

    /** @return array<string, array{string, class-string<SecurityError>}> */
    public static function forbiddenTemplates(): array
    {
        return [
            'tag macro'        => ['{% macro x() %}{% endmacro %}', SecurityNotAllowedTagError::class],
            'tag import'       => ["{% import '@test.prints/sample/page.html.twig' as m %}", SecurityNotAllowedTagError::class],
            'tag do'           => ['{% do 1 + 1 %}', SecurityNotAllowedTagError::class],
            'filter map'       => ["{{ rows|map(r => r)|join(',') }}", SecurityNotAllowedFilterError::class],
            'filter format'    => ["{{ '%s'|format(value) }}", SecurityNotAllowedFilterError::class],
            'function range'   => ['{{ range(1, 3)|join }}', SecurityNotAllowedFunctionError::class],
            'function constant' => ["{{ constant('PHP_VERSION') }}", SecurityNotAllowedFunctionError::class],
            'function source'  => ["{{ source('@test.prints/sample/page.html.twig') }}", SecurityNotAllowedFunctionError::class],
            'method call'      => ['{{ object.secret() }}', SecurityNotAllowedMethodError::class],
            'property read'    => ['{{ object.visible }}', SecurityNotAllowedPropertyError::class],
        ];
    }

    /** @param class-string<SecurityError> $error */
    #[DataProvider('forbiddenTemplates')]
    public function testSandboxRejects(string $template, string $error): void
    {
        $this->expectException($error);
        $this->render($template, ['value' => 'x', 'rows' => [1], 'object' => new SandboxProbe()]);
    }

    public function testObjectCannotBePrintedThroughToString(): void
    {
        $this->expectException(SecurityNotAllowedMethodError::class);
        $this->render('{{ object }}', ['object' => new SandboxProbe()]);
    }

    public function testUnknownVariableIsAnError(): void
    {
        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage('"totals"');
        $this->render('{{ totals.total }}', []);
    }

    public function testCompiledTemplatesGoToCacheDirectory(): void
    {
        $cacheDir = $this->root . '/cache/twig';

        $this->assertSame('obsah', $this->render('obsah', [], $cacheDir));

        $compiled = glob($cacheDir . '/*/*.php') ?: [];
        $this->assertCount(1, $compiled, 'adresář cache vznikne sám a nese kompilovanou šablonu');
    }
}

class SandboxProbe
{
    public string $visible = 'vidět';

    public function secret(): string
    {
        return 'tajné';
    }

    public function __toString(): string
    {
        return 'objekt';
    }
}
