<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\SessionService;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Module\Core\Exchange\Dataset\DatasetException;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eException;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eExpectedCards;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2ePreflight;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eProgress;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eReport;
use Shipard\Module\Core\Exchange\Dataset\E2e\E2eSession;
use Shipard\Module\Core\Exchange\Dataset\E2e\LocalBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\E2e\PinnedBaseUrl;
use Shipard\Module\Core\Exchange\Dataset\E2e\PlaywrightRunner;
use Shipard\Module\Core\Mail\Eval\Browse\EvalCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds dataset-e2e <dir|zip> [--no-reseed] [--base-url <url>]
 * [--output-dir <adresář>] [--json] [--progress-file <soubor>]` — proklikání
 * datové sady v prohlížeči (tasks/testing-phase7a.md § 3, #103 D91–D95):
 * přeseedování zdroje dat sady (`dataset-seed --yes` ve stejném procesu),
 * krátkodobá session uživatele `e2e` jen v souboru `storageState` (D92),
 * Playwright Test jako podproces (`tests/e2e/`, env `SHPD_E2E_*`),
 * zneplatnění session a smazání souboru i při chybě a při SIGTERM,
 * `dataset-verify --no-apply --json` ve stejném procesu a report.
 *
 * Preflight (exit 2, nic se nemění): režim serveru přesně `development`
 * (nejde-li určit → odmítnout), zdroj dat třídy `free`, sada ze seznamu sad
 * v checkoutu, Node v pevném PATH, závislosti `tests/e2e/node_modules`,
 * Chromium Playwrightu, **lokální host** `--base-url` (D97, {@see LocalBaseUrl}:
 * IP literál nebo jméno jen s loopback / vlastními adresami; ověřená adresa
 * se připíchne curl i Chromiu), dosažitelnost `<base-url>/<ds-id>/app/`; s
 * `--no-reseed` navíc `dataset.name` v `main.json` = název sady. Exit 0 jen
 * při stavu `ok`, 1 při nálezech (D95), 2 preflight, 130 zrušeno. S `--json`
 * jde na stdout přesně jeden JSON dokument (report, nebo
 * `{ok: false, problems}` při odmítnutí), lidské řádky na stderr. Token
 * session se neobjeví ve výstupu, argumentech, průběhu ani reportu.
 * Testovatelné přes podtřídu (seamy níže).
 */
class DatasetE2eCommand extends Command implements SignalableCommandInterface
{
    public const int EXIT_CANCELLED = E2eReport::EXIT_CANCELLED;
    public const string DEFAULT_BASE_URL = 'http://127.0.0.1';
    /** TTL session: 3 minuty na kartu, nejméně hodina (I7). */
    public const int SESSION_SECONDS_PER_CARD = 180;
    public const int SESSION_MIN_SECONDS = 3600;
    public const float CANCEL_GRACE_SECONDS = 20.0;

    private bool $cancelRequested = false;
    /** stdout pro `{ok: false, problems}` při odmítnutí s `--json` (null bez `--json`). */
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
        $this->setName('dataset-e2e')
            ->setDescription('Přeseeduje zdroj dat sadou, proklikne všechny karty návrhů v prohlížeči (Playwright Test) a ověří výsledek dataset-verify --no-apply (jen development, jen free zdroj dat)')
            ->addArgument('path', InputArgument::REQUIRED, 'Složka sady (s expected/) nebo .zip')
            ->addOption('no-reseed', null, InputOption::VALUE_NONE, 'Nepřeseedovat — jen pro ladění; zdroj dat musí držet tutéž sadu')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Adresa serveru bez cesty zdroje dat (aplikace = <base-url>/<ds-id>/app/)', self::DEFAULT_BASE_URL)
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Adresář běhu (report.json, playwright-report/, traces/, walkthrough.json); výchozí nový adresář v systémovém temp')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Report jako JSON na stdout (lidské řádky na stderr)')
            ->addOption('progress-file', null, InputOption::VALUE_REQUIRED, 'Průběh jako JSON řádky (started / step / card / finished / error) do tohoto souboru — pro úlohu dashboardu');
    }

    // ── Signály ────────────────────────────────────────────────────────────

    /** @return list<int> */
    public function getSubscribedSignals(): array
    {
        return \defined('SIGTERM') && \defined('SIGINT') ? [\SIGTERM, \SIGINT] : [];
    }

    /**
     * SIGTERM / SIGINT: Playwright dostane SIGINT (dopíše reportery), session
     * se uklidí, exit 130. `false` = Symfony proces neukončí, příkaz doběhne.
     */
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

    /** Režim serveru; null = nejde určit (fail-closed — preflight odmítne). */
    protected function getServerMode(): ?string
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg->getMode();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Pravidlo lokální adresy běhu (D97) — šev pro testy (falešný resolver a rozhraní). */
    protected function createLocalBaseUrl(): LocalBaseUrl
    {
        return new LocalBaseUrl();
    }

    protected function createPreflight(): E2ePreflight
    {
        return new E2ePreflight($this->repoRoot());
    }

    /** @return list<string> názvy sad v checkoutu (pojistka D60) */
    protected function knownDatasets(): array
    {
        return (new EvalCatalog($this->repoRoot()))->datasetNames();
    }

    protected function dataSourceInfo(string $dsDir): ?DataSourceInfo
    {
        return (new DataSourceInventory(dirname(rtrim($dsDir, '/'))))->read($dsDir);
    }

    protected function createSession(DataSourceConnection $db): E2eSession
    {
        return new E2eSession($db, new SessionService());
    }

    /** @param \Closure(): bool $shouldCancel */
    protected function createRunner(E2ePreflight $preflight, \Closure $shouldCancel): PlaywrightRunner
    {
        return new PlaywrightRunner(
            (string) $preflight->nodeBinary(),
            $preflight->cliPath(),
            $preflight->configPath(),
            $preflight->e2eDir(),
            $shouldCancel,
            self::CANCEL_GRACE_SECONDS,
        );
    }

    /**
     * Jiný příkaz `shpd-ds` ve stejném procesu (I8, vzor `DatasetSeedCommand::runReset`).
     *
     * @param array<string, mixed> $args
     */
    protected function runSub(string $command, array $args, OutputInterface $out): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            throw new E2eException("Nelze spustit {$command}: příkaz běží bez aplikace");
        }
        return $app->find($command)->run(new ArrayInput($args), $out);
    }

    /** @param list<string> $messageIds */
    protected function expectedCards(DataSourceConnection $db, array $messageIds): int
    {
        return E2eExpectedCards::count($db, $messageIds);
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    protected function defaultOutputDir(): string
    {
        return sys_get_temp_dir() . '/shpd-e2e-' . $this->now()->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $human = $json ? $err : $output;
        $this->jsonOut = $json ? $output : null;
        // $cancelRequested se nenuluje: signál doručený před startem běhu platí (instance příkazu běží jednou).

        $dsDir = rtrim($this->getDataSourceDir(), '/');
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::INVALID;
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $noReseed = (bool) $input->getOption('no-reseed');

        // 1. Volby a vstup — nic se nemění.
        $baseUrl = rtrim(trim((string) $input->getOption('base-url')), '/');
        if (!preg_match('#^https?://[^/\s]+$#', $baseUrl)) {
            return $this->refuse($err, ['--base-url musí být absolutní http(s) adresa serveru bez cesty (např. http://127.0.0.1)']);
        }
        $origin = $baseUrl;
        $appUrl = $baseUrl . '/' . $dsConfig->getId() . '/app/';

        $progressOpt = $input->getOption('progress-file');
        try {
            $progress = is_string($progressOpt) && trim($progressOpt) !== ''
                ? E2eProgress::toFile(trim($progressOpt))
                : E2eProgress::none();
        } catch (E2eException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }

        try {
            $reader = DatasetReader::open((string) $input->getArgument('path'));
            $manifest = $reader->getManifest();
        } catch (DatasetException $e) {
            return $this->refuse($err, [$e->getMessage()]);
        }
        $setDir = $reader->getRootDir();
        $setMessageIds = [];
        foreach ($reader->listFiles('mail') as $rel) {
            try {
                $messageId = $reader->readJsonc($rel)['messageId'] ?? null;
            } catch (DatasetException) {
                $messageId = null;
            }
            if (is_string($messageId) && $messageId !== '') {
                $setMessageIds[] = $messageId;
            }
        }

        // 2. Preflight (exit 2). Lokální host (D97) před dosažitelností
        // i před session — ověřená adresa se připíchne curl i Chromiu.
        $preflight = $this->createPreflight();
        $problems = $preflight->problems($this->getServerMode(), $this->dataSourceInfo($dsDir), $manifest->name, $this->knownDatasets());
        $pinned = null;
        try {
            $pinned = $this->createLocalBaseUrl()->verify($baseUrl);
            $origin = $pinned->origin;
            $appUrl = $origin . '/' . $dsConfig->getId() . '/app/';
        } catch (E2eException $e) {
            $problems[] = $e->getMessage();
        }
        if ($setMessageIds === []) {
            $problems[] = 'sada nemá žádnou zprávu v mail/ — není co proklikat';
        }
        if ($noReseed) {
            $held = $dsConfig->getDataset()['name'] ?? null;
            if ($held !== $manifest->name) {
                $problems[] = sprintf(
                    '--no-reseed: zdroj dat drží sadu %s (main.json dataset.name), ne %s — bez --no-reseed se přeseeduje',
                    $held === null ? 'žádnou' : "'{$held}'",
                    "'{$manifest->name}'",
                );
            }
        }
        if ($problems === [] && $pinned instanceof PinnedBaseUrl) {
            $reach = $preflight->appReachable($appUrl, pin: $pinned);
            if ($reach !== null) {
                $problems[] = $reach;
            }
        }
        if ($problems !== [] || !$pinned instanceof PinnedBaseUrl) {
            $reader->close();
            return $this->refuse($err, $problems);
        }

        $outputDirOpt = $input->getOption('output-dir');
        $outputDir = is_string($outputDirOpt) && trim($outputDirOpt) !== '' ? rtrim(trim($outputDirOpt), '/') : $this->defaultOutputDir();
        if ((!is_dir($outputDir) && !@mkdir($outputDir, 0700, true)) || !is_writable($outputDir)
            || (!is_dir($outputDir . '/' . E2eReport::TRACES_DIR) && !@mkdir($outputDir . '/' . E2eReport::TRACES_DIR, 0700, true))) {
            $reader->close();
            return $this->refuse($err, ["adresář běhu {$outputDir} nejde vytvořit nebo zapisovat"]);
        }

        $meta = [
            'dataset' => ['name' => $manifest->name, 'profile' => $manifest->profile, 'country' => $manifest->country],
            'dataSource' => $dsConfig->getId(),
            'appUrl' => $appUrl,
            'outputDir' => $outputDir,
            'expected' => null,
        ];
        $human->writeln(sprintf('<info>Sada</info> %s → zdroj dat %s, aplikace %s', $manifest->name, $dsConfig->getId(), $appUrl));
        $human->writeln("Adresář běhu: {$outputDir}");
        $progress->started([
            'pid' => getmypid() ?: null,
            'dataset' => $manifest->name,
            'dataSource' => $dsConfig->getId(),
            'appUrl' => $appUrl,
            'outputDir' => $outputDir,
            'expected' => null,
        ]);

        $steps = [
            'reseed' => ['ran' => false, 'exitCode' => null, 'durationMs' => null],
            'walkthrough' => ['ran' => false, 'exitCode' => null, 'termSignal' => null, 'durationMs' => null],
            'verify' => ['ran' => false, 'exitCode' => null],
        ];
        $cancelled = false;
        $verify = null;
        $verifyRefusal = null;
        $walkthrough = null;
        $playwright = null;

        try {
            // 3. Přeseedování (D91) — dataset-seed --yes ve stejném procesu, výstup na stderr.
            if ($this->cancelRequested) {
                $cancelled = true;
            } elseif (!$noReseed) {
                $human->writeln('<info>Přeseedování…</info>');
                $progress->step(E2eProgress::STEP_RESEED, E2eProgress::STATUS_STARTED);
                $t0 = microtime(true);
                $exit = $this->runSub('dataset-seed', ['path' => $setDir, '--yes' => true], $err);
                $steps['reseed'] = ['ran' => true, 'exitCode' => $exit, 'durationMs' => (int) round((microtime(true) - $t0) * 1000)];
                $progress->step(E2eProgress::STEP_RESEED, $exit === Command::SUCCESS ? E2eProgress::STATUS_FINISHED : E2eProgress::STATUS_FAILED, $exit);
                if ($exit !== Command::SUCCESS) {
                    $human->writeln("<error>Přeseedování selhalo (exit {$exit}) — proklikání ani ověření neběží.</error>");
                }
            }

            // 4. Session a proklikání.
            if ($this->cancelRequested) {
                $cancelled = true;
            }
            if (!$cancelled && (!$steps['reseed']['ran'] || $steps['reseed']['exitCode'] === Command::SUCCESS)) {
                $db = $this->dsConnection ?? new DataSourceConnection($dsConfig);
                $session = $this->createSession($db);
                try {
                    $user = $session->ensureUser();
                    foreach ($user['warnings'] as $warning) {
                        $human->writeln("<comment>warning:</comment> {$warning}");
                    }
                    if ($user['created']) {
                        $human->writeln("Uživatel '{$user['login']}' založen (administrátor, systémový, bez hesla).");
                    }
                    $stale = $session->invalidateAll();
                    if ($stale > 0) {
                        $human->writeln("<comment>warning:</comment> zneplatněno {$stale} session uživatele '{$user['login']}' z minulého běhu");
                    }
                    $expected = $this->expectedCards($db, $setMessageIds);
                    $meta['expected'] = $expected;
                    $human->writeln("Karet návrhů k proklikání: {$expected}");

                    $ttl = max(self::SESSION_MIN_SECONDS, self::SESSION_SECONDS_PER_CARD * $expected);
                    $storageState = $session->open($origin, $outputDir, $ttl);

                    $e2eEnv = [
                        'SHPD_E2E_APP_URL' => $appUrl,
                        'SHPD_E2E_STORAGE_STATE' => $storageState,
                        'SHPD_E2E_OUTPUT_DIR' => $outputDir,
                        'SHPD_E2E_EXPECTED_CARDS' => (string) $expected,
                    ];
                    if ($progress->path() !== null) {
                        $e2eEnv['SHPD_E2E_PROGRESS_FILE'] = $progress->path();
                    }
                    // Připíchnutá adresa hostu pro Chromium (D97) — jen u jména, literál není co mapovat.
                    if ($pinned->chromiumRule() !== null) {
                        $e2eEnv['SHPD_E2E_HOST_RESOLVER_RULES'] = $pinned->chromiumRule();
                    }
                    $env = PlaywrightRunner::environment($e2eEnv, $preflight->home());

                    $human->writeln('<info>Proklikání (Playwright Test)…</info>');
                    $progress->step(E2eProgress::STEP_WALKTHROUGH, E2eProgress::STATUS_STARTED, null, ['expected' => $expected]);
                    $runner = $this->createRunner($preflight, fn (): bool => $this->cancelRequested);
                    $result = $runner->run($env, $err);
                    $steps['walkthrough'] = [
                        'ran' => true,
                        'exitCode' => $result['exitCode'],
                        'termSignal' => $result['termSignal'],
                        'durationMs' => $result['durationMs'],
                    ];
                    $cancelled = $result['cancelled'] || $this->cancelRequested;
                    $progress->step(
                        E2eProgress::STEP_WALKTHROUGH,
                        $cancelled ? E2eProgress::STATUS_FAILED : E2eProgress::STATUS_FINISHED,
                        $result['exitCode'],
                        ['termSignal' => $result['termSignal'], 'cancelled' => $cancelled],
                    );
                } finally {
                    // Session pryč i při výjimce a při SIGTERM (D92).
                    $session->close();
                }

                $walkthrough = self::readJsonFile($outputDir . '/' . E2eReport::WALKTHROUGH_FILE);
                $playwright = self::readJsonFile($outputDir . '/' . E2eReport::PLAYWRIGHT_REPORT_FILE);

                // 5. Ověření jako F3 — dataset-verify --no-apply --json ve stejném procesu.
                if (!$cancelled) {
                    $human->writeln('<info>Ověření (dataset-verify --no-apply)…</info>');
                    $progress->step(E2eProgress::STEP_VERIFY, E2eProgress::STATUS_STARTED);
                    $buffer = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
                    $exit = $this->runSub('dataset-verify', ['path' => $setDir, '--no-apply' => true, '--json' => true], $buffer);
                    $text = trim($buffer->fetch());
                    $decoded = json_decode($text, true);
                    if (is_array($decoded) && isset($decoded['checks'])) {
                        $verify = $decoded;
                    } else {
                        $verifyRefusal = $text !== '' ? $text : "dataset-verify skončil bez výstupu (exit {$exit})";
                    }
                    $steps['verify'] = ['ran' => true, 'exitCode' => $exit];
                    $progress->step(E2eProgress::STEP_VERIFY, $verify !== null && $exit === Command::SUCCESS ? E2eProgress::STATUS_FINISHED : E2eProgress::STATUS_FAILED, $exit);
                }
            }
        } catch (\Throwable $e) {
            $reader->close();
            $message = $e->getMessage();
            $err->writeln("<error>dataset-e2e selhalo: {$message}</error>");
            $progress->error($message);
            $this->jsonOut?->writeln((string) json_encode(['ok' => false, 'problems' => [$message]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::FAILURE;
        }
        $reader->close();

        // 6. Report.
        $report = E2eReport::build($meta, $walkthrough, $playwright, $verify, $verifyRefusal, $steps, $cancelled);
        if ($json) {
            $output->writeln((string) json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
        }
        $report->writeText($human);
        $progress->finished($report->status(), count($report->applied()), count($report->failed()), $report->exitCode());
        return $report->exitCode();
    }

    /** @return array<string, mixed>|null */
    private static function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @param list<string> $problems */
    private function refuse(OutputInterface $err, array $problems): int
    {
        $err->writeln('<error>dataset-e2e odmítnuto:</error>');
        foreach ($problems as $problem) {
            $err->writeln("  - {$problem}");
        }
        // S --json dostane volající (úloha dashboardu) důvody i strojově.
        $this->jsonOut?->writeln((string) json_encode(['ok' => false, 'problems' => $problems], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return Command::INVALID;
    }
}
