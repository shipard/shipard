<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Server\CronProvisioner;
use Shipard\Core\Server\CronSwitchOutcome;
use Shipard\Core\Server\DataSourceCronSwitch;
use Shipard\Core\Server\DataSourceStateEntry;
use Shipard\Core\Server\DataSourceStateScanner;
use Shipard\Core\Server\PermissionSpec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Idempotentní generátor /etc/cron.d/shipard + runtime adresáře pro locky
 * a heartbeaty. Volá ho `shpd-server upgrade` jako subproces (po pullu běží
 * vždy nový kód), admin ho může spustit i ručně. Přepisuje jen když se
 * rendrovaný obsah liší od souboru na disku.
 *
 * První instalace mimo produkci (#115 D10): cron soubor ještě neexistuje,
 * na stroji tedy dosud žádná úloha neběžela a některé zdroje dat mohou být
 * kopie reálných dat. Všem existujícím zdrojům dat se proto vypne přepínač
 * periodických úloh (`DataSourceCronSwitch`, totéž co `ds-cron --off
 * --all`) — nejdřív vypnout, teprve potom zapsat cron soubor, jinak by
 * první tik stihl proběhnout nad kopiemi. Selže-li vypnutí u kteréhokoli
 * zdroje dat, cron soubor nevznikne. Poškozený `state.json` neblokuje —
 * takový zdroj dat je fail-closed zavřený a žádnou úlohu nedostane.
 * `--keep-ds-cron` vypínání přeskočí; na produkci se na zdroje dat nesahá
 * nikdy a přegenerování existujícího souboru je nemění.
 *
 * `--check` je pro skripty (`dev-update.sh`): exit 0 = soubor existuje
 * a odpovídá šabloně, 1 = chybí nebo se liší; bez výstupu, bez roota.
 */
class CronInstallCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('cron-install')
             ->setDescription('Generate /etc/cron.d/shipard and the runtime directory (idempotent)')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the rendered file and whether it would be (re)written, without changing anything')
             ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 0 when the cron file exists and matches the template, 1 otherwise; no output, no root needed')
             ->addOption('keep-ds-cron', null, InputOption::VALUE_NONE, 'First install outside production: leave the periodic-jobs switch of existing data sources untouched');
    }

    protected function getCronFilePath(): string
    {
        return CronProvisioner::CRON_FILE;
    }

    protected function getRunDir(): string
    {
        return CronProvisioner::RUN_DIR;
    }

    protected function getDataSourcesDir(): string
    {
        return '/opt/shipard/data-sources';
    }

    protected function getRepoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function getPhpBinary(): string
    {
        return PHP_BINARY;
    }

    protected function getEuid(): int
    {
        return posix_geteuid();
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** Server mode; 'development' když server.json chybí (produkce ho má vždy). */
    protected function getServerMode(): string
    {
        try {
            $cfg = $this->serverConfig;
            if ($cfg === null) {
                $cfg = new ServerConfig();
                $cfg->load();
            }
            return $cfg->getMode();
        } catch (\Throwable) {
            return 'development';
        }
    }

    protected function detectShipardUser(string $mode): string
    {
        return PermissionSpec::detectShipardUser($mode);
    }

    /** Chown/chgrp — vyžaduje root; v testech no-op override. */
    protected function applyOwnership(string $path, string $user): void
    {
        @chown($path, $user);
        @chgrp($path, $user);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mode = $this->getServerMode();
        $shipardUser = $this->detectShipardUser($mode);
        $cronFile = $this->getCronFilePath();

        $content = new CronProvisioner()->renderTemplate(
            $this->getPhpBinary(),
            $this->getRepoRoot(),
            $shipardUser,
        );

        $existing = is_file($cronFile) ? (string) @file_get_contents($cronFile) : null;
        $upToDate = $existing === $content;

        if ($input->getOption('check')) {
            return $upToDate ? Command::SUCCESS : Command::FAILURE;
        }

        // „První instalace“ = soubor před během neexistuje. Jen tehdy a jen
        // mimo produkci se sahá na zdroje dat (D10).
        $firstInstall = $existing === null;
        $keepDsCron = (bool) $input->getOption('keep-ds-cron');
        $switchOff = $firstInstall && $mode !== 'production' && !$keepDsCron;

        if ($input->getOption('dry-run')) {
            $output->writeln('Target: ' . $cronFile);
            $output->writeln($upToDate
                ? '<info>Up to date — nothing would be written.</info>'
                : ($existing === null
                    ? '<comment>Would create the file:</comment>'
                    : '<comment>Content differs — would rewrite:</comment>'));
            $output->writeln('');
            $output->write($content);
            if ($firstInstall && $mode !== 'production') {
                $output->writeln('');
                if ($keepDsCron) {
                    $output->writeln('<comment>First install outside production: --keep-ds-cron — existing data sources keep their periodic-jobs switch.</comment>');
                } else {
                    $this->previewSwitchOff($output);
                }
            }
            return Command::SUCCESS;
        }

        if ($this->getEuid() !== 0) {
            $output->writeln('<error>cron-install must run as root (writes ' . dirname($cronFile) . ').</error>');
            return Command::FAILURE;
        }

        // Runtime adresář pro locky a heartbeaty — píší do něj cron joby
        // běžící pod shipard uživatelem.
        $runDir = $this->getRunDir();
        if (!is_dir($runDir)) {
            if (!@mkdir($runDir, 0750, true)) {
                $output->writeln('<error>Cannot create ' . $runDir . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('✓ created ' . $runDir);
        }
        @chmod($runDir, 0750);
        $this->applyOwnership($runDir, $shipardUser);

        // Pořadí je závazné: nejdřív vypnout, potom zapsat cron soubor.
        if ($switchOff && !$this->switchOffAllDataSources($output, $cronFile)) {
            return Command::FAILURE;
        }

        if ($upToDate) {
            $output->writeln(sprintf(
                '✓ %s up to date (template version %d)',
                $cronFile,
                CronProvisioner::TEMPLATE_VERSION,
            ));
            return Command::SUCCESS;
        }

        // Atomický zápis: jméno s tečkou cron ignoruje, rozepsaný soubor se
        // tedy nikdy nevykoná; rename je na stejném filesystému atomické.
        $tmp = dirname($cronFile) . '/shipard.tmp';
        if (@file_put_contents($tmp, $content) === false) {
            $output->writeln('<error>Cannot write ' . $tmp . '</error>');
            return Command::FAILURE;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $cronFile)) {
            @unlink($tmp);
            $output->writeln('<error>Cannot move ' . $tmp . ' to ' . $cronFile . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '✓ %s written (template version %d)',
            $cronFile,
            CronProvisioner::TEMPLATE_VERSION,
        ));
        return Command::SUCCESS;
    }

    /**
     * D10: vypne periodické úlohy všem zdrojům dat na stroji. Vrací false,
     * když u některého vypnutí selhalo — cron soubor se pak nezapíše.
     * Poškozený `state.json` se jen vypíše a přeskočí.
     */
    private function switchOffAllDataSources(OutputInterface $output, string $cronFile): bool
    {
        $entries = $this->scanDataSources();
        if ($entries === []) {
            $output->writeln('· first install outside production: no data sources — nothing to switch off');
            return true;
        }

        $output->writeln(sprintf(
            'First install outside production — switching periodic jobs off for %d existing data source(s) (#115 D10):',
            count($entries),
        ));

        $switch = $this->cronSwitch();
        $now = $this->now();
        $failed = 0;
        $skipped = 0;
        foreach ($entries as $entry) {
            $result = $switch->switch($entry->dsId, false, DsCronCommand::CHANGED_BY, $now);
            $name = $switch->displayName($entry->dsId);
            if ($result->outcome === CronSwitchOutcome::Corrupted) {
                $skipped++;
                $output->writeln("  · {$entry->dsId}: state.json unusable — skipped (data source is closed fail-closed and gets no jobs)   ({$name})");
                continue;
            }
            if (!$result->isOk()) {
                $failed++;
                $output->writeln("  <error>{$result->message}</error>");
                continue;
            }
            $output->writeln("  {$result->message}   ({$name})");
        }

        if ($failed > 0) {
            $output->writeln(sprintf(
                '<error>✗ Could not switch periodic jobs off for %d data source(s) — %s NOT written. Fix the data sources above and run cron-install again.</error>',
                $failed,
                $cronFile,
            ));
            return false;
        }

        $output->writeln(sprintf(
            'Periodic jobs are off for %d data source(s)%s. Switch them on where you want them:',
            count($entries) - $skipped,
            $skipped > 0 ? sprintf(' (%d skipped)', $skipped) : '',
        ));
        $output->writeln('  sudo shpd-server ds-cron --on <id> [<id>…]      (overview: shpd-server ds-cron)');
        return true;
    }

    /** `--dry-run` první instalace mimo produkci: koho by vypnul. Nic nezapisuje. */
    private function previewSwitchOff(OutputInterface $output): void
    {
        $entries = $this->scanDataSources();
        if ($entries === []) {
            $output->writeln('<comment>First install outside production: no data sources — nothing would be switched off.</comment>');
            return;
        }

        $output->writeln(sprintf(
            '<comment>First install outside production — periodic jobs would be switched off for %d data source(s) (#115 D10):</comment>',
            count($entries),
        ));
        $switch = $this->cronSwitch();
        foreach ($entries as $entry) {
            $output->writeln(sprintf(
                '  %s  %s   (%s)',
                $entry->dsId,
                match (true) {
                    $entry->isCorrupted() => 'state.json unusable — skipped',
                    $entry->isCronOff() => 'cron off (unchanged)',
                    default => 'cron on → off',
                },
                $switch->displayName($entry->dsId),
            ));
        }
        $output->writeln('  Keep the switches as they are with --keep-ds-cron; enable later with: sudo shpd-server ds-cron --on <id>…');
    }

    /** @return list<DataSourceStateEntry> */
    private function scanDataSources(): array
    {
        return new DataSourceStateScanner($this->getDataSourcesDir())->scan($this->now());
    }

    private function cronSwitch(): DataSourceCronSwitch
    {
        return new DataSourceCronSwitch($this->getDataSourcesDir());
    }
}
