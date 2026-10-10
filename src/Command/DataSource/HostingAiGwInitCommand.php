<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Hosting\AiGwKeyStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Klíč organizace pro AI gateway (D5) — secrets/ai-gw-anthropic.key (0600).
 *
 * --set-key čte klíč z interaktivního promptu (skrytý vstup), případně ze
 * STDIN když není TTY — nikdy z argv, aby se nedostal do shell history.
 * Přepsání existujícího klíče = rotace (gateway ho čte per-request).
 * --status ukáže jen existenci/mtime/práva, nikdy obsah.
 *
 * Spec: tasks/hosting-05-ai-gateway.md, docs/hosting.md §5.5.
 */
class HostingAiGwInitCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('hosting-ai-gw-init')
             ->setDescription('Manage the AI gateway org key (secrets/ai-gw-anthropic.key)')
             ->addOption('set-key', null, InputOption::VALUE_NONE, 'Read the Anthropic API key from prompt/STDIN and store it')
             ->addOption('status', null, InputOption::VALUE_NONE, 'Show whether the key file exists (never prints the key)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $setKey = (bool) $input->getOption('set-key');
        $status = (bool) $input->getOption('status');

        if ($setKey === $status) {
            $output->writeln('<error>Error: pass exactly one of --set-key or --status</error>');
            return Command::FAILURE;
        }

        if ($status) {
            return $this->printStatus($dsDir, $output);
        }

        $key = $this->readKeyInput($input, $output);
        if ($key === null || trim($key) === '') {
            $output->writeln('<error>Error: no key provided</error>');
            return Command::FAILURE;
        }

        $existed = AiGwKeyStore::exists($dsDir);

        try {
            $warnings = AiGwKeyStore::write($dsDir, $key);
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>AI gateway org key ' . ($existed ? 'rotated' : 'created')
            . ': ' . AiGwKeyStore::keyFilePath($dsDir) . '</info>');
        foreach ($warnings as $warning) {
            $output->writeln('<comment>Warning: ' . $warning . '</comment>');
        }
        $output->writeln('');
        $output->writeln('<comment>Next steps:</comment>');
        $output->writeln('  1. Issue gateway tokens: shpd-ds hosting-ai-token --ds <ndx> --generate');
        $output->writeln('  2. Point client data sources at the gateway: shpd-ds ai-backend-set-key --base-url ...');

        return Command::SUCCESS;
    }

    private function printStatus(string $dsDir, OutputInterface $output): int
    {
        $keyFile = AiGwKeyStore::keyFilePath($dsDir);

        if (!is_file($keyFile)) {
            $output->writeln("<comment>AI gateway org key: missing ({$keyFile})</comment>");
            $output->writeln("Run 'shpd-ds hosting-ai-gw-init --set-key' to store it.");
            return Command::SUCCESS;
        }

        $perms = fileperms($keyFile) & 0777;
        $mtime = date('Y-m-d H:i:s', (int) filemtime($keyFile));
        $output->writeln("<info>AI gateway org key: present</info>");
        $output->writeln("  file:  {$keyFile}");
        $output->writeln("  mtime: {$mtime}");
        $output->writeln(sprintf('  perms: %04o%s', $perms, $perms === 0600 ? '' : ' <error>(must be 0600)</error>'));

        return $perms === 0600 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Klíč z interaktivního promptu (skrytý vstup) nebo ze STDIN pipe —
     * protected kvůli test seamu.
     */
    protected function readKeyInput(InputInterface $input, OutputInterface $output): ?string
    {
        if (function_exists('stream_isatty') && !@stream_isatty(STDIN)) {
            $piped = stream_get_contents(STDIN);
            return $piped === false ? null : trim($piped);
        }

        $question = new Question('Anthropic API key (input hidden): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable(true);

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $answer = $helper->ask($input, $output, $question);

        return is_string($answer) ? $answer : null;
    }
}
