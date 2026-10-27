<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\SessionService;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Version;
use Shipard\Module\Core\Exchange\Dataset\DatasetCatalog;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eSession;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\E2e\PinnedBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\Video\DatasetBuildCache;
use Shipard\Module\Core\Exchange\Dataset\Video\DatasetBuildProcess;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoException;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoPreflight;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoProgress;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoReport;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoRunnerProcess;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenario;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoScenarioCatalog;
use Shipard\Module\Core\Exchange\Dataset\Video\VideoStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds video-run <scénář> [--verb check|build] [--anchor YYYY-MM-DD]
 * [--base-url <url>] [--json] [--progress-file <soubor>]` — video nad demo
 * sadou (tasks/testing-phase7b.md § 2, #103 D119, D120, D125, D127, D128):
 * demo sada scénáře se sestaví s kotvou = dnešek do mezipaměti
 * ({@see DatasetBuildCache}, druhý běh téhož dne nestaví), přeseeduje se
 * vyhrazený volný zdroj dat (`dataset-seed --yes` ve stejném procesu),
 * otevře se krátkodobá session uživatele `video` jen v souboru
 * `storageState` (D92) a video-runner proběhne jako podproces: `check`
 * = průchod, `build` = `record` + `compose`. Prohlížeč dostane pevný čas
 * (kotva + `clockTime` scénáře v jeho časové zóně — D120) a jazyk podle
 * `locale` scénáře (D121). Hotové video a `meta.json` leží
 * v {@see VideoStore} (D127), výsledek ověření v `check.json`.
 *
 * Preflight (exit 2, nic se nemění): režim serveru `development`, zdroj
 * dat třídy `free`, scénář platný a jeho sada demo sada z checkoutu
 * s příběhem, Gotenberg v `server.json`, Node / závislosti runneru /
 * Chromium runneru / ffmpeg / python3, **lokální host** `--base-url`
 * (D97) a dosažitelnost API zdroje dat; před stavbou sady i `/health`
 * Gotenbergu. Exit 0 jen při stavu `ok`, 1 při nálezech (scénář nesedí,
 * sada nebo seed selhaly), 2 preflight, 130 zrušeno. S `--json` jde na
 * stdout přesně jeden JSON dokument, lidské řádky na stderr. Token
 * session se neobjeví ve výstupu, argumentech, průběhu ani reportu.
 * Zrušení (SIGTERM / SIGINT): generátor dostane SIGTERM, runner SIGINT,
 * session se uklidí; nic rozepsaného se nepovýší. Testovatelné přes
 * podtřídu (seamy níže).
 */
class VideoRunCommand extends Command implements SignalableCommandInterface
{
    public const int EXIT_CANCELLED = VideoReport::EXIT_CANCELLED;
    public const string DEFAULT_BASE_URL = 'http://127.0.0.1';
    public const string DEFAULT_VERB = VideoReport::VERB_CHECK;
    public const array VERBS = [VideoReport::VERB_CHECK, VideoReport::VERB_BUILD];
    /** Uživatel videí: vlastní login a jméno (ukazuje se v menu uživatele na videu), jazyk podle `locale` scénáře. */
    public const string USER_LOGIN = 'video';
    public const string USER_FULL_NAME = 'Jana Zelená';
    public const string USER_LANGUAGE = 'auto';
    public const int SESSION_SECONDS = 3600;
    public const float CANCEL_GRACE_SECONDS = 20.0;
    public const float BUILD_CANCEL_GRACE_SECONDS = 10.0;

    private bool $cancelRequested = false;
    private ?OutputInterface $jsonOut = null;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('video-run')
            ->setDescription('Sestaví demo sadu scénáře s kotvou = dnešek, přeseeduje zdroj dat, otevře session a ověří (check) nebo natočí (build) video ze scénáře (jen development, jen free zdroj dat)')
            ->addArgument('scenario', InputArgument::REQUIRED, 'Id scénáře (demo/scenarios/<id>.jsonc) nebo cesta k němu')
            ->addOption('verb', null, InputOption::VALUE_REQUIRED, 'check = průchod bez záznamu, build = natočit a složit video', self::DEFAULT_VERB)
            ->addOption('anchor', null, InputOption::VALUE_REQUIRED, 'Kotva sady YYYY-MM-DD (výchozí dnešek; jiný den jen pro ladění)')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Adresa serveru bez cesty zdroje dat (aplikace = <base-url>/<ds-id>/app/)', self::DEFAULT_BASE_URL)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Report jako JSON na stdout (lidské řádky na stderr)')
            ->addOption('progress-file', null, InputOption::VALUE_REQUIRED, 'Průběh jako JSON řádky (started / step / finished / error) do tohoto souboru — pro úlohu dashboardu');
    }

    // ── Signály ────────────────────────────────────────────────────────────

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') && \defined('SIGINT') ? [\SIGTERM, \SIGINT] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->cancelRequested = true;
        return false;
    }

    /** Žádost o zrušení mimo signál (test seam). */
    public function requestCancel(): void
    {
        $this->cancelRequested = true;
    }

    // ── Seamy ──────────────────────────────────────────────────────────────

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function loadServerConfig(): ?ServerConfig
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Režim serveru; null = nejde určit (fail-closed — preflight odmítne). */
    protected function getServerMode(): ?string
    {
        return $this->loadServerConfig()?->getMode();
    }

    /** Adresa Gotenbergu (`render.url` v server.json); null = není nastavená. */
    protected function gotenbergUrl(): ?string
    {
        try {
            return $this->loadServerConfig()?->getRender()?->url;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function createLocalBaseUrl(): LocalBaseUrl
    {
        return new LocalBaseUrl();
    }

    protected function createPreflight(): VideoPreflight
    {
        return new VideoPreflight($this->repoRoot());
    }

    protected function createCatalog(): DatasetCatalog
    {
        return new DatasetCatalog($this->repoRoot());
    }

    protected function createScenarioCatalog(): VideoScenarioCatalog
    {
        return new VideoScenarioCatalog($this->repoRoot());
    }

    protected function createCache(): DatasetBuildCache
    {
        return new DatasetBuildCache(DatasetBuildCache::defaultDir(), $this->repoRoot());
    }

    protected function createStore(): VideoStore
    {
        return new VideoStore(VideoStore::defaultDir());
    }

    protected function dataSourceInfo(string $dsDir): ?DataSourceInfo
    {
        return (new DataSourceInventory(dirname(rtrim($dsDir, '/'))))->read($dsDir);
    }

    protected function createSession(DataSourceConnection $db): E2eSession
    {
        return new E2eSession($db, new SessionService(), self::USER_LOGIN, self::USER_FULL_NAME, self::USER_LANGUAGE);
    }

    /**
     * @param list<string> $argv
     * @param \Closure(): bool $shouldCancel
     */
    protected function createBuildProcess(array $argv, \Closure $shouldCancel): DatasetBuildProcess
    {
        return new DatasetBuildProcess($argv, $this->repoRoot(), $shouldCancel, self::BUILD_CANCEL_GRACE_SECONDS);
    }

    /** @param \Closure(): bool $shouldCancel */
    protected function createRunner(VideoPreflight $preflight, string $verb, string $scenarioPath, \Closure $shouldCancel): VideoRunnerProcess
    {
        return new VideoRunnerProcess((string) $preflight->nodeBinary(), $preflight->runnerDir(), $verb, $scenarioPath, $shouldCancel, self::CANCEL_GRACE_SECONDS);
    }

    /**
     * Jiný příkaz `shpd-ds` ve stejném procesu (vzor `DatasetE2eCommand::runSub`).
     *
     * @param array<string, mixed> $args
     */
    protected function runSub(string $command, array $args, OutputInterface $out): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            throw new VideoException("Nelze spustit {$command}: příkaz běží bez aplikace");
        }
        return $app->find($command)->run(new ArrayInput($args), $out);
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** Plný hash HEAD checkoutu pro `meta.json`; null bez gitu (např. pod jiným uživatelem). */
    protected function commitHash(): ?string
    {
        return Version::gitInfo()['fullHash'] ?? null;
    }

    /** Soukromý adresář běhu pro `storageState` (0700, po běhu smazaný). */
    protected function privateDir(): string
    {
        return sys_get_temp_dir() . '/shpd-video-' . $this->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $human = $json ? $err : $output;
        $this->jsonOut = $json ? $output : null;

        $dsDir = rtrim($this->getDataSourceDir(), '/');
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::INVALID;
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsId = $dsConfig->getId();

        // 1. Volby a scénář — nic se nemění.
        $verb = trim((string) $input->getOption('verb'));
        if (!in_array($verb, self::VERBS, true)) {
            return $this->refuse($err, ["--verb musí být check nebo build, je '{$verb}'"]);
        }
        $baseUrl = rtrim(trim((string) $input->getOption('base-url')), '/');
        if (!preg_match('#^https?://[^/\s]+$#', $baseUrl)) {
            return $this->refuse($err, ['--base-url musí být absolutní http(s) adresa serveru bez cesty (např. http://127.0.0.1)']);
        }
        $anchorOpt = $input->getOption('anchor');
        $anchor = is_string($anchorOpt) && trim($anchorOpt) !== '' ? trim($anchorOpt) : $this->now()->format('Y-m-d');
        try {
            DatasetBuildCache::assertAnchor($anchor);
        } catch (VideoException $e) {
            return $this->refuse($err, ['--anchor: ' . $e->getMessage()]);
        }
        $progressOpt = $input->getOption('progress-file');
        try {
            $progress = is_string($progressOpt) && trim($progressOpt) !== ''
                ? VideoProgress::toFile(trim($progressOpt))
                : VideoProgress::none();
        } catch (VideoException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }
        try {
            $scenario = $this->createScenarioCatalog()->resolve((string) $input->getArgument('scenario'));
            $fixedTime = $scenario->fixedTime($anchor);
        } catch (VideoException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }

        // 2. Preflight (exit 2): nástroje, zdroj dat, sada, lokální host (D97), dosažitelnost.
        $preflight = $this->createPreflight();
        $catalog = $this->createCatalog();
        $gotenbergUrl = $this->gotenbergUrl();
        $problems = $preflight->videoProblems($this->getServerMode(), $this->dataSourceInfo($dsDir), $scenario, $catalog, $gotenbergUrl);
        $pinned = null;
        $origin = $baseUrl;
        try {
            $pinned = $this->createLocalBaseUrl()->verify($baseUrl);
            $origin = $pinned->origin;
        } catch (E2eException $e) {
            $problems[] = $e->getMessage();
        }
        if ($problems === [] && $pinned instanceof PinnedBaseUrl) {
            $reach = $preflight->appReachable(E2ePreflight::probeUrl($origin, $dsId), pin: $pinned);
            if ($reach !== null) {
                $problems[] = $reach;
            }
        }
        if ($problems !== [] || !$pinned instanceof PinnedBaseUrl || $gotenbergUrl === null) {
            return $this->refuse($err, $problems);
        }

        $cache = $this->createCache();
        $store = $this->createStore();
        try {
            $sourceHash = $cache->sourceHash($scenario->dataset);
            $cacheDir = $cache->dir($scenario->dataset, $anchor);
        } catch (VideoException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }
        $cached = $cache->isFresh($scenario->dataset, $anchor, $sourceHash);
        if (!$cached) {
            $gotenberg = $preflight->gotenbergReachable($gotenbergUrl);
            if ($gotenberg !== null) {
                return $this->refuse($err, [$gotenberg]);
            }
        }

        $appUrl = $origin . '/' . $dsId . '/app/';
        $dsInfo = $this->dataSourceInfo($dsDir);
        $meta = [
            'verb' => $verb,
            'scenario' => $scenario->toArray(),
            'anchor' => $anchor,
            'fixedTime' => $fixedTime,
            'dataset' => ['name' => $scenario->dataset, 'cacheDir' => $cacheDir, 'cached' => $cached, 'sourceHash' => $sourceHash],
            'dataSource' => ['id' => $dsId, 'name' => $dsInfo?->name ?? $dsConfig->getName()],
            'appUrl' => $appUrl,
        ];
        $human->writeln(sprintf('<info>Scénář</info> %s (%s) → sada %s, kotva %s, zdroj dat %s', $scenario->id, $verb, $scenario->dataset, $anchor, $dsId));
        $human->writeln("Aplikace: {$appUrl}, čas v prohlížeči: {$fixedTime}");
        $progress->started([
            'pid' => getmypid() ?: null,
            'verb' => $verb,
            'scenario' => $scenario->id,
            'dataset' => $scenario->dataset,
            'anchor' => $anchor,
            'dataSource' => $dsId,
            'appUrl' => $appUrl,
        ]);

        $steps = [];
        $cancelled = false;
        $result = null;
        $video = null;

        try {
            // 3. Sestavení sady s kotvou (D120, D128) a přeseedování — pod zámkem sady.
            $lock = $cache->lock($scenario->dataset);
            try {
                if ($this->cancelRequested) {
                    $cancelled = true;
                } elseif ($cached) {
                    $human->writeln("<info>Sada z mezipaměti</info> {$cacheDir}");
                    $steps[VideoProgress::STEP_BUILD] = ['ran' => true, 'cached' => true, 'exitCode' => 0, 'termSignal' => null, 'durationMs' => 0];
                    $progress->step(VideoProgress::STEP_BUILD, VideoProgress::STATUS_FINISHED, 0, ['cached' => true]);
                } else {
                    $human->writeln("<info>Sestavení sady {$scenario->dataset} s kotvou {$anchor}…</info> (první build dne renderuje PDF — minuty)");
                    $progress->step(VideoProgress::STEP_BUILD, VideoProgress::STATUS_STARTED);
                    $cache->prepare($scenario->dataset, $anchor);
                    $argv = $cache->buildArgv((string) $preflight->python3Binary(), $scenario->dataset, $anchor);
                    $build = $this->createBuildProcess($argv, fn (): bool => $this->cancelRequested);
                    $res = $build->run(DatasetBuildProcess::environment($gotenbergUrl, $preflight->home(), $preflight->toolPath()), $err);
                    $cancelled = $res['cancelled'] || $this->cancelRequested;
                    $ok = !$cancelled && $res['exitCode'] === 0;
                    if ($ok) {
                        $cache->markBuilt($scenario->dataset, $anchor, $sourceHash, $this->commitHash(), $this->now());
                        foreach ($cache->prune($scenario->dataset, $anchor) as $removed) {
                            $human->writeln("Starší sestavení smazáno: {$removed}");
                        }
                    }
                    $steps[VideoProgress::STEP_BUILD] = ['ran' => true, 'cached' => false, 'exitCode' => $res['exitCode'], 'termSignal' => $res['termSignal'], 'durationMs' => $res['durationMs']];
                    $progress->step(VideoProgress::STEP_BUILD, $ok ? VideoProgress::STATUS_FINISHED : VideoProgress::STATUS_FAILED, $res['exitCode'], ['cached' => false, 'cancelled' => $cancelled]);
                    if (!$ok && !$cancelled) {
                        $human->writeln('<error>Sestavení sady selhalo (' . ($res['termSignal'] !== null ? "signál {$res['termSignal']}" : 'exit ' . ($res['exitCode'] ?? '?')) . ') — přeseedování ani video neběží.</error>');
                    }
                }

                if (!$cancelled && ($steps[VideoProgress::STEP_BUILD]['exitCode'] ?? null) === 0) {
                    $human->writeln('<info>Přeseedování…</info>');
                    $progress->step(VideoProgress::STEP_RESEED, VideoProgress::STATUS_STARTED);
                    $t0 = microtime(true);
                    $exit = $this->runSub('dataset-seed', ['path' => $cacheDir, '--yes' => true], $err);
                    $steps[VideoProgress::STEP_RESEED] = ['ran' => true, 'exitCode' => $exit, 'termSignal' => null, 'durationMs' => (int) round((microtime(true) - $t0) * 1000)];
                    $progress->step(VideoProgress::STEP_RESEED, $exit === Command::SUCCESS ? VideoProgress::STATUS_FINISHED : VideoProgress::STATUS_FAILED, $exit);
                    if ($exit !== Command::SUCCESS) {
                        $human->writeln("<error>Přeseedování selhalo (exit {$exit}) — video neběží.</error>");
                    }
                    if ($this->cancelRequested) {
                        $cancelled = true;
                    }
                }
            } finally {
                $cache->unlock($lock);
            }

            // 4. Session a runner.
            if (!$cancelled && ($steps[VideoProgress::STEP_RESEED]['exitCode'] ?? null) === Command::SUCCESS) {
                $store->prepare($scenario->id);
                $privateDir = $this->privateDir();
                if (!@mkdir($privateDir, 0700, true)) {
                    throw new VideoException("Soukromý adresář běhu {$privateDir} nejde vytvořit");
                }
                $db = $this->dsConnection ?? new DataSourceConnection($dsConfig);
                $session = $this->createSession($db);
                try {
                    $user = $session->ensureUser();
                    foreach ($user['warnings'] as $warning) {
                        $human->writeln("<comment>warning:</comment> {$warning}");
                    }
                    if ($user['created']) {
                        $human->writeln("Uživatel '{$user['login']}' založen (administrátor, systémový, bez hesla).");
                    } elseif ($user['full_name'] !== self::USER_FULL_NAME) {
                        $human->writeln("<comment>warning:</comment> uživatel '{$user['login']}' má jméno '{$user['full_name']}' — na videu bude ono (video-run existujícího uživatele nemění)");
                    }
                    $stale = $session->invalidateAll();
                    if ($stale > 0) {
                        $human->writeln("<comment>warning:</comment> zneplatněno {$stale} session uživatele '{$user['login']}' z minulého běhu");
                    }
                    $storageState = $session->open($origin, $privateDir, self::SESSION_SECONDS);

                    $vars = [
                        'SHPD_BASE_URL' => $origin . '/' . $dsId,
                        'SHPD_STORAGE_STATE' => $storageState,
                        'VIDEO_WORK_DIR' => $store->workDir($scenario->id),
                        'VIDEO_OUT_DIR' => $store->outDir($scenario->id),
                        'VIDEO_FIXED_TIME' => $fixedTime,
                        'VIDEO_HEADFUL' => '0',
                    ];
                    if ($pinned->chromiumRule() !== null) {
                        $vars['VIDEO_HOST_RESOLVER_RULES'] = $pinned->chromiumRule();
                    }
                    $env = VideoRunnerProcess::environment($vars, $preflight->home(), $preflight->toolPath());

                    $first = $verb === VideoReport::VERB_BUILD ? VideoProgress::STEP_RECORD : VideoProgress::STEP_CHECK;
                    $human->writeln($first === VideoProgress::STEP_CHECK ? '<info>Ověření (video-runner check)…</info>' : '<info>Natáčení (video-runner record)…</info>');
                    $res = $this->runRunnerStep($preflight, $first, $scenario, $env, $err, $progress, $steps);
                    $cancelled = $res['cancelled'] || $this->cancelRequested;
                    $result = $store->readResult($scenario->id);
                    if (!$cancelled) {
                        $store->writeCheck($scenario->id, [
                            'at' => $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                            'verb' => $first,
                            'anchor' => $anchor,
                            'fixedTime' => $fixedTime,
                            'status' => $result === null ? VideoReport::STATUS_FAILED : (($result['status'] ?? null) === VideoReport::STATUS_OK ? VideoReport::STATUS_OK : VideoReport::STATUS_FAILED),
                            'durationMs' => $res['durationMs'],
                            'error' => $result === null ? ['message' => 'runner nezapsal result.json'] : ($result['error'] ?? null),
                            'commit' => $this->commitHash(),
                        ]);
                    }

                    if ($verb === VideoReport::VERB_BUILD && !$cancelled && $res['exitCode'] === 0 && ($result['status'] ?? null) === VideoReport::STATUS_OK) {
                        $human->writeln('<info>Skládání (video-runner compose)…</info>');
                        $res = $this->runRunnerStep($preflight, VideoProgress::STEP_COMPOSE, $scenario, $env, $err, $progress, $steps);
                        $cancelled = $res['cancelled'] || $this->cancelRequested;
                        $result = $store->readResult($scenario->id);
                        if (!$cancelled && $res['exitCode'] === 0 && ($result['status'] ?? null) === VideoReport::STATUS_OK && is_file($store->composedPath($scenario->id))) {
                            $video = $store->promote($scenario->id, [
                                'dataset' => $scenario->dataset,
                                'anchor' => $anchor,
                                'fixedTime' => $fixedTime,
                                'recordedAt' => $this->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                                'commit' => $this->commitHash(),
                                'durationMs' => $store->readDurationMs($scenario->id),
                            ]);
                            $video['path'] = $store->videoPath($scenario->id);
                        }
                    }
                } finally {
                    // Session pryč i při výjimce a při SIGTERM (D92); storageState runner přepsal, maže se až teď.
                    $session->close();
                    DatasetReader::removeTree($privateDir);
                }
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $err->writeln("<error>video-run selhalo: {$message}</error>");
            $progress->error($message);
            $this->jsonOut?->writeln((string) json_encode(['ok' => false, 'problems' => [$message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::FAILURE;
        }

        // 5. Report.
        $report = VideoReport::build($meta, $steps, $result, $video, $cancelled);
        if ($json) {
            $output->writeln((string) json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
        }
        $report->writeText($human);
        $progress->finished($report->status(), $report->exitCode(), $video);
        return $report->exitCode();
    }

    /**
     * Jeden běh runneru (`check` / `record` / `compose`) s krokem průběhu.
     *
     * @param array<string, string> $env
     * @param array<string, array<string, mixed>> $steps
     * @return array{exitCode: ?int, termSignal: ?int, cancelled: bool, durationMs: int}
     */
    private function runRunnerStep(VideoPreflight $preflight, string $step, VideoScenario $scenario, array $env, OutputInterface $log, VideoProgress $progress, array &$steps): array
    {
        $progress->step($step, VideoProgress::STATUS_STARTED);
        $runner = $this->createRunner($preflight, $step, $scenario->path, fn (): bool => $this->cancelRequested);
        $res = $runner->run($env, $log);
        $cancelled = $res['cancelled'] || $this->cancelRequested;
        $steps[$step] = ['ran' => true, 'exitCode' => $res['exitCode'], 'termSignal' => $res['termSignal'], 'durationMs' => $res['durationMs']];
        $progress->step($step, !$cancelled && $res['exitCode'] === 0 ? VideoProgress::STATUS_FINISHED : VideoProgress::STATUS_FAILED, $res['exitCode'], ['termSignal' => $res['termSignal'], 'cancelled' => $cancelled]);
        return $res;
    }

    /** @param list<string> $problems */
    private function refuse(OutputInterface $err, array $problems): int
    {
        $err->writeln('<error>video-run odmítnuto:</error>');
        foreach ($problems as $problem) {
            $err->writeln("  - {$problem}");
        }
        $this->jsonOut?->writeln((string) json_encode(['ok' => false, 'problems' => $problems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Command::INVALID;
    }
}
