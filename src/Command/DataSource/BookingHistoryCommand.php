<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Exchange\BookingHistory\AccountTagMap;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalysis;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryAnalyzer;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryClassifier;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFile;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryFormatException;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryItemUsageTagger;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistoryReport;
use Shipard\Module\Core\Exchange\BookingHistory\BookingHistorySeedBuilder;
use Shipard\Module\Core\Exchange\BookingHistory\SeedApplier;
use Shipard\Module\Core\Exchange\BookingHistory\TagCache;
use Shipard\Module\Economy\Items\AccountingItemsOffer;
use Shipard\Module\Economy\Items\ContentTagBackfill;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Zpracování souboru účetní historie (`docs/booking-history-format.md`,
 * `tasks/booking-history-import.md` D31).
 *
 * Bez režimu jen zvaliduje soubor a vypíše souhrn hlavičky. Režimy jsou
 * kombinovatelné a `--dry-run` platí pro oba zápisové:
 *
 *   --report      kvalita zdroje, pokrytí taxonomie, konzistence LLM×reverz,
 *                 mrtvé štítky, náhled seedu → markdown + souhrn na stdout
 *   --apply-seed  zápis pravidel IČO→štítek (origin `seed`, D32)
 *   --tag-items   reverzní otagování živých položek DS (D34)
 *
 * Tenká vrstva nad službami v `modules/core/exchange/src/BookingHistory/` —
 * příkaz jen drátuje runtime, čte přepínače a tiskne. Testovatelné přes
 * podtřídu (override getDataSourceDir + DI dsConfig/dsConnection/llm).
 */
class BookingHistoryCommand extends Command
{
    private const ITEMS_MODE_OFFER = 'offer';
    private const ITEMS_MODE_USAGE = 'usage';
    private const ITEMS_MODE_AUTO = 'auto';
    private const ITEMS_MODE_INVALID = '?';

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?LlmClient $llm = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('booking-history')
            ->setDescription('Zpracuje soubor účetní historie (report kvality, seed pravidel IČO→štítek, otagování položek)')
            ->addOption('input', null, InputOption::VALUE_REQUIRED, 'Cesta k souboru shpd.economy.booking-history.v1 (JSONL)')
            ->addOption('report', null, InputOption::VALUE_NONE, 'Vyrob markdown report')
            ->addOption('report-out', null, InputOption::VALUE_REQUIRED, 'Cesta reportu (default <input>.report.md)')
            ->addOption('apply-seed', null, InputOption::VALUE_NONE, 'Zapiš seed pravidla IČO→štítek')
            ->addOption('tag-items', null, InputOption::VALUE_OPTIONAL, 'Otaguj živé položky DS: offer (podle účtů) | usage (podle klasifikace textů) | auto (default)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypiš plán, nic neměň')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'AI backend pro klasifikaci (id nebo název)')
            ->addOption('no-llm', null, InputOption::VALUE_NONE, 'Bez LLM klasifikace — report jen z reverzu účet→štítek')
            ->addOption('seed-min-share', null, InputOption::VALUE_REQUIRED, 'Seed: min. podíl řádků dominantního štítku (default ' . BookingHistorySeedBuilder::DEFAULT_MIN_SHARE . ')')
            ->addOption('seed-min-docs', null, InputOption::VALUE_REQUIRED, 'Seed: min. počet dokladů dominantního štítku (default ' . BookingHistorySeedBuilder::DEFAULT_MIN_DOC_COUNT . ')')
            ->addOption('seed-min-coverage', null, InputOption::VALUE_REQUIRED, 'Seed: min. pokrytí řádků IČO reverzem (default ' . BookingHistorySeedBuilder::DEFAULT_MIN_COVERAGE . ')')
            ->addOption('usage-min-share', null, InputOption::VALUE_REQUIRED, 'Usage: min. podíl dominantního štítku položky (default ' . BookingHistoryItemUsageTagger::DEFAULT_MIN_SHARE . ')')
            ->addOption('usage-min-rows', null, InputOption::VALUE_REQUIRED, 'Usage: min. řádků dominantního štítku (default ' . BookingHistoryItemUsageTagger::DEFAULT_MIN_ROWS . ')');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    protected function createLlmClient(?ModelCatalog $catalog = null): LlmClient
    {
        return $this->llm ?? new AnthropicLlmClient($catalog);
    }

    /** Seam pro testy — e2e test podstrčí mock LLM i mock resolver backendů. */
    protected function createClassifier(
        DataSourceConnection $db,
        DataSourceConfig $dsConfig,
        ConfigRuntime $config,
        ?int $backendNdx,
        TagCache $cache,
    ): BookingHistoryClassifier {
        return new BookingHistoryClassifier(
            llm: $this->createLlmClient(ModelCatalog::fromConfig($config)),
            backends: new AiBackendResolver($db, $dsConfig),
            config: $config,
            backendNdx: $backendNdx,
            cache: $cache,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $inputPath = (string) ($input->getOption('input') ?? '');
        if ($inputPath === '') {
            $output->writeln('<error>Chybí --input=<soubor.jsonl></error>');
            return Command::FAILURE;
        }

        try {
            $file = BookingHistoryFile::open($inputPath);
        } catch (BookingHistoryFormatException $e) {
            $output->writeln("<error>Soubor {$inputPath}: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $dsDir        = $this->getDataSourceDir();
        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $lang         = $dsConfig->getDefaultLanguage();
        $resolver     = $this->buildResolver();
        $config       = ConfigRuntime::load($dsDir, $lang);

        $offer = new AccountingItemsOffer($dsConnection, $resolver);
        $variant = $file->header->effectiveChartVariant();
        // Neznámá osnova → přesná shoda analytiky se ověřuje názvem položky
        // (D36); u deklarované osnovy analytikám věříme.
        $accountTags = AccountTagMap::fromOffer($offer, $variant, $file->header->chartVariantIsGuessed());

        $this->printHeader($output, $file, $variant, $accountTags);

        $wantReport = (bool) $input->getOption('report');
        $wantSeed   = (bool) $input->getOption('apply-seed');
        $dryRun     = (bool) $input->getOption('dry-run');

        $itemsMode = $this->itemsMode($input, $output);
        $wantItems = $itemsMode !== null;
        if ($itemsMode === self::ITEMS_MODE_INVALID) {
            return Command::FAILURE;
        }

        if (!$wantReport && !$wantSeed && !$wantItems) {
            // Validace už proběhla otevřením souboru; projdeme ho celý, aby
            // se ozvala i chyba na posledním řádku.
            try {
                $count = 0;
                foreach ($file->records() as $ignored) {
                    $count++;
                }
            } catch (BookingHistoryFormatException $e) {
                $output->writeln("<error>Soubor {$inputPath}: {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
            $output->writeln('');
            $output->writeln("Soubor je validní, záznamů: {$count}.");
            $output->writeln('<comment>Bez režimu se nic nepočítá — přidej --report / --apply-seed / --tag-items.</comment>');
            return Command::SUCCESS;
        }

        // Jeden průchod souborem pro všechny režimy (kvalita, seed, clustery).
        $analyzer = new BookingHistoryAnalyzer(
            minShare: $this->floatOption($input, 'seed-min-share', BookingHistorySeedBuilder::DEFAULT_MIN_SHARE),
            minDocCount: $this->intOption($input, 'seed-min-docs', BookingHistorySeedBuilder::DEFAULT_MIN_DOC_COUNT),
            minCoverage: $this->floatOption($input, 'seed-min-coverage', BookingHistorySeedBuilder::DEFAULT_MIN_COVERAGE),
        );
        try {
            $analysis = $analyzer->analyze($file, $accountTags);
        } catch (BookingHistoryFormatException $e) {
            $output->writeln("<error>Soubor {$inputPath}: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        // Klasifikace není jen pro report — usage režim otagování na ní
        // stojí (agreguje LLM štítky per kód položky, D38).
        $noLlm = (bool) $input->getOption('no-llm');
        $needsLlm = $wantReport || ($wantItems && $itemsMode !== self::ITEMS_MODE_OFFER);
        if ($needsLlm && $noLlm && $itemsMode === self::ITEMS_MODE_USAGE) {
            $output->writeln('<comment>--tag-items=usage potřebuje klasifikaci textů, ale je zapnuté --no-llm'
                . ' → otaguju podle účtů (offer).</comment>');
            $itemsMode = self::ITEMS_MODE_OFFER;
            $needsLlm = $wantReport;
        }
        if ($needsLlm && !$noLlm) {
            $this->classify($output, $analysis, $inputPath, $dsConnection, $dsConfig, $config, $input->getOption('backend'));
        }

        $taxonomy = $config->cfgItem('core.exchange.contentTags');
        $taxonomy = is_array($taxonomy) ? $taxonomy : [];

        $seedApplier = new SeedApplier($dsConnection->getDibiConnection());
        $candidates  = $analysis->seed->candidates();
        $seedPlan    = ($wantReport || $wantSeed) ? $seedApplier->plan($candidates) : [];

        if ($wantSeed) {
            $this->applySeed($output, $seedApplier, $candidates, $seedPlan, $dryRun);
        }

        // Otagování jde před zápisem reportu, aby v něm mohl být jeho
        // výsledek (u usage režimu je to hlavní přínos běhu).
        $itemsResult = null;
        if ($wantItems) {
            $itemsResult = $this->tagItems(
                $output,
                $analysis,
                $itemsMode,
                $dsConnection,
                $dsConfig,
                $config,
                $resolver,
                $offer,
                $lang,
                $dryRun,
                $this->floatOption($input, 'usage-min-share', BookingHistoryItemUsageTagger::DEFAULT_MIN_SHARE),
                $this->intOption($input, 'usage-min-rows', BookingHistoryItemUsageTagger::DEFAULT_MIN_ROWS),
            );
        }

        if ($wantReport) {
            $this->writeReport($output, $input, $analysis, $taxonomy, $seedPlan, $inputPath, $itemsResult);
        }

        return Command::SUCCESS;
    }

    // ── Režimy ──────────────────────────────────────────────────────────────

    private function printHeader(
        OutputInterface $output,
        BookingHistoryFile $file,
        string $variant,
        AccountTagMap $accountTags,
    ): void {
        $header = $file->header;
        $output->writeln("Zdroj: {$header->sourceLabel()}");
        $output->writeln(sprintf(
            'Varianta osnovy: %s%s',
            $header->chartVariant,
            $header->chartVariantIsGuessed() ? " → reverz nad nabídkou „{$variant}“" : '',
        ));
        $output->writeln(sprintf(
            'Období: %s — %s, měna: %s, typy dokladů: %s',
            $header->period['from'] ?? '?',
            $header->period['to'] ?? '?',
            $header->currency,
            $header->docTypes !== [] ? implode(', ', $header->docTypes) : '—',
        ));
        if ($accountTags->isEmpty()) {
            $output->writeln('<comment>Nabídka účetních položek pro tuto variantu není k dispozici'
                . ' — reverz účet→štítek nic nezasáhne.</comment>');
        }
    }

    private function classify(
        OutputInterface $output,
        BookingHistoryAnalysis $analysis,
        string $inputPath,
        DataSourceConnection $db,
        DataSourceConfig $dsConfig,
        ConfigRuntime $config,
        mixed $backendOption,
    ): void {
        $clusters = $analysis->clustersForClassification();
        if ($clusters === []) {
            return;
        }

        $cache = TagCache::forInput($inputPath);
        $classifier = $this->createClassifier(
            $db,
            $dsConfig,
            $config,
            $this->resolveBackendNdx($db, $backendOption),
            $cache,
        );

        $output->writeln('');
        $output->writeln(sprintf(
            'Klasifikace: %d distinct textů (cache: %s)',
            count($clusters),
            basename($cache->path),
        ));

        $result = $classifier->classify(
            $clusters,
            static function (int $done, int $total) use ($output): void {
                if ($total > 0) {
                    $output->write(sprintf("\r  hotovo %d / %d", $done, $total));
                }
            },
        );
        if ($result['calls'] > 0) {
            $output->writeln('');
        }

        if ($result['unavailable']) {
            $output->writeln('<comment>  AI backend nebo taxonomie není k dispozici'
                . ' — report pojede jen z reverzu.</comment>');
            return;
        }
        $output->writeln(sprintf(
            '  z cache %d, nově klasifikováno %d, volání %d, padlých dávek %d',
            $result['cached'],
            $result['classified'],
            $result['calls'],
            $result['failedBatches'],
        ));
        $analysis->applyLlmTags($result['tags']);
    }

    /**
     * @param array<string, array{action: string, tag: string, existingTag?: string, existingOrigin?: string}> $seedPlan
     * @param array<string, mixed> $taxonomy
     * @param ?array<string, mixed> $itemsResult souhrn otagování položek pro report
     */
    private function writeReport(
        OutputInterface $output,
        InputInterface $input,
        BookingHistoryAnalysis $analysis,
        array $taxonomy,
        array $seedPlan,
        string $inputPath,
        ?array $itemsResult = null,
    ): void {
        $report = new BookingHistoryReport(
            $analysis,
            $taxonomy,
            $seedPlan,
            $inputPath,
            date('Y-m-d H:i'),
            $itemsResult,
        );

        $target = (string) ($input->getOption('report-out') ?? '') ?: $inputPath . '.report.md';
        $output->writeln('');
        foreach ($report->summaryLines() as $line) {
            $output->writeln($line);
        }

        if (@file_put_contents($target, $report->render()) === false) {
            $output->writeln("<error>Report se nepodařilo zapsat do {$target}</error>");
            return;
        }
        $output->writeln('');
        $output->writeln("Report: <info>{$target}</info>");
    }

    /**
     * @param list<\Shipard\Module\Core\Exchange\BookingHistory\SeedCandidate> $candidates
     * @param array<string, array{action: string, tag: string, existingTag?: string, existingOrigin?: string}> $plan
     */
    private function applySeed(
        OutputInterface $output,
        SeedApplier $applier,
        array $candidates,
        array $plan,
        bool $dryRun,
    ): void {
        $output->writeln('');
        $output->writeln('<comment>Seed pravidel IČO → štítek</comment>');
        if ($candidates === []) {
            $output->writeln('  Žádný kandidát nesplnil prahy — není co zapsat.');
            return;
        }

        $byAction = ['insert' => 0, 'update' => 0, 'skip' => 0, 'same' => 0];
        foreach ($plan as $companyId => $entry) {
            $byAction[$entry['action']] = ($byAction[$entry['action']] ?? 0) + 1;
            if ($entry['action'] === 'skip') {
                $output->writeln(sprintf(
                    '  <comment>přeskočeno</comment> %s: má `%s` (původ %s), import navrhoval `%s`',
                    $companyId,
                    $entry['existingTag'] ?? '?',
                    $entry['existingOrigin'] ?? '?',
                    $entry['tag'],
                ));
            }
        }

        if ($dryRun) {
            $output->writeln(sprintf(
                '  <comment>--dry-run:</comment> nových %d, aktualizací seedu %d,'
                . ' přeskočeno (user/learned) %d, bez změny %d',
                $byAction['insert'],
                $byAction['update'],
                $byAction['skip'],
                $byAction['same'],
            ));
            return;
        }

        $counts = $applier->apply($candidates);
        $output->writeln(sprintf(
            '  zapsáno: nových %d, aktualizací %d, přeskočeno %d, bez změny %d',
            $counts['inserted'],
            $counts['updated'],
            $counts['skipped'],
            $counts['same'],
        ));
    }

    /**
     * Otagování živých položek DS. Vrací souhrn pro report (nebo null,
     * když se nedalo nic udělat).
     *
     * @return array{mode: string, matchRate: ?float, matchedCodes: int, fileCodes: int, plan: list<array<string, mixed>>, applied: ?array{updated: int, failed: int}}|null
     */
    private function tagItems(
        OutputInterface $output,
        BookingHistoryAnalysis $analysis,
        string $mode,
        DataSourceConnection $db,
        DataSourceConfig $dsConfig,
        ConfigRuntime $config,
        ModulePathResolver $resolver,
        AccountingItemsOffer $offer,
        string $lang,
        bool $dryRun,
        float $usageMinShare,
        int $usageMinRows,
    ): ?array {
        $output->writeln('');
        $output->writeln('<comment>Otagování položek</comment>');

        $tables = TableLoader::load($dsConfig, $resolver, $lang);
        $dibi = $db->getDibiConnection();
        $registry = DocumentLoader::load($dsConfig, $resolver);
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);

        $backfill = new ContentTagBackfill(
            db: $db,
            offer: $offer,
            config: $config,
            tables: $tables,
            dsConfig: $dsConfig,
            documentRegistry: $registry,
            eventDispatcher: $dispatcher,
        );

        if (!$backfill->accountingActive() && $mode !== self::ITEMS_MODE_USAGE) {
            // Usage režim účet nepotřebuje — pracuje s kódy a klasifikací.
            $output->writeln('<comment>  Modul účetnictví není aktivní — položky nemají účet,'
                . ' není podle čeho tagovat (offer režim).</comment>');
            if ($mode === self::ITEMS_MODE_OFFER) {
                return null;
            }
        }

        $tagger = new BookingHistoryItemUsageTagger(
            $backfill->liveItemsByCode(),
            $analysis->usageByItemCode(),
            $analysis->fileItemCodes,
            $usageMinShare,
            $usageMinRows,
        );

        $resolved = $mode;
        if ($mode === self::ITEMS_MODE_AUTO) {
            $resolved = $tagger->isAutoEligible() ? self::ITEMS_MODE_USAGE : self::ITEMS_MODE_OFFER;
            $output->writeln(sprintf(
                '  režim: <info>%s</info> (auto — shoda kódů %s: %d z %d kódů souboru je v katalogu, práh %s)',
                $resolved,
                $this->percent($tagger->matchRate()),
                $tagger->matchedCodeCount(),
                $tagger->fileCodeCount(),
                $this->percent(BookingHistoryItemUsageTagger::AUTO_MATCH_RATE),
            ));
        } else {
            $output->writeln(sprintf(
                '  režim: <info>%s</info> (shoda kódů %s)',
                $resolved,
                $this->percent($tagger->matchRate()),
            ));
        }

        return $resolved === self::ITEMS_MODE_USAGE
            ? $this->tagItemsFromUsage($output, $backfill, $tagger, $dryRun)
            : $this->tagItemsFromOffer($output, $backfill, $tagger, $dryRun);
    }

    /**
     * @return array{mode: string, matchRate: ?float, matchedCodes: int, fileCodes: int, plan: list<array<string, mixed>>, applied: ?array{updated: int, failed: int}}|null
     */
    private function tagItemsFromUsage(
        OutputInterface $output,
        ContentTagBackfill $backfill,
        BookingHistoryItemUsageTagger $tagger,
        bool $dryRun,
    ): ?array {
        $plan = $tagger->plan();
        $skipped = $tagger->skipped();
        $output->writeln(sprintf(
            '  kódů s klasifikovanými texty: %d — návrh %d, mimo katalog %d, už otagované %d,'
            . ' bez dominantního štítku %d, remíza %d, pod prahem podílu %d, pod prahem řádků %d',
            array_sum([$skipped['candidates'], $skipped['notInCatalog'], $skipped['alreadyTagged'],
                $skipped['dominantNull'], $skipped['tie'], $skipped['belowShare'], $skipped['belowRows']]),
            $skipped['candidates'],
            $skipped['notInCatalog'],
            $skipped['alreadyTagged'],
            $skipped['dominantNull'],
            $skipped['tie'],
            $skipped['belowShare'],
            $skipped['belowRows'],
        ));

        $summary = [
            'mode'         => self::ITEMS_MODE_USAGE,
            'matchRate'    => $tagger->matchRate(),
            'matchedCodes' => $tagger->matchedCodeCount(),
            'fileCodes'    => $tagger->fileCodeCount(),
            'plan'         => $plan,
            'applied'      => null,
        ];
        if ($plan === []) {
            return $summary;
        }

        if ($dryRun) {
            foreach ($plan as $entry) {
                $output->writeln(sprintf(
                    '  <comment>plán</comment> %s (%s) → `%s`  [%d z %d řádků, %s]',
                    $entry['code'],
                    $entry['name'],
                    $entry['tag'],
                    $entry['dominantRows'],
                    $entry['rows'],
                    $this->percent($entry['share']),
                ));
            }
            $output->writeln(sprintf('  <comment>--dry-run:</comment> otagovalo by %d položek', count($plan)));
            return $summary;
        }

        $result = $backfill->apply($plan);
        $summary['applied'] = [
            'updated' => count($result['updated']),
            'failed'  => count($result['failed']),
        ];
        $output->writeln(sprintf(
            '  otagováno %d položek, selhalo %d',
            count($result['updated']),
            count($result['failed']),
        ));
        foreach ($result['failed'] as $failure) {
            $output->writeln("  <error>položka {$failure['id']}: {$failure['reason']}</error>");
        }
        return $summary;
    }

    /**
     * @return array{mode: string, matchRate: ?float, matchedCodes: int, fileCodes: int, plan: list<array<string, mixed>>, applied: ?array{updated: int, failed: int}}|null
     */
    private function tagItemsFromOffer(
        OutputInterface $output,
        ContentTagBackfill $backfill,
        BookingHistoryItemUsageTagger $tagger,
        bool $dryRun,
    ): ?array {
        if (!$backfill->accountingActive()) {
            return null;
        }
        if (!$backfill->offerAvailable()) {
            $output->writeln('<comment>  Varianta účtové osnovy DS není nastavená'
                . ' (economy.accountChart) — mapa účet→štítek je prázdná, není podle čeho tagovat.</comment>');
            return null;
        }

        $plan = $backfill->plan();
        $skipped = $backfill->planSkipped();
        $output->writeln(sprintf(
            '  netagovaných položek s účtem: %d — jednoznačných %d,'
            . ' kolizní účet %d, účet mimo nabídku %d',
            $skipped['candidates'] + $skipped['ambiguousAccount'] + $skipped['unmappedAccount'],
            $skipped['candidates'],
            $skipped['ambiguousAccount'],
            $skipped['unmappedAccount'],
        ));

        $summary = [
            'mode'         => self::ITEMS_MODE_OFFER,
            'matchRate'    => $tagger->matchRate(),
            'matchedCodes' => $tagger->matchedCodeCount(),
            'fileCodes'    => $tagger->fileCodeCount(),
            'plan'         => $plan,
            'applied'      => null,
        ];
        if ($plan === []) {
            return $summary;
        }
        if ($dryRun) {
            foreach ($plan as $entry) {
                $output->writeln(sprintf(
                    '  <comment>plán</comment> %s (%s, účet %s) → `%s`',
                    $entry['code'],
                    $entry['name'],
                    $entry['account'],
                    $entry['tag'],
                ));
            }
            $output->writeln(sprintf('  <comment>--dry-run:</comment> otagovalo by %d položek', count($plan)));
            return $summary;
        }

        $result = $backfill->apply($plan);
        $summary['applied'] = [
            'updated' => count($result['updated']),
            'failed'  => count($result['failed']),
        ];
        $output->writeln(sprintf(
            '  otagováno %d položek, selhalo %d',
            count($result['updated']),
            count($result['failed']),
        ));
        foreach ($result['failed'] as $failure) {
            $output->writeln("  <error>položka {$failure['id']}: {$failure['reason']}</error>");
        }
        return $summary;
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', ' ') . ' %';
    }

    /**
     * Režim `--tag-items`. Opce má volitelnou hodnotu, takže „chybí" a „je
     * bez hodnoty" se nedají rozlišit z `getOption()` (obojí je null) —
     * přítomnost se čte z raw vstupu.
     *
     * @return ?string null = otagovat se nemá; ITEMS_MODE_INVALID = chybná hodnota
     */
    private function itemsMode(InputInterface $input, OutputInterface $output): ?string
    {
        if (!$input->hasParameterOption('--tag-items', true)) {
            return null;
        }
        $value = $input->getOption('tag-items');
        if ($value === null || $value === '') {
            return self::ITEMS_MODE_AUTO;
        }
        $mode = strtolower(trim((string) $value));
        if (!in_array($mode, [self::ITEMS_MODE_OFFER, self::ITEMS_MODE_USAGE, self::ITEMS_MODE_AUTO], true)) {
            $output->writeln("<error>--tag-items: neznámý režim \"{$mode}\" (offer | usage | auto)</error>");
            return self::ITEMS_MODE_INVALID;
        }
        return $mode;
    }

    private function floatOption(InputInterface $input, string $name, float $default): float
    {
        $value = $input->getOption($name);
        return is_numeric($value) ? (float) $value : $default;
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * `--backend` bere id nebo název backendu; bez přepínače platí settings
     * override `exchange.contentTag.backend` a pak default backend DS
     * (kaskáda z D18). null = default backend.
     */
    private function resolveBackendNdx(DataSourceConnection $db, mixed $option): ?int
    {
        $option = is_string($option) ? trim($option) : '';
        if ($option === '') {
            $setting = (new SettingsStore($db))->get('exchange.contentTag.backend');
            return is_numeric($setting) && (int) $setting > 0 ? (int) $setting : null;
        }
        if (preg_match('/^\d+$/', $option) === 1) {
            return (int) $option;
        }
        $row = $db->fetchRow(
            'SELECT `id` FROM `core_ai_backends` WHERE `name` = %s AND `is_active` = %i LIMIT 1',
            $option,
            1,
        );
        return is_array($row) && isset($row['id']) ? (int) $row['id'] : null;
    }
}
