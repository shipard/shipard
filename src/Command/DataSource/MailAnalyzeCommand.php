<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunner;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunnerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runner AI analýzy došlé zprávy v procesu (tasks/mail-analysis-inprocess.md
 * D14). Primárně ho spouští příjem, nahrání, předzpracování a reanalýza
 * detached spawnem (`--message`), z cronu běží minutový `--sweep`. Selhaná
 * analýza **není** chyba příkazu (zpráva doteče do stavu 10 / 70) —
 * FAILURE jen pro špatné volání a chyby infrastruktury.
 */
class MailAnalyzeCommand extends Command
{
    /** Přílohy v base64 a v těle požadavku — CLI bez limitu nespoléhá na default. */
    public const MIN_MEMORY_LIMIT = '512M';

    private ?ServerConfig $serverConfig = null;
    private bool $serverConfigLoaded = false;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?AnalysisRunner $runner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-analyze')
            ->setDescription('Run the AI analysis of a queued incoming message in process, or spawn runners for the queue')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Id of the queued incoming message to analyze')
            ->addOption('sweep', null, InputOption::VALUE_NONE, 'Spawn runners for queued messages without an active claim (up to the free slots)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /**
     * Server config sdílí resolver modulů, log a limit souběhu; nenačitatelný
     * = null, příkaz degraduje (default moduly, bez logu, výchozí limit).
     */
    protected function loadServerConfig(): ?ServerConfig
    {
        if (!$this->serverConfigLoaded) {
            $this->serverConfigLoaded = true;
            try {
                $sc = new ServerConfig();
                $sc->load();
                $this->serverConfig = $sc;
            } catch (\Throwable) {
                $this->serverConfig = null;
            }
        }
        return $this->serverConfig;
    }

    protected function buildResolver(): ModulePathResolver
    {
        $sc = $this->loadServerConfig();
        try {
            if ($sc !== null) {
                return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
            }
        } catch (\Throwable) {
            // fallback níže
        }
        return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
    }

    protected function getLogPath(): ?string
    {
        $cfg = $this->loadServerConfig();
        if ($cfg === null) {
            return null;
        }
        try {
            ErrorLogger::setLogLevel($cfg->getLogLevel());
            return $cfg->getLogFile();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $messageOpt = $input->getOption('message');
        $sweep = (bool) $input->getOption('sweep');

        if ($sweep === ($messageOpt !== null)) {
            $output->writeln('<error>Error: use exactly one of --message <id> or --sweep</error>');
            return Command::FAILURE;
        }

        $messageId = 0;
        if ($messageOpt !== null) {
            if (!ctype_digit((string) $messageOpt) || (int) $messageOpt < 1) {
                $output->writeln('<error>Error: --message must be a positive integer</error>');
                return Command::FAILURE;
            }
            $messageId = (int) $messageOpt;
        }

        self::ensureMemoryLimit(self::MIN_MEMORY_LIMIT);
        ErrorLogger::setLogPath($this->getLogPath());
        ErrorLogger::setRequestContext('cli: mail-analyze' . ($sweep ? ' --sweep' : ' --message=' . $messageId));

        try {
            $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
            $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
            ErrorLogger::setDsId($dsConfig->getId());
            $runner = $this->runner ?? AnalysisRunnerFactory::create(
                $dsConfig,
                $dsConnection,
                $dsDir,
                $this->buildResolver(),
                $this->loadServerConfig(),
            );

            if ($sweep) {
                return $this->runSweep($runner, $output);
            }
            return $this->runMessage($runner, $messageId, $output);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'mail-analyze failed');
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /**
     * Zvedne `memory_limit`, je-li nastavený níž než `$minimum`
     * (`-1` = bez limitu, nechat). Přílohy jsou v base64 a ještě jednou
     * v těle požadavku — 30 MB příloh je přes 80 MB v paměti.
     */
    public static function ensureMemoryLimit(string $minimum): void
    {
        $current = (string) ini_get('memory_limit');
        $currentBytes = self::shorthandToBytes($current);
        if ($currentBytes < 0) {
            return;
        }
        if ($currentBytes < self::shorthandToBytes($minimum)) {
            @ini_set('memory_limit', $minimum);
        }
    }

    /** `512M` → bajty; `-1` → -1; nečitelné = 0 (bere se jako „zvednout“). */
    public static function shorthandToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function runSweep(AnalysisRunner $runner, OutputInterface $output): int
    {
        $result = $runner->sweep();
        if ($result['skipped'] !== null) {
            $output->writeln('Skipped: ' . $result['skipped']);
            return Command::SUCCESS;
        }
        if ($result['spawned'] === []) {
            $output->writeln('No queued messages to analyze.');
            return Command::SUCCESS;
        }
        $output->writeln(sprintf(
            '<info>Spawned %d analysis runner(s):</info> %s',
            count($result['spawned']),
            implode(', ', $result['spawned']),
        ));
        return Command::SUCCESS;
    }

    private function runMessage(AnalysisRunner $runner, int $messageId, OutputInterface $output): int
    {
        $result = $runner->run($messageId);
        $note = (string) ($result['note'] ?? '');

        switch ($result['status']) {
            case 'disabled':
                $output->writeln('Skipped: ' . $note);
                return Command::SUCCESS;
            case 'no_slot':
                $output->writeln("No free slot: message {$messageId} stays queued for the sweep.");
                return Command::SUCCESS;
            case 'not_eligible':
                $output->writeln("Skipped: message {$messageId} is not queued for analysis.");
                return Command::SUCCESS;
            case 'not_configured':
                $output->writeln("<comment>Not configured:</comment> {$note} — message {$messageId} stays queued.");
                return Command::SUCCESS;
            case 'claim_failed':
                $output->writeln("Claim failed: {$note}");
                return Command::SUCCESS;
            case 'done':
                $output->writeln(sprintf(
                    '<info>Done</info> message %d: analysis #%d (document: %s; %s)',
                    $messageId,
                    (int) ($result['analysisNdx'] ?? 0),
                    !empty($result['hasDocument']) ? 'yes' : 'no',
                    $note,
                ));
                return Command::SUCCESS;
            case 'failed':
                $output->writeln(sprintf(
                    '<comment>Failed</comment> message %d: [%s] %s → analysis_state %d (%s)',
                    $messageId,
                    (string) ($result['errorType'] ?? ''),
                    $note,
                    (int) ($result['newState'] ?? 0),
                    !empty($result['retryable']) ? 'queued again' : 'needs a user decision',
                ));
                return Command::SUCCESS;
            case 'lost_claim':
                $output->writeln("<comment>Claim lost</comment> message {$messageId}: {$note}");
                return Command::SUCCESS;
            default:
                $output->writeln("<error>Error: message {$messageId}: {$note}</error>");
                return Command::FAILURE;
        }
    }
}
