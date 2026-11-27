<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Server\CompletionInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ServerInitCommand extends Command
{
    protected string $serverConfigPath = '/etc/shipard/server.json';

    protected function configure(): void
    {
        $this->setName('server-init')
             ->setDescription('Initialize the Shipard server configuration')
             ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Operating mode: development or production', 'development')
             ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Shipard user (owns /opt/shipard, runs as PHP-FPM). Defaults: $SUDO_USER in development, "shipard" in production.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->isRunningAsRoot()) {
            $output->writeln('<error>This command must be run as root</error>');
            return Command::FAILURE;
        }

        $mode = (string) $input->getOption('mode');
        if ($mode !== 'development' && $mode !== 'production') {
            $output->writeln("<error>Invalid --mode '{$mode}'. Must be 'development' or 'production'.</error>");
            return Command::FAILURE;
        }

        $user = $input->getOption('user');
        if ($user === null || $user === '') {
            $user = $this->defaultShipardUser($mode);
        }
        if ($user === null) {
            $output->writeln('<error>Cannot determine shipard user. Pass --user=<name> explicitly.</error>');
            return Command::FAILURE;
        }
        if (posix_getpwnam($user) === false) {
            $output->writeln("<error>User '{$user}' does not exist on this system.</error>");
            return Command::FAILURE;
        }

        if (file_exists($this->serverConfigPath)) {
            $output->writeln('<info>Server is already initialized</info>');
            // Still re-apply ownership in case it was wrong
            $this->applyConfigOwnership($user, $output);
            $this->installShellCompletion($output);
            return Command::SUCCESS;
        }

        $password = $this->generatePassword();

        if (!$this->runMysqladmin($password)) {
            $output->writeln('<error>Failed to set MariaDB root password via mysqladmin</error>');
            return Command::FAILURE;
        }

        $dir = dirname($this->serverConfigPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            $output->writeln('<error>Failed to create config directory</error>');
            return Command::FAILURE;
        }

        $config = [
            'host'           => '127.0.0.1',
            'port'           => 3306,
            'admin_user'     => 'root',
            'admin_password' => $password,
            'mode'           => $mode,
        ];
        // Nová vývojová instalace dostane https na IP (#183 D3, U2). Ve WSL
        // ne — prohlížeč ve Windows chodí přes localhost, a ten je
        // zabezpečený kontext sám o sobě (U12). Existující server.json se
        // nemění (větev výše), takže stávající servery zůstávají u http.
        if ($mode === 'development' && !$this->isWsl()) {
            $config['devAccess'] = ['httpsIp' => true];
        }
        file_put_contents($this->serverConfigPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->applyConfigOwnership($user, $output);
        $this->installShellCompletion($output);

        $output->writeln('<info>Server initialized successfully</info>');
        $output->writeln("  Mode:    <comment>{$mode}</comment>");
        $output->writeln("  User:    <comment>{$user}</comment>");
        $output->writeln("  Config:  <comment>{$this->serverConfigPath}</comment>");
        if (isset($config['devAccess'])) {
            $output->writeln('  https:   <comment>devAccess.httpsIp is on — finish with: sudo shpd-server dev-https</comment>');
        }
        return Command::SUCCESS;
    }

    protected function isRunningAsRoot(): bool
    {
        return posix_getuid() === 0;
    }

    /** WSL podle jádra (`microsoft` / `wsl` v osrelease) — stejné pravidlo jako `dev-bootstrap.sh`. */
    protected function isWsl(): bool
    {
        $release = @file_get_contents('/proc/sys/kernel/osrelease');
        return is_string($release) && preg_match('/microsoft|wsl/i', $release) === 1;
    }

    protected function defaultShipardUser(string $mode): ?string
    {
        if ($mode === 'production') {
            return 'shipard';
        }
        $sudoUser = getenv('SUDO_USER');
        if (is_string($sudoUser) && $sudoUser !== '' && $sudoUser !== 'root') {
            return $sudoUser;
        }
        return null;
    }

    protected function generatePassword(): string
    {
        $charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=[]{}|';
        $password = '';
        for ($i = 0; $i < 32; $i++) {
            $password .= $charset[random_int(0, strlen($charset) - 1)];
        }
        return $password;
    }

    protected function runMysqladmin(string $password): bool
    {
        $escaped = escapeshellarg($password);
        exec("mysqladmin -u root password {$escaped}", $out, $exitCode);
        return $exitCode === 0;
    }

    /**
     * Apply per-contract ownership/mode on /etc/shipard and /etc/shipard/server.json.
     * Best-effort: failures are reported but don't abort init.
     */
    protected function applyConfigOwnership(string $user, OutputInterface $output): void
    {
        $configDir = dirname($this->serverConfigPath);
        if (is_dir($configDir)) {
            $this->setOwnership($configDir, 'root', $user, 0750, $output);
        }
        if (is_file($this->serverConfigPath)) {
            $this->setOwnership($this->serverConfigPath, 'root', $user, 0640, $output);
        }
    }

    protected function createCompletionInstaller(): CompletionInstaller
    {
        return new CompletionInstaller();
    }

    /** Best-effort: binárky nemusí být v PATH (instalace symlinků je na adminovi). */
    private function installShellCompletion(OutputInterface $output): void
    {
        $installer = $this->createCompletionInstaller();
        foreach (CompletionInstaller::BINARIES as $binary) {
            $result = $installer->install($binary);
            if ($result['status'] === 'skipped' || $result['status'] === 'error') {
                $output->writeln('<comment>Warning: bash completion for ' . $binary . ': ' . $result['message'] . '</comment>');
            }
        }
    }

    protected function setOwnership(string $path, string $owner, string $group, int $mode, OutputInterface $output): void
    {
        if (!@chown($path, $owner)) {
            $output->writeln("<comment>Warning: chown {$owner} {$path} failed</comment>");
        }
        if (!@chgrp($path, $group)) {
            $output->writeln("<comment>Warning: chgrp {$group} {$path} failed</comment>");
        }
        if (!@chmod($path, $mode)) {
            $output->writeln(sprintf('<comment>Warning: chmod %04o %s failed</comment>', $mode, $path));
        }
    }
}
