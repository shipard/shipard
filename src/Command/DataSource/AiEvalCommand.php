<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\TableLoader;
use Shipard\Core\Ai\AiCallOverride;
use Shipard\Core\Ai\AiCallPlan;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Utils\GitState;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Exchange\Compare\CanonicalComparator;
use Shipard\Module\Core\Exchange\Compare\Projection;
use Shipard\Module\Core\Exchange\Compare\ProjectionException;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetPreflight;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\Verify\VerifyExpectations;
use Shipard\Module\Core\Exchange\Enrich\RowEnrichmentPipeline;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputation;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisResultWriter;
use Shipard\Module\Core\Mail\Analysis\AttachmentPreparer;
use Shipard\Module\Core\Mail\Analysis\OutputParser;
use Shipard\Module\Core\Mail\Analysis\PromptRenderer;
use Shipard\Module\Core\Mail\Eval\EvalCallResult;
use Shipard\Module\Core\Mail\Eval\EvalCostEstimator;
use Shipard\Module\Core\Mail\Eval\EvalDataSource;
use Shipard\Module\Core\Mail\Eval\EvalException;
use Shipard\Module\Core\Mail\Eval\EvalMessage;
use Shipard\Module\Core\Mail\Eval\EvalMessages;
use Shipard\Module\Core\Mail\Eval\EvalMetrics;
use Shipard\Module\Core\Mail\Eval\EvalPreflight;
use Shipard\Module\Core\Mail\Eval\EvalReport;
use Shipard\Module\Core\Mail\Eval\EvalRun;
use Shipard\Module\Core\Mail\Eval\EvalRunner;
use Shipard\Module\Core\Mail\Eval\EvalRunStore;
use Shipard\Module\Core\Mail\Eval\EvalVariant;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `shpd-ds ai-eval <sada> --variant <soubor> [--only] [--limit] [--repeat]
 * [--save-raw <adresář>] [--out <adresář>] [--yes] [--json]` — evaluace AI
 * analýzy nad datovou sadou (#103 F4, tasks/testing-phase4.md D34–D41,
 * docs/test-corpus.md § 18).
 *
 * Preflight ({@see EvalPreflight}, exit 2, nic se nemění), odhad ceny
 * a potvrzení (bez `--yes` jen na TTY; neinteraktivně končí exit 2 —
 * útrata je vědomý krok), běh nanečisto ({@see EvalRunner} nad
 * {@see AnalysisComputation}: žádný claim, zápis ani změna stavu zprávy),
 * porovnání s `canonicalJson` ideální analýzy ze souborů sady, metriky
 * ({@see EvalMetrics}) a soubor výsledků ({@see EvalRunStore},
 * `tests/eval/<sada>/<datum>-<varianta>[-n].json`). Exit 0 = běh doběhl
 * (i se špatnými výsledky), 1 = některé volání selhalo infrastrukturně
 * (`ai_error`, `config_error`, `internal`), 2 = preflight nebo vstup.
 * Testovatelné přes podtřídu (seamy níže).
 */
class AiEvalCommand extends Command
{
    public const string AI_FUNCTION = EvalPreflight::AI_FUNCTION;
    public const string CONTENT_TAGS_FUNCTION = 'exchange.contentTags';
    private const string MIN_MEMORY_LIMIT = '512M';

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ai-eval')
            ->setDescription('Evaluace AI analýzy nad datovou sadou: analýza nanečisto, porovnání s očekáváním po polích, soubor výsledků (stojí peníze)')
            ->addArgument('path', InputArgument::REQUIRED, 'Složka sady (s expected/) nebo .zip')
            ->addOption('variant', null, InputOption::VALUE_REQUIRED, 'Soubor varianty (tests/eval/variants/<název>.jsonc)')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 'Jen tyto doklady: čísla buněk, stemy, varianty nebo messageId, čárkami (0101,0902)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nejvýš tolik zpráv (po --only)')
            ->addOption('repeat', null, InputOption::VALUE_REQUIRED, 'Každou zprávu tolikrát (průměr a rozpětí metrik, stabilita polí)', '1')
            ->addOption('save-raw', null, InputOption::VALUE_REQUIRED, 'Uložit syrové výstupy modelu do této složky (mimo repo)')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Složka výsledků (výchozí tests/eval/<název sady>/ v repu)')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Bez potvrzení odhadu ceny')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Soubor výsledků jako JSON na stdout (lidské řádky na stderr)');
    }

    // ── Seamy ──────────────────────────────────────────────────────────────

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    /** Režim serveru; `development` bez server.json (produkce ho má vždy). */
    protected function getServerMode(): string
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg->getMode();
        } catch (\Throwable) {
            return 'development';
        }
    }

    protected function buildResolver(): ModulePathResolver
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    protected function loadConfig(string $dsDir, string $language): ConfigRuntime
    {
        return ConfigRuntime::load($dsDir, $language);
    }

    protected function createPreflight(): DatasetPreflight
    {
        return new DatasetPreflight();
    }

    protected function createDataSource(DataSourceConnection $db): EvalDataSource
    {
        return new EvalDataSource($db);
    }

    protected function createResolver(DataSourceConnection $db, ConfigRuntime $config, DataSourceConfig $dsConfig): AiFunctionResolver
    {
        return new AiFunctionResolver($db, $config, $dsConfig);
    }

    protected function createLlmClient(ModelCatalog $catalog): LlmClient
    {
        return new AnthropicLlmClient($catalog);
    }

    /** Výpočet nanečisto se stejným wiringem jako ostrý runner, bez zápisu. */
    protected function createComputation(
        DataSourceConnection $db,
        DataSourceConfig $dsConfig,
        ConfigRuntime $config,
        string $dsDir,
        LlmClient $llm,
        ModelCatalog $catalog,
        EvalVariant $variant,
    ): AnalysisComputing {
        $tables = TableLoader::load($dsConfig, $this->buildResolver(), $dsConfig->getDefaultLanguage());
        $attachments = new AttachmentService($db, $dsDir, $tables);
        $enricher = RowEnrichmentPipeline::create($db, $config, $dsConfig, $variant->contentTagsOverride(), $llm);
        $validator = new AnalysisResultWriter($db, $dsConfig, new SchemaValidator(SchemaLoader::default()), $enricher, $config);
        return new AnalysisComputation(new AttachmentPreparer($attachments), new PromptRenderer(), new OutputParser(), $llm, $validator, $catalog);
    }

    /** @return array{commit: ?string, dirty: ?bool} */
    protected function gitState(): array
    {
        return GitState::describe();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    protected function runsDir(?string $out, string $setName): string
    {
        return $out !== null && $out !== '' ? rtrim($out, '/') : EvalRunStore::defaultDir(dirname(__DIR__, 3), $setName);
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $human = $json ? $err : $output;

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::INVALID;
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);

        // 1. Vstup: varianta, sada, výběr zpráv.
        $variantPath = $input->getOption('variant');
        if (!is_string($variantPath) || trim($variantPath) === '') {
            return $this->refuse($err, ['--variant <soubor> je povinný (např. tests/eval/variants/baseline.jsonc)']);
        }
        $repeat = $input->getOption('repeat');
        if (!is_string($repeat) || !ctype_digit($repeat) || (int) $repeat < 1) {
            return $this->refuse($err, ['--repeat musí být kladné celé číslo']);
        }
        $repeat = (int) $repeat;
        $limitOpt = $input->getOption('limit');
        $limit = null;
        if ($limitOpt !== null) {
            if (!is_string($limitOpt) || !ctype_digit($limitOpt) || (int) $limitOpt < 1) {
                return $this->refuse($err, ['--limit musí být kladné celé číslo']);
            }
            $limit = (int) $limitOpt;
        }

        try {
            $variant = EvalVariant::load(trim($variantPath));
            $reader = DatasetReader::open((string) $input->getArgument('path'));
            $manifest = $reader->getManifest();
            $messages = EvalMessages::fromDataset($reader);
        } catch (EvalException | DatasetException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }
        $only = EvalMessages::parseOnly($input->getOption('only'));
        $selected = EvalMessages::filter($messages, $only, $limit);
        if ($selected === []) {
            $reader->close();
            return $this->refuse($err, ['žádná zpráva sady neodpovídá --only / --limit']);
        }

        // 2. Wiring a preflight (nic se nemění).
        $db = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        try {
            $config = $this->loadConfig($dsDir, $dsConfig->getDefaultLanguage());
        } catch (\RuntimeException $e) {
            $reader->close();
            return $this->refuse($err, ['compiled config chybí — spusť shpd-ds ds-upgrade (' . $e->getMessage() . ')']);
        }
        $dataSource = $this->createDataSource($db);
        $resolver = $this->createResolver($db, $config, $dsConfig);
        $profile = $dataSource->profile($variant->profile);
        $override = $profile !== null
            ? AiCallOverride::fromRow($profile)->merge($variant->callOverride())
            : $variant->callOverride();

        $setMessageIds = array_map(static fn (EvalMessage $m): string => $m->messageId, $messages);
        $preflight = new EvalPreflight($this->getServerMode(), $reader, $dsConfig, $this->createPreflight(), $dataSource, $variant, $resolver, $override);
        $problems = $preflight->problems($setMessageIds);
        if ($problems !== [] || $profile === null) {
            $reader->close();
            return $this->refuse($err, $problems);
        }
        try {
            $projection = Projection::load($reader->resolvePath(VerifyExpectations::PROJECTION));
        } catch (ProjectionException | DatasetException $e) {
            $reader->close();
            return $this->refuse($err, [VerifyExpectations::PROJECTION . ': ' . $e->getMessage()]);
        }

        // 3. Odhad a potvrzení.
        $plan = $resolver->plan(self::AI_FUNCTION, $override);
        $tagPlan = $this->contentTagsPlan($resolver, $variant);
        $catalog = $resolver->catalog();
        $store = new EvalRunStore($this->runsDir($input->getOption('out'), $manifest->name));
        $setHash = EvalRunStore::datasetHash($reader);
        $lastRun = $store->runsFor($variant->name, $setHash)[0] ?? null;
        $seriesRun = $lastRun === null ? EvalCostEstimator::seriesRun($store->runs(), $plan->catalogModel) : null;
        $calls = count($selected) * $repeat;
        $estimate = EvalCostEstimator::estimate($calls, $lastRun, $seriesRun, $plan, $tagPlan, $catalog);

        $human->writeln(sprintf(
            '<info>Evaluace AI</info> — sada %s: %d zpráv × %d opakování = %d volání, model %s (připojení %s — %s), profil %s',
            $manifest->name, count($selected), $repeat, $calls, $plan->model, $plan->connectionCode, $plan->origins['connection'] ?? '?', $variant->profile,
        ));
        $human->writeln(match ($estimate['basis']) {
            'lastRun' => sprintf('Odhad ceny: ~%s USD (%s USD / volání podle posledního běhu %s)', number_format((float) $estimate['total'], 4, '.', ''), number_format((float) $estimate['perCall'], 4, '.', ''), (string) ($estimate['source']['file'] ?? '')),
            'series' => sprintf('Odhad ceny: ~%s USD (%s USD / volání z ceníku a tokenů na doklad podle běhu %s — model %s téže řady)', number_format((float) $estimate['total'], 4, '.', ''), number_format((float) $estimate['perCall'], 4, '.', ''), (string) ($estimate['source']['file'] ?? ''), (string) ($estimate['source']['model'] ?? '?')),
            'catalog' => sprintf('Odhad ceny: ~%s USD (%s USD / volání z ceníku katalogu a typické velikosti dokladu)', number_format((float) $estimate['total'], 4, '.', ''), number_format((float) $estimate['perCall'], 4, '.', '')),
            default => 'Odhad ceny: neznámý (model mimo katalog)',
        });

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive() || !$this->stdinIsTerminal()) {
                $reader->close();
                return $this->refuse($err, ['bez --yes se evaluace neinteraktivně nespouští — stojí peníze; potvrď odhad na terminálu nebo přidej --yes']);
            }
            $question = new ConfirmationQuestion('Spustit? [y/N] ', false);
            if (!$this->getHelper('question')->ask($input, $human, $question)) {
                $human->writeln('Aborted.');
                $reader->close();
                return Command::SUCCESS;
            }
        }

        // 4. Běh.
        self::ensureMemoryLimit();
        $rawDir = $input->getOption('save-raw');
        $rawDir = is_string($rawDir) && trim($rawDir) !== '' ? rtrim($rawDir, '/') : null;
        if ($rawDir !== null && !is_dir($rawDir) && !@mkdir($rawDir, 0755, true) && !is_dir($rawDir)) {
            $reader->close();
            return $this->refuse($err, ["--save-raw: nejde založit {$rawDir}"]);
        }
        try {
            $call = $resolver->resolve(self::AI_FUNCTION, $override);
        } catch (AiFunctionException $e) {
            $reader->close();
            return $this->refuse($err, ['volání funkce ' . self::AI_FUNCTION . ' nejde sestavit: ' . $e->getMessage()]);
        }
        $llm = $this->createLlmClient($catalog);
        $computation = $this->createComputation($db, $dsConfig, $config, $dsDir, $llm, $catalog, $variant);
        $runner = new EvalRunner(
            $computation,
            new CanonicalComparator($projection),
            $dataSource,
            $profile,
            $call,
            $variant->promptTemplate,
            $repeat,
            $rawDir,
            static function (EvalCallResult $r, EvalMessage $m) use ($human, $repeat): void {
                $human->writeln(EvalReport::progressLine($r, $m, $repeat));
            },
        );

        $startedAt = $this->now();
        try {
            $results = $runner->run($selected, $dataSource->messageNdxByCode());
        } catch (EvalException $e) {
            $reader->close();
            return $this->refuse($err, [$e->getMessage()]);
        }
        $finishedAt = $this->now();

        // 5. Metriky, soubor výsledků, souhrn.
        $metrics = EvalMetrics::compute($results, $selected, $repeat);
        $run = EvalRun::build(
            [
                'name' => $manifest->name,
                'country' => $manifest->country,
                'profile' => $manifest->profile,
                'path' => (string) $input->getArgument('path'),
                'hash' => $setHash,
                'messages' => count($selected),
                'messagesInSet' => count($messages),
                'only' => $only,
                'limit' => $limit,
            ],
            $this->gitState(),
            $variant,
            $profile,
            $plan,
            $tagPlan,
            $startedAt->format(DATE_ATOM),
            $finishedAt->format(DATE_ATOM),
            $repeat,
            $metrics,
            $results,
        );
        $reader->close();
        try {
            $file = $store->write($run, $startedAt->format('Y-m-d'), $variant->name);
        } catch (EvalException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $human->writeln('');
        foreach (EvalReport::lines($run, $file) as $line) {
            $human->writeln($line);
        }
        if ($json) {
            $output->writeln((string) json_encode(['file' => $file] + $run, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }

        foreach ($results as $r) {
            if ($r->isInfrastructureFailure()) {
                return Command::FAILURE;
            }
        }
        return Command::SUCCESS;
    }

    private function contentTagsPlan(AiFunctionResolver $resolver, EvalVariant $variant): ?AiCallPlan
    {
        try {
            return $resolver->plan(self::CONTENT_TAGS_FUNCTION, $variant->contentTagsOverride());
        } catch (AiFunctionException) {
            return null;
        }
    }

    /**
     * Symfony Console 7 neinteraktivitu z chybějícího terminálu neodvozuje
     * (`Application::configureIO` vypne interakci jen pro `-n` a `--quiet`)
     * a `QuestionHelper` na prázdném nebo přesměrovaném STDIN vrátí výchozí
     * odpověď potvrzení — běh by skončil „Aborted.“ s exit 0 místo odmítnutí
     * (exit 2). Dotaz proto jde jen na skutečný terminál; protected kvůli
     * test seamu.
     */
    protected function stdinIsTerminal(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDIN);
    }

    /** @param list<string> $problems */
    private function refuse(OutputInterface $err, array $problems): int
    {
        $err->writeln('<error>ai-eval odmítnuto:</error>');
        foreach ($problems as $problem) {
            $err->writeln("  - {$problem}");
        }
        return Command::INVALID;
    }

    /** Přílohy v base64 a obohacení v paměti — CLI bez limitu nespoléhá na default. */
    private static function ensureMemoryLimit(): void
    {
        $current = (string) ini_get('memory_limit');
        if ($current === '-1') {
            return;
        }
        $bytes = static function (string $v): int {
            $unit = strtoupper(substr($v, -1));
            $n = (int) $v;
            return match ($unit) { 'G' => $n << 30, 'M' => $n << 20, 'K' => $n << 10, default => $n };
        };
        if ($bytes($current) < $bytes(self::MIN_MEMORY_LIMIT)) {
            @ini_set('memory_limit', self::MIN_MEMORY_LIMIT);
        }
    }
}
