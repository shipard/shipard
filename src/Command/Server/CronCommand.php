<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Server\CronProvisioner;
use Shipard\Core\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Dispatcher periodických úloh — volá ho /etc/cron.d/shipard. Pro každý
 * aktivní data source spustí per-DS příkazy slotu subprocesem (shpd-ds);
 * server-level příkazy slotu (SERVER_SLOT_JOBS, shpd-server) běží jednou
 * za běh. Lock proti překryvu běhů a heartbeat soubor pro doctor.
 *
 * Exit kód: SUCCESS i při selhaných jobech (reportuje doctor/alerty),
 * FAILURE jen infra chyba (neznámý slot, nečitelný seznam DS, heartbeat
 * nelze zapsat).
 */
class CronCommand extends Command
{
    /**
     * slot → per-DS shpd-ds příkazy (řetězec = příkaz + volby oddělené
     * mezerou); deklarativní registr v module.jsonc až bude jobů víc.
     * mail-preprocess --sweep je jen záchrana — primární spouštěč runneru
     * je detached spawn z intake (tasks/mail-preprocess.md D8). Totéž
     * mail-analyze --sweep za reaperem: runnery AI analýzy spouští příjem,
     * předzpracování a reanalýza, sweep dohledává frontu bez claimu
     * (tasks/mail-analysis-inprocess.md D14).
     */
    public const SLOT_JOBS = [
        'minute'       => ['mail-outbox-run', 'mail-analysis-reap', 'mail-preprocess --sweep', 'mail-analyze --sweep'],
        'two-minutes'  => [],
        'five-minutes' => ['alerts-run'],
        'daily'        => ['mail-idempotency-prune', 'vat-periods-ensure', 'work-orders-invoice-run'],
        'weekly'       => ['alerts-prune'],
    ];

    /**
     * job → lifecycle stavy DS, ve kterých job běží (docs/ds-state.md, #56).
     * Job bez záznamu běží jen v `active` (fail-closed). V `suspended` /
     * maintenance / `pending_deletion` neběží nic — efektivní stav se
     * v mapě nehledá. Registr se přesune do module.jsonc spolu se SLOT_JOBS.
     */
    public const JOB_ALLOWED_STATES = [
        'mail-outbox-run'         => [DataSourceState::ACTIVE],
        'mail-analysis-reap'      => [DataSourceState::ACTIVE],
        'mail-preprocess --sweep' => [DataSourceState::ACTIVE],
        'mail-analyze --sweep'    => [DataSourceState::ACTIVE],
        'alerts-run'              => [DataSourceState::ACTIVE],
        'mail-idempotency-prune'  => [DataSourceState::ACTIVE, DataSourceState::READ_ONLY],
        'vat-periods-ensure'      => [DataSourceState::ACTIVE],
        'work-orders-invoice-run' => [DataSourceState::ACTIVE],
        'alerts-prune'            => [DataSourceState::ACTIVE, DataSourceState::READ_ONLY],
    ];

    /**
     * slot → server-level shpd-server příkazy — jednou za běh slotu, ne per
     * DS. Neřídí se stavem DS (běží i pro zavřené DS — ds-state-check právě
     * ty hlídá, #56 D8).
     */
    public const SERVER_SLOT_JOBS = [
        'two-minutes' => ['hosting-sync'],
        'daily'       => ['ds-state-check'],
    ];

    private const JOB_TIMEOUT_SECONDS = 600;

    /** Kolik posledních bajtů výstupu jobu se drží pro log při selhání. */
    private const FAILURE_OUTPUT_TAIL_BYTES = 8192;

    protected function configure(): void
    {
        $this->setName('cron')
             ->setDescription('Run scheduled per-DS jobs for the given slot (invoked from /etc/cron.d/shipard)')
             ->addOption('slot', null, InputOption::VALUE_REQUIRED, 'Slot to run: ' . implode(', ', array_keys(self::SLOT_JOBS)));
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    protected function getShpdDsPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/shpd-ds';
    }

    protected function getShpdServerPath(): string
    {
        return dirname(__DIR__, 3) . '/bin/shpd-server';
    }

    protected function getRunDir(): string
    {
        return CronProvisioner::RUN_DIR;
    }

    protected function getJobTimeoutSeconds(): int
    {
        return self::JOB_TIMEOUT_SECONDS;
    }

    /** Log path ze server.json; null (= default ErrorLoggeru) když config chybí. */
    protected function getLogPath(): ?string
    {
        try {
            $cfg = new ServerConfig();
            $cfg->load();
            ErrorLogger::setLogLevel($cfg->getLogLevel());
            return $cfg->getLogFile();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slot = $input->getOption('slot');
        if (!is_string($slot) || !isset(self::SLOT_JOBS[$slot])) {
            $output->writeln(sprintf(
                '<error>Unknown or missing --slot (valid: %s)</error>',
                implode(', ', array_keys(self::SLOT_JOBS)),
            ));
            return Command::FAILURE;
        }

        ErrorLogger::setLogPath($this->getLogPath());
        ErrorLogger::setRequestContext('cli: cron --slot=' . $slot);

        // Lock per slot — překrývající se běh se tiše ukončí, aby se minute
        // slot nehromadil, když jeden běh trvá déle než minutu.
        $runDir = $this->getRunDir();
        if (!is_dir($runDir)) {
            @mkdir($runDir, 0750, true);
        }
        $lockHandle = @fopen(CronProvisioner::lockPath($slot, $runDir), 'c');
        if ($lockHandle === false) {
            $output->writeln('<error>Cannot open lock file in ' . $runDir . '</error>');
            ErrorLogger::error('cron: cannot open lock file', ['slot' => $slot, 'runDir' => $runDir]);
            return Command::FAILURE;
        }
        if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $output->writeln('Previous run still active — skipping.');
            ErrorLogger::info('cron slot skipped — previous run still active', ['slot' => $slot]);
            return Command::SUCCESS;
        }

        $startedAt = microtime(true);

        $dsDir = $this->getDataSourcesDir();
        if (!is_dir($dsDir)) {
            $output->writeln('<error>Data sources directory not found: ' . $dsDir . '</error>');
            ErrorLogger::error('cron: data sources directory not found', ['slot' => $slot, 'dir' => $dsDir]);
            return Command::FAILURE;
        }

        $allDirs = glob($dsDir . '/*', GLOB_ONLYDIR) ?: [];
        sort($allDirs);
        $candidates = array_values(array_filter(
            $allDirs,
            static fn(string $d): bool => is_file($d . '/config/main.json')
        ));

        $jobs = self::SLOT_JOBS[$slot];
        $serverJobs = self::SERVER_SLOT_JOBS[$slot] ?? [];
        ErrorLogger::info('cron slot started', [
            'slot' => $slot,
            'dsCount' => count($candidates),
            'jobs' => $jobs,
            'serverJobs' => $serverJobs,
        ]);

        $jobsRun = 0;
        $failures = [];
        $skippedDataSources = 0;
        $corruptedStateFiles = 0;

        // Server-level joby (shpd-server) — jednou za běh slotu, ne per DS.
        foreach ($serverJobs as $job) {
            $result = $this->runServerJob($job);
            $jobsRun++;
            if ($result['exitCode'] !== 0 || $result['timedOut']) {
                $failures[] = [
                    'ds' => '(server)',
                    'job' => $job,
                    'exitCode' => $result['exitCode'],
                    'timedOut' => $result['timedOut'],
                ];
                ErrorLogger::error('cron server job failed', [
                    'slot' => $slot,
                    'job' => $job,
                    'exitCode' => $result['exitCode'],
                    'timedOut' => $result['timedOut'],
                    'outputTail' => $result['output'],
                ]);
            }
        }

        foreach ($candidates as $d) {
            $id = basename($d);
            // DS mohl zmizet během běhu (ds-delete) — přeskočit, ne selhat.
            if (!is_file($d . '/config/main.json')) {
                ErrorLogger::info('cron: data source disappeared mid-run — skipped', ['slot' => $slot, 'ds' => $id]);
                continue;
            }
            // Gating podle config/state.json — čte jen stavový soubor,
            // main.json ani DB se nedotýká. Přeskočený DS = tichý skip
            // (minute slot by logem spamoval), počet jde do heartbeatu.
            $state = DataSourceState::load($d);
            if ($state->isCorrupted()) {
                $corruptedStateFiles++;
            }
            $dsJobs = self::jobsForState($jobs, $state->getEffectiveState());
            if ($dsJobs === []) {
                $skippedDataSources++;
                continue;
            }
            foreach ($dsJobs as $job) {
                $result = $this->runJob($d, $job);
                $jobsRun++;
                if ($result['exitCode'] !== 0 || $result['timedOut']) {
                    $failures[] = [
                        'ds' => $id,
                        'job' => $job,
                        'exitCode' => $result['exitCode'],
                        'timedOut' => $result['timedOut'],
                    ];
                    ErrorLogger::error('cron job failed', [
                        'slot' => $slot,
                        'ds' => $id,
                        'job' => $job,
                        'exitCode' => $result['exitCode'],
                        'timedOut' => $result['timedOut'],
                        'outputTail' => $result['output'],
                    ]);
                }
            }
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $heartbeat = [
            'ts' => date('c'),
            'slot' => $slot,
            'templateVersion' => CronProvisioner::TEMPLATE_VERSION,
            'appVersion' => Version::VERSION,
            'dsCount' => count($candidates),
            'skippedDataSources' => $skippedDataSources,
            'corruptedStateFiles' => $corruptedStateFiles,
            'jobsRun' => $jobsRun,
            'failedCount' => count($failures),
            'failures' => $failures,
            'durationMs' => $durationMs,
        ];
        if (!$this->writeHeartbeat(CronProvisioner::heartbeatPath($slot, $runDir), $heartbeat)) {
            $output->writeln('<error>Cannot write heartbeat file in ' . $runDir . '</error>');
            ErrorLogger::error('cron: cannot write heartbeat', ['slot' => $slot, 'runDir' => $runDir]);
            return Command::FAILURE;
        }

        ErrorLogger::info('cron slot finished', [
            'slot' => $slot,
            'dsCount' => count($candidates),
            'skippedDataSources' => $skippedDataSources,
            'jobsRun' => $jobsRun,
            'failedCount' => count($failures),
            'durationMs' => $durationMs,
        ]);
        $output->writeln(sprintf(
            'Slot %s: %d data source(s), %d skipped by state, %d job(s) run, %d failed, %d ms',
            $slot,
            count($candidates),
            $skippedDataSources,
            $jobsRun,
            count($failures),
            $durationMs,
        ));
        foreach ($failures as $f) {
            $output->writeln(sprintf(
                '<comment>  failed: %s %s (exit %d%s)</comment>',
                $f['ds'],
                $f['job'],
                $f['exitCode'],
                $f['timedOut'] ? ', timed out' : '',
            ));
        }

        // Selhané joby nejsou infra chyba — cron nesmí spamovat MAILTO,
        // problémy per DS reportuje doctor a centrální log.
        return Command::SUCCESS;
    }

    /**
     * Joby slotu povolené pro efektivní stav DS (JOB_ALLOWED_STATES;
     * job bez záznamu = jen active).
     *
     * @param list<string> $jobs
     * @return list<string>
     */
    public static function jobsForState(array $jobs, string $effectiveState): array
    {
        return array_values(array_filter(
            $jobs,
            static fn(string $job): bool => in_array(
                $effectiveState,
                self::JOB_ALLOWED_STATES[$job] ?? [DataSourceState::ACTIVE],
                true,
            ),
        ));
    }

    /**
     * Spustí `shpd-ds <job>` s cwd v adresáři DS. Timeout: SIGTERM,
     * 5 s grace, pak SIGKILL. Výstup ořezaný na posledních pár KB.
     *
     * @return array{exitCode: int, timedOut: bool, output: string}
     */
    protected function runJob(string $dsDir, string $job): array
    {
        $argv = preg_split('/\s+/', trim($job)) ?: [$job];
        return $this->runProcessJob([$this->getShpdDsPath(), ...$argv], $dsDir);
    }

    /**
     * Spustí server-level `shpd-server <job>` — jednou za běh slotu.
     *
     * @return array{exitCode: int, timedOut: bool, output: string}
     */
    protected function runServerJob(string $job): array
    {
        return $this->runProcessJob([$this->getShpdServerPath(), $job], null);
    }

    /**
     * @param list<string> $argv
     * @return array{exitCode: int, timedOut: bool, output: string}
     */
    private function runProcessJob(array $argv, ?string $cwd): array
    {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            return ['exitCode' => -1, 'timedOut' => false, 'output' => 'proc_open failed'];
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $this->getJobTimeoutSeconds();
        $output = '';
        $timedOut = false;
        $exitCode = -1;

        $drain = function () use (&$output, $pipes): void {
            foreach ([1, 2] as $i) {
                $chunk = @stream_get_contents($pipes[$i]);
                if (is_string($chunk) && $chunk !== '') {
                    $output = substr($output . $chunk, -self::FAILURE_OUTPUT_TAIL_BYTES);
                }
            }
        };

        while (true) {
            $drain();
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 15); // SIGTERM
                $grace = microtime(true) + 5.0;
                while (microtime(true) < $grace) {
                    usleep(100_000);
                    $status = proc_get_status($process);
                    if (!$status['running']) {
                        break;
                    }
                }
                if ($status['running']) {
                    proc_terminate($process, 9); // SIGKILL
                }
                break;
            }
            usleep(100_000);
        }

        $drain();
        foreach ($pipes as $pipe) {
            @fclose($pipe);
        }
        proc_close($process);

        return ['exitCode' => $exitCode, 'timedOut' => $timedOut, 'output' => $output];
    }

    /** Atomický zápis (tmp + rename), aby doctor nikdy nečetl rozepsaný soubor. */
    private function writeHeartbeat(string $path, array $data): bool
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json . "\n") === false) {
            return false;
        }
        @chmod($tmp, 0640);
        return @rename($tmp, $path);
    }
}
