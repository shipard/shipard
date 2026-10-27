<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Cli\BinPaths;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\DevUpdate\UpdateChecker;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Core\DevUpdate\UpdateGuards;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStatus;
use Shipard\Core\DevUpdate\UpdateStepRunner;
use Shipard\Core\DevUpdate\Upstream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * `shpd-server dev-update [--yes] [--job=<id>]` (#129 D21, D22) — aktualizace
 * vývojového checkoutu v pěti krocích:
 *
 *   1. kontrola (fetch `stable` z GitHubu) a pojistky ({@see UpdateGuards});
 *      kterákoli selže → exit 1 s radou, nic se nemění;
 *   2. `git merge --ff-only <cílová reference>` s vypnutými hooky
 *      (`-c core.hooksPath=/dev/null`): `post-merge` by jinak pustil
 *      `dev-update.sh` podruhé;
 *   3. `bash scripts/dev-update.sh` (už nová verze z kroku 2);
 *   4. `shpd-server ds-upgrade-all`;
 *   5. `shpd-server cron-install --check` — exit 1 je jen upozornění.
 *
 * Na konci podproces `dev-update-check`, aby štítek v pruhu zmizel.
 *
 * **Kód se mění pod běžícím procesem:** po kroku 2 jsou na disku jiné PHP
 * soubory i jiný autoloader. Od toho okamžiku se tu nesmí načíst žádná
 * dosud nenačtená třída — všechno (prostředí, cesty, runner, argv kroků)
 * se resolvuje před merge a kroky 3–5 běží výhradně jako samostatné procesy
 * ({@see UpdateStepRunner}, vzor {@see UpgradeCommand}).
 *
 * `--job=<id>` předává úloha dashboardu (`update`), aby pojistka
 * `jobRunning` nepovažovala vlastní běžící úlohu za cizí (stejně jako
 * `ds-delete --job`). Bez `--yes` se v terminálu zeptá; neinteraktivně bez
 * `--yes` odmítne. Jen režim `development`.
 */
class DevUpdateCommand extends Command
{
    private const int STEPS = 5;

    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dev-update')
             ->setDescription('Update this development checkout: fetch stable from GitHub, fast-forward, dev-update.sh, ds-upgrade-all, cron check (development only)')
             ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Do not ask for confirmation (dashboard job, scripts)')
             ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Id of the dashboard job running this command (its own job does not count as a running job)');
    }

    // ── Seamy pro testy ────────────────────────────────────────────────────

    protected function loadServerConfig(): ServerConfig
    {
        $config = $this->serverConfig ?? new ServerConfig();
        $config->load();
        return $config;
    }

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
    protected function environment(): array
    {
        return UpdateEnvironment::env();
    }

    protected function jobStore(): JobStore
    {
        return new JobStore(JobStore::defaultDir());
    }

    protected function stateStore(): UpdateStateStore
    {
        return new UpdateStateStore(UpdateStateStore::defaultDir());
    }

    protected function upstreamUrl(): string
    {
        return Upstream::URL;
    }

    /** @return list<string> */
    protected function serverCommand(): array
    {
        return BinPaths::shpdServerCommand();
    }

    /**
     * Krok jako podproces s živým výstupem. Seam pro testy — po kroku 2 už
     * nesmí sáhnout na žádnou novou třídu.
     *
     * @param list<string> $argv
     * @param array<string, string> $env
     */
    protected function runStep(UpdateStepRunner $runner, array $argv, string $cwd, array $env): int
    {
        return $runner->run($argv, $cwd, $env);
    }

    protected function stdinIsTerminal(): bool
    {
        return \defined('STDIN') && @stream_isatty(STDIN);
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->loadServerConfig();
        } catch (\Throwable $e) {
            return $this->fail($output, 'Cannot load server config: ' . $e->getMessage());
        }
        $mode = $config->getMode();
        if ($mode !== ServerAiConnection::MODE_DEVELOPMENT) {
            return $this->fail($output, "dev-update is for development servers only (server.json mode = {$mode}) — nothing changed.");
        }

        // Vše potřebné po merge (krok 2) musí být v paměti už teď.
        $root = $this->getRepoRoot();
        $env = $this->environment();
        $path = $env['PATH'] ?? UpdateEnvironment::DEFAULT_PATH;
        $git = new Git($root, $env);
        $store = $this->stateStore();
        $status = new UpdateStatus($git, $store, $this->jobStore(), $path);
        $checker = new UpdateChecker($git, $store, $this->upstreamUrl());
        $runner = new UpdateStepRunner();
        $server = $this->serverCommand();
        $bash = UpdateEnvironment::findTool('bash', $path) ?? '/bin/bash';
        $ownJob = $input->getOption('job');
        $ownJob = is_string($ownJob) && JobStore::isValidId($ownJob) ? $ownJob : null;
        $scriptEnv = $env + ['SHPD_DEV_UPDATE_NO_CRON_HINT' => '1'];

        // 1. Kontrola a pojistky — nic se nemění.
        $this->banner($output, 1, 'Checking stable on GitHub');
        try {
            $state = $checker->check();
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }
        if ($state === null) {
            return $this->fail($output, 'Another check is running right now — try again in a moment.');
        }
        DevUpdateCheckCommand::printState($output, $state);
        $snapshot = $status->snapshot($ownJob);
        if ($snapshot['blockers'] !== []) {
            $output->writeln('');
            $output->writeln('<error>Update blocked — nothing changed:</error>');
            foreach ($snapshot['blockers'] as $blocker) {
                $output->writeln('  - ' . UpdateGuards::advice($blocker));
            }
            return Command::FAILURE;
        }
        $ref = (string) ($state['upstream']['ref'] ?? Upstream::REF_FORK);
        if ((int) ($state['behind'] ?? 0) === 0) {
            $output->writeln('<info>Already up to date with stable — nothing to do.</info>');
            return Command::SUCCESS;
        }

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive() || !$this->stdinIsTerminal()) {
                return $this->fail($output, 'Updating needs a confirmation: run it in an interactive terminal, or pass --yes for a non-interactive run.');
            }
            $question = new ConfirmationQuestion(sprintf('Fast-forward to stable (%d commit(s)), run dev-update.sh and upgrade all data sources? [y/N] ', (int) $state['behind']), false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('<comment>Aborted — nothing changed.</comment>');
                return Command::FAILURE;
            }
        }

        // 2. Fast-forward. Od teď žádná nová třída — jen podprocesy.
        $gitBinary = $git->binary();
        if ($gitBinary === null) {
            return $this->fail($output, 'git is not available in PATH — nothing changed.');
        }
        $this->banner($output, 2, 'git merge --ff-only ' . $ref);
        $exit = $this->runStep($runner, [$gitBinary, '-C', $root, '-c', 'core.hooksPath=/dev/null', 'merge', '--ff-only', $ref], $root, $env);
        if ($exit !== 0) {
            return $this->fail($output, "git merge --ff-only failed (exit {$exit}) — the checkout is unchanged; reconcile by hand (git status).");
        }

        // 3. Závislosti a build — už nová verze skriptu.
        $this->banner($output, 3, 'scripts/dev-update.sh');
        $exit = $this->runStep($runner, [$bash, $root . '/scripts/dev-update.sh'], $root, $scriptEnv);
        if ($exit !== 0) {
            return $this->fail($output, "scripts/dev-update.sh failed (exit {$exit}) — the code is already at the new version; fix the cause and run it again by hand.");
        }

        // 4. Schéma a konfigurace všech zdrojů dat — nový proces, nový kód.
        $this->banner($output, 4, 'shpd-server ds-upgrade-all');
        $exit = $this->runStep($runner, [...$server, 'ds-upgrade-all'], $root, $env);
        if ($exit !== 0) {
            return $this->fail($output, "ds-upgrade-all failed (exit {$exit}) — see the output above; run shpd-server ds-upgrade-all again after fixing it.");
        }

        // 5. Cron — jen upozornění.
        $this->banner($output, 5, 'shpd-server cron-install --check');
        $exit = $this->runStep($runner, [...$server, 'cron-install', '--check'], $root, $env);
        if ($exit === 0) {
            $output->writeln('cron file is up to date.');
        } else {
            $output->writeln('<comment>Periodic jobs (cron) are missing or outdated on this machine — regenerate them:</comment>');
            $output->writeln('<comment>    sudo shpd-server cron-install</comment>');
        }

        $output->writeln('');
        $output->writeln('==> Re-checking against GitHub');
        $exit = $this->runStep($runner, [...$server, 'dev-update-check'], $root, $env);
        if ($exit !== 0) {
            $output->writeln('<comment>dev-update-check did not succeed (exit ' . $exit . ') — the dashboard label refreshes on its next check.</comment>');
        }
        $output->writeln('');
        $output->writeln('<info>Update finished. Reload the dashboard in the browser — its files were rebuilt.</info>');
        return Command::SUCCESS;
    }

    private function banner(OutputInterface $output, int $step, string $title): void
    {
        $output->writeln('');
        $output->writeln(sprintf('==> [%d/%d] %s', $step, self::STEPS, $title));
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln('<error>' . $message . '</error>');
        return Command::FAILURE;
    }
}
