<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceState;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Stav zdroje dat — `config/state.json` (#56 D9, docs/ds-state.md).
 *
 *   ds-state                                  show
 *   ds-state set <state> [--delete-after=<ISO>] [--yes]
 *   ds-state maintenance --on [--reason=<r>] | --off
 *   ds-state cron --on | --off                (#115 D14 — přepínač periodických úloh)
 *
 * HTTP: suspended / maintenance / pending_deletion → 503; read_only →
 * ReadOnlyPolicy (403 na mutacích, docs/ds-state.md). Lokální CLI je pro
 * nehostované DS a nouzové zásahy — u hostovaných DS drží desired state
 * hosting (D6, fáze 3).
 */
class DsStateCommand extends Command
{
    public const string CHANGED_BY = 'cli';

    protected function configure(): void
    {
        $this->setName('ds-state')
             ->setDescription('Show or change the data source state (config/state.json): show | set <state> | maintenance --on/--off | cron --on/--off')
             ->addArgument('action', InputArgument::OPTIONAL, 'show | set | maintenance | cron', 'show')
             ->addArgument('value', InputArgument::OPTIONAL, 'State for set: ' . implode(' | ', DataSourceState::STATES))
             ->addOption('on', null, InputOption::VALUE_NONE, 'maintenance / cron: switch on')
             ->addOption('off', null, InputOption::VALUE_NONE, 'maintenance / cron: switch off')
             ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'maintenance --on: ' . implode(' | ', DataSourceState::MAINTENANCE_REASONS) . ' (default manual)')
             ->addOption('delete-after', null, InputOption::VALUE_REQUIRED, 'set pending_deletion: ISO 8601 date/time after which the DS may be physically deleted (required)')
             ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation (set pending_deletion)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $action = (string) $input->getArgument('action');
        $state = DataSourceState::load($dsDir);

        return match ($action) {
            'show' => $this->runShow($state, $dsDir, $output),
            'set' => $this->runSet($state, $dsDir, $input, $output),
            'maintenance' => $this->runMaintenance($state, $dsDir, $input, $output),
            'cron' => $this->runCron($state, $dsDir, $input, $output),
            default => $this->fail($output, "Unknown action '{$action}'. Use: show | set <state> | maintenance --on|--off | cron --on|--off"),
        };
    }

    private function runShow(DataSourceState $state, string $dsDir, OutputInterface $output): int
    {
        if ($state->isCorrupted()) {
            $output->writeln('<error>state.json is unreadable or invalid — data source is treated as suspended (fail-closed).</error>');
            $output->writeln('  File: ' . DataSourceState::filePath($dsDir));
            $output->writeln('  Fix or remove the file (missing file = active), or overwrite it with: shpd-ds ds-state set <state>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('  %-18s %s', 'State:', $state->getState()));
        if ($state->isMaintenanceActive()) {
            $output->writeln(sprintf(
                '  %-18s <comment>on</comment> (reason: %s, since: %s)',
                'Maintenance:',
                $state->getMaintenanceReason(),
                $state->getMaintenanceSince() ?? '?',
            ));
        } else {
            $output->writeln(sprintf('  %-18s off', 'Maintenance:'));
        }
        if ($state->isCronEnabled()) {
            $output->writeln(sprintf('  %-18s on', 'Cron:'));
        } else {
            $output->writeln(sprintf('  %-18s <comment>off</comment> (periodic jobs are not running)', 'Cron:'));
        }
        if ($state->getDeleteAfter() !== null) {
            $output->writeln(sprintf('  %-18s %s', 'Delete after:', $state->getDeleteAfter()));
        }
        $output->writeln(sprintf('  %-18s <info>%s</info>%s', 'Effective:', $state->getEffectiveState(), $this->effectiveHint($state)));
        if ($state->isFromFile()) {
            $output->writeln(sprintf(
                '  %-18s %s by %s',
                'Changed:',
                $state->getChanged() ?? '?',
                $state->getChangedBy() ?? '?',
            ));
        } else {
            $output->writeln('  <comment>(no state.json — default active)</comment>');
        }
        return Command::SUCCESS;
    }

    private function runSet(DataSourceState $state, string $dsDir, InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('value');
        if (!is_string($target) || !in_array($target, DataSourceState::STATES, true)) {
            return $this->fail($output, 'Usage: ds-state set <' . implode('|', DataSourceState::STATES) . '>');
        }

        if ($state->isCorrupted()) {
            // Přepis poškozeného souboru je legitimní oprava — start z čistého active.
            $output->writeln('<comment>Existing state.json is invalid — overwriting.</comment>');
            $state = DataSourceState::active();
        }

        $next = $state->withState($target);

        if ($target === DataSourceState::PENDING_DELETION) {
            $raw = $input->getOption('delete-after');
            if (!is_string($raw) || trim($raw) === '') {
                return $this->fail($output, 'set pending_deletion requires --delete-after=<ISO 8601 date/time>');
            }
            try {
                $deleteAfter = new \DateTimeImmutable(trim($raw));
            } catch (\Exception) {
                return $this->fail($output, "Invalid --delete-after value '{$raw}' (expected ISO 8601, e.g. 2026-10-01 or 2026-10-01T00:00:00Z)");
            }
            if ($deleteAfter <= $this->now()) {
                return $this->fail($output, '--delete-after must be in the future');
            }
            $next = $next->withDeleteAfter($deleteAfter);

            if (!$input->getOption('yes')) {
                $question = new ConfirmationQuestion(sprintf(
                    'Mark data source %s for deletion after %s? The DS will stop serving requests (503) immediately. [y/N] ',
                    basename($dsDir),
                    DataSourceState::formatUtc($deleteAfter),
                ), false);
                if (!$this->getHelper('question')->ask($input, $output, $question)) {
                    $output->writeln('Aborted.');
                    return Command::FAILURE;
                }
            }
        }

        try {
            $saved = $next->save($dsDir, self::CHANGED_BY, $this->now());
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln(sprintf('State set to <info>%s</info>.', $saved->getState()));
        $this->writeEffective($saved, $output);
        return Command::SUCCESS;
    }

    private function runMaintenance(DataSourceState $state, string $dsDir, InputInterface $input, OutputInterface $output): int
    {
        $on = (bool) $input->getOption('on');
        $off = (bool) $input->getOption('off');
        if ($on === $off) {
            return $this->fail($output, 'Usage: ds-state maintenance --on [--reason=' . implode('|', DataSourceState::MAINTENANCE_REASONS) . '] | --off');
        }

        if ($state->isCorrupted()) {
            return $this->fail($output, 'state.json is invalid — repair it first with: shpd-ds ds-state set <state>');
        }

        if ($on) {
            $reason = $input->getOption('reason');
            $reason = is_string($reason) && $reason !== '' ? $reason : 'manual';
            if (!in_array($reason, DataSourceState::MAINTENANCE_REASONS, true)) {
                return $this->fail($output, "Unknown --reason '{$reason}' (valid: " . implode(', ', DataSourceState::MAINTENANCE_REASONS) . ')');
            }
            $next = $state->withMaintenance($reason, $this->now());
        } else {
            if (!$state->isMaintenanceActive()) {
                $output->writeln('<comment>Maintenance is not active — nothing to do.</comment>');
                $this->writeEffective($state, $output);
                return Command::SUCCESS;
            }
            $next = $state->withoutMaintenance();
        }

        try {
            $saved = $next->save($dsDir, self::CHANGED_BY, $this->now());
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln($on
            ? sprintf('Maintenance <info>on</info> (reason: %s).', $saved->getMaintenanceReason())
            : 'Maintenance <info>off</info>.');
        $this->writeEffective($saved, $output);
        return Command::SUCCESS;
    }

    /**
     * Přepínač periodických úloh (#115 D14). Nezávislý na stavu i maintenance;
     * cílový stav = stávající → nic nezapisuje. Poškozený soubor odmítá jako
     * maintenance — oprava jde přes `set`.
     */
    private function runCron(DataSourceState $state, string $dsDir, InputInterface $input, OutputInterface $output): int
    {
        $on = (bool) $input->getOption('on');
        $off = (bool) $input->getOption('off');
        if ($on === $off) {
            return $this->fail($output, 'Usage: ds-state cron --on | --off');
        }

        if ($state->isCorrupted()) {
            return $this->fail($output, 'state.json is invalid — repair it first with: shpd-ds ds-state set <state>');
        }

        if ($state->isCronEnabled() === $on) {
            $output->writeln(sprintf('<comment>Cron is already %s — nothing to do.</comment>', $on ? 'on' : 'off'));
            return Command::SUCCESS;
        }

        try {
            $saved = $state->withCron($on)->save($dsDir, self::CHANGED_BY, $this->now());
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        $output->writeln($saved->isCronEnabled()
            ? 'Cron <info>on</info> — periodic jobs run for this data source again.'
            : 'Cron <info>off</info> — the dispatcher skips this data source (queued mail, alerts, VAT periods, periodic invoicing).');
        $this->writeEffective($saved, $output);
        return Command::SUCCESS;
    }

    private function writeEffective(DataSourceState $state, OutputInterface $output): void
    {
        $output->writeln(sprintf('Effective state: <info>%s</info>%s', $state->getEffectiveState(), $this->effectiveHint($state)));
    }

    private function effectiveHint(DataSourceState $state): string
    {
        if ($state->isMaintenanceActive()) {
            return ' — maintenance overrides lifecycle state; use `ds-state maintenance --off` to reopen';
        }
        if ($state->blocksHttp()) {
            return ' — HTTP returns 503, cron jobs are skipped';
        }
        return '';
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln("<error>{$message}</error>");
        return Command::FAILURE;
    }
}
