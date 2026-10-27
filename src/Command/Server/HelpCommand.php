<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HelpCommand extends Command
{
    /** Příkaz, který si vyžádal `--help`; null = obecná nápověda. */
    private ?Command $describeCommand = null;

    /**
     * Symfony volá při `<příkaz> --help` na commandu jménem `help`. Ručně
     * psaná nápověda tady vestavěnou nahrazuje, takže bez téhle metody
     * `--help` u **kteréhokoli** příkazu skončil fatální chybou.
     */
    public function setCommand(Command $command): void
    {
        $this->describeCommand = $command;
    }

    protected function configure(): void
    {
        $this->setName('help')
             ->setDescription('Show available commands');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Popis konkrétního příkazu (jeho opce) nechává na Symfony —
        // ručně psaný přehled je jen pro seznam příkazů.
        if ($this->describeCommand !== null) {
            (new DescriptorHelper())->describe($output, $this->describeCommand);
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>Shipard Server Management v' . Version::VERSION . '</info>');
        $output->writeln('');
        $output->writeln('<comment>Available commands:</comment>');
        $output->writeln('  <info>version</info>        Show Shipard server version');
        $output->writeln('  <info>help</info>           Show this help message');
        $output->writeln('  <info>server-init</info>    Initialize the Shipard server configuration');
        $output->writeln('  <info>ds-create</info>      Create a new data source');
        $output->writeln('  <info>ds-delete</info>      Delete a free data source outside production: grants, database, DB user, directory (no backup)');
        $output->writeln('  <info>ds-upgrade-all</info> Run ds-upgrade on all data sources');
        $output->writeln('  <info>upgrade</info>        Deploy a new version (git pull, composer, frontend, ds-upgrade-all, doctor)');
        $output->writeln('  <info>next-table-id</info>  Print the next available table ID');
        $output->writeln('  <info>einvoice-render</info> Render a structured e-invoice (ISDOC) from a canonical JSON document (no data source)');
        $output->writeln('  <info>domain-add</info>      Add a host → data source mapping');
        $output->writeln('  <info>domain-list</info>     List all host → data source mappings');
        $output->writeln('  <info>domain-remove</info>   Remove a host → data source mapping');
        $output->writeln('  <info>cron</info>            Run scheduled per-DS jobs for the given slot (invoked from /etc/cron.d/shipard)');
        $output->writeln('  <info>cron-install</info>    Generate /etc/cron.d/shipard and the runtime directory (idempotent)');
        $output->writeln('  <info>completion-install</info> Install bash completion for shpd-server and shpd-ds (idempotent)');
        $output->writeln('  <info>hosting-sync</info>    Sync with the hosting: reconcile local data sources and provision queued requests');
        $output->writeln('  <info>ds-state-check</info>  Warn about data sources stuck in maintenance > 7 days or with unusable state.json (daily cron job)');
        $output->writeln('  <info>ds-cron</info>         List data sources with their periodic-jobs switch, or switch it on/off (--all or IDs)');
        $output->writeln('  <info>ds-list</info>         List data sources: data class, purpose, origin, country, modules, cron, state, server AI (--json)');
        $output->writeln('  <info>dev-ai-key</info>      Store or check the server AI key for development data sources (--set-key | --status)');
        $output->writeln('  <info>dev-update-check</info> Check how far this development checkout is behind stable on GitHub (--json)');
        $output->writeln('  <info>dev-update</info>      Update this development checkout: fetch, fast-forward, dev-update.sh, ds-upgrade-all, cron check');
        $output->writeln('  <info>test-round</info>      Run the whole test round as a dashboard job: code tests, public datasets, video checks; --eval only with --max-cost');
        $output->writeln('  <info>doctor</info>          Diagnose server configuration and permissions');
        $output->writeln('  <info>fix-permissions</info> Fix ownership and modes (requires sudo)');
        $output->writeln('');
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  shpd-server <command> [options]');
        $output->writeln('');
        $output->writeln('<comment>Options:</comment>');
        $output->writeln('  ds-create --name=<n> --language=<cs|en> --country=<cc> [--module=<id>] [--no-cron] [--real-copy] [--purpose=<text>]');
        $output->writeln('                                           Create a new data source');
        $output->writeln('                                           (--module defaults to install.base, --no-cron = periodic jobs off,');
        $output->writeln('                                           --real-copy = copy of real data (import target; no server AI on a development');
        $output->writeln('                                           server), --purpose = one-line purpose shown by ds-list; --no-server-ai is deprecated)');
        $output->writeln('  ds-delete <id> [--dry-run] [--yes]        Delete a free data source (never in production, never a real copy,');
        $output->writeln('                                           never with a running dashboard job); confirm by typing the ID, --yes skips it');
        $output->writeln('  ds-upgrade-all [--ds=<id>] [--stop-on-error] [--dry-run]');
        $output->writeln('                                           Upgrade all data sources');
        $output->writeln('  upgrade [--dry-run] [--full] [--skip-ds-upgrade]');
        $output->writeln('                                           Deploy a new version from git');
        $output->writeln('  einvoice-render --format=isdoc --input=<canonical.json> --output=<file> --uuid-name=<text> [--issuing-system=<text>]');
        $output->writeln('                                           Write an ISDOC file from a canonical document (validated against XSD 6.0.1 and 6.0.2);');
        $output->writeln('                                           exit 1 = input problem (paths on stderr), 2 = output failed the schema; nothing written on error');
        $output->writeln('  domain-add --host <host> --ds <ds-id>    Map a hostname to a data source');
        $output->writeln('  cron --slot=<slot> [--ds=<id>]           Run slot jobs (--ds: one data source, ignores its cron switch)');
        $output->writeln('  cron-install [--dry-run | --check] [--keep-ds-cron]');
        $output->writeln('                                           Generate /etc/cron.d/shipard; the first install outside production');
        $output->writeln('                                           switches periodic jobs off for existing data sources (--check: exit 0/1, silent)');
        $output->writeln('  ds-cron [--on|--off] [--all | <id>…]     Overview, or switch periodic jobs per data source');
        $output->writeln('  ds-list [--json]                         Data sources with data class (free | realCopy), purpose, origin, cron, state');
        $output->writeln('  dev-ai-key --set-key | --status          Server AI key (/etc/shipard/secrets/dev-ai-<platform>.key, development only)');
        $output->writeln('  dev-update-check [--json]                Fetch stable from GitHub (anonymous HTTPS) and report behind / ahead,');
        $output->writeln('                                           commits and update safeguards; exit 1 only when the check fails (development only)');
        $output->writeln('  dev-update [--yes] [--job=<id>]           Fetch, git merge --ff-only, scripts/dev-update.sh, ds-upgrade-all, cron-install --check;');
        $output->writeln('                                           refuses on another branch, dirty tree, local commits, running job, missing tools');
        $output->writeln('  test-round [--only=<code,datasets,videos>] [--dataset=<name|dir>]… [--scenario=<id>]… [--base-url=<origin>]');
        $output->writeln('             [--eval=<variant> --repeat=<n> --max-cost=<USD>] [--detach | --follow=<id>] [--json]');
        $output->writeln('                                           Code tests → public datasets (reseed, verify, walkthrough) → video checks as one dashboard');
        $output->writeln('                                           job on dedicated data sources; follows it (Ctrl+C cancels, twice detaches); evaluation only');
        $output->writeln('                                           with --eval and a spend cap (estimate first); exit 0 all ok, 1 problems, 2 refused, 130 cancelled');
        $output->writeln('  domain-remove --host <host>              Remove a hostname mapping');
        $output->writeln('  doctor                                   Read-only health check (no options)');
        $output->writeln('  fix-permissions [--dry-run] [--force]    Fix /opt/shipard and /etc/shipard');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
