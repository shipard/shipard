<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Ai\DevAiKeyStore;
use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\ServerConfig;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Serverový AI klíč pro vývojové zdroje dat (tasks/testing-phase4.md D40,
 * #103 D10) — `/etc/shipard/secrets/dev-ai-<platforma>.key` (0600).
 *
 * `--set-key` čte klíč z interaktivního promptu (skrytý vstup), případně ze
 * STDIN když není TTY — nikdy z argv, aby se nedostal do shell history.
 * Přepsání existujícího klíče = rotace. `--status` ukáže jen existenci,
 * mtime a práva, nikdy obsah. Server mimo režim `development` příkaz
 * odmítne — serverové připojení tam neexistuje. Platformu a volitelnou
 * adresu určuje sekce `devAi` v server.json ({@see \Shipard\Core\Ai\DevAiConfig}).
 */
class DevAiKeyCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('dev-ai-key')
             ->setDescription('Manage the server AI key used by development data sources (/etc/shipard/secrets/dev-ai-<platform>.key)')
             ->addOption('set-key', null, InputOption::VALUE_NONE, 'Read the API key from prompt/STDIN and store it (overwrite = rotation)')
             ->addOption('status', null, InputOption::VALUE_NONE, 'Show whether the key file exists (never prints the key)');
    }

    /** Seam pro testy. */
    protected function loadServerConfig(): ServerConfig
    {
        $config = $this->serverConfig ?? new ServerConfig();
        $config->load();
        return $config;
    }

    /** Vzor vlastníka zapsaného klíče; null = výchozí (`/opt/shipard`). Seam pro testy. */
    protected function ownerReference(): ?string
    {
        return null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = $this->loadServerConfig();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Error: failed to load server config: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $mode = $config->getMode();
        if ($mode !== ServerAiConnection::MODE_DEVELOPMENT) {
            $output->writeln("<error>Error: dev-ai-key is for development servers only (server.json mode = {$mode})</error>");
            return Command::FAILURE;
        }

        $setKey = (bool) $input->getOption('set-key');
        $status = (bool) $input->getOption('status');
        if ($setKey === $status) {
            $output->writeln('<error>Error: pass exactly one of --set-key or --status</error>');
            return Command::FAILURE;
        }

        try {
            $devAi = $config->getDevAi();
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $configDir = $config->getConfigDir();

        if ($status) {
            return $this->printStatus($configDir, $devAi->platform, $output);
        }

        $key = $this->readKeyInput($input, $output);
        if ($key === null || trim($key) === '') {
            $output->writeln('<error>Error: no key provided</error>');
            return Command::FAILURE;
        }

        $existed = DevAiKeyStore::exists($configDir, $devAi->platform);
        try {
            $warnings = DevAiKeyStore::write($configDir, $devAi->platform, $key, $this->ownerReference());
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Server AI key ' . ($existed ? 'rotated' : 'created')
            . ': ' . DevAiKeyStore::keyFilePath($configDir, $devAi->platform) . '</info>');
        foreach ($warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }
        $output->writeln('');
        $output->writeln('<comment>Platform:</comment> ' . $devAi->platform
            . ($devAi->baseUrl !== null ? ' (' . $devAi->baseUrl . ')' : ' (default platform URL)'));
        $output->writeln('<comment>Who uses it:</comment> data sources with "dataClass": "free" in config/main.json');
        $output->writeln('  (ds-create on this server, dataset-seed, shpd-ds ds-class set free; a real copy — realCopy, or no class — never)');
        $output->writeln('  and without an active AI connection of their own holding a key. Overview: shpd-server ds-list');
        $output->writeln('<comment>Verify:</comment> cd /opt/shipard/data-sources/<id> && shpd-ds ai-test --function mail.extraction');

        return Command::SUCCESS;
    }

    private function printStatus(string $configDir, string $platform, OutputInterface $output): int
    {
        $keyFile = DevAiKeyStore::keyFilePath($configDir, $platform);

        if (!is_file($keyFile)) {
            $output->writeln("<comment>Server AI key ({$platform}): missing ({$keyFile})</comment>");
            $output->writeln("Run 'sudo shpd-server dev-ai-key --set-key' to store it.");
            return Command::SUCCESS;
        }

        $perms = fileperms($keyFile) & 0777;
        $mtime = date('Y-m-d H:i:s', (int) filemtime($keyFile));
        $output->writeln("<info>Server AI key ({$platform}): present</info>");
        $output->writeln("  file:  {$keyFile}");
        $output->writeln("  mtime: {$mtime}");
        $output->writeln(sprintf('  perms: %04o%s', $perms, $perms === 0600 ? '' : ' <error>(must be 0600)</error>'));

        return $perms === 0600 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Klíč z interaktivního promptu (skrytý vstup) nebo ze STDIN pipe —
     * protected kvůli test seamu (vzor HostingAiGwInitCommand).
     */
    protected function readKeyInput(InputInterface $input, OutputInterface $output): ?string
    {
        if (function_exists('stream_isatty') && !@stream_isatty(STDIN)) {
            $piped = stream_get_contents(STDIN);
            return $piped === false ? null : trim($piped);
        }

        $question = new Question('API key (input hidden): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable(true);

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $answer = $helper->ask($input, $output, $question);

        return is_string($answer) ? $answer : null;
    }
}
