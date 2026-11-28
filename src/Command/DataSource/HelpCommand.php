<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Cli\ModuleCommandGroup;
use Shipard\Cli\ModuleCommandHelp;
use Shipard\Core\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HelpCommand extends Command
{
    /** Příkaz, který si vyžádal `--help`; null = obecná nápověda. */
    private ?Command $describeCommand = null;

    /** @var list<ModuleCommandGroup> příkazy modulů registrované v aplikaci */
    private array $moduleGroups = [];

    /**
     * Příkazy modulů pro generovanou část nápovědy — factory předá ty,
     * které v aplikaci registrovala (moduly aktivní ve zdroji dat).
     *
     * @param list<ModuleCommandGroup> $groups
     */
    public function setModuleGroups(array $groups): void
    {
        $this->moduleGroups = $groups;
    }

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

        if (!file_exists(getcwd() . '/config/main.json')) {
            $output->writeln('<error>Not a Shipard data source directory (config/main.json not found)</error>');
            return Command::FAILURE;
        }

        $output->writeln('');
        $output->writeln('<info>Shipard Data Source Tool v' . Version::VERSION . '</info>');
        $output->writeln('');
        $output->writeln('<comment>Basic:</comment>');
        $output->writeln('  <info>version</info>                 Show Shipard data source tool version');
        $output->writeln('  <info>help</info>                    Show this help message');
        $output->writeln('  <info>ds-upgrade</info>              Upgrade the data source schema and configuration');
        $output->writeln('  <info>ds-reset</info>                Reset the data source — drop all data tables and recreate them, keeping users, API keys and protected tables');
        $output->writeln('');
        $output->writeln('<comment>Settings:</comment>');
        $output->writeln('  <info>ds-setting</info>              Read and write data source settings: list | get <key> | set <key> <value> [--unset]');
        $output->writeln('  <info>ds-state</info>                Show or change the data source state (config/state.json): show | set <state> [--delete-after] | maintenance --on [--reason] | --off | cron --on | --off');
        $output->writeln('  <info>ds-class</info>                Show or change the data class (config/main.json dataClass free | realCopy): show | set free|realCopy [--purpose=<text>] — set free only in an interactive terminal');
        $output->writeln('  <info>ds-module</info>               List modules of the data source and enable or disable add-ons (config/main.json modules, then ds-upgrade): list [--json] | enable <id> | disable <id> [--no-upgrade]');
        $output->writeln('');
        $output->writeln('<comment>Users:</comment>');
        $output->writeln('  <info>user-create</info>             Create a new user in the data source');
        $output->writeln('  <info>user-set-admin</info>          Grant or revoke administrator rights for a user');
        $output->writeln('  <info>auth-emergency-login</info>    Break-glass: create a session token for a user directly in the DB (bypasses auth policy)');
        $output->writeln('');
        $output->writeln('<comment>API keys:</comment>');
        $output->writeln('  <info>api-key-create</info>          Create a new API key for an existing user');
        $output->writeln('  <info>api-key-list</info>            List API keys in the data source');
        $output->writeln('  <info>api-key-revoke</info>          Revoke (deactivate) an API key');
        $output->writeln('');
        $output->writeln('<comment>Secrets:</comment>');
        $output->writeln('  <info>ds-secret</info>               List or set data source secrets in secrets/ (db.password, mail-relay.password, oidc-<id>.client-secret): list | set <name> — value from a hidden prompt or STDIN, never an argument');
        $output->writeln('  <info>ds-secrets-health</info>       Check health of the per-DS secrets infrastructure');
        $output->writeln('  <info>ds-secrets-rotate</info>       Rotate the per-DS secrets.key — re-encrypts all encrypted_text columns');
        $output->writeln('');
        $output->writeln('<comment>Hosting:</comment>');
        $output->writeln('  <info>hosting-stats</info>           Collect pending-work counts (alerts, mail) for the hosting stats push');
        $output->writeln('');
        $output->writeln('<comment>Reports and prints:</comment>');
        $output->writeln('  <info>report-run</info>              Spustí report a vypíše ReportResult jako JSON na stdout; --param id=hodnota = parametr reportu; --format=xlsx|csv [--output=<soubor>] = export');
        $output->writeln('  <info>report-diff</info>             Porovná dva ReportResult JSON soubory (kontrolní diff)');
        $output->writeln('  <info>print-run</info>               Spustí tisk nad záznamem: PrintData jako JSON na stdout; --format=pdf --output=<soubor> = PDF; --format=html --output=<adresář> = HTML pro vývoj šablon; --data=<PrintData.json> = render bez databáze; --language=cs|en|sk|de; tisk pro účtenkovou tiskárnu: --format=escpos --profile=<id> --output=<soubor> = bajty ESC/POS, --format=markup = výstup šablony');
        $output->writeln('  <info>print-escpos-test</info>       Zkušební účtenka profilu tiskárny jako bajty ESC/POS: --profile=<id> --output=<soubor>');
        $output->writeln('');
        $output->writeln('<comment>Dataset:</comment>');
        $output->writeln('  <info>dataset-dump</info>            Export the whole DS into a portable dataset folder (--zip, --force)');
        $output->writeln('  <info>dataset-seed</info>            Reset the DS and import a dataset folder/zip (--no-reset to merge, -y)');
        $output->writeln('  <info>dataset-verify</info>          Apply the proposals of a seeded dataset and verify accounting, VAT and costs against expected/ (--no-apply, --json, --write-ledger-snapshot)');
        $output->writeln('  <info>dataset-e2e</info>             Reseed the free DS with a dataset, click through every proposal card in the browser (Playwright Test) and run dataset-verify --no-apply (--no-reseed, --base-url, --output-dir, --json, --progress-file; development only, exit 0/1/2/130)');
        $output->writeln('  <info>video-run</info>               Build the demo dataset of a video scenario anchored at today, reseed the free DS, open a session and check or record the video (--verb check|build, --anchor, --base-url, --json, --progress-file; development only, exit 0/1/2/130)');
        $output->writeln('');
        // Příkazy modulů aktivních ve zdroji dat — generované z popisů
        // příkazů (#172 D5); ručně psané jsou jen příkazy jádra výše.
        ModuleCommandHelp::write($output, $this->moduleGroups);
        $output->writeln('<comment>Usage:</comment>');
        $output->writeln('  shpd-ds <command> [options]');
        $output->writeln('');
        $output->writeln('<comment>Examples:</comment>');
        $output->writeln('  shpd-ds ds-upgrade');
        $output->writeln('  shpd-ds ds-reset --dry-run');
        $output->writeln('  shpd-ds ds-reset -y');
        $output->writeln('  shpd-ds ds-setting set economy.accountChart default');
        $output->writeln('  shpd-ds ds-setting list');
        $output->writeln('  shpd-ds ds-state maintenance --on --reason=import');
        $output->writeln('  shpd-ds ds-state cron --off');
        $output->writeln('  shpd-ds ds-class set free --purpose="Ruční zkoušení UI"');
        $output->writeln('  shpd-ds ds-module list');
        $output->writeln('  shpd-ds ds-module enable facility.core');
        $output->writeln('  shpd-ds user-create --login=admin --password=...');
        $output->writeln('  shpd-ds ds-secret set mail-relay.password');
        $output->writeln('  shpd-ds ds-secrets-rotate --dry-run');
        $output->writeln('  shpd-ds dataset-dump /tmp/web-demo --zip');
        $output->writeln('  shpd-ds dataset-seed /tmp/web-demo.zip -y');
        $output->writeln('  shpd-ds dataset-verify /path/to/repo/tests/datasets/cz/vat-payer');
        $output->writeln('  shpd-ds dataset-e2e /path/to/repo/tests/datasets/cz/vat-payer --json');
        $output->writeln('  shpd-ds video-run s1-prijata-faktura --verb build');
        $output->writeln('');
        $output->writeln('<comment>Note:</comment>');
        $output->writeln('  Run from within a data source directory (must contain config/main.json)');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
