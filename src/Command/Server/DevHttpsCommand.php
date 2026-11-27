<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\DevAccess;
use Shipard\Core\Server\DevCertificate;
use Shipard\Core\Server\DevHttpsPlan;
use Shipard\Core\Server\DevHttpsPlanner;
use Shipard\Core\Server\DevHttpsSite;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-server dev-https [--check]` — https vstupy vývojového serveru
 * (#183 D3, D4, D6, U3). Jen režim `development`, běží jako root (vzor
 * `cron-install`), idempotentní:
 *
 * 1. podle `devAccess` vyrobí nebo obnoví self-signed certifikát pro IP
 *    (`/etc/shipard/tls/dev-ip/`, `openssl` CLI — {@see DevCertificate}),
 * 2. u jména ověří certifikát dodaný vývojářem (`dev-host/`): existuje,
 *    sedí ke klíči a SAN nese jméno — jinak blok pro jméno nevznikne
 *    a příkaz skončí nenulově,
 * 3. vygeneruje `/etc/nginx/sites-available/shipard-https.conf` ze šablony
 *    ({@see DevHttpsSite}) a odkáže ho do `sites-enabled`; bez zapnutého
 *    https vstupu odkaz i soubor odstraní,
 * 4. `nginx -t` a reload; při chybě vrátí předchozí stav site souboru
 *    a skončí nenulově.
 *
 * Cizí web s `default_server` na 443: blok pro IP nevznikne, příkaz varuje
 * a skončí úspěšně (U21). Rozhodování je v {@see DevHttpsPlanner} — sdílí
 * ho `--check` a `doctor`.
 *
 * `--check` je pro skripty (`dev-update.sh`): bez roota, nic nemění, exit 1,
 * když by běh něco udělal (certifikát pro IP chybí, expiruje nebo nenese
 * adresu serveru; site soubor chybí nebo je zastaralý), jinak 0. Bez
 * výstupu, s `-v` vypíše důvody. Mimo režim `development` vždy 0.
 */
class DevHttpsCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dev-https')
             ->setDescription('Development server: certificate for the IP address and the nginx https site from devAccess (idempotent)')
             ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 1 when a run would change something, 0 otherwise; no changes, no root needed (-v prints the reasons)');
    }

    // ── ševy ────────────────────────────────────────────────────────────────

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function getEuid(): int
    {
        return posix_geteuid();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function getHostname(): string
    {
        return gethostname() ?: 'server';
    }

    /** Null = `server.json` nejde načíst. */
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

    protected function createAccess(ServerConfig $config): DevAccess
    {
        return DevAccess::fromServerConfig($config);
    }

    protected function createPlanner(): DevHttpsPlanner
    {
        return DevHttpsPlanner::forServer($this->getRepoRoot());
    }

    protected function detectShipardUser(string $mode): string
    {
        return PermissionSpec::detectShipardUser($mode);
    }

    /** Chown + chgrp + chmod — vyžaduje root; v testech bez chown. */
    protected function applyOwnership(string $path, string $owner, string $group, int $mode): void
    {
        @chown($path, $owner);
        @chgrp($path, $group);
        @chmod($path, $mode);
    }

    /**
     * Spustí program bez shellu.
     *
     * @param list<string> $argv
     * @return array{exitCode: int, output: string}
     */
    protected function runProcess(array $argv): array
    {
        $process = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return ['exitCode' => -1, 'output' => $argv[0] . ' cannot be started'];
        }
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exitCode' => proc_close($process), 'output' => trim((string) $output)];
    }

    /** @return array{exitCode: int, output: string} */
    protected function nginxTest(): array
    {
        return $this->runProcess(['nginx', '-t']);
    }

    /** @return array{exitCode: int, output: string} */
    protected function nginxReload(): array
    {
        return $this->runProcess(['systemctl', 'reload', 'nginx']);
    }

    // ── běh ─────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $check = (bool) $input->getOption('check');
        $config = $this->loadServerConfig();
        if ($config === null) {
            if ($check) {
                return Command::SUCCESS;
            }
            $output->writeln('<error>Server config /etc/shipard/server.json cannot be loaded — run server-init first.</error>');
            return Command::FAILURE;
        }
        if ($config->getMode() !== 'development') {
            if ($check) {
                return Command::SUCCESS;
            }
            $output->writeln('<error>dev-https is for development servers only (mode in server.json is "' . $config->getMode() . '").</error>');
            return Command::FAILURE;
        }

        $access = $this->createAccess($config);
        $planner = $this->createPlanner();
        $plan = $planner->plan($access, $this->now());

        if ($check) {
            if ($output->isVerbose()) {
                foreach ([...$plan->blockers, ...$plan->pendingActions()] as $line) {
                    $output->writeln($line);
                }
            }
            return $plan->needsRun() ? Command::FAILURE : Command::SUCCESS;
        }

        if ($this->getEuid() !== 0) {
            $output->writeln('<error>dev-https must run as root (writes /etc/shipard/tls and /etc/nginx).</error>');
            return Command::FAILURE;
        }

        foreach ($access->problems as $problem) {
            $output->writeln('<comment>⚠ server.json: ' . $problem . '</comment>');
        }
        foreach ($plan->warnings as $warning) {
            $output->writeln('<comment>⚠ ' . $warning . '</comment>');
        }
        if ($plan->blockers !== []) {
            foreach ($plan->blockers as $blocker) {
                $output->writeln('<error>✗ ' . $blocker . '</error>');
            }
            return Command::FAILURE;
        }

        $failed = false;
        $changed = false;
        $shipardUser = $this->detectShipardUser('development');

        // 1. certifikát pro IP
        if ($plan->ipBlock) {
            if ($plan->ipCertRenewal !== null) {
                $output->writeln('Certificate for the IP entry: ' . $plan->ipCertRenewal . ' — creating a new one');
                $error = $this->createIpCertificate($planner, $access, $shipardUser);
                if ($error !== null) {
                    $output->writeln('<error>✗ ' . $error . '</error>');
                    return Command::FAILURE;
                }
                $changed = true;
                $output->writeln('✓ ' . $planner->certFile(DevHttpsPlanner::IP_DIR) . ' created for ' . implode(', ', $access->addresses()));
                $output->writeln('  <comment>A new certificate means a new warning in the browser — click through it once per address.</comment>');
            } else {
                $output->writeln(sprintf(
                    '✓ certificate for the IP entry is valid (%d days left)',
                    (int) $plan->ipCertificate->daysLeft($this->now()),
                ));
            }
        }

        // 2. certifikát pro jméno dodává vývojář
        if ($access->httpsHost() !== null) {
            if ($plan->hostProblem !== null) {
                $failed = true;
                $output->writeln('<error>✗ ' . $plan->hostProblem . '</error>');
                $output->writeln('  The https entry for ' . $access->httpsHost() . ' is not created. Put the certificate and its key to:');
                $output->writeln('    ' . $planner->certFile(DevHttpsPlanner::HOST_DIR));
                $output->writeln('    ' . $planner->keyFile(DevHttpsPlanner::HOST_DIR));
                $output->writeln('  (symlinks are fine), then run: sudo shpd-server dev-https');
                $this->ensureTlsDirs($planner, $shipardUser);
            } else {
                $output->writeln('✓ certificate for ' . $access->httpsHost() . ' found');
            }
        }

        // 3. site soubor
        $siteChanged = !$plan->siteUpToDate();
        if ($siteChanged) {
            $error = $this->applySite($plan);
            if ($error !== null) {
                $this->restoreSite($plan);
                $output->writeln('<error>✗ ' . $error . '</error>');
                return Command::FAILURE;
            }
            $changed = true;
            $output->writeln($plan->expectedSite === null
                ? '✓ ' . $plan->siteFile . ' removed (no https entry enabled)'
                : '✓ ' . $plan->siteFile . ' written and enabled');
        } elseif ($plan->expectedSite !== null) {
            $output->writeln('✓ ' . $plan->siteFile . ' up to date');
        }

        // 4. nginx
        if ($changed) {
            $test = $this->nginxTest();
            if ($test['exitCode'] !== 0) {
                if ($siteChanged) {
                    $this->restoreSite($plan);
                }
                $output->writeln('<error>✗ nginx -t failed' . ($siteChanged ? ' — the previous ' . DevHttpsSite::FILE_NAME . ' was restored' : '') . ':</error>');
                $output->writeln($test['output']);
                return Command::FAILURE;
            }
            $reload = $this->nginxReload();
            if ($reload['exitCode'] !== 0) {
                $output->writeln('<error>✗ nginx reload failed:</error>');
                $output->writeln($reload['output']);
                return Command::FAILURE;
            }
            $output->writeln('✓ nginx reloaded');
        } elseif (!$access->hasHttpsEntry()) {
            $output->writeln('No https entry is enabled (devAccess in /etc/shipard/server.json) — nothing to do.');
        }

        foreach ($access->enabledKinds() as $kind) {
            $skipped = ($kind === DevAccess::HTTPS_IP && !$plan->ipBlock) || ($kind === DevAccess::HTTPS_HOST && !$plan->hostBlock);
            $output->writeln(sprintf('  %-10s %s%s', $kind, (string) $access->origin($kind), $skipped ? '   (not active)' : ''));
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /** Adresáře certifikátů podle kontraktu práv (U4). */
    private function ensureTlsDirs(DevHttpsPlanner $planner, string $shipardUser): ?string
    {
        foreach ([$planner->tlsDir(), $planner->tlsDir() . '/' . DevHttpsPlanner::IP_DIR, $planner->tlsDir() . '/' . DevHttpsPlanner::HOST_DIR] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
                return 'Cannot create ' . $dir;
            }
            $this->applyOwnership($dir, 'root', $shipardUser, 0750);
        }
        return null;
    }

    /**
     * Vyrobí klíč a certifikát pro IP do dočasných souborů, ověří je a teprve
     * potom nahradí platné (klíč první) — rozbitý běh nezanechá pár, který
     * k sobě nepatří. Vrací větu chyby, null = hotovo.
     */
    private function createIpCertificate(DevHttpsPlanner $planner, DevAccess $access, string $shipardUser): ?string
    {
        $error = $this->ensureTlsDirs($planner, $shipardUser);
        if ($error !== null) {
            return $error;
        }
        $certFile = $planner->certFile(DevHttpsPlanner::IP_DIR);
        $keyFile = $planner->keyFile(DevHttpsPlanner::IP_DIR);
        $dir = dirname($certFile);
        $tmpConfig = $dir . '/.openssl.cnf.tmp';
        $tmpCert = $dir . '/.fullchain.pem.tmp';
        $tmpKey = $dir . '/.privkey.pem.tmp';
        $cleanup = static function () use ($tmpConfig, $tmpCert, $tmpKey): void {
            foreach ([$tmpConfig, $tmpCert, $tmpKey] as $file) {
                @unlink($file);
            }
        };
        $cleanup();

        if (@file_put_contents($tmpConfig, DevCertificate::opensslConfig($this->getHostname(), $access->addresses())) === false) {
            return 'Cannot write ' . $tmpConfig;
        }
        // Klíč nesmí být ani na okamžik čitelný jinému než rootovi.
        $umask = umask(0077);
        try {
            $result = $this->runProcess(DevCertificate::opensslArgs($tmpConfig, $tmpKey, $tmpCert));
        } finally {
            umask($umask);
        }
        if ($result['exitCode'] !== 0) {
            $cleanup();
            return 'openssl failed (exit ' . $result['exitCode'] . '): ' . $result['output'];
        }

        $created = DevCertificate::read($tmpCert);
        if (!$created->isOk() || $created->missingIps($access->addresses()) !== [] || $created->matchesKeyFile($tmpKey) !== true) {
            $cleanup();
            return 'openssl produced a certificate that does not match the request — nothing changed';
        }

        $this->applyOwnership($tmpKey, 'root', 'root', 0600);
        $this->applyOwnership($tmpCert, 'root', $shipardUser, 0644);
        if (!@rename($tmpKey, $keyFile) || !@rename($tmpCert, $certFile)) {
            $cleanup();
            return 'Cannot move the new certificate to ' . $dir;
        }
        @unlink($tmpConfig);
        return null;
    }

    /** Zapíše (nebo odstraní) site soubor a odkaz v sites-enabled. Vrací větu chyby. */
    private function applySite(DevHttpsPlan $plan): ?string
    {
        if ($plan->expectedSite === null) {
            if ((is_link($plan->siteLink) || is_file($plan->siteLink)) && !@unlink($plan->siteLink)) {
                return 'Cannot remove ' . $plan->siteLink;
            }
            if (is_file($plan->siteFile) && !@unlink($plan->siteFile)) {
                return 'Cannot remove ' . $plan->siteFile;
            }
            return null;
        }

        if ($plan->currentSite !== $plan->expectedSite) {
            $tmp = dirname($plan->siteFile) . '/.' . DevHttpsSite::FILE_NAME . '.tmp';
            if (@file_put_contents($tmp, $plan->expectedSite) === false) {
                return 'Cannot write ' . $tmp;
            }
            @chmod($tmp, 0644);
            if (!@rename($tmp, $plan->siteFile)) {
                @unlink($tmp);
                return 'Cannot move ' . $tmp . ' to ' . $plan->siteFile;
            }
        }
        if (is_link($plan->siteLink) || is_file($plan->siteLink)) {
            @unlink($plan->siteLink);
        }
        if (!@symlink($plan->siteFile, $plan->siteLink)) {
            return 'Cannot link ' . $plan->siteLink . ' → ' . $plan->siteFile;
        }
        return null;
    }

    /** Vrátí site soubor a odkaz do stavu před během (obsah a existence z plánu). */
    private function restoreSite(DevHttpsPlan $plan): void
    {
        if ($plan->currentSite === null) {
            @unlink($plan->siteFile);
        } else {
            @file_put_contents($plan->siteFile, $plan->currentSite);
            @chmod($plan->siteFile, 0644);
        }
        if (is_link($plan->siteLink) || is_file($plan->siteLink)) {
            @unlink($plan->siteLink);
        }
        if ($plan->siteLinked && $plan->currentSite !== null) {
            @symlink($plan->siteFile, $plan->siteLink);
        }
    }
}
