<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Orchestrace nasazení nové verze: git pull → composer → frontend build →
 * ds-upgrade-all → (ve vývoji dev-https) → doctor. Tenký orchestrátor — každý krok běží jako
 * subproces (passthru), protože příkaz aktualizuje kód, ze kterého sám
 * běží: od `git pull` dál se v tomto procesu nesmí lazy-loadovat žádné
 * další třídy (nová verze na disku ≠ stará verze v paměti). Vše potřebné
 * (composer path, shipard user) se resolví před pullem.
 */
class UpgradeCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('upgrade')
             ->setDescription('Deploy a new version: git pull, composer, frontend build, ds-upgrade-all, doctor')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show incoming commits and the step plan without changing anything')
             ->addOption('full', null, InputOption::VALUE_NONE, 'Force the composer and frontend steps even without relevant changes')
             ->addOption('skip-ds-upgrade', null, InputOption::VALUE_NONE, 'Skip the ds-upgrade-all step');
    }

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Server mode; 'development' when server.json is missing (production always has it). */
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

    protected function getEuid(): int
    {
        return posix_geteuid();
    }

    protected function getCurrentUserName(): string
    {
        $info = posix_getpwuid(posix_getuid());
        return is_array($info) && isset($info['name']) ? $info['name'] : 'unknown';
    }

    protected function detectShipardUser(string $mode): string
    {
        return PermissionSpec::detectShipardUser($mode);
    }

    /**
     * Spustí příkaz a zachytí výstup (stdout+stderr).
     *
     * @return array{lines: string[], exitCode: int}
     */
    protected function capture(string $shellCmd): array
    {
        $lines = [];
        $exitCode = 1;
        @exec($shellCmd . ' 2>&1', $lines, $exitCode);
        return ['lines' => $lines, 'exitCode' => $exitCode];
    }

    /** Spustí krok s živým výstupem (passthru), vrátí exit code. */
    protected function runStep(string $shellCmd): int
    {
        $exitCode = 0;
        passthru($shellCmd, $exitCode);
        return $exitCode;
    }

    /** Verze běžícího PHP (např. '8.5') — suffix názvu FPM služby. */
    protected function getPhpVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Které kroky se mají provést — čistá funkce kvůli testům.
     *
     * @param string[] $changedFiles
     * @return array{composer: bool, frontend: bool, dsUpgradeAll: bool, cronInstall: bool, nginxReload: bool, fpmReload: bool}
     */
    public function computePlan(array $changedFiles, bool $full, bool $skipDsUpgrade): array
    {
        $composer = $full;
        $frontend = $full;
        $nginxReload = $full;
        $fpmReload = $full;
        foreach ($changedFiles as $file) {
            if ($file === 'composer.json' || $file === 'composer.lock') {
                $composer = true;
            }
            if (str_starts_with($file, 'frontend/')) {
                $frontend = true;
            }
            if (str_starts_with($file, 'docs/nginx/')) {
                $nginxReload = true;
            }
            if (str_starts_with($file, 'docs/php/')) {
                $fpmReload = true;
            }
        }
        return [
            'composer' => $composer,
            'frontend' => $frontend,
            'dsUpgradeAll' => !$skipDsUpgrade,
            // Vždy — subproces je levný a idempotentní; konvergence cron
            // souboru nesmí záviset na tom, které cesty se zrovna změnily.
            'cronInstall' => true,
            'nginxReload' => $nginxReload,
            'fpmReload' => $fpmReload,
        ];
    }

    /** @return array{lines: string[], exitCode: int} */
    private function git(string $args, string $root, ?string $sudoUser): array
    {
        $cmd = 'git -C ' . escapeshellarg($root) . ' ' . $args;
        if ($sudoUser !== null) {
            $cmd = 'sudo -u ' . escapeshellarg($sudoUser) . ' -H ' . $cmd;
        }
        return $this->capture($cmd);
    }

    private function wrapUser(string $shellCmd, ?string $sudoUser): string
    {
        if ($sudoUser === null) {
            return $shellCmd;
        }
        return 'sudo -u ' . escapeshellarg($sudoUser) . ' -H sh -c ' . escapeshellarg($shellCmd);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $root = $this->getRepoRoot();
        if (!is_dir($root . '/.git')) {
            $output->writeln('<error>Not a git checkout: ' . $root . '</error>');
            return Command::FAILURE;
        }

        // --- Uživatelé (D2) — resolvnout před jakoukoli změnou ---
        $mode = $this->getServerMode();
        $shipardUser = $this->detectShipardUser($mode);
        $euid = $this->getEuid();
        $currentUser = $this->getCurrentUserName();

        $sudoUser = null;
        $runDoctor = false;
        if ($euid === 0) {
            $sudoUser = $shipardUser;
            $runDoctor = true;
        } elseif ($currentUser !== $shipardUser && $mode === 'production') {
            $output->writeln(sprintf(
                '<error>On production, upgrade must run as root or as %s (current user: %s) — files would change owner.</error>',
                $shipardUser,
                $currentUser,
            ));
            return Command::FAILURE;
        }

        // --- Git pre-flight (D3) ---
        $status = $this->git('status --porcelain', $root, $sudoUser);
        if ($status['exitCode'] !== 0) {
            $output->writeln('<error>git status failed:</error>');
            $this->writeLines($output, $status['lines'], 10);
            return Command::FAILURE;
        }
        if (!empty($status['lines'])) {
            $output->writeln('<error>Working tree is not clean — commit, stash or discard local changes first:</error>');
            $this->writeLines($output, $status['lines'], 10);
            return Command::FAILURE;
        }

        $branchRes = $this->git('rev-parse --abbrev-ref HEAD', $root, $sudoUser);
        $branch = trim($branchRes['lines'][0] ?? '');
        if ($branchRes['exitCode'] !== 0 || $branch === '') {
            $output->writeln('<error>Could not determine the current branch.</error>');
            return Command::FAILURE;
        }
        if ($branch === 'HEAD') {
            $output->writeln('<error>Detached HEAD — check out a branch first.</error>');
            return Command::FAILURE;
        }

        $oldHash = trim($this->git('rev-parse --short HEAD', $root, $sudoUser)['lines'][0] ?? '');

        $output->writeln('Fetching origin...');
        $fetch = $this->git('fetch', $root, $sudoUser);
        if ($fetch['exitCode'] !== 0) {
            $output->writeln('<error>git fetch failed:</error>');
            $this->writeLines($output, $fetch['lines'], 10);
            return Command::FAILURE;
        }

        $countRes = $this->git('rev-list --count HEAD..origin/' . $branch, $root, $sudoUser);
        $incoming = (int) trim($countRes['lines'][0] ?? '0');

        $full = (bool) $input->getOption('full');
        if ($incoming === 0 && !$full) {
            $output->writeln('<info>Already up to date.</info>');
            return Command::SUCCESS;
        }

        if ($incoming > 0) {
            $output->writeln('');
            $output->writeln(sprintf('<info>Incoming commits (%d):</info>', $incoming));
            $log = $this->git('log --oneline -20 HEAD..origin/' . $branch, $root, $sudoUser);
            $this->writeLines($output, $log['lines'], 20);
            if ($incoming > 20) {
                $output->writeln(sprintf('  ... and %d more', $incoming - 20));
            }
        }

        // --- Plán kroků (D4) ---
        $diff = $this->git('diff --name-only HEAD origin/' . $branch, $root, $sudoUser);
        $skipDsUpgrade = (bool) $input->getOption('skip-ds-upgrade');
        $plan = $this->computePlan($diff['lines'], $full, $skipDsUpgrade);

        $output->writeln('');
        $output->writeln('<info>Plan:</info>');
        $output->writeln('  [run]  git pull --ff-only');
        $output->writeln($plan['composer']
            ? '  [run]  composer install' . ($full ? ' (--full)' : ' (composer.json/lock changed)')
            : '  [skip] composer install (no composer.json/lock changes)');
        $output->writeln($plan['frontend']
            ? '  [run]  frontend build' . ($full ? ' (--full)' : ' (frontend/ changed)')
            : '  [skip] frontend build (no frontend/ changes)');
        $output->writeln($plan['dsUpgradeAll']
            ? '  [run]  ds-upgrade-all'
            : '  [skip] ds-upgrade-all (--skip-ds-upgrade)');
        $output->writeln($euid === 0
            ? '  [run]  cron install'
            : '  [skip] cron install (not running as root)');
        $output->writeln($euid === 0
            ? '  [run]  completion install'
            : '  [skip] completion install (not running as root)');
        $output->writeln(match (true) {
            !$plan['nginxReload'] => '  [skip] nginx reload (no docs/nginx/ changes)',
            $euid !== 0 => '  [skip] nginx reload (not running as root)',
            default => '  [run]  nginx reload' . ($full ? ' (--full)' : ' (docs/nginx/ changed)'),
        });
        $output->writeln(match (true) {
            !$plan['fpmReload'] => '  [skip] php-fpm reload (no docs/php/ changes)',
            $euid !== 0 => '  [skip] php-fpm reload (not running as root)',
            default => '  [run]  php-fpm reload' . ($full ? ' (--full)' : ' (docs/php/ changed)'),
        });
        // https vstupy vývojového serveru (#183 U3): obnova certifikátu pro IP
        // a site souboru — jen ve vývoji, jen pod rootem.
        $runDevHttps = $euid === 0 && $mode === 'development';
        if ($mode === 'development') {
            $output->writeln($runDevHttps
                ? '  [run]  dev https'
                : '  [skip] dev https (not running as root)');
        }
        $output->writeln($runDoctor
            ? '  [run]  doctor'
            : '  [skip] doctor (not running as root)');
        $output->writeln('');

        if ($input->getOption('dry-run')) {
            $output->writeln('<comment>--dry-run: nothing changed</comment>');
            return Command::SUCCESS;
        }

        // --- Příprava před pullem: composer path (self-update, D1) ---
        $composerPath = null;
        if ($plan['composer']) {
            $which = $this->capture('command -v composer');
            $composerPath = trim($which['lines'][0] ?? '');
            if ($which['exitCode'] !== 0 || $composerPath === '') {
                $output->writeln('<error>composer not found in PATH — install it or rerun without composer changes.</error>');
                return Command::FAILURE;
            }
        }

        $verbosityFlag = match (true) {
            $output->isDebug() => ' -vvv',
            $output->isVeryVerbose() => ' -vv',
            $output->isVerbose() => ' -v',
            default => '',
        };

        $shpdServer = escapeshellarg($root . '/bin/shpd-server');
        $steps = [
            ['git pull', $this->wrapUser('cd ' . escapeshellarg($root) . ' && git pull --ff-only', $sudoUser)],
        ];
        if ($plan['composer']) {
            $steps[] = ['composer install', $this->wrapUser(
                'cd ' . escapeshellarg($root) . ' && ' . escapeshellarg($composerPath) . ' install --no-dev --optimize-autoloader',
                $sudoUser,
            )];
        }
        if ($plan['frontend']) {
            $steps[] = ['frontend build', $this->wrapUser(
                'cd ' . escapeshellarg($root . '/frontend') . ' && npm ci && npm run build',
                $sudoUser,
            )];
        }
        if ($plan['dsUpgradeAll']) {
            // Nový proces = nový kód (po pullu).
            $steps[] = ['ds-upgrade-all', $this->wrapUser($shpdServer . ' ds-upgrade-all' . $verbosityFlag, $sudoUser)];
        }
        // Cron install a reload kroky běží přímo jako root (žádný wrapUser —
        // /etc/cron.d i systemctl potřebují root, ne shipard uživatele).
        if ($euid === 0) {
            $steps[] = ['cron install', $shpdServer . ' cron-install'];
            $steps[] = ['completion install', $shpdServer . ' completion-install'];
        }
        $nginxReloadCmd = 'nginx -t && systemctl reload nginx';
        $fpmReloadCmd = 'systemctl reload php' . $this->getPhpVersion() . '-fpm';
        $reloadSteps = [];
        if ($euid === 0) {
            if ($plan['nginxReload']) {
                $steps[] = ['nginx reload', $nginxReloadCmd];
                $reloadSteps[] = 'nginx reload';
            }
            if ($plan['fpmReload']) {
                $steps[] = ['php-fpm reload', $fpmReloadCmd];
                $reloadSteps[] = 'php-fpm reload';
            }
        }

        // --- Provedení (D5) — od git pull dál žádné lazy-loadované třídy ---
        $executed = [];
        foreach ($steps as [$name, $cmd]) {
            $this->writeStepBanner($output, $name);
            $exitCode = $this->runStep($cmd);
            if ($exitCode !== 0) {
                $output->writeln('');
                $output->writeln(sprintf('<error>Step "%s" failed (exit code %d) — upgrade aborted.</error>', $name, $exitCode));
                if (in_array($name, $reloadSteps, true)) {
                    $output->writeln('<error>Code is deployed — only the service config/reload is broken.</error>');
                    $output->writeln('Fix the config, then reload manually:');
                    $output->writeln('  ' . $nginxReloadCmd);
                    $output->writeln('  ' . $fpmReloadCmd);
                } else {
                    $output->writeln('Finish the remaining steps manually, see docs/operations/production.md §11.');
                }
                return Command::FAILURE;
            }
            $executed[] = $name;
        }

        $newHash = trim($this->git('rev-parse --short HEAD', $root, $sudoUser)['lines'][0] ?? '');

        // Poslední krok před doctorem. Selhání upgrade nepřeruší (U22) —
        // kód je nasazený, rozbitý je nejvýš https vstup; doctor proběhne.
        $devHttpsFailed = false;
        if ($runDevHttps) {
            $this->writeStepBanner($output, 'dev https');
            $devHttpsFailed = $this->runStep($shpdServer . ' dev-https') !== 0;
            if (!$devHttpsFailed) {
                $executed[] = 'dev https';
            }
        }

        $doctorFailed = false;
        if ($runDoctor) {
            $this->writeStepBanner($output, 'doctor');
            $doctorFailed = $this->runStep($shpdServer . ' doctor') !== 0;
            if (!$doctorFailed) {
                $executed[] = 'doctor';
            }
        }

        // --- Summary (D11) — verze z gitu, konstanta v paměti je po pullu stará ---
        $output->writeln('');
        $output->writeln('==========================================');
        $output->writeln(sprintf('Upgraded %s → %s (%d commits)', $oldHash, $newHash, $incoming));
        $output->writeln('Steps: ' . implode(', ', $executed));
        if (!$plan['composer']) {
            $output->writeln('Skipped: composer install (no changes)');
        }
        if (!$plan['frontend']) {
            $output->writeln('Skipped: frontend build (no changes)');
        }
        if (!$plan['dsUpgradeAll']) {
            $output->writeln('Skipped: ds-upgrade-all (--skip-ds-upgrade)');
        }
        if (!$runDoctor) {
            $output->writeln("Skipped: doctor — run 'sudo shpd-server doctor' to verify");
        }
        $output->writeln('==========================================');

        if ($euid !== 0) {
            $output->writeln('');
            $output->writeln('<comment>Root-only steps skipped — run manually as root:</comment>');
            $output->writeln('  sudo shpd-server cron-install');
            $output->writeln('  sudo shpd-server completion-install');
            if ($mode === 'development') {
                $output->writeln('  sudo shpd-server dev-https');
            }
            if ($plan['nginxReload']) {
                $output->writeln('  sudo nginx -t && sudo systemctl reload nginx');
            }
            if ($plan['fpmReload']) {
                $output->writeln('  sudo systemctl reload php' . $this->getPhpVersion() . '-fpm');
            }
        }

        if ($devHttpsFailed) {
            $output->writeln('<error>Code deployed, but dev-https failed — the https entries of this development server need attention:</error>');
            $output->writeln('  sudo shpd-server dev-https');
        }
        if ($doctorFailed) {
            $output->writeln('<error>Code deployed, but doctor reported issues — inspect the output above.</error>');
        }
        if ($devHttpsFailed || $doctorFailed) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param string[] $lines */
    private function writeLines(OutputInterface $output, array $lines, int $limit): void
    {
        foreach (array_slice($lines, 0, $limit) as $line) {
            $output->writeln('  ' . $line);
        }
        if (count($lines) > $limit) {
            $output->writeln(sprintf('  ... (%d more)', count($lines) - $limit));
        }
    }

    private function writeStepBanner(OutputInterface $output, string $name): void
    {
        $output->writeln('');
        $output->writeln('==========================================');
        $output->writeln('===== ' . $name . ' =====');
        $output->writeln('==========================================');
    }
}
