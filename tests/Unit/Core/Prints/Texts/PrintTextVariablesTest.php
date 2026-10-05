<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints\Texts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Texts\PrintTextVariables;
use Shipard\Tests\Unit\Core\Prints\PrintDefinitionTest;

/**
 * Kurátorský seznam proměnných pro texty na tiscích (#90 D51): sdílená sada
 * v adresáři tisku + vlastní proměnné tisku, popisky z katalogů.
 */
class PrintTextVariablesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/shpd_printvars_' . uniqid('', true);
        mkdir($this->root . '/test/docs/prints/_layout', 0755, true);
        mkdir($this->root . '/test/docs/prints/invoice', 0755, true);
        mkdir($this->root . '/test/docs/prints/card', 0755, true);
        file_put_contents($this->root . '/test/docs/module.jsonc', '{"id": "test.docs", "name": "Docs"}');
        file_put_contents($this->root . '/test/docs/prints/_layout/text-variables.jsonc', <<<'JSONC'
            // sdílená sada
            [
                "data.document.number",
                { "path": "data.dates.due", "filter": "date" },
                { "path": "data.customer.name", "filter": "default('')" },
            ]
            JSONC);
        file_put_contents($this->root . '/test/docs/prints/_layout/messages.jsonc', (string) json_encode([
            'var.data.document.number' => ['cs' => 'Číslo dokladu', 'en' => 'Document number'],
            'var.data.dates.due'       => ['cs' => 'Datum splatnosti', 'en' => 'Due date'],
        ]));
        file_put_contents($this->root . '/test/docs/prints/invoice/messages.jsonc', (string) json_encode([
            'var.data.payment.amountToPay' => ['cs' => 'Částka k úhradě', 'en' => 'Amount due'],
        ]));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function variables(): PrintTextVariables
    {
        return new PrintTextVariables($this->paths());
    }

    private function paths(): PrintTemplatePaths
    {
        return new PrintTemplatePaths(new ModulePathResolver([$this->root]));
    }

    /** @param array<string, mixed> $overrides */
    private static function definition(array $overrides = []): PrintDefinition
    {
        return PrintDefinition::fromArray(PrintDefinitionTest::declaration($overrides + [
            'template'      => '@test.docs/invoice',
            'catalogs'      => ['@test.docs/_layout'],
            'textSlots'     => ['footer'],
            'textVariables' => ['@test.docs/_layout'],
        ]), 'test.docs');
    }

    public function testSharedSetIsLoadedFromPrintDirectory(): void
    {
        $this->assertSame([
            ['path' => 'data.document.number', 'filter' => null],
            ['path' => 'data.dates.due', 'filter' => 'date'],
            ['path' => 'data.customer.name', 'filter' => "default('')"],
        ], $this->variables()->forPrint(self::definition()));
    }

    public function testOwnVariablesFollowTheSetAndFirstDeclarationOfPathWins(): void
    {
        $definition = self::definition(['textVariables' => [
            '@test.docs/_layout',
            ['path' => 'data.payment.amountToPay', 'filter' => 'money(data.payment.currency)'],
            // Stejná cesta jako v sadě — platí sada.
            ['path' => 'data.dates.due'],
            'meta.title',
        ]]);

        $this->assertSame(
            ['data.document.number', 'data.dates.due', 'data.customer.name', 'data.payment.amountToPay', 'meta.title'],
            array_column($this->variables()->forPrint($definition), 'path'),
        );
        $this->assertSame('date', $this->variables()->forPrint($definition)[1]['filter']);
    }

    public function testPrintWithoutVariablesOffersNone(): void
    {
        $this->assertSame([], $this->variables()->forPrint(self::definition(['textVariables' => []])));
    }

    public function testDescribeGivesLabelInLanguageAndExampleWithFilter(): void
    {
        $definition = self::definition(['textVariables' => [
            '@test.docs/_layout',
            ['path' => 'data.payment.amountToPay', 'filter' => 'money(data.payment.currency)'],
        ]]);
        $catalogs = new PrintCatalogLoader($this->paths());

        $this->assertSame([
            ['path' => 'data.document.number', 'label' => 'Číslo dokladu', 'example' => '{{ data.document.number }}'],
            ['path' => 'data.dates.due', 'label' => 'Datum splatnosti', 'example' => '{{ data.dates.due|date }}'],
            // Bez popisku v katalogu zůstává cesta — proměnná z nabídky nevypadne.
            ['path' => 'data.customer.name', 'label' => 'data.customer.name', 'example' => "{{ data.customer.name|default('') }}"],
            [
                'path'    => 'data.payment.amountToPay',
                'label'   => 'Částka k úhradě',
                'example' => '{{ data.payment.amountToPay|money(data.payment.currency) }}',
            ],
        ], $this->variables()->describe([$definition], $catalogs, 'cs'));

        $this->assertSame(
            ['Document number', 'Due date', 'data.customer.name', 'Amount due'],
            array_column($this->variables()->describe([$definition], $catalogs, 'en'), 'label'),
        );
    }

    public function testDescribeOfMorePrintsIsTheirIntersection(): void
    {
        $invoice = self::definition(['textVariables' => ['@test.docs/_layout', 'data.payment.amountToPay']]);
        $card    = self::definition([
            'id' => 'test.docs.card', 'template' => '@test.docs/card',
            'textVariables' => ['data.dates.due', 'meta.title', 'data.document.number'],
        ]);
        $catalogs = new PrintCatalogLoader($this->paths());

        // Pořadí a filtr prvního tisku.
        $this->assertSame(
            ['{{ data.document.number }}', '{{ data.dates.due|date }}'],
            array_column($this->variables()->describe([$invoice, $card], $catalogs, 'cs'), 'example'),
        );
        $this->assertSame(
            ['{{ data.dates.due }}', '{{ data.document.number }}'],
            array_column($this->variables()->describe([$card, $invoice], $catalogs, 'cs'), 'example'),
        );
        $this->assertSame([], $this->variables()->describe([], $catalogs, 'cs'));
    }

    public function testMissingSetIsDeclarationError(): void
    {
        $definition = self::definition(['textVariables' => ['@test.docs/invoice']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("text variable set '@test.docs/invoice' has no text-variables.jsonc");
        $this->variables()->forPrint($definition);
    }

    public function testInvalidSetIsDeclarationError(): void
    {
        file_put_contents($this->root . '/test/docs/prints/_layout/text-variables.jsonc', '[{"path": "document.number"}]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Text variable set '@test.docs/_layout': text variable must be a path");
        $this->variables()->forPrint(self::definition());
    }

    /** @return array<string, array{mixed}> */
    public static function invalidEntries(): array
    {
        return [
            'bez kořene data / meta' => ['document.number'],
            'jen kořen'              => ['data'],
            'cizí kořen'             => ['branding.logo'],
            'výraz místo cesty'      => ['data.rows|length'],
            'index pole'             => ['data.rows[0].text'],
            'není řetězec'           => [5],
            'objekt bez cesty'       => [['filter' => 'date']],
            'nepovolený filtr'       => [['path' => 'data.x', 'filter' => 'raw']],
            'filtr s kódem'          => [['path' => 'data.x', 'filter' => 'default(include("x"))']],
            'filtr není řetězec'     => [['path' => 'data.x', 'filter' => ['date']]],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function testInvalidEntryIsRejected(mixed $entry): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PrintTextVariables::parse($entry);
    }

    public function testValidEntries(): void
    {
        $this->assertSame(['path' => 'language', 'filter' => null], PrintTextVariables::parse('language'));
        $this->assertSame(['path' => 'meta.title', 'filter' => 'upper'], PrintTextVariables::parse(['path' => 'meta.title', 'filter' => 'upper']));
        $this->assertSame('{{ data.totals.total|money }}', PrintTextVariables::example('data.totals.total', 'money'));
    }
}
