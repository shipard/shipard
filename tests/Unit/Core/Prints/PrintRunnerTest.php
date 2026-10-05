<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Prints;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\I18n\DocumentLanguageResolver;
use Shipard\Core\Prints\PrintBuilder;
use Shipard\Core\Prints\PrintBuildResult;
use Shipard\Core\Prints\PrintCatalogLoader;
use Shipard\Core\Prints\PrintData;
use Shipard\Core\Prints\PrintDefinition;
use Shipard\Core\Prints\PrintFormat;
use Shipard\Core\Prints\PrintLanguageResolver;
use Shipard\Core\Prints\PrintMessage;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Core\Prints\PrintParty;
use Shipard\Core\Prints\PrintPartyProvider;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRegistry;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Core\Prints\PrintRequest;
use Shipard\Core\Prints\PrintRunner;
use Shipard\Core\Config\RenderConfig;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintRenderer;
use Shipard\Core\Prints\PrintTemplatePaths;
use Shipard\Core\Prints\Texts\PrintTextProvider;
use Shipard\Core\Prints\Twig\PrintTwigFactory;
use Shipard\Core\Render\Engine\RenderEngineInterface;
use Shipard\Core\Render\RenderClient;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Render\RenderResult;
use Shipard\Core\Settings\BrandingStorage;
use Shipard\Core\Settings\KeyValueStore;

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
        FakePartyPrintBuilder::$party = null;
        FakePartyPrintBuilder::$partyCalls = 0;
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
        string $ownCountry = 'cz',
        ?BrandingStorage $branding = null,
        array &$configLanguages = [],
        ?array $catalog = null,
        string $builder = FakePrintBuilder::class,
        ?KeyValueStore $settings = null,
        ?PrintTextProvider $texts = null,
    ): PrintRunner {
        $registry = new PrintRegistry();
        $registry->add(PrintDefinition::fromArray(
            PrintDefinitionTest::declaration($declaration + ['builder' => $builder]),
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
            self::languages($ownCountry),
            $branding,
            $catalog === null ? null : $this->catalogLoader($catalog),
            clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-02T10:30:00+02:00'),
            settings: $settings,
            texts: $texts,
        );
    }

    /**
     * Volba jazyka nad pevným výřezem číselníků (jazyky dokumentů a země) —
     * sdílí ji i `PrintsApiTest`.
     */
    public static function languages(string $ownCountry = 'cz'): PrintLanguageResolver
    {
        return new PrintLanguageResolver(
            static fn (): DocumentLanguageResolver => new DocumentLanguageResolver(
                // `pl` = jazyk dokumentů, pro který tisk překlady nemá.
                ['cs' => [], 'en' => [], 'sk' => [], 'de' => [], 'pl' => []],
                [
                    'cz' => ['languages' => ['cs']],
                    'sk' => ['languages' => ['sk']],
                    'at' => ['languages' => ['de']],
                    'gb' => ['languages' => ['en']],
                    'fr' => ['languages' => ['fr']],
                    'pl' => ['languages' => ['pl']],
                ],
                $ownCountry,
            ),
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
                'branding'    => ['logo' => null, 'logoPlacement' => 'left', 'accentColor' => '#c8c8c8'],
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
        $this->runner(configLanguages: $configLanguages)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'en');

        $request = FakePrintBuilder::$lastRequest;
        $this->assertInstanceOf(PrintRequest::class, $request);
        $this->assertSame('docs.invoicesOut.invoice', $request->definition->id);
        $this->assertSame(123, $request->recordId);
        $this->assertSame(self::RECORD, $request->record);
        $this->assertSame('en', $request->language);
        $this->assertSame(['en'], $configLanguages, 'konfigurace v jazyce tisku, ne v jazyce requestu');
    }

    // ── jazyk tisku (#94 D2–D4) ─────────────────────────────────────────────

    public function testBuilderWithoutPartyPrintsInMainLanguageOfOwnCountry(): void
    {
        $output = $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('cs', $output->printData->language);

        $output = $this->runner(ownCountry: 'gb')->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('en', $output->printData->language);

        // Hlavní jazyk vlastní země mimo jazyky dokumentů → en, bez hlášení.
        $output = $this->runner(ownCountry: 'fr')->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('en', $output->printData->language);
        $this->assertSame(['qr.noAccount'], self::messageCodes($output->printData->messages));
    }

    public function testPersonLanguageWinsOverPartyCountry(): void
    {
        FakePartyPrintBuilder::$party = new PrintParty(personLanguage: 'en', country: 'cz');

        $output = $this->runner(builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame('en', $output->printData->language);
        $this->assertSame('en', FakePrintBuilder::$lastRequest?->language);
    }

    public function testPartyCountryDecidesWithoutPersonLanguage(): void
    {
        FakePartyPrintBuilder::$party = new PrintParty(personLanguage: null, country: 'gb');
        $output = $this->runner(builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('en', $output->printData->language);

        // Záznam bez partnera → hlavní jazyk vlastní země.
        FakePartyPrintBuilder::$party = null;
        $output = $this->runner(builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame('cs', $output->printData->language);
        $this->assertSame(2, FakePartyPrintBuilder::$partyCalls);
    }

    public function testRequestedLanguageWinsAndSkipsPartyLookup(): void
    {
        FakePartyPrintBuilder::$party = new PrintParty(personLanguage: 'en', country: 'gb');

        $output = $this->runner(builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'cs');

        $this->assertSame('cs', $output->printData->language);
        $this->assertSame(0, FakePartyPrintBuilder::$partyCalls);
    }

    public function testInternalPrintIgnoresParty(): void
    {
        FakePartyPrintBuilder::$party = new PrintParty(personLanguage: 'en', country: 'gb');

        $output = $this->runner(declaration: ['audience' => 'internal'], builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame('cs', $output->printData->language);
        $this->assertSame(0, FakePartyPrintBuilder::$partyCalls);
    }

    public function testSlovakAndGermanPartyIsPrintedInItsLanguage(): void
    {
        $parties = [
            'sk' => new PrintParty(personLanguage: 'sk', country: 'cz'),
            'de' => new PrintParty(personLanguage: null, country: 'AT'),
        ];
        foreach ($parties as $language => $party) {
            FakePartyPrintBuilder::$party = $party;
            $configLanguages = [];

            $output = $this->runner(configLanguages: $configLanguages, builder: FakePartyPrintBuilder::class)
                ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

            $this->assertSame($language, $output->printData->language);
            $this->assertSame($language, FakePrintBuilder::$lastRequest?->language);
            $this->assertSame(['en', $language], $configLanguages, 'strana nad záložní konfigurací, build v jazyce tisku');
            $this->assertSame(['qr.noAccount'], self::messageCodes($output->printData->messages));
        }
    }

    public function testDocumentLanguageWithoutCatalogPrintsInEnglishWithMessage(): void
    {
        // Jazyk dokumentů přidaný dřív než překlady tisku.
        $parties = [
            new PrintParty(personLanguage: 'pl', country: 'cz'),
            new PrintParty(personLanguage: null, country: 'PL'),
        ];
        foreach ($parties as $party) {
            FakePartyPrintBuilder::$party = $party;

            $output = $this->runner(builder: FakePartyPrintBuilder::class)
                ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

            $this->assertSame('en', $output->printData->language);
            $messages = $output->printData->messages;
            $this->assertSame(['qr.noAccount', 'language.unavailable'], self::messageCodes($messages));
            $this->assertStringContainsString("'pl'", $messages[1]->text);
        }
    }

    public function testPartyLookupReusesFallbackConfigForEnglishPrint(): void
    {
        // Strana se hledá nad konfigurací v záložním jazyce; je-li to
        // i jazyk tisku, podruhé se nenačítá.
        FakePartyPrintBuilder::$party = new PrintParty(personLanguage: null, country: 'gb');
        $configLanguages = [];
        $this->runner(configLanguages: $configLanguages, builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame(['en'], $configLanguages);

        FakePartyPrintBuilder::$party = new PrintParty(personLanguage: null, country: 'cz');
        $configLanguages = [];
        $this->runner(configLanguages: $configLanguages, builder: FakePartyPrintBuilder::class)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);
        $this->assertSame(['en', 'cs'], $configLanguages);
    }

    /**
     * @param list<PrintMessage> $messages
     * @return list<string>
     */
    private static function messageCodes(array $messages): array
    {
        return array_map(static fn (PrintMessage $m): string => $m->code, $messages);
    }

    public function testUnsupportedRequestedLanguageThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Parameter 'language' must be one of cs|en|sk|de");
        $this->runner()->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'fr');
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

        $this->assertSame('logo.svg', $output->printData->toArray()['branding']['logo']);
    }

    /** @param array<string, mixed> $values */
    private static function settings(array $values): KeyValueStore
    {
        return new class ($values) implements KeyValueStore {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values) {}

            public function get(string $key): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function getMany(array $keys): array
            {
                return array_map($this->get(...), array_combine($keys, $keys));
            }

            public function set(string $key, mixed $value): void
            {
                $this->values[$key] = $value;
            }

            public function delete(string $key): void
            {
                unset($this->values[$key]);
            }
        };
    }

    public function testAppearanceSettingsGoToBranding(): void
    {
        $output = $this->runner(settings: self::settings([
            PrintRunner::SETTING_ACCENT_COLOR   => '#0A5C8F',
            PrintRunner::SETTING_LOGO_PLACEMENT => 'right',
        ]))->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame(
            ['logo' => null, 'logoPlacement' => 'right', 'accentColor' => '#0a5c8f'],
            $output->printData->toArray()['branding'],
        );
    }

    public function testInvalidAppearanceSettingsFallBackToDefaults(): void
    {
        // `ds-setting set` hodnoty nekontroluje — tisk je ověřuje při čtení.
        $output = $this->runner(settings: self::settings([
            PrintRunner::SETTING_ACCENT_COLOR   => 'red; background: url(x)',
            PrintRunner::SETTING_LOGO_PLACEMENT => ['right'],
        ]))->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame(
            ['logo' => null, 'logoPlacement' => 'left', 'accentColor' => PrintData::DEFAULT_ACCENT_COLOR],
            $output->printData->toArray()['branding'],
        );
    }

    // ── uživatelské texty (#90 D47–D52) ─────────────────────────────────────

    /**
     * Zdroj textů, který si pamatuje, na co se runner ptal.
     *
     * @param array<string, list<array{id: int, text: string}>> $texts
     */
    private static function textProvider(array $texts): PrintTextProvider
    {
        return new class ($texts) implements PrintTextProvider {
            /** @var list<array{print: string, record: array<string, mixed>, language: string, day: string}> */
            public array $calls = [];

            /** @param array<string, list<array{id: int, text: string}>> $texts */
            public function __construct(private readonly array $texts) {}

            public function resolve(
                PrintDefinition $definition,
                array $record,
                string $language,
                \DateTimeImmutable $today,
            ): array {
                $this->calls[] = [
                    'print' => $definition->id, 'record' => $record, 'language' => $language,
                    'day' => $today->format('Y-m-d'),
                ];
                return $this->texts;
            }
        };
    }

    private const SENDABLE_WITH_SLOTS = [
        'sendPurpose' => 'invoices', 'recipientPerson' => 'partner',
        'textSlots'   => ['footer', 'emailSubject'],
    ];

    public function testUserTextsAreResolvedForPrintDayAndRenderedIntoEnvelope(): void
    {
        $provider = self::textProvider([
            'footer'       => [['id' => 1, 'text' => 'Doklad **{{ data.document.number }}**']],
            'emailSubject' => [['id' => 2, 'text' => 'Faktura {{ data.document.number }}']],
        ]);

        $output = $this->runner(declaration: self::SENDABLE_WITH_SLOTS, texts: $provider)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json, 'en');

        $this->assertSame(
            [
                'footer'       => '<div class="print-text"><p>Doklad <strong>2026000123</strong></p></div>',
                'emailSubject' => 'Faktura 2026000123',
            ],
            $output->printData->toArray()['texts'],
        );
        // Rozhoduje den tisku (hodiny runneru), ne datum dokladu; jazyk je jazyk tisku.
        $this->assertSame(
            [['print' => 'docs.invoicesOut.invoice', 'record' => self::RECORD, 'language' => 'en', 'day' => '2026-10-02']],
            $provider->calls,
        );
    }

    public function testBrokenUserTextBecomesMessageAndPrintStillRuns(): void
    {
        $provider = self::textProvider(['footer' => [
            ['id' => 7, 'text' => '{{ data.document.numbr }}'],
            ['id' => 8, 'text' => 'Děkujeme.'],
        ]]);

        $output = $this->runner(declaration: self::SENDABLE_WITH_SLOTS, texts: $provider)
            ->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame(
            ['footer' => '<div class="print-text"><p>Děkujeme.</p></div>'],
            $output->printData->texts,
        );
        // Hlášení builderu zůstává, za ním varování o vynechaném textu.
        $this->assertSame(['qr.noAccount', 'textError'], self::messageCodes($output->printData->messages));
    }

    public function testPrintWithoutTextSlotsDoesNotAskForTexts(): void
    {
        $provider = self::textProvider(['footer' => [['id' => 1, 'text' => 'x']]]);

        $output = $this->runner(texts: $provider)->run('docs.invoicesOut.invoice', 123, PrintFormat::Json);

        $this->assertSame([], $provider->calls);
        $this->assertSame([], $output->printData->texts);
    }

    public function testRenderDataTakesTextsFromEnvelopeWithoutResolving(): void
    {
        $envelope = self::envelope();
        $envelope['texts'] = ['footer' => '<div class="print-text"><p>z JSON</p></div>'];

        $output = $this->templateRunner(withDb: false)
            ->renderData('docs.invoicesOut.invoice', $envelope, PrintFormat::Html);

        $this->assertSame(['footer' => '<div class="print-text"><p>z JSON</p></div>'], $output->printData->texts);
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
            self::languages(),
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
        $runner->renderData('docs.invoicesOut.invoice', ['language' => 'fr'] + self::envelope(), PrintFormat::Html);
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

/** Fake builder, který zná stranu tisku — runner se ho ptá před `build()`. */
class FakePartyPrintBuilder extends FakePrintBuilder implements PrintPartyProvider
{
    public static ?PrintParty $party = null;
    public static int $partyCalls = 0;

    public function printParty(array $record, DataSourceConnection $db, ?ConfigRuntime $config): ?PrintParty
    {
        self::$partyCalls++;
        return self::$party;
    }
}
