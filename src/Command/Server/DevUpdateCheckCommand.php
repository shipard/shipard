<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\DevUpdate\Git;
use Shipard\Core\DevUpdate\UpdateChecker;
use Shipard\Core\DevUpdate\UpdateEnvironment;
use Shipard\Core\DevUpdate\UpdateGuards;
use Shipard\Core\DevUpdate\UpdateStateStore;
use Shipard\Core\DevUpdate\UpdateStatus;
use Shipard\Core\DevUpdate\Upstream;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-server dev-update-check [--json]` (#129 D22): fetch `stable`
 * z GitHubu ({@see UpdateChecker}), zápis `state.json` a výpis — pozadu /
 * napřed, commity, pojistky aktualizace ({@see UpdateGuards}). Spouští ho
 * i vývojářský dashboard jako odpojený proces (štítek v pruhu). Jen režim
 * `development`. Exit 0 i když je checkout pozadu; 1 jen při selhání
 * kontroly nebo mimo development.
 */
class DevUpdateCheckCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dev-update-check')
             ->setDescription('Check how far this development checkout is behind stable on GitHub (anonymous HTTPS fetch; development only)')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Print the state and the update safeguards as JSON');
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

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->loadServerConfig();
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: cannot load server config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $mode = $config->getMode();
        if ($mode !== ServerAiConnection::MODE_DEVELOPMENT) {
            $output->writeln("<error>Error: dev-update-check is for development servers only (server.json mode = {$mode})</error>");
            return Command::FAILURE;
        }

        $env = $this->environment();
        $git = new Git($this->getRepoRoot(), $env);
        $store = $this->stateStore();
        $status = new UpdateStatus($git, $store, $this->jobStore(), $env['PATH'] ?? UpdateEnvironment::DEFAULT_PATH);
        $json = (bool) $input->getOption('json');

        try {
            $state = (new UpdateChecker($git, $store, $this->upstreamUrl()))->check();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $snapshot = $status->snapshot();

        if ($json) {
            $output->writeln((string) json_encode($snapshot + ['busy' => $state === null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $state === null || ($state['ok'] ?? false) === true ? Command::SUCCESS : Command::FAILURE;
        }

        if ($state === null) {
            $output->writeln('<comment>Another check is already running — nothing started.</comment>');
            return Command::SUCCESS;
        }

        self::printState($output, $state);
        self::printBlockers($output, $snapshot['blockers']);
        return ($state['ok'] ?? false) === true ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param array<string, mixed> $state */
    public static function printState(OutputInterface $output, array $state): void
    {
        $head = $state['head'] ?? [];
        $upstream = $state['upstream'] ?? [];
        $output->writeln(sprintf(
            'HEAD: %s @ %s',
            (string) ($head['branch'] ?? '(detached)'),
            substr((string) ($head['hash'] ?? '?'), 0, 8),
        ));
        if (($state['ok'] ?? false) !== true) {
            $output->writeln(sprintf(
                '<error>Check failed (%s): %s</error>',
                (string) ($state['errorCode'] ?? 'unknown'),
                (string) ($state['error'] ?? ''),
            ));
            return;
        }
        $output->writeln(sprintf(
            'Upstream: %s @ %s (%s)',
            (string) ($upstream['branch'] ?? Upstream::BRANCH),
            substr((string) ($upstream['hash'] ?? '?'), 0, 8),
            (string) ($upstream['ref'] ?? ''),
        ));
        $behind = (int) ($state['behind'] ?? 0);
        $ahead = (int) ($state['ahead'] ?? 0);
        if ($behind === 0 && $ahead === 0) {
            $output->writeln('<info>Up to date with stable.</info>');
        } else {
            $output->writeln(sprintf('Behind: <info>%d</info>   Ahead: %d', $behind, $ahead));
        }
        if ($behind > 0) {
            if (is_string($state['oldestMissingAt'] ?? null)) {
                $output->writeln('Oldest missing change: ' . $state['oldestMissingAt']);
            }
            $commits = is_array($state['commits'] ?? null) ? $state['commits'] : [];
            foreach ($commits as $commit) {
                $output->writeln(sprintf('  %s  %s', (string) ($commit['hash'] ?? ''), (string) ($commit['subject'] ?? '')));
            }
            if ($behind > count($commits)) {
                $output->writeln(sprintf('  …and %d more: %s', $behind - count($commits), (string) ($state['compareUrl'] ?? '')));
            }
        }
        $output->writeln('Checked: ' . (string) ($state['checkedAt'] ?? ''));
    }

    /** @param list<array<string, mixed>> $blockers */
    public static function printBlockers(OutputInterface $output, array $blockers): void
    {
        if ($blockers === []) {
            $output->writeln('Update: <info>allowed</info> (shpd-server dev-update)');
            return;
        }
        $output->writeln('Update: <comment>blocked</comment>');
        foreach ($blockers as $blocker) {
            $output->writeln('  - ' . UpdateGuards::advice($blocker));
        }
    }
}
