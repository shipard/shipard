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
 * `PrintData` → (PDF) renderer.
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
     */
    public function __construct(
        private readonly PrintRegistry $registry,
        private readonly DataSourceConnection $db,
        \Closure $configFactory,
        private readonly PrintLanguageResolver $languages,
        private readonly ?BrandingStorage $branding = null,
        private readonly ?PrintCatalogLoader $catalogs = null,
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

        $result = $this->createBuilder($definition)->build(new PrintRequest(
            definition: $definition,
            recordId: $recordId,
            record: $record,
            language: $language,
            db: $this->db,
            config: ($this->configFactory)($language),
            translator: $this->catalogs?->translator($definition, $language)
                ?? new PrintTranslator([], $language),
        ));

        $printData = new PrintData(
            printId: $definition->id,
            version: $result->version,
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

        if ($format === PrintFormat::Json) {
            return new PrintOutput($format, $printData);
        }

        throw new PrintRenderException(RenderErrorKind::Unconfigured, 'Print renderer is not available');
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
