<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;
use Shipard\Core\Settings\BrandingStorage;

/**
 * PrintRunner s fake builderem: obálka `PrintData`, dostupnost tisku pro
 * záznam, jazyk tisku a chybové větve. DB je stub — runner z ní čte jen
 * jeden řádek tabulky deklarace.
 */
class PrintRunnerTest extends TestCase
{
    private const RECORD = ['id' => 123, 'doc_type' => 'invno', 'docState' => 40, 'doc_number' => '2026000123'];

    private ?string $dsPath = null;

    protected function setUp(): void
    {
        FakePrintBuilder::$lastRequest = null;
    }

    protected function tearDown(): void
    {
        if ($this->dsPath !== null) {
            $this->rmTree($this->dsPath);
        }
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

    /**
     * @param array<string, mixed>|null $record
     * @param array<string, mixed> $declaration
     * @param list<string> $configLanguages Sem runner zapíše jazyky, pro které chtěl konfiguraci.
     * @param array<string, array<string, string>>|null $catalog Katalog překladů tisku (klíč → jazyk → text).
     */
    private function runner(
        ?array $record = self::RECORD,
        array $declaration = [],
        string $defaultLanguage = 'cs',
        ?BrandingStorage $branding = null,
        array &$configLanguages = [],
        ?array $catalog = null,
    ): PrintRunner {
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(
            PrintDefinitionTest::declaration($declaration + ['builder' => FakePrintBuilder::class]),
            'docs.invoicesOut',
        ));

        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($record);

        return new PrintRunner(
            $registry,
            $db,
            static function (string $language) use (&$configLanguages) {
                $configLanguages[] = $language;
                return null;
            },
            new PrintLanguageResolver($defaultLanguage),
            $branding,
            $catalog === null ? null : $this->catalogLoader($catalog),
            clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-02T10:30:00+02:00'),
        );
    }

    /**
     * Katalog tisku jako `messages.jsonc` v adresáři šablony dočasného
     * modulu — deklarace testu míří na `@docs.invoicesOut/invoice`.
     *
     * @param array<string, array<string, string>> $catalog
     */
    private function catalogLoader(array $catalog): PrintCatalogLoader
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_prints_' . uniqid('', true);
        $modules  = $this->dsPath . '/modules';
        $template = $modules . '/docs/invoicesOut/prints/invoice';
        mkdir($template, 0755, true);
        file_put_contents($modules . '/docs/invoicesOut/module.jsonc', '{"id": "docs.invoicesOut", "name": "Invoices"}');
        file_put_contents($template . '/messages.jsonc', (string) json_encode($catalog));

        return new PrintCatalogLoader(new PrintTemplatePaths(new ModulePathResolver([$modules])));
    }

    public function testJsonRunWrapsBuilderResultInEnvelope(): void
    {
        $output = $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame(PrintFormat::Json, $output->format);
        $this->assertNull($output->pdfContent);
        $this->assertSame(
            [
                'printId'     => 'docs.invoicesOut.invoice',
                'version'     => 3,
                'language'    => 'cs',
                'record'      => ['table' => 'docs_core_heads', 'id' => 123, 'docState' => 40],
                'generatedAt' => '2026-10-02T10:30:00+02:00',
                'meta'        => [
                    'title' => 'Faktura 2026000123', 'fileName' => 'faktura-2026000123.pdf', 'watermark' => null,
                ],
                'branding'    => ['logo' => null],
                'texts'       => [],
                'messages'    => [['severity' => 'warning', 'code' => 'qr.noAccount', 'text' => 'QR nevznikl']],
                'data'        => ['document' => ['number' => '2026000123']],
            ],
            $output->printData->toArray(),
        );
    }

    public function testJsonKeepsEmptyTextsAsObject(): void
    {
        $output = $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $json = (string) json_encode($output->printData);
        $this->assertStringContainsString('"texts":{}', $json);
        $this->assertStringContainsString('"messages":[{', $json);
    }

    public function testBuilderGetsLoadedRecordAndConfigInPrintLanguage(): void
    {
        $configLanguages = [];
        $this->runner(defaultLanguage: 'cs', configLanguages: $configLanguages)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'en');

        $request = FakePrintBuilder::$lastRequest;
        $this->assertInstanceOf(PrintRequest::class, $request);
        $this->assertSame('docs.invoicesOut.invoice', $request->definition->id);
        $this->assertSame(123, $request->recordId);
        $this->assertSame(self::RECORD, $request->record);
        $this->assertSame('en', $request->language);
        $this->assertSame(['en'], $configLanguages, 'konfigurace v jazyce tisku, ne v jazyce requestu');
    }

    public function testLanguageDefaultsToDataSourceLanguage(): void
    {
        $output = $this->runner(defaultLanguage: 'en')->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('en', $output->printData->language);

        // Výchozí jazyk DS mimo podporované → en, tisk nespadne.
        $output = $this->runner(defaultLanguage: 'de')->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('en', $output->printData->language);
    }

    public function testUnsupportedRequestedLanguageThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter 'language' must be one of cs|en");
        $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'de');
    }

    public function testUnknownPrintThrows(): void
    {
        $this->expectException(PrintNotFoundException::class);
        $this->runner()->run('docs.invoicesOut.missing', 123, PrintFormat::Json);
    }

    public function testMissingRecordThrows(): void
    {
        $this->expectException(PrintRecordNotFoundException::class);
        $this->runner(record: null)->run('docs.invoicesOut.invoice', 999, PrintFormat::Json);
    }

    public function testDraftIsNotAvailable(): void
    {
        $this->expectException(PrintNotAvailableException::class);
        $this->expectExceptionMessage('current state');
        $this->runner(record: ['docState' => 10] + self::RECORD)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
    }

    public function testOtherRecordTypeIsNotAvailable(): void
    {
        $this->expectException(PrintNotAvailableException::class);
        $this->expectExceptionMessage('kind of record');
        $this->runner(record: ['doc_type' => 'invni'] + self::RECORD)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
    }

    // ── vodoznak (D23) ──────────────────────────────────────────────────────

    public function testWatermarkOfRecordStateIsTranslatedIntoMeta(): void
    {
        $declaration = ['docStates' => [40, 30], 'watermarks' => ['30' => 'watermark.cancelled'], 'catalogs' => []];
        $catalog = ['watermark.cancelled' => ['cs' => 'STORNO', 'en' => 'CANCELLED']];

        $cancelled = ['docState' => 30] + self::RECORD;
        $output = $this->runner(record: $cancelled, declaration: $declaration, catalog: $catalog)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('STORNO', $output->printData->watermark);
        $this->assertSame('STORNO', $output->printData->toArray()['meta']['watermark']);
        $this->assertSame('faktura-2026000123.pdf', $output->printData->fileName, 'název souboru se stornem nemění');

        $output = $this->runner(record: $cancelled, declaration: $declaration, catalog: $catalog)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'en');
        $this->assertSame('CANCELLED', $output->printData->watermark);

        // Stav bez klíče ve `watermarks` vodoznak nemá.
        $output = $this->runner(declaration: $declaration, catalog: $catalog)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertNull($output->printData->watermark);
    }

    public function testBuilderClassMustImplementInterface(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not implement PrintBuilder');
        $this->runner(declaration: ['builder' => \stdClass::class])
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
    }

    public function testLogoFromBrandingBecomesAssetName(): void
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_prints_' . uniqid('', true);
        mkdir($this->dsPath . '/branding', 0755, true);
        file_put_contents($this->dsPath . '/branding/companyLogo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $output = $this->runner(branding: new BrandingStorage($this->dsPath))
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame(['logo' => 'logo.svg'], $output->printData->toArray()['branding']);
    }

    /**
     * Runner s rendererem nad šablonou v dočasném modulu `test.prints`
     * (stránka + CSS asset + katalog). Bez enginu = render služba
     * nenakonfigurovaná; bez `$withDb` runner nemá spojení do databáze.
     */
    private function templateRunner(?RenderEngineInterface $engine = null, bool $withDb = true): PrintRunner
    {
        $this->dsPath = sys_get_temp_dir() . '/shpd_prints_' . uniqid('', true);
        $modules = $this->dsPath . '/modules';
        $template = $modules . '/test/prints/prints/sample';
        mkdir($template, 0755, true);
        file_put_contents($modules . '/test/prints/module.jsonc', '{"id": "test.prints", "name": "Prints"}');
        file_put_contents(
            $template . '/page.html.twig',
            '<h1>{{ meta.title }}</h1><p>{{ t(\'label.number\') }} {{ data.document.number }}</p>',
        );
        file_put_contents($template . '/style.css', 'h1 { color: #000; }');
        file_put_contents(
            $template . '/messages.jsonc',
            '{ "label.number": { "cs": "Číslo", "en": "Number" } }',
        );

        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(
            PrintDefinitionTest::declaration([
                'builder' => FakePrintBuilder::class, 'template' => '@test.prints/sample', 'catalogs' => [],
            ]),
            'test.prints',
        ));

        $db = null;
        if ($withDb) {
            $db = $this->createStub(DataSourceConnection::class);
            $db->method('fetchRow')->willReturn(self::RECORD);
        }

        $paths = new PrintTemplatePaths(new ModulePathResolver([$modules]));
        return new PrintRunner(
            $registry,
            $db,
            static fn (string $language) => null,
            new PrintLanguageResolver('cs'),
            catalogs: new PrintCatalogLoader($paths),
            renderer: new PrintRenderer(
                $paths,
                new PrintTwigFactory($paths),
                $engine === null
                    ? new RenderClient(null)
                    : new RenderClient(new RenderConfig('http://127.0.0.1:3000'), $engine),
            ),
        );
    }

    /** @return array<string, mixed> Obálka, jakou vrací `format=json`. */
    private static function envelope(): array
    {
        return [
            'printId'  => 'docs.invoicesOut.invoice',
            'version'  => 3,
            'language' => 'cs',
            'record'   => ['table' => 'docs_core_heads', 'id' => 123, 'docState' => 40],
            'meta'     => ['title' => 'Faktura 77', 'fileName' => 'faktura-77.pdf'],
            'data'     => ['document' => ['number' => '77']],
        ];
    }

    public function testPdfRunRendersTemplateThroughRenderClient(): void
    {
        $engine = $this->createMock(RenderEngineInterface::class);
        $engine->expects($this->once())
            ->method('renderHtml')
            ->with(
                '<h1>Faktura 2026000123</h1><p>Číslo 2026000123</p>',
                ['style.css' => 'h1 { color: #000; }'],
                $this->anything(),
                $this->anything(),
            )
            ->willReturn(RenderResult::success('%PDF-1.7 fake'));

        $output = $this->templateRunner($engine)->run('docs.invoicesOut.invoice', 123, PrintFormat::Pdf);

        $this->assertSame(PrintFormat::Pdf, $output->format);
        $this->assertSame('%PDF-1.7 fake', $output->pdfContent);
        $this->assertSame('faktura-2026000123.pdf', $output->printData->fileName);
    }

    // ── HTML a render z hotových dat (D28) ──────────────────────────────────

    public function testHtmlRunReturnsDocumentWithoutRenderService(): void
    {
        $output = $this->templateRunner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Html);

        $this->assertSame(PrintFormat::Html, $output->format);
        $this->assertNull($output->pdfContent);
        $this->assertSame('<h1>Faktura 2026000123</h1><p>Číslo 2026000123</p>', $output->document?->html);
        $this->assertSame(['style.css' => 'h1 { color: #000; }'], $output->document?->assets);
    }

    public function testRenderDataRendersReadyEnvelopeWithoutDatabaseAndBuilder(): void
    {
        $output = $this->templateRunner(withDb: false)
            ->renderData('docs.invoicesOut.invoice', self::envelope(), PrintFormat::Html);

        $this->assertNull(FakePrintBuilder::$lastRequest, 'builder se nevolá');
        $this->assertSame('<h1>Faktura 77</h1><p>Číslo 77</p>', $output->document?->html);
        $this->assertSame('faktura-77.pdf', $output->printData->fileName);
    }

    public function testRenderDataToPdfGoesThroughRenderClient(): void
    {
        $engine = $this->createMock(RenderEngineInterface::class);
        $engine->expects($this->once())
            ->method('renderHtml')
            ->with('<h1>Faktura 77</h1><p>Číslo 77</p>', $this->anything(), $this->anything(), $this->anything())
            ->willReturn(RenderResult::success('%PDF-1.7 fake'));

        $output = $this->templateRunner($engine, withDb: false)
            ->renderData('docs.invoicesOut.invoice', self::envelope(), PrintFormat::Pdf);

        $this->assertSame('%PDF-1.7 fake', $output->pdfContent);
    }

    public function testRenderDataLanguageOverridesEnvelope(): void
    {
        $runner = $this->templateRunner(withDb: false);

        $output = $runner->renderData('docs.invoicesOut.invoice', self::envelope(), PrintFormat::Html, 'en');
        $this->assertSame('en', $output->printData->language);
        $this->assertSame('<h1>Faktura 77</h1><p>Number 77</p>', $output->document?->html);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'language' must be one of");
        $runner->renderData('docs.invoicesOut.invoice', ['language' => 'de'] + self::envelope(), PrintFormat::Html);
    }

    public function testRenderDataRejectsDataOfAnotherPrint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("belong to print 'docs.proformasOut.proforma'");
        $this->templateRunner(withDb: false)->renderData(
            'docs.invoicesOut.invoice',
            ['printId' => 'docs.proformasOut.proforma'] + self::envelope(),
            PrintFormat::Html,
        );
    }

    public function testRenderDataRejectsVersionNewerThanBuilder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('version 4 is newer than version 3');
        $this->templateRunner(withDb: false)->renderData(
            'docs.invoicesOut.invoice',
            ['version' => 4] + self::envelope(),
            PrintFormat::Html,
        );
    }

    public function testRenderDataRejectsJsonFormatAndUnknownPrint(): void
    {
        $runner = $this->templateRunner(withDb: false);

        try {
            $runner->renderData('docs.invoicesOut.invoice', self::envelope(), PrintFormat::Json);
            $this->fail('InvalidArgumentException expected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('html or pdf', $e->getMessage());
        }

        $this->expectException(PrintNotFoundException::class);
        $runner->renderData('docs.invoicesOut.missing', self::envelope(), PrintFormat::Html);
    }

    public function testRunWithoutDatabaseIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        $this->templateRunner(withDb: false)->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
    }

    public function testPdfWithoutRendererFailsAsUnconfigured(): void
    {
        try {
            $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Pdf);
            $this->fail('PrintRenderException expected');
        } catch (PrintRenderException $e) {
            $this->assertSame(RenderErrorKind::Unconfigured, $e->errorKind);
            $this->assertTrue($e->isServiceUnavailable());
        }
    }
}

class FakePrintBuilder implements PrintBuilder
{
    public static ?PrintRequest $lastRequest = null;

    public function build(PrintRequest $request): PrintBuildResult
    {
        self::$lastRequest = $request;
        $number = (string) $request->record['doc_number'];

        return new PrintBuildResult(
            data: ['document' => ['number' => $number]],
            title: 'Faktura ' . $number,
            fileName: 'faktura-' . $number . '.pdf',
            messages: [PrintMessage::warning('qr.noAccount', 'QR nevznikl')],
        );
    }

    public function version(): int
    {
        return 3;
    }
}
