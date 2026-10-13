<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataSourceState;
use Shipard\Core\Server\DataSourceCronSwitch;
use Shipard\Core\Server\DataSourceStateScanner;
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
 * Přepíná bez potvrzení (volá se ze skriptů). Chyba jednoho DS (neznámé ID,
 * poškozený state.json, nezapsatelný soubor) příkaz nezastaví — pokračuje
 * dalšími a skončí FAILURE. Per-DS varianta téhož je `shpd-ds ds-state cron`.
 * Vlastní přepnutí dělá `DataSourceCronSwitch`, kterou používá i první
 * instalace cronu v `cron-install` (D10) — tady se jen vypisuje výsledek.
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
        $result = $this->cronSwitch()->switch($id, $enable, self::CHANGED_BY, $this->now());
        $output->writeln($result->isOk() ? "  {$result->message}" : "  <error>{$result->message}</error>");
        return $result->isOk();
    }

    private function cronSwitch(): DataSourceCronSwitch
    {
        return new DataSourceCronSwitch($this->getDataSourcesDir());
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
                $this->cronSwitch()->displayName($e->dsId),
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
