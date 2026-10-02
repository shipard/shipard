<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reload AI profilu z JSONC šablony do DB. Aktualizuje prompt_template,
 * prompt_version, output_schema, supported_doc_types, confidence_thresholds
 * a language. Nepřepisuje admin-controlled pole (name, is_default, is_active,
 * backend) — admin si je mohl lokálně upravit.
 *
 * Tenký wrapper nad AIAnalyzerProvisioner::syncProfileFromTemplate() —
 * tutéž logiku volá automaticky ds-upgrade (upgrade-only). Manuální příkaz
 * slouží pro --force (downgrade / same-version overwrite), --dry-run
 * a --template-path scénáře.
 *
 * Workflow je popsán v modules/core/mail/docs/ai-prompts.md (sekce
 * "Iterativní ladění promptu"). Specifikace: tasks/ai-profile-reload.md,
 * tasks/ai-profile-sync-in-ds-upgrade.md.
 */
class AiProfileReloadCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ai-profile-reload')
             ->setDescription('Reload AI profile from JSONC template into the DB')
             ->addOption(
                 'profile',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Profile code (default: code from template, typically "czech_general")',
             )
             ->addOption(
                 'template-path',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Path to JSONC template (default: modules/core/mail/profiles/czech_general.jsonc)',
             )
             ->addOption(
                 'force',
                 null,
                 InputOption::VALUE_NONE,
                 'Overwrite even if DB version >= template version',
             )
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 'Show what would change without writing',
             );
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $templatePath = $input->getOption('template-path');
        try {
            $template = AIAnalyzerProvisioner::loadProfileTemplate(
                $templatePath !== null ? (string) $templatePath : null,
            );
        } catch (\RuntimeException $e) {
            $output->writeln('<error>Template error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $templateProfileCode = (string) $template['profile_id'];
        $optionProfile = $input->getOption('profile');
        $profileCode = $optionProfile !== null ? (string) $optionProfile : $templateProfileCode;

        if ($optionProfile !== null && $optionProfile !== $templateProfileCode) {
            $output->writeln(
                "<error>Profile mismatch: --profile='{$optionProfile}' but template profile_id='{$templateProfileCode}'.</error>",
            );
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $row = $dsConnection->fetchRow(
            'SELECT id, prompt_version, prompt_template FROM core_mail_ai_profiles WHERE profile_id = %s',
            $profileCode,
        );
        if ($row === null) {
            $output->writeln("<error>Error: profile '{$profileCode}' not found.</error>");
            $output->writeln('<comment>Run "shpd-ds ai-analyzer-bootstrap" first.</comment>');
            return Command::FAILURE;
        }

        $profileId = (int) $row['id'];
        $currentVersion = (string) $row['prompt_version'];
        $newVersion = (string) $template['prompt_version'];
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');

        $cmp = AIAnalyzerProvisioner::compareVersions($newVersion, $currentVersion);

        if ($cmp === 0 && !$force) {
            $output->writeln(
                "Profile '{$profileCode}' (id={$profileId}) is already at version {$currentVersion}. Use --force to overwrite.",
            );
            return Command::SUCCESS;
        }

        if ($cmp < 0 && !$force) {
            $output->writeln(
                "<error>DB version {$currentVersion} is newer than template {$newVersion} for profile '{$profileCode}'. Use --force to downgrade.</error>",
            );
            return Command::FAILURE;
        }

        $oldLen = strlen((string) $row['prompt_template']);
        $newLen = strlen((string) $template['prompt_template']);

        if ($dryRun) {
            $output->writeln("<comment>Dry-run — no changes written.</comment>");
            $output->writeln("Would update profile '{$profileCode}' (id={$profileId}):");
            $output->writeln("  prompt_version: {$currentVersion} → {$newVersion}");
            $output->writeln("  prompt_template length: {$oldLen} → {$newLen} chars");
            return Command::SUCCESS;
        }

        $provisioner = new AIAnalyzerProvisioner($dsConnection);
        $sync = $provisioner->syncProfileFromTemplate(
            $templatePath !== null ? (string) $templatePath : null,
            force: $force,
        );

        if ($sync['status'] !== 'updated') {
            $output->writeln(
                "<error>Unexpected sync status '{$sync['status']}' for profile '{$profileCode}' — nothing written.</error>",
            );
            return Command::FAILURE;
        }

        $output->writeln(
            "<info>Updated profile '{$profileCode}' (id={$profileId}): {$currentVersion} → {$newVersion}</info>",
        );
        $output->writeln('Re-queue messages via UI ("Znovu analyzovat") or SQL to apply the new prompt.');

        return Command::SUCCESS;
    }
}
