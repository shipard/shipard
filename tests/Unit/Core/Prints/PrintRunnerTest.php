<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
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
use Shipard\Core\Render\RenderErrorKind;
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
            foreach (glob($this->dsPath . '/branding/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->dsPath . '/branding');
            @rmdir($this->dsPath);
        }
    }

    /**
     * @param array<string, mixed>|null $record
     * @param array<string, mixed> $declaration
     * @param list<string> $configLanguages Sem runner zapíše jazyky, pro které chtěl konfiguraci.
     */
    private function runner(
        ?array $record = self::RECORD,
        array $declaration = [],
        string $defaultLanguage = 'cs',
        ?BrandingStorage $branding = null,
        array &$configLanguages = [],
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
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-02T10:30:00+02:00'),
        );
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
                'meta'        => ['title' => 'Faktura 2026000123', 'fileName' => 'faktura-2026000123.pdf'],
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
            version: 3,
        );
    }
}
