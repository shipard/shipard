<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\MainConfigFile;
use Shipard\Core\Server\MainConfigPatcher;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Třída dat zdroje dat — `config/main.json` (#126 D6–D9, tasks/ds-data-class.md).
 *
 *   ds-class                                   show
 *   ds-class set realCopy [--purpose=<text>]   kdykoli — bezpečný směr
 *   ds-class set free [--purpose=<text>]       jen interaktivně, s potvrzením
 *
 * `free` = ukázková / seedovaná data (Claude smí měnit bez schválení, ve
 * vývoji dostane serverové AI připojení), `realCopy` = kopie skutečných dat.
 * Chybějící nebo neplatná třída platí jako `realCopy`. Přechod na `free` je
 * pojistka proti omylu, ne proti úmyslu: bez terminálu (`-n`, MCP most,
 * Claude Code) končí chybou — volné zdroje označuje vlastník stroje sám;
 * `--yes` záměrně neexistuje. Stejná třída = beze změny třídy, původu
 * i času (jen `--purpose` se zapíše). Zápis přes {@see MainConfigFile::patch}
 * odebere zaniklý `ai.useServerConnection` (U8).
 */
class DsClassCommand extends Command
{
    public const string ORIGIN = 'ds-class';

    protected function configure(): void
    {
        $this->setName('ds-class')
             ->setDescription('Show or change the data class of the data source (config/main.json dataClass): show | set free|realCopy [--purpose]')
             ->addArgument('action', InputArgument::OPTIONAL, 'show | set', 'show')
             ->addArgument('value', InputArgument::OPTIONAL, 'Class for set: ' . DataClass::Free->value . ' | ' . DataClass::RealCopy->value)
             ->addOption('purpose', null, InputOption::VALUE_REQUIRED, 'Purpose of the data source (one line, max ' . MainConfigPatcher::PURPOSE_MAX_LENGTH . ' characters); empty removes it');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    /** Režim serveru; `development` bez server.json (produkce ho má vždy). */
    protected function getServerMode(): string
    {
        try {
            $cfg = new ServerConfig();
            $cfg->load();
            return $cfg->getMode();
        } catch (\Throwable) {
            return 'development';
        }
    }

    /** Server nabízí serverové AI připojení (režim development + uložený klíč). */
    protected function serverOffersAi(): bool
    {
        return ServerAiConnection::detect() !== null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        try {
            $config = new DataSourceConfig($dsDir);
        } catch (\RuntimeException $e) {
            return $this->fail($output, 'config/main.json is unusable: ' . $e->getMessage());
        }

        $action = (string) $input->getArgument('action');
        return match ($action) {
            'show' => $this->runShow($config, $output),
            'set' => $this->runSet($config, $dsDir, $input, $output),
            default => $this->fail($output, "Unknown action '{$action}'. Use: show | set free|realCopy [--purpose=<text>]"),
        };
    }

    private function runShow(DataSourceConfig $config, OutputInterface $output): int
    {
        $class = $config->getDataClass();
        if ($config->hasExplicitDataClass()) {
            $output->writeln(sprintf('  %-18s <info>%s</info>', 'Data class:', $class->value));
        } elseif ($config->getDataClassRaw() === null) {
            $output->writeln(sprintf('  %-18s <comment>%s</comment> (not set in main.json — counts as realCopy)', 'Data class:', $class->value));
        } else {
            $output->writeln(sprintf(
                '  %-18s <comment>%s</comment> (invalid value %s — counts as realCopy)',
                'Data class:',
                $class->value,
                json_encode($config->getDataClassRaw(), JSON_UNESCAPED_UNICODE),
            ));
        }
        $output->writeln(sprintf('  %-18s %s', 'Purpose:', $config->getPurpose() ?? '(none)'));
        $output->writeln(sprintf('  %-18s %s', 'Origin:', $config->getOrigin() ?? '?'));
        $output->writeln(sprintf('  %-18s %s', 'Changed:', $config->getDataClassChanged() ?? '?'));
        if ($this->getServerMode() === 'development') {
            $output->writeln(sprintf('  %-18s %s', 'Server AI:', $this->serverAiLabel($class)));
        }
        if ($config->hasLegacyServerAiFlag()) {
            $output->writeln('<comment>  [WARN] main.json still has ai.useServerConnection — the key is no longer used (the data class decides, #126 D9); the next ds-class set removes it</comment>');
        }
        return Command::SUCCESS;
    }

    private function runSet(DataSourceConfig $config, string $dsDir, InputInterface $input, OutputInterface $output): int
    {
        $value = $input->getArgument('value');
        $target = is_string($value) ? DataClass::tryFrom($value) : null;
        if ($target === null) {
            return $this->fail($output, 'Usage: ds-class set free|realCopy [--purpose=<text>]');
        }

        $purposeOption = $input->getOption('purpose');
        $purpose = null;
        if (is_string($purposeOption)) {
            try {
                $purpose = MainConfigPatcher::normalizePurpose($purposeOption);
            } catch (\InvalidArgumentException $e) {
                return $this->fail($output, 'Invalid --purpose: ' . $e->getMessage());
            }
        }

        $sameClass = $config->hasExplicitDataClass() && $config->getDataClass() === $target;

        if ($sameClass && $purposeOption === null) {
            $output->writeln(sprintf('<comment>unchanged</comment> — data class is already %s.', $target->value));
            return Command::SUCCESS;
        }

        if (!$sameClass && $target === DataClass::Free) {
            // Pojistka proti omylu (D8): přechod na free jen z terminálu.
            if (!$input->isInteractive()) {
                return $this->fail($output, 'Marking a data source as free requires an interactive terminal — run the command yourself.');
            }
            $question = new ConfirmationQuestion(sprintf(
                "Data source '%s' is a real copy (purpose: %s). Mark it as free — Claude may then change it without approval and, on a development server, it uses the server AI connection? [y/N] ",
                $config->getName(),
                $config->getPurpose() ?? 'none',
            ), false);
            if (!$this->getHelper('question')->ask($input, $output, $question)) {
                $output->writeln('Aborted.');
                return Command::SUCCESS;
            }
        }

        $changedAt = DataSourceState::formatUtc($this->now());
        try {
            MainConfigFile::patch($dsDir, static function (array $raw) use ($sameClass, $target, $changedAt, $purposeOption, $purpose): array {
                if (!$sameClass) {
                    $raw = MainConfigPatcher::withDataClass($raw, $target, self::ORIGIN, $changedAt);
                }
                if ($purposeOption !== null) {
                    $raw = MainConfigPatcher::withPurpose($raw, $purpose);
                }
                return $raw;
            });
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        if ($sameClass) {
            $output->writeln(sprintf(
                '<comment>unchanged</comment> — data class is already %s; purpose %s.',
                $target->value,
                $purpose === '' ? 'removed' : 'set to ' . $purpose,
            ));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Data class set to <info>%s</info> (origin: %s).', $target->value, self::ORIGIN));
        if ($purposeOption !== null) {
            $output->writeln(sprintf('  %-18s %s', 'Purpose:', $purpose === '' ? '(removed)' : $purpose));
        }
        if ($this->getServerMode() === 'development') {
            $output->writeln(sprintf('  %-18s %s', 'Server AI:', $this->serverAiLabel($target)));
        }
        return Command::SUCCESS;
    }

    /** Efektivní serverové AI připojení podle třídy a serveru (oddíl 4 tasku). */
    private function serverAiLabel(DataClass $class): string
    {
        if ($class !== DataClass::Free) {
            return 'off (realCopy)';
        }
        return $this->serverOffersAi()
            ? 'on (free data source)'
            : 'off (no server AI key — shpd-server dev-ai-key)';
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln("<error>{$message}</error>");
        return Command::FAILURE;
    }
}
