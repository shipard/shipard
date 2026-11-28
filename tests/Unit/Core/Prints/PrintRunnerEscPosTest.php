<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\EscPos\EscPosDrivers;
use Shipard\Core\Prints\EscPos\EscPosRenderer;
use Shipard\Core\Prints\EscPos\PrinterProfiles;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintFormatNotSupportedException;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Tests\Unit\Core\Prints\EscPos\EscPosConfig;

/**
 * Tisk na účtenkovou tiskárnu v `PrintRunner` (#177 U35): šablona
 * `page.escpos.twig` v dočasném modulu, ovladač a profily ze zdrojového
 * JSONC modulu core.prints.
 */
final class PrintRunnerEscPosTest extends TestCase
{
    use EscPosConfig;

    private const RECEIPT = 'test.prints.receipt';
    private const PAGE    = 'test.prints.page';
    private const RECORD  = ['id' => 7, 'doc_type' => 'cashreg', 'docState' => 40, 'doc_number' => '2026000007'];
    private const INIT    = "\x1B@\x1C.\x1Bt\x12";

    private const TEMPLATE = <<<'TWIG'
        <receipt>
            <text align="center" bold="1">{{ meta.title }}</text>
            <row><l>{{ t('label.number') }}</l><r>{{ data.document.number }}</r></row>
            <text>{{ data.document.note }}</text>
            <cut/>
        </receipt>
        TWIG;

    private ?string $modules = null;

    /** @var list<string> jazyky, pro které runner chtěl konfiguraci */
    private array $configLanguages = [];

    protected function tearDown(): void
    {
        if ($this->modules !== null) {
            $this->rmTree($this->modules);
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

    /** @param array<string, mixed>|null $items cfgItemy konfigurace; null = konfigurace bez tisku na účtenky */
    private function runner(string $template = self::TEMPLATE, ?array $items = null, bool $withConfig = true): PrintRunner
    {
        $this->modules = sys_get_temp_dir() . '/shpd_escpos_' . uniqid('', true);
        $prints = $this->modules . '/test/prints/prints';
        mkdir($prints . '/receipt', 0755, true);
        mkdir($prints . '/page', 0755, true);
        file_put_contents($this->modules . '/test/prints/module.jsonc', '{"id": "test.prints", "name": "Prints"}');
        file_put_contents($prints . '/receipt/page.escpos.twig', $template);
        file_put_contents($prints . '/receipt/messages.jsonc', '{ "label.number": { "cs": "Číslo", "en": "Number" } }');
        file_put_contents($prints . '/page/page.html.twig', '<h1>{{ meta.title }}</h1>');

        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::receiptDeclaration([
            'id' => self::RECEIPT, 'builder' => FakeReceiptBuilder::class, 'template' => '@test.prints/receipt', 'catalogs' => [],
        ]), 'test.prints'));
        $registry->add(PrintDefinition::fromArray(PrintDefinitionTest::declaration([
            'id' => self::PAGE, 'builder' => FakeReceiptBuilder::class, 'template' => '@test.prints/page', 'catalogs' => [],
            'filter' => ['doc_type' => ['cashreg']],
        ]), 'test.prints'));

        $db = $this->createStub(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(self::RECORD);

        $items ??= [
            EscPosDrivers::CFG_ITEM   => self::printsJsonc('printerDrivers.jsonc'),
            PrinterProfiles::CFG_ITEM => self::printsJsonc('printerProfiles.jsonc'),
        ];
        $config = $this->createStub(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);

        $paths = new PrintTemplatePaths(new ModulePathResolver([$this->modules]));
        return new PrintRunner(
            $registry,
            $db,
            function (string $language) use ($config, $withConfig): ?ConfigRuntime {
                $this->configLanguages[] = $language;
                return $withConfig ? $config : null;
            },
            PrintRunnerTest::languages(),
            catalogs: new PrintCatalogLoader($paths),
            renderer: new PrintRenderer($paths, new PrintTwigFactory($paths), new RenderClient(null)),
        );
    }

    private static function wide(): \Shipard\Core\Prints\EscPos\PrinterProfile
    {
        return (new PrinterProfiles(self::printsJsonc('printerProfiles.jsonc')))->get('escpos-80-cp852')['profile'];
    }

    public function testReceiptIsRenderedToBytesByProfile(): void
    {
        $output = $this->runner()->run(self::RECEIPT, 7, PrintFormat::Escpos, 'cs', self::wide());

        $title = "\xE9\x9Ftenka 2026000007";
        $this->assertSame(
            self::INIT
            . "\x1BE\x01\x1Ba\x01" . $title . "\n"
            . "\x1BE\x00\x1Ba\x00" . "\xAC\xA1slo" . str_repeat(' ', 42 - 5 - 10) . "2026000007\n"
            . "A & B <s.r.o.>\n"
            . "\x1DVA\x05",
            $output->bytes,
        );
        $this->assertSame(PrintFormat::Escpos, $output->format);
        $this->assertSame([], $output->warnings);
        $this->assertNull($output->pdfContent);
        $this->assertNull($output->document);
        // Šablona je k dispozici i u bajtů — tisková úloha ji může uložit k ladění.
        $this->assertStringContainsString('<receipt>', (string) $output->markup);
        // Hlášení builderu zůstávají v datech tisku.
        $this->assertCount(1, $output->printData->messages);
    }

    public function testWidthOfTheRowFollowsTheProfile(): void
    {
        $narrow = (new PrinterProfiles(self::printsJsonc('printerProfiles.jsonc')))->get('escpos-58-cp852')['profile'];

        $bytes = (string) $this->runner()->run(self::RECEIPT, 7, PrintFormat::Escpos, 'cs', $narrow)->bytes;

        $this->assertStringContainsString("\xAC\xA1slo" . str_repeat(' ', 32 - 5 - 10) . "2026000007\n", $bytes);
    }

    public function testWithoutProfileTheFallbackIsUsedWithWarning(): void
    {
        $output = $this->runner()->run(self::RECEIPT, 7, PrintFormat::Escpos, 'cs');

        $this->assertStringContainsString("\xAC\xA1slo" . str_repeat(' ', 32 - 5 - 10) . "2026000007\n", (string) $output->bytes);
        $this->assertSame([PrinterProfiles::WARNING_NO_PROFILE], array_map(
            static fn (PrintMessage $m): string => $m->code,
            $output->warnings,
        ));
        // Ovladač a katalog se čtou z konfigurace v jazyce tisku.
        $this->assertContains('cs', $this->configLanguages);
    }

    public function testWarningsOfTheRendererAreCarriedToTheOutput(): void
    {
        $output = $this->runner('<receipt><qr>{{ data.document.number }}</qr></receipt>')
            ->run(self::RECEIPT, 7, PrintFormat::Escpos, 'cs', self::wide());

        $this->assertSame(self::INIT . "2026000007\n", $output->bytes);
        $this->assertSame([EscPosRenderer::WARNING_QR_AS_TEXT], array_map(
            static fn (PrintMessage $m): string => $m->code,
            $output->warnings,
        ));
    }

    public function testMarkupFormatReturnsOutputOfTheTemplateWithEscapedValues(): void
    {
        // Na výstup šablony není konfigurace zdroje dat potřeba.
        $output = $this->runner(withConfig: false)->run(self::RECEIPT, 7, PrintFormat::Markup, 'en');

        $this->assertNull($output->bytes);
        $this->assertStringContainsString('<row><l>Number</l><r>2026000007</r></row>', (string) $output->markup);
        // Autoescape `html` dává platné XML.
        $this->assertStringContainsString('<text>A &amp; B &lt;s.r.o.&gt;</text>', (string) $output->markup);
    }

    public function testReadyDataRenderToBytesWithoutDatabase(): void
    {
        $envelope = [
            'printId'  => self::RECEIPT,
            'version'  => 1,
            'language' => 'cs',
            'record'   => ['table' => 'docs_core_heads', 'id' => 7, 'docState' => 40],
            'meta'     => ['title' => 'Uctenka 9', 'fileName' => 'uctenka-9.pdf'],
            'data'     => ['document' => ['number' => '9', 'note' => 'x']],
        ];

        $output = $this->runner()->renderData(self::RECEIPT, $envelope, PrintFormat::Escpos, null, self::wide());

        $this->assertStringStartsWith(self::INIT . "\x1BE\x01\x1Ba\x01Uctenka 9\n", (string) $output->bytes);
        $this->assertStringContainsString('<text>x</text>', (string) $this->runner()->renderData(
            self::RECEIPT,
            $envelope,
            PrintFormat::Markup,
        )->markup);
    }

    public function testFormatMustFitOutputOfThePrint(): void
    {
        $runner = $this->runner();

        foreach ([
            [self::RECEIPT, PrintFormat::Pdf],
            [self::RECEIPT, PrintFormat::Html],
            [self::PAGE, PrintFormat::Escpos],
            [self::PAGE, PrintFormat::Markup],
        ] as [$printId, $format]) {
            try {
                $runner->run($printId, 7, $format);
                $this->fail("{$printId} → {$format->value}: PrintFormatNotSupportedException expected");
            } catch (PrintFormatNotSupportedException $e) {
                $this->assertStringContainsString($format->value, $e->getMessage());
            }
            try {
                $runner->renderData($printId, ['printId' => $printId], $format);
                $this->fail("{$printId} → {$format->value}: PrintFormatNotSupportedException expected");
            } catch (PrintFormatNotSupportedException) {
                $this->addToAssertionCount(1);
            }
        }

        // JSON dává každý tisk; stránka do HTML jde dál.
        $this->assertSame('2026000007', $runner->run(self::RECEIPT, 7, PrintFormat::Json)->printData->data['document']['number']);
        $this->assertSame('<h1>Účtenka 2026000007</h1>', $runner->run(self::PAGE, 7, PrintFormat::Html, 'cs')->document?->html);
    }

    public function testInvalidMarkupOfTheTemplateIsRenderErrorWithLine(): void
    {
        try {
            $this->runner("<receipt>\n<text>a</text>\n<table/>\n</receipt>")
                ->run(self::RECEIPT, 7, PrintFormat::Escpos, 'cs', self::wide());
            $this->fail('PrintRenderException expected');
        } catch (PrintRenderException $e) {
            $this->assertSame(RenderErrorKind::InvalidInput, $e->errorKind);
            $this->assertFalse($e->isServiceUnavailable());
            $this->assertStringContainsString(self::RECEIPT, $e->getMessage());
            $this->assertStringContainsString('line 3: unknown element <table>', $e->getMessage());
        }
    }

    public function testDataSourceWithoutPrinterConfigIsUnconfigured(): void
    {
        foreach ([[self::wide(), 'Printer driver'], [null, 'Printer profile']] as [$profile, $message]) {
            try {
                $this->runner(items: [])->run(self::RECEIPT, 7, PrintFormat::Escpos, 'cs', $profile);
                $this->fail('PrintRenderException expected');
            } catch (PrintRenderException $e) {
                $this->assertSame(RenderErrorKind::Unconfigured, $e->errorKind);
                $this->assertStringContainsString($message, $e->getMessage());
                $this->assertStringContainsString('ds-upgrade', $e->getMessage());
            }
        }
    }
}

class FakeReceiptBuilder implements PrintBuilder
{
    public function build(PrintRequest $request): PrintBuildResult
    {
        $number = (string) $request->record['doc_number'];

        return new PrintBuildResult(
            data: ['document' => ['number' => $number, 'note' => 'A & B <s.r.o.>']],
            title: 'Účtenka ' . $number,
            fileName: 'uctenka-' . $number . '.pdf',
            messages: [PrintMessage::warning('receipt.note', 'Poznámka')],
        );
    }

    public function version(): int
    {
        return 1;
    }
}
