<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunnerFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runner technického předzpracování došlé zprávy (tasks/mail-preprocess.md).
 * Primárně ho spouští intake detached spawnem (`--message`), z cronu běží
 * jen záchranný `--sweep`. Selhané akce nejsou chyba příkazu (zpráva
 * doteče do stavu 40 a do AI fronty) — FAILURE jen pro špatné volání
 * a infra chyby.
 */
class MailPreprocessCommand extends Command
{
    private ?ServerConfig $serverConfig = null;
    private bool $serverConfigLoaded = false;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?PreprocessRunner $runner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-preprocess')
            ->setDescription('Run the stored preprocess plan of an incoming message, or rescue stuck messages')
            ->addOption('message', null, InputOption::VALUE_REQUIRED, 'Id of the incoming message to preprocess')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Re-match current rules, drop previously generated attachments and regenerate (any state)')
            ->addOption('sweep', null, InputOption::VALUE_NONE, 'Rescue stuck messages (pending without runner, running with a dead process)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /**
     * Server config sdílí resolver modulů, log a rendering klient
     * (`render` sekce); nenačitatelný = null, příkaz degraduje (default
     * moduly, bez logu, render akce selhávají s `unconfigured`).
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
                return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 5) . '/modules');
            }
        } catch (\Throwable) {
            // fallback níže
        }
        return new ModulePathResolver([dirname(__DIR__, 5) . '/modules']);
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
        $force = (bool) $input->getOption('force');

        if ($sweep === ($messageOpt !== null)) {
            $output->writeln('<error>Error: use exactly one of --message <id> or --sweep</error>');
            return Command::FAILURE;
        }
        if ($sweep && $force) {
            $output->writeln('<error>Error: --force cannot be combined with --sweep</error>');
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

        ErrorLogger::setLogPath($this->getLogPath());
        ErrorLogger::setRequestContext('cli: mail-preprocess' . ($sweep ? ' --sweep' : ' --message=' . $messageId));

        try {
            $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
            $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
            ErrorLogger::setDsId($dsConfig->getId());
            $runner = $this->runner ?? PreprocessRunnerFactory::create(
                $dsConfig,
                $dsConnection,
                $dsDir,
                $this->buildResolver(),
                $this->loadServerConfig(),
            );

            if ($sweep) {
                return $this->runSweep($runner, $output);
            }
            return $this->runMessage($runner, $messageId, $force, $output);
        } catch (\Throwable $e) {
            ErrorLogger::logException($e, 'mail-preprocess failed');
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function runSweep(PreprocessRunner $runner, OutputInterface $output): int
    {
        $result = $runner->sweep();
        if ($result['requeued'] === [] && $result['failed'] === []) {
            $output->writeln('No stuck messages.');
            return Command::SUCCESS;
        }
        if ($result['requeued'] !== []) {
            $output->writeln(sprintf(
                '<info>Requeued %d message(s):</info> %s',
                count($result['requeued']),
                implode(', ', $result['requeued']),
            ));
        }
        if ($result['failed'] !== []) {
            $output->writeln(sprintf(
                '<comment>Gave up on %d message(s) after %d attempts:</comment> %s',
                count($result['failed']),
                PreprocessRunner::MAX_ATTEMPTS,
                implode(', ', $result['failed']),
            ));
        }
        return Command::SUCCESS;
    }

    private function runMessage(PreprocessRunner $runner, int $messageId, bool $force, OutputInterface $output): int
    {
        $result = $runner->run($messageId, $force);

        switch ($result['status']) {
            case 'not_found':
                $output->writeln("<error>Message {$messageId} not found</error>");
                return Command::FAILURE;
            case 'refused':
                $output->writeln("<error>Refused: {$result['note']}</error>");
                return Command::FAILURE;
            case 'skipped':
                $output->writeln("Skipped: {$result['note']}");
                return Command::SUCCESS;
            case 'no_match':
                $output->writeln("No match: {$result['note']}");
                return Command::SUCCESS;
        }

        foreach ($result['results'] ?? [] as $entry) {
            $output->writeln(sprintf(
                '  %s %s%s%s',
                $entry['ok'] ? '[OK]  ' : '[FAIL]',
                isset($entry['ruleId']) && $entry['ruleId'] !== '' ? $entry['ruleId'] . '/' : '',
                $entry['action'],
                ($entry['note'] ?? '') !== '' ? ' — ' . $entry['note'] : '',
            ));
        }
        $output->writeln(sprintf(
            '%s message %d: %s (isdoc: %s)',
            $result['status'] === 'lost_race' ? '<comment>Lost race on</comment>' : ($result['status'] === 'done' ? '<info>Done</info>' : '<comment>Done with errors</comment>'),
            $messageId,
            $result['status'],
            $result['isdoc'] ?? '-',
        ));

        return Command::SUCCESS;
    }
}
