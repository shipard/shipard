<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Server\DataSourceStateScanner;
use Shipard\Core\Utils\IdGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * shpd-server ds-cron — přepínač periodických úloh přes všechny DS na
 * stroji (#115 D14), bez přechodu do adresáře DS:
 *
 *   ds-cron                        přehled: ID | Name | Cron | State
 *   ds-cron --off --all            vypnout všem
 *   ds-cron --off <id> [<id>…]     vypnout vyjmenovaným
 *   ds-cron --on <id> [<id>…]      zapnout vyjmenovaným
 *
 * Přepíná bez potvrzení (volá se ze skriptů, fáze 2 ho použije při první
 * instalaci cronu — D10). Chyba jednoho DS (neznámé ID, poškozený
 * state.json, nezapsatelný soubor) příkaz nezastaví — pokračuje dalšími
 * a skončí FAILURE. Per-DS varianta téhož je `shpd-ds ds-state cron`.
 */
class DsCronCommand extends Command
{
    public const string CHANGED_BY = 'cli';

    protected function configure(): void
    {
        $this->setName('ds-cron')
             ->setDescription('List data sources with their periodic-jobs switch, or switch it: --on|--off (--all | <id>...)')
             ->addArgument('ids', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Data source IDs to switch (xxxx-xxxx-xxxx-xxxx)')
             ->addOption('on', null, InputOption::VALUE_NONE, 'Switch periodic jobs on for the given data sources')
             ->addOption('off', null, InputOption::VALUE_NONE, 'Switch periodic jobs off for the given data sources')
             ->addOption('all', null, InputOption::VALUE_NONE, 'Apply --on/--off to every data source on this server');
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $on = (bool) $input->getOption('on');
        $off = (bool) $input->getOption('off');
        $all = (bool) $input->getOption('all');
        /** @var list<string> $ids */
        $ids = array_values(array_unique(array_map('strval', (array) $input->getArgument('ids'))));

        if (!$on && !$off) {
            if ($all || $ids !== []) {
                return $this->usage($output, 'Say what to do: --on or --off');
            }
            $this->renderOverview($output);
            return Command::SUCCESS;
        }
        if ($on && $off) {
            return $this->usage($output, '--on and --off are mutually exclusive');
        }
        if ($all && $ids !== []) {
            return $this->usage($output, 'Use either --all or a list of IDs, not both');
        }
        if (!$all && $ids === []) {
            return $this->usage($output, 'Give data source IDs or --all');
        }

        $targets = $all
            ? array_map(static fn($e) => $e->dsId, $this->scan())
            : $ids;

        $failures = 0;
        foreach ($targets as $id) {
            if (!$this->switchOne($id, $on, $output)) {
                $failures++;
            }
        }

        $output->writeln('');
        $this->renderOverview($output);

        return $failures === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /** @return bool true = ok (zapsáno nebo beze změny), false = chyba tohoto DS */
    private function switchOne(string $id, bool $enable, OutputInterface $output): bool
    {
        $label = $enable ? 'on' : 'off';

        // Tvar ID před složením cesty — `../x` nesmí dojít k is_file().
        if (!preg_match(IdGenerator::ID_PATTERN, $id)) {
            $output->writeln("  <error>✗ {$id}: invalid data source ID (expected xxxx-xxxx-xxxx-xxxx)</error>");
            return false;
        }
        $dir = $this->getDataSourcesDir() . '/' . $id;
        if (!is_file($dir . '/config/main.json')) {
            $output->writeln("  <error>✗ {$id}: unknown data source (no config/main.json)</error>");
            return false;
        }

        $state = DataSourceState::load($dir);
        if ($state->isCorrupted()) {
            $output->writeln("  <error>✗ {$id}: state.json unusable — not overwriting; repair first: cd {$dir} && shpd-ds ds-state set <state></error>");
            return false;
        }
        if ($state->isCronEnabled() === $enable) {
            $output->writeln("  · {$id}: unchanged (cron {$label})");
            return true;
        }

        try {
            $state->withCron($enable)->save($dir, self::CHANGED_BY, $this->now());
        } catch (\RuntimeException $e) {
            $output->writeln("  <error>✗ {$id}: {$e->getMessage()}</error>");
            return false;
        }
        $output->writeln("  ✓ {$id}: cron {$label}");
        return true;
    }

    private function renderOverview(OutputInterface $output): void
    {
        $entries = $this->scan();
        if ($entries === []) {
            $output->writeln('  (no data sources)');
            return;
        }

        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Cron', 'State']);
        $cronOff = 0;
        foreach ($entries as $e) {
            if ($e->isCronOff()) {
                $cronOff++;
            }
            $table->addRow([
                $e->dsId,
                $this->readName($this->getDataSourcesDir() . '/' . $e->dsId),
                $e->isCorrupted() ? '?' : ($e->isCronOff() ? '<comment>off</comment>' : 'on'),
                $this->stateLabel($e->state),
            ]);
        }
        $table->render();
        $output->writeln(sprintf('  %d data source(s), %d with cron off', count($entries), $cronOff));
    }

    /** @return list<\Shipard\Core\Server\DataSourceStateEntry> */
    private function scan(): array
    {
        return (new DataSourceStateScanner($this->getDataSourcesDir()))->scan($this->now());
    }

    /** Název z main.json shovívavě — nečitelný soubor dá `?`, příkaz kvůli němu neselže. */
    private function readName(string $dir): string
    {
        $raw = @file_get_contents($dir . '/config/main.json');
        if ($raw === false) {
            return '?';
        }
        $data = json_decode($raw, true);
        $name = is_array($data) ? ($data['name'] ?? null) : null;
        return is_string($name) && $name !== '' ? $name : '?';
    }

    private function stateLabel(DataSourceState $state): string
    {
        if ($state->isCorrupted()) {
            return 'corrupted';
        }
        if ($state->isMaintenanceActive()) {
            return sprintf('%s (maintenance: %s)', $state->getEffectiveState(), $state->getMaintenanceReason());
        }
        return $state->getEffectiveState();
    }

    private function usage(OutputInterface $output, string $why): int
    {
        $output->writeln("<error>{$why}</error>");
        $output->writeln('Usage: ds-cron                        overview');
        $output->writeln('       ds-cron --off --all            switch periodic jobs off for all data sources');
        $output->writeln('       ds-cron --on|--off <id> [<id>…] switch for the listed data sources');
        return Command::FAILURE;
    }
}
