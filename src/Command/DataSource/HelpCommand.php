<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

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
        $output->writeln('  <info>ds-state</info>                Show or change the data source state (config/state.json): show | set <state> [--delete-after] | maintenance --on [--reason] | --off');
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
        $output->writeln('  <info>ds-secrets-health</info>       Check health of the per-DS secrets infrastructure');
        $output->writeln('  <info>ds-secrets-rotate</info>       Rotate the per-DS secrets.key — re-encrypts all encrypted_text columns');
        $output->writeln('');
        $output->writeln('<comment>Hosting:</comment>');
        $output->writeln('  <info>hosting-oidc-init</info>       Generate the OIDC OP RSA signing key (secrets/oidc-op.key)');
        $output->writeln('  <info>hosting-oidc-client</info>     Register a data source as an OIDC OP client — set client secret and redirect URI');
        $output->writeln('  <info>hosting-server-key</info>      Generate or revoke the provisioning API key of a hosting server');
        $output->writeln('  <info>hosting-router-key</info>      Generate or revoke the lookup API key of a hosting mail router');
        $output->writeln('  <info>hosting-analyzer-key</info>    Generate or revoke the lookup API key of a hosting AI analyzer');
        $output->writeln('  <info>hosting-ai-gw-init</info>      Manage the AI gateway org key (secrets/ai-gw-anthropic.key)');
        $output->writeln('  <info>hosting-ai-token</info>        Generate or revoke an AI gateway token for a data source');
        $output->writeln('  <info>hosting-stats</info>           Collect pending-work counts (alerts, mail) for the hosting stats push');
        $output->writeln('');
        $output->writeln('<comment>Mail:</comment>');
        $output->writeln('  <info>mail-router-bootstrap</info>   Ensure _mail_router system user and default mailbox exist');
        $output->writeln('  <info>mail-router-setup</info>       Generate (or rotate) the API key used by the external mail-router');
        $output->writeln('  <info>mail-idempotency-prune</info>  Remove expired idempotency keys for incoming mail');
        $output->writeln('  <info>mail-outbox-run</info>         Process due messages in the outbound mail queue');
        $output->writeln('  <info>mail-outbox-retry</info>       Re-queue a failed outbound message');
        $output->writeln('  <info>mail-send-test</info>          Send a test message through the outbound mail transport');
        $output->writeln('  <info>mail-preprocess</info>         Run the stored preprocess plan of a message (--message <id> [--force]) or rescue stuck ones (--sweep)');
        $output->writeln('  <info>mail-target-backfill</info>   Fill partner and title of messages linked to a document (--dry-run, --limit, --batch)');
        $output->writeln('');
        $output->writeln('<comment>Alerts:</comment>');
        $output->writeln('  <info>alerts-run</info>              Run due alert checks (or a single check)');
        $output->writeln('  <info>alerts-prune</info>            Delete resolved/dismissed alerts older than the retention window');
        $output->writeln('');
        $output->writeln('<comment>Economy:</comment>');
        $output->writeln('  <info>bank-import-statement</info>   Import bankovního výpisu ze souboru (CAMT/GPC/FIO)');
        $output->writeln('  <info>accbal-match</info>            Spáruje nespárované bankovní úhrady proti otevřeným předpisům (clearing → 311/321)');
        $output->writeln('  <info>booking-history</info>         Zpracuje soubor účetní historie (report kvality, seed pravidel IČO→štítek, otagování položek)');
        $output->writeln('  <info>report-run</info>              Spustí report a vypíše ReportResult jako JSON na stdout');
        $output->writeln('  <info>report-diff</info>             Porovná dva ReportResult JSON soubory (kontrolní diff)');
        $output->writeln('  <info>vat-periods-ensure</info>      Zajistí instance daňových tvrzení (přiznání/KH/SH) pro dnešek a zítřek — denní cron');
        $output->writeln('  <info>vat-filing-compose</info>      Sestaví podání DPH za instanci tvrzení, nebo přepočítá snapshot konceptu');
        $output->writeln('  <info>vat-filing-files</info>        Vyrobí soubory podání DPH pro daňový portál (XML, PDF opis)');
        $output->writeln('  <info>vat-filing-xml-diff</info>     Porovná dva soubory podání DPH po větách a atributech');
        $output->writeln('  <info>vat-filing-account</info>      Zaúčtuje podané přiznání DPH — účetní doklad (koncept) navázaný na podání; --dry-run jen vypíše řádky');
        $output->writeln('  <info>vat-filing-import</info>       Importuje staré podání DPH z původního XML (snapshot composerem, podané hodnoty z XML, přílohy, Podáno); --dry-run jen porovná');
        $output->writeln('  <info>doc-reaccount</info>           Přegeneruje účetní deník dokladu ve stavu 40; --force obejde zámek období (zaloguje se)');
        $output->writeln('');
        $output->writeln('<comment>Registry (Spisovna):</comment>');
        $output->writeln('  <info>registry-extract-texts</info>  Fill registry documents extracted_text from attachments (default: missing only)');
        $output->writeln('');
        $output->writeln('<comment>AI Analyzer:</comment>');
        $output->writeln('  <info>ai-analyzer-bootstrap</info>   Ensure _ai_analyzer user, default AI backend and default profile exist');
        $output->writeln('  <info>ai-analyzer-setup</info>       Generate (or rotate) the API key used by the external AI analyzer');
        $output->writeln('  <info>ai-analyzer-set-key</info>     Set (or rotate) the API key on an AI backend; encrypts via DsSecretCipher');
        $output->writeln('  <info>ai-profile-reload</info>       Reload AI profile from JSONC template into the DB');
        $output->writeln('  <info>mail-analysis-reap</info>      Release expired AI analysis claims and re-queue affected messages');
        $output->writeln('');
        $output->writeln('<comment>Dataset:</comment>');
        $output->writeln('  <info>dataset-dump</info>            Export the whole DS into a portable dataset folder (--zip, --force)');
        $output->writeln('  <info>dataset-seed</info>            Reset the DS and import a dataset folder/zip (--no-reset to merge, -y)');
        $output->writeln('');
        $output->writeln('<comment>Seed (test data):</comment>');
        $output->writeln('  <info>seed-persons</info>            Generate fake persons with optional contacts and bank accounts');
        $output->writeln('  <info>seed-clear</info>              Remove all seeded test persons (TEST- prefix)');
        $output->writeln('  <info>seed-mail</info>               Generate fake mailboxes and incoming messages for core.mail');
        $output->writeln('  <info>seed-mail-clear</info>         Remove all core.mail seed data (TEST- / TEST-MSG- prefix)');
        $output->writeln('');
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
        $output->writeln('  shpd-ds user-create --login=admin --password=...');
        $output->writeln('  shpd-ds ai-analyzer-set-key --backend default --api-key <api-key>');
        $output->writeln('  shpd-ds ds-secrets-rotate --dry-run');
        $output->writeln('  shpd-ds dataset-dump /tmp/web-demo --zip');
        $output->writeln('  shpd-ds dataset-seed /tmp/web-demo.zip -y');
        $output->writeln('  shpd-ds seed-persons --count=100 --with-contacts --with-bank-accounts');
        $output->writeln('  shpd-ds seed-persons -c 20 --company-ratio=60');
        $output->writeln('  shpd-ds seed-clear');
        $output->writeln('  shpd-ds seed-mail -c 60');
        $output->writeln('  shpd-ds seed-mail-clear');
        $output->writeln('');
        $output->writeln('<comment>Note:</comment>');
        $output->writeln('  Run from within a data source directory (must contain config/main.json)');
        $output->writeln('');

        return Command::SUCCESS;
    }
}
