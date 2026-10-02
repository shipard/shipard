<?php

declare(strict_types=1);

namespace Shipard\Core\Prints;

use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Render\RenderErrorKind;
use Shipard\Core\Settings\BrandingStorage;

/**
 * Jediný vstupní bod pro běh tisku — REST controller i CLI volají výhradně
 * `run()`, nikdy builder přímo.
 *
 * registr → záznam → dostupnost (filtr, stav) → jazyk → builder → obálka
 * `PrintData` → (PDF / HTML) renderer.
 *
 * `renderData()` vstupuje až do posledního kroku s hotovým `PrintData`
 * — vývoj šablon bez záznamu v databázi (#90 D28).
 */
final class PrintRunner
{
    /** @var \Closure(string): ?ConfigRuntime */
    private readonly \Closure $configFactory;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    /**
     * @param \Closure(string): ?ConfigRuntime $configFactory Konfigurace
     *        v daném jazyce — jazyk tisku se může lišit od jazyka requestu,
     *        proto runner nedostává hotový `ConfigRuntime`.
     * @param ?\Closure(): \DateTimeImmutable $clock Čas vzniku (testy).
     * @param ?DataSourceConnection $db Null = runner umí jen `renderData()`.
     */
    public function __construct(
        private readonly PrintRegistry $registry,
        private readonly ?DataSourceConnection $db,
        \Closure $configFactory,
        private readonly PrintLanguageResolver $languages,
        private readonly ?BrandingStorage $branding = null,
        private readonly ?PrintCatalogLoader $catalogs = null,
        private readonly ?PrintRenderer $renderer = null,
        ?\Closure $clock = null,
    ) {
        $this->configFactory = $configFactory;
        $this->clock = $clock ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @throws PrintNotFoundException Neznámé id tisku (→ 404).
     * @throws PrintRecordNotFoundException Záznam neexistuje (→ 404).
     * @throws PrintNotAvailableException Záznam nesplní filtr / stav (→ 409).
     * @throws \InvalidArgumentException Nepodporovaný jazyk (→ 400).
     * @throws PrintBuildException Záznamu chybí data pro tisk (→ 409).
     * @throws PrintRenderException PDF nevzniklo (→ 503 / 500).
     */
    public function run(
        string $printId,
        int $recordId,
        PrintFormat $format,
        ?string $language = null,
    ): PrintOutput {
        $definition = $this->registry->get($printId);
        if ($definition === null) {
            throw new PrintNotFoundException("Unknown print '{$printId}'");
        }
        if ($this->db === null) {
            throw new \LogicException('PrintRunner without a database connection can only render ready print data');
        }

        $record = $this->db->fetchRow('SELECT * FROM %n WHERE [id] = %i', $definition->table, $recordId);
        if ($record === null) {
            throw new PrintRecordNotFoundException(
                "Record {$recordId} not found in '{$definition->table}'",
            );
        }
        if (!$definition->matchesFilter($record)) {
            throw new PrintNotAvailableException(
                "Print '{$printId}' is not declared for this kind of record",
            );
        }
        if (!$definition->matchesDocState($record)) {
            throw new PrintNotAvailableException(
                "Print '{$printId}' is not available in the current state of the record",
            );
        }

        $language = $this->languages->resolve($language, $definition, $record);

        $translator = $this->catalogs?->translator($definition, $language)
            ?? new PrintTranslator([], $language);

        $builder = $this->createBuilder($definition);
        $result  = $builder->build(new PrintRequest(
            definition: $definition,
            recordId: $recordId,
            record: $record,
            language: $language,
            db: $this->db,
            config: ($this->configFactory)($language),
            translator: $translator,
        ));

        $printData = new PrintData(
            printId: $definition->id,
            version: $builder->version(),
            language: $language,
            table: $definition->table,
            recordId: $recordId,
            docState: (int) $record['docState'],
            generatedAt: ($this->clock)(),
            title: $result->title,
            fileName: $result->fileName,
            logo: $this->logoAssetName(),
            messages: $result->messages,
            data: $result->data,
        );

        return $this->output($definition, $printData, $translator, $format);
    }

    /**
     * Render z hotového `PrintData` (výstup `format=json` nebo fixture) —
     * bez záznamu, kontroly dostupnosti i builderu.
     *
     * @param array<string, mixed> $envelope `PrintData` jako pole.
     * @param ?string $language Přebije jazyk z obálky (překlady šablony;
     *        popisky v `data` zůstávají, jak jsou).
     * @throws PrintNotFoundException Neznámé id tisku.
     * @throws \InvalidArgumentException Obálka nemá očekávaný tvar, patří
     *         jinému tisku, je novější než builder, jazyk není podporovaný,
     *         nebo je vyžádaný formát `json`.
     * @throws PrintRenderException PDF nevzniklo.
     */
    public function renderData(
        string $printId,
        array $envelope,
        PrintFormat $format,
        ?string $language = null,
    ): PrintOutput {
        if ($format === PrintFormat::Json) {
            throw new \InvalidArgumentException('Ready print data can be rendered to html or pdf only');
        }
        $definition = $this->registry->get($printId);
        if ($definition === null) {
            throw new PrintNotFoundException("Unknown print '{$printId}'");
        }
        // Data jiného tisku by cizí šablona vykreslila špatně, nebo vůbec.
        $dataPrintId = $envelope['printId'] ?? null;
        if ($dataPrintId !== $printId) {
            throw new \InvalidArgumentException(sprintf(
                "Print data belong to print '%s', not to '%s'",
                is_string($dataPrintId) ? $dataPrintId : '?',
                $printId,
            ));
        }

        $printData = PrintData::fromArray($envelope, $this->createBuilder($definition)->version());
        $language  = $this->languages->resolve($language ?? $printData->language, $definition, []);
        if ($language !== $printData->language) {
            $printData = $printData->withLanguage($language);
        }

        $translator = $this->catalogs?->translator($definition, $language)
            ?? new PrintTranslator([], $language);

        return $this->output($definition, $printData, $translator, $format);
    }

    private function output(
        PrintDefinition $definition,
        PrintData $printData,
        PrintTranslator $translator,
        PrintFormat $format,
    ): PrintOutput {
        if ($format === PrintFormat::Json) {
            return new PrintOutput($format, $printData);
        }

        if ($this->renderer === null) {
            throw new PrintRenderException(RenderErrorKind::Unconfigured, 'Print renderer is not available');
        }
        if ($format === PrintFormat::Html) {
            return new PrintOutput(
                $format,
                $printData,
                document: $this->renderer->renderDocument($definition, $printData, $translator),
            );
        }
        return new PrintOutput(
            $format,
            $printData,
            $this->renderer->renderPdf($definition, $printData, $translator),
        );
    }

    private function createBuilder(PrintDefinition $definition): PrintBuilder
    {
        $builderClass = $definition->builderClass;
        if (!class_exists($builderClass)) {
            throw new \RuntimeException(
                "Print '{$definition->id}': builder class '{$builderClass}' not found",
            );
        }
        $builder = new $builderClass();
        if (!$builder instanceof PrintBuilder) {
            throw new \RuntimeException(
                "Print '{$definition->id}': '{$builderClass}' does not implement PrintBuilder",
            );
        }
        return $builder;
    }

    /**
     * Logo z brandingu jako název assetu (`logo.<přípona>`), pod kterým ho
     * šablona referencuje. Bez nahraného loga null.
     */
    private function logoAssetName(): ?string
    {
        $stored = $this->branding?->findSlotFile('companyLogo');
        if ($stored === null) {
            return null;
        }
        return 'logo.' . pathinfo($stored, PATHINFO_EXTENSION);
    }
}
