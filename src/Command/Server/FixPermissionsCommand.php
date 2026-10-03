<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Server\HealthChecker;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class FixPermissionsCommand extends Command
{
    protected string $serverConfigPath = '/etc/shipard/server.json';

    public function __construct(
        private readonly ?PermissionSpec $injectedSpec = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('fix-permissions')
             ->setDescription('Fix ownership and modes in /opt/shipard and /etc/shipard')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be changed without applying')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$dryRun && !$this->isRoot()) {
            $output->writeln('<error>This command must be run as root (use sudo)</error>');
            $output->writeln('<comment>Or use --dry-run to preview changes</comment>');
            return Command::FAILURE;
        }

        $configFile = $this->serverConfigPath;
        if (!is_file($configFile)) {
            $output->writeln("<error>Config file missing: {$configFile}</error>");
            $output->writeln('<comment>→ Run: sudo bash scripts/install-packages.sh --mode=development</comment>');
            return Command::FAILURE;
        }

        $configContent = @file_get_contents($configFile);
        if ($configContent === false) {
            $output->writeln("<error>Config file not readable: {$configFile}</error>");
            return Command::FAILURE;
        }
        $config = json_decode($configContent, true);
        if (!is_array($config)) {
            $output->writeln("<error>Config file is not valid JSON: {$configFile}</error>");
            return Command::FAILURE;
        }
        $mode = is_string($config['mode'] ?? null) ? $config['mode'] : 'unknown';

        $spec = $this->injectedSpec ?? $this->buildSpec($mode);
        $shipardUser = $spec->getShipardUser();

        $output->writeln("Target user: <comment>{$shipardUser}</comment>");
        $output->writeln("Mode:        <comment>{$mode}</comment>");
        $output->writeln('');

        $checker = new HealthChecker($spec);
        $allIssues = $checker->checkAll();
        $fixable = array_values(array_filter($allIssues, static fn($i) => $i['fixable']));
        $unfixable = array_values(array_filter($allIssues, static fn($i) => !$i['fixable']));

        if (count($unfixable) > 0) {
            $output->writeln('<comment>Unfixable issues (will not be touched):</comment>');
            foreach ($unfixable as $issue) {
                $output->writeln("  ✗ {$issue['path']}: {$issue['message']}");
            }
            $output->writeln('');
        }

        if (count($fixable) === 0) {
            $output->writeln('<info>Nothing to fix — all paths already match the contract.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('Will apply ' . count($fixable) . ' changes:');
        foreach ($fixable as $issue) {
            $output->writeln("  {$issue['path']}: {$issue['message']}");
        }

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('<info>--dry-run: no changes applied</info>');
            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            $output->writeln('');
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('Proceed? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('Cancelled.');
                return Command::SUCCESS;
            }
        }

        $output->writeln('');
        $applied = 0;
        foreach ($spec->getGlobalEntries() as $entry) {
            $applied += $this->fixEntry($entry, $spec, $output);
        }
        foreach ($spec->discoverDataSources() as $dsDir) {
            foreach ($spec->getDataSourceEntries($dsDir) as $entry) {
                $applied += $this->fixEntry($entry, $spec, $output);
            }
        }
        $applied += $this->fixCheckoutTraverse($checker, $output);

        $output->writeln('');
        $output->writeln("<info>Applied {$applied} fixes.</info>");
        return Command::SUCCESS;
    }

    protected function isRoot(): bool
    {
        return posix_geteuid() === 0;
    }

    protected function buildSpec(string $mode): PermissionSpec
    {
        return new PermissionSpec($this->detectShipardUser($mode));
    }

    protected function detectShipardUser(string $mode): string
    {
        return PermissionSpec::detectShipardUser($mode);
    }

    /**
     * @param array{path: string, type: 'dir'|'file', owner: 'root'|'user', group: 'user', mode: int, optional?: bool} $entry
     */
    protected function fixEntry(array $entry, PermissionSpec $spec, OutputInterface $output): int
    {
        $path = $entry['path'];
        $optional = $entry['optional'] ?? false;
        if (!file_exists($path)) {
            if (!$optional) {
                $output->writeln("  <error>SKIP {$path}: does not exist (cannot create)</error>");
            }
            return 0;
        }

        $stat = @stat($path);
        if ($stat === false) {
            $output->writeln("  <error>SKIP {$path}: stat() failed</error>");
            return 0;
        }

        $expectedOwner = $spec->resolveOwner($entry['owner']);
        $expectedGroup = $spec->resolveOwner($entry['group']);

        $ownerInfo = posix_getpwuid($stat['uid']);
        $currentOwner = $ownerInfo['name'] ?? (string) $stat['uid'];
        $groupInfo = posix_getgrgid($stat['gid']);
        $currentGroup = $groupInfo['name'] ?? (string) $stat['gid'];
        $currentMode = $stat['mode'] & 0777;

        $count = 0;

        if ($currentOwner !== $expectedOwner) {
            if (@chown($path, $expectedOwner)) {
                $output->writeln("  chown {$expectedOwner} {$path}");
                $count++;
            } else {
                $output->writeln("  <error>FAIL chown {$expectedOwner} {$path}</error>");
            }
        }
        if ($currentGroup !== $expectedGroup) {
            if (@chgrp($path, $expectedGroup)) {
                $output->writeln("  chgrp {$expectedGroup} {$path}");
                $count++;
            } else {
                $output->writeln("  <error>FAIL chgrp {$expectedGroup} {$path}</error>");
            }
        }
        if ($currentMode !== $entry['mode']) {
            if (@chmod($path, $entry['mode'])) {
                $output->writeln(sprintf('  chmod %04o %s', $entry['mode'], $path));
                $count++;
            } else {
                $output->writeln(sprintf('  <error>FAIL chmod %04o %s</error>', $entry['mode'], $path));
            }
        }

        if (!empty($entry['recurse']) && $entry['type'] === 'dir') {
            $count += $this->fixContents($path, $expectedOwner, $expectedGroup, $output);
        }

        return $count;
    }

    /**
     * Grants `x` for others on the checkout's ancestors, so nginx can reach
     * /opt/shipard/shpd/public. Only directories owned by the shipard user —
     * foreign ones stay as they are (listed among the unfixable issues).
     */
    protected function fixCheckoutTraverse(HealthChecker $checker, OutputInterface $output): int
    {
        $count = 0;
        foreach ($checker->findBlockedCheckoutAncestors() as $dir) {
            if (!$dir['fixable']) {
                continue;
            }
            if (@chmod($dir['path'], $dir['mode'] | 0001)) {
                $output->writeln("  chmod o+x {$dir['path']}");
                $count++;
            } else {
                $output->writeln("  <error>FAIL chmod o+x {$dir['path']}</error>");
            }
        }
        return $count;
    }

    /**
     * Recursively applies expected ownership to $dir contents. Modes are
     * preserved (file modes vary by content type and aren't part of the
     * contract).
     */
    protected function fixContents(string $dir, string $expectedOwner, string $expectedGroup, OutputInterface $output): int
    {
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $dir,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME,
                ),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (\UnexpectedValueException $e) {
            $output->writeln("  <error>SKIP recurse {$dir}: " . $e->getMessage() . '</error>');
            return 0;
        }

        $count = 0;
        foreach ($iter as $path) {
            $stat = @lstat($path);
            if ($stat === false) {
                continue;
            }
            $ownerInfo = posix_getpwuid($stat['uid']);
            $currentOwner = $ownerInfo['name'] ?? (string) $stat['uid'];
            $groupInfo = posix_getgrgid($stat['gid']);
            $currentGroup = $groupInfo['name'] ?? (string) $stat['gid'];

            if ($currentOwner !== $expectedOwner) {
                if (@chown($path, $expectedOwner)) {
                    $output->writeln("  chown {$expectedOwner} {$path}");
                    $count++;
                } else {
                    $output->writeln("  <error>FAIL chown {$expectedOwner} {$path}</error>");
                }
            }
            if ($currentGroup !== $expectedGroup) {
                if (@chgrp($path, $expectedGroup)) {
                    $output->writeln("  chgrp {$expectedGroup} {$path}");
                    $count++;
                } else {
                    $output->writeln("  <error>FAIL chgrp {$expectedGroup} {$path}</error>");
                }
            }
        }
        return $count;
    }
}
