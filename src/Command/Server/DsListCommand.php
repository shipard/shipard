<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Ai\ServerAiConnection;
use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\DataSourceInfo;
use Shipard\Core\Server\DataSourceInventory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * shpd-server ds-list [--json] — přehled zdrojů dat na stroji (#126 D10):
 * ID, název, třída dat, účel, původ, země, moduly, cron, stav a ve vývoji
 * efektivní serverové AI. Jediný zdroj přehledu i pro Clauda (`--json`
 * je stabilní kontrakt, {@see DataSourceInfo::toArray}).
 *
 * Čte jen `config/main.json` a `config/state.json` přes
 * {@see DataSourceInventory} — bez tajemství, bez databáze, funguje i pro
 * zdroj v údržbě; sudo nepotřebuje, stačí uživatel, který `main.json`
 * přečte (vlastník zdrojů dat). Nečitelné řádky dostanou `?` a radu pod
 * tabulkou. Exit 0 i bez zdrojů dat.
 */
class DsListCommand extends Command
{
    private const int PURPOSE_WIDTH = 40;

    protected function configure(): void
    {
        $this->setName('ds-list')
             ->setDescription('List data sources with data class, purpose, origin, modules, cron, state and server AI (--json for scripts)')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output (stable contract, no secrets)');
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    /** Server mode; 'development' when server.json is missing (production always has it). */
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
        $infos = (new DataSourceInventory($this->getDataSourcesDir()))->scan();
        $mode = $this->getServerMode();
        $development = $mode === ServerAiConnection::MODE_DEVELOPMENT;
        $serverAi = $development && $this->serverOffersAi();

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'server' => ['mode' => $mode, 'serverAi' => $serverAi],
                'dataSources' => array_map(static fn(DataSourceInfo $i): array => $i->toArray($serverAi), $infos),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        if ($infos === []) {
            $output->writeln('  (no data sources)');
            return Command::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Class', 'Purpose', 'Origin', 'Country', 'Modules', 'Cron', 'State'];
        if ($development) {
            $headers[] = 'Server AI';
        }
        $table = new Table($output);
        $table->setHeaders($headers);

        $free = 0;
        $implicit = 0;
        $unreadable = 0;
        foreach ($infos as $info) {
            if ($info->dataClass === DataClass::Free) {
                $free++;
            }
            if (!$info->dataClassExplicit) {
                $implicit++;
            }
            if (!$info->readable) {
                $unreadable++;
            }
            $row = [
                $info->id,
                $info->name,
                $this->classLabel($info),
                $info->readable ? $this->shorten($info->purpose) : '?',
                $info->readable ? ($info->origin ?? '') : '?',
                $info->readable ? ($info->country ?? '') : '?',
                $info->readable ? implode(', ', $info->modules) : '?',
                $info->state === 'corrupted' ? '?' : ($info->cronEnabled ? 'on' : '<comment>off</comment>'),
                $info->state,
            ];
            if ($development) {
                $row[] = $info->usesServerAi($serverAi) ? 'on' : 'off';
            }
            $table->addRow($row);
        }
        $table->render();

        $output->writeln('  Class: free | realCopy | realCopy* = not set in main.json (counts as realCopy) | realCopy! = invalid value (counts as realCopy)');
        if ($unreadable > 0) {
            $output->writeln('  ? = config/main.json not readable — run as the data sources owner or with sudo');
        }
        $output->writeln(sprintf(
            '  %d data source(s): %d free, %d realCopy (%d without explicit class)',
            count($infos),
            $free,
            count($infos) - $free,
            $implicit,
        ));

        return Command::SUCCESS;
    }

    private function classLabel(DataSourceInfo $info): string
    {
        if (!$info->readable) {
            return '?';
        }
        if ($info->dataClassInvalid) {
            return $info->dataClass->value . '!';
        }
        if (!$info->dataClassExplicit) {
            return $info->dataClass->value . '*';
        }
        return $info->dataClass->value;
    }

    private function shorten(?string $purpose): string
    {
        if ($purpose === null) {
            return '';
        }
        return mb_strlen($purpose) > self::PURPOSE_WIDTH
            ? rtrim(mb_substr($purpose, 0, self::PURPOSE_WIDTH - 1)) . '…'
            : $purpose;
    }
}
