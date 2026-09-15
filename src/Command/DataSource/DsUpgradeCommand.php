<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\SchemaComparator;
use Shipard\Core\Database\SchemaValidator;
use Shipard\Core\Database\SqlGenerator;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Settings\LayerCParameters;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Core\Version;
use Shipard\Module\Core\Mail\AIAnalyzerProvisioner;
use Shipard\Module\Core\Mail\MailRouterProvisioner;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRulesProvisioner;
use Shipard\Module\Core\Units\UnitsProvisioner;
use Shipard\Module\Docs\Core\BoundNumberSeriesProvisioner;
use Shipard\Module\Docs\Core\NumberSeriesProvisioner;
use Shipard\Module\Economy\Accbal\BalancesProvisioner;
use Shipard\Module\Economy\Accbal\ClearingInfrastructureProvisioner;
use Shipard\Module\Economy\Accounting\AccountChartProvisioner;
use Shipard\Module\Economy\Accounting\TransitAccountsProvisioner;
use Shipard\Module\Economy\Codebooks\FiscalYearsProvisioner;
use Shipard\Module\Economy\Items\ItemKindsProvisioner;
use Shipard\Module\Economy\Vat\ReportPeriodsProvisioner;
use Shipard\Module\Economy\Vat\VatOutputsMapping;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DsUpgradeCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-upgrade')
             ->setDescription('Upgrade the data source schema and configuration');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function getModulePathResolver(): ModulePathResolver
    {
        $cfg = $this->serverConfig;
        if ($cfg === null) {
            $cfg = new ServerConfig();
            $cfg->load();
        }
        return ModulePathResolver::fromServerConfig($cfg, dirname(__DIR__, 3) . '/modules');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        $modulePathResolver = $this->getModulePathResolver();

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $output->writeln('<info>Shipard Data Source Upgrade v' . Version::VERSION . '</info>', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Data source: ' . $dsConfig->getName() . ' (' . $dsConfig->getId() . ')', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);

        // Ensure writable directories exist (att, branding, cache) with the
        // PermissionSpec mode — mkdir alone is subject to umask, so chmod
        // explicitly (also converges dirs created by older versions).
        foreach (['att', 'branding', 'cache', 'cache/thumbnails', 'cache/oidc'] as $subdir) {
            $dirPath = $dsDir . '/' . $subdir;
            if (!is_dir($dirPath)) {
                @mkdir($dirPath, 0755, true);
            }
            @chmod($dirPath, 0750);
        }

        // Step 1.5: Ensure per-DS secrets key exists (generated for legacy DS
        // upgraded from before encrypted_text was introduced).
        $secretsKeyFile = DsSecretCipher::keyFilePath($dsDir);
        if (!is_file($secretsKeyFile)) {
            try {
                $warnings = DsSecretCipher::generateKey($dsDir);
                $output->writeln('  [INFO] Created secrets/secrets.key — no data migration needed');
                $output->writeln('         (no encrypted columns existed in this DS yet).');
                foreach ($warnings as $warning) {
                    $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
                }
            } catch (\RuntimeException $e) {
                $output->writeln('<error>Failed to initialise secrets key: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }
        }

        // Step 2: Resolve modules
        $output->writeln('Resolving modules...', OutputInterface::VERBOSITY_VERBOSE);
        $allModules = ModuleLoader::loadAllModules($modulePathResolver);
        $errors = [];
        $resolvedModules = ModuleResolver::resolve($allModules, $dsConfig->getModules(), $errors);

        foreach ($errors as $error) {
            $output->writeln('<error>' . $error . '</error>');
        }

        $directCount = count($dsConfig->getModules());
        $totalCount = count($resolvedModules);
        $depCount = $totalCount - $directCount;
        $output->writeln('  Active modules: ' . $totalCount . ' (' . $directCount . ' direct + ' . $depCount . ' dependencies)', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('  Module order: ' . implode(', ', array_map(fn($m) => $m->id, $resolvedModules)), OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);

        // Step 3: Load table definitions and apply extensions
        $rawTables = [];
        $tableDefs = [];

        foreach ($resolvedModules as $module) {
            $modulePath = $modulePathResolver->getPath($module->id);
            if ($modulePath === null) continue;

            foreach ($module->tables as $tableFile) {
                $filePath = $modulePath . '/tables/' . $tableFile . '.jsonc';
                $raw = JsoncParser::parseFile($filePath);
                $rawTables[$tableFile] = $raw;
                $tableDefs[$tableFile] = TableDefinition::fromArray($raw);
            }
        }

        foreach ($resolvedModules as $module) {
            $modulePath = $modulePathResolver->getPath($module->id);
            if ($modulePath === null) continue;

            foreach ($module->extensions as $extFile) {
                $filePath = $modulePath . '/extensions/' . $extFile . '.jsonc';
                $extData = JsoncParser::parseFile($filePath);
                $ext = ExtensionDefinition::fromArray($extData);

                if (isset($tableDefs[$ext->table])) {
                    $tableDefs[$ext->table] = TableMerger::merge($tableDefs[$ext->table], $ext);
                }
            }
        }

        // Step 4: Validate
        $validation = SchemaValidator::validate(array_values($rawTables));
        if (!empty($validation['errors'])) {
            foreach ($validation['errors'] as $error) {
                $output->writeln('<error>' . $error . '</error>');
            }
            return Command::FAILURE;
        }
        foreach ($validation['warnings'] as $warning) {
            $output->writeln('<comment>' . $warning . '</comment>');
        }

        // Step 5: Compile configuration
        $output->writeln('Compiling configuration...', OutputInterface::VERBOSITY_VERBOSE);
        $languages = ['cs', 'en'];
        $outputPath = $dsDir . '/config/configuration';

        // Schémata strukturovaných polí (#74) sbíráme z UŽ MERGNUTÝCH definic —
        // sloupec se `schema` může přijít i z extension (profil podatele).
        $structuredSchemas = [];
        foreach ($tableDefs as $tableName => $tableDef) {
            foreach ($tableDef->getStructuredColumns() as $colId => $cfgId) {
                $structuredSchemas[$cfgId] = $tableName . '.' . $colId;
            }
        }

        try {
            ConfigCompiler::compile(
                $resolvedModules, $modulePathResolver, $languages, $outputPath, $structuredSchemas,
            );
        } catch (\RuntimeException $e) {
            // Nevalidní schéma = upgrade se zastaví s cestou k chybě; bez
            // validace by se sloupec ukládal bez kontroly hodnot.
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $configItemCount = 0;
        foreach ($resolvedModules as $module) {
            $configItemCount += count($module->config);
        }

        $output->writeln('  Config items: ' . $configItemCount, OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('  Languages: ' . implode(', ', $languages), OutputInterface::VERBOSITY_VERBOSE);
        foreach ($languages as $lang) {
            $output->writeln('  Written to: config/configuration/compiled.' . $lang . '.json', OutputInterface::VERBOSITY_VERBOSE);
        }
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);

        // Step 6: Sync database schema
        $output->writeln('Checking database...', OutputInterface::VERBOSITY_VERBOSE);
        $created = 0;
        $altered = 0;
        $unchanged = 0;

        foreach ($tableDefs as $tableName => $tableDef) {
            $existingColumns = $dsConnection->getTableColumns($tableName);
            $existingIndexes = $dsConnection->getTableIndexes($tableName);
            $existingNullability = $dsConnection->getTableColumnsNullability($tableName);
            $ops = SchemaComparator::compare($tableDef, $existingColumns, $existingIndexes, $existingNullability);

            if (empty($ops)) {
                $output->writeln('  [OK]     ' . $tableName, OutputInterface::VERBOSITY_VERBOSE);
                $unchanged++;
                continue;
            }

            $hasCreate = false;
            foreach ($ops as $op) {
                if ($op['op'] === 'create_table') {
                    $hasCreate = true;
                    break;
                }
            }

            if ($hasCreate) {
                $sql = SqlGenerator::generateCreateTable($tableName, $tableDef);
                $dsConnection->executeSQL($sql);

                foreach ($tableDef->indexes as $index) {
                    $idxSql = SqlGenerator::generateCreateIndex($tableName, $index);
                    $dsConnection->executeSQL($idxSql);
                }

                $output->writeln('  [CREATE] ' . $tableName);
                foreach ($tableDef->columns as $col) {
                    if ($col->type === 'encrypted_text') {
                        $this->logEncryptedColumnAdded($output, $tableName, $col->id);
                    }
                }
                $created++;
            } else {
                $changes = [];
                $newEncryptedColumns = [];
                foreach ($ops as $op) {
                    if ($op['op'] === 'add_column') {
                        $sql = SqlGenerator::generateAddColumn($tableName, $op['column']);
                        $dsConnection->executeSQL($sql);
                        $changes[] = 'added column: ' . $op['column']->id . ' (' . $op['column']->type . ')';
                        if ($op['column']->type === 'encrypted_text') {
                            $newEncryptedColumns[] = $op['column']->id;
                        }
                    } elseif ($op['op'] === 'modify_column') {
                        $sql = SqlGenerator::generateModifyColumn($tableName, $op['column']);
                        $dsConnection->executeSQL($sql);
                        $changes[] = 'modified column: ' . $op['column']->id;
                    } elseif ($op['op'] === 'create_index') {
                        $sql = SqlGenerator::generateCreateIndex($tableName, $op['index']);
                        $dsConnection->executeSQL($sql);
                        $changes[] = 'added index: ' . $op['index']->id;
                    }
                }
                $output->writeln('  [ALTER]  ' . $tableName . ' — ' . implode(', ', $changes));
                foreach ($newEncryptedColumns as $columnId) {
                    $this->logEncryptedColumnAdded($output, $tableName, $columnId);
                }
                $altered++;
            }
        }

        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Upgrade complete. ' . $created . ' tables created, ' . $altered . ' tables altered, ' . $unchanged . ' tables unchanged.');

        // Backfill doc_state_changed_at for rows that pre-date the column.
        // Idempotent: after first run no NULL rows remain, second run is no-op.
        // Without it, StaleInRepairCheck would silently ignore those rows forever.
        if ($this->isModuleActive($resolvedModules, 'docs.core')) {
            $dsConnection->executeSQL('UPDATE docs_core_heads SET doc_state_changed_at = NOW() WHERE doc_state_changed_at IS NULL');

            // #63: mód 2 (matematicky na 0,01) sloučen do 0 (na haléře) — byly
            // totožné, viz RoundingModes. Idempotentní: po prvním běhu žádný
            // řádek s 2 nezbývá, druhý běh je no-op.
            $dsConnection->executeSQL('UPDATE docs_core_heads SET total_rounding_mode = 0 WHERE total_rounding_mode = 2');
            $dsConnection->executeSQL('UPDATE docs_core_heads SET vat_rounding_mode = 0 WHERE vat_rounding_mode = 2');
        }

        // Clearing infrastruktura (261200/261300 + saldo skupina unmatched_payments)
        // se zajišťuje BEZPODMÍNEČNĚ — i pod skipProvisioning. Není to migrovaná
        // data, ale enginový kontrakt modulů bank/accbal. Bez ní bankovní engine
        // spadne na account_not_found a matcher úhrad najde nula kandidátů.
        // Idempotentní; na normálním DS no-op (full provisionery v else větvi pak
        // skupinu/účty přeskočí dle number/code).
        $this->provisionClearingInfrastructure($resolvedModules, $dsConnection, $output);

        // Tranzitní účty 261100 (převody) / 261400 (karty) + syntetika 261 —
        // BEZPODMÍNEČNĚ, i pod skipProvisioning (#59 Task E): předpis na ně
        // míří pevnou maskou, migrovaný rozvrh je nemá (staré 261001/261002)
        // a AccountChartProvisioner pod skipProvisioning neběží. Idempotentní.
        $this->provisionTransitAccounts($resolvedModules, $dsConnection, $output);

        // AI analyzer (user, backend, profil + version sync ze šablony) se
        // zajišťuje BEZPODMÍNEČNĚ — i pod skipProvisioning. Není to migrovaná
        // data, ale systémový kontrakt modulů core.mail/core.ai. Idempotentní;
        // klíče (backend key, analyzer API key) přežívají ds-reset přes
        // keepOnReset, takže po resetu není potřeba žádná ruční akce.
        $this->provisionAiAnalyzer($resolvedModules, $dsConnection, $output);

        // Systémová pravidla předzpracování pošty (tasks/mail-preprocess.md
        // §2) — BEZPODMÍNEČNĚ, i pod skipProvisioning: import-mode DS
        // přijímá poštu stejně. Idempotentní upsert dle rule_id,
        // archivované se nekřísí.
        $this->provisionPreprocessRules($resolvedModules, $dsConnection, $output);

        // Řady vázané na pokladnu/sklad (docTypes[].series_binding) —
        // BEZPODMÍNEČNĚ, i pod skipProvisioning: import dokladů ze starého
        // systému dohledává řadu podle (typ, kód pokladny), takže řady musí
        // existovat před importem (#59 D12). Idempotentní, per aktivní entita.
        $this->provisionDocCoreBoundNumberSeries($resolvedModules, $dsDir, $dsConnection, $output);

        // Parametry vrstvy C (docs/ds-setup.md §5.2) — jedna instance kvůli
        // request-level cache; provisionery jen čtou, žádný set se tu neděje.
        $settings = new SettingsStore($dsConnection);

        if ($dsConfig->shouldSkipProvisioning()) {
            $output->writeln('');
            $output->writeln("<comment>[SKIP] Provisioning disabled via config (skipProvisioning=true).</comment>");
            $output->writeln("<comment>       No reference data (units, item kinds, fiscal years, VAT report periods,</comment>");
            $output->writeln("<comment>       number series, mail router) was generated.</comment>");
            $output->writeln("<comment>       Set skipProvisioning=false in config/main.json and re-run</comment>");
            $output->writeln("<comment>       ds-upgrade once the import is complete.</comment>");
            // Saldokontní skupiny se pod skipProvisioning nezakládají, ale
            // existující dostanou chybějící účty seedu (#72 D6: 315 v
            // Pohledávkách je enginový kontrakt, ne migrovaná data).
            $this->provisionAccbalBalances($resolvedModules, $dsConnection, $output, createGroups: false);
        } else {
            $this->provisionUnits($resolvedModules, $dsConnection, $output);
            $this->provisionItemKinds($resolvedModules, $dsConnection, $output);
            $this->provisionAccountChart($resolvedModules, $settings, $dsConnection, $output);
            $this->provisionAccbalBalances($resolvedModules, $dsConnection, $output);
            $this->provisionFiscalYears($resolvedModules, $dsDir, $settings, $dsConnection, $output);
            $this->provisionVatPeriods($resolvedModules, $dsDir, $dsConnection, $output);
            $this->provisionDocCoreNumberSeries($resolvedModules, $dsDir, $dsConnection, $output);
            $this->provisionMailRouter($resolvedModules, $dsConfig, $dsConnection, $output);
        }

        $secretsWarnings = DsSecretCipher::healthCheck($dsConfig);
        foreach ($secretsWarnings as $warning) {
            $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
        }

        // Warning, ne chyba — ds-upgrade musí na DS založených před
        // ds-setup Task 01 dál projít (fallback zanikne po reimportu, D9).
        if (!$dsConfig->hasCountry()) {
            $output->writeln('<comment>  [WARN] main.json neobsahuje `country` — '
                . 'používá se přechodný fallback \'cz\'. Doplň hodnotu ručně nebo '
                . 'reimportem (docs/ds-setup.md D9).</comment>');
        }

        // Nerozhodnuté parametry vrstvy C — [TODO], ne [WARN]: není to
        // porucha, je to nedokončené nastavení (D2/D6). Na nastaveném DS ticho.
        $undecided = [];
        foreach (LayerCParameters::SPECS as $paramKey => $spec) {
            if (!$this->isModuleActive($resolvedModules, $spec['module']) || LayerCParameters::isOptional($paramKey)) {
                continue;
            }
            if ($settings->get($paramKey) === null) {
                $undecided[$paramKey] = $spec['example'];
            }
        }
        if ($undecided !== []) {
            $output->writeln('<comment>  [TODO] Nerozhodnuté parametry (docs/ds-setup.md §5.2):</comment>');
            foreach ($undecided as $paramKey => $example) {
                $output->writeln(sprintf(
                    '<comment>         %-29s bin/shpd-ds ds-setting set %s %s</comment>',
                    $paramKey,
                    $paramKey,
                    $example,
                ));
            }
        }

        return Command::SUCCESS;
    }

    private function logEncryptedColumnAdded(OutputInterface $output, string $table, string $column): void
    {
        $output->writeln(sprintf("  [INFO] Adding encrypted_text column '%s.%s'.", $table, $column));
        $output->writeln('         Application layer must use DsSecretCipher for read/write.');
        $output->writeln('         Plaintext values will not be readable from DB directly.');
    }

    /**
     * @param array{created: int, existing: int} $stats
     */
    private function logProvisioningResult(
        OutputInterface $output,
        string $label,
        array $stats,
    ): void {
        if ($stats['created'] > 0) {
            $output->writeln(sprintf(
                '  [CREATE] %s — created: %d, existing: %d',
                $label,
                $stats['created'],
                $stats['existing'],
            ));
        } else {
            $output->writeln(sprintf(
                '  [OK]     %s — created: 0, existing: %d',
                $label,
                $stats['existing'],
            ), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionMailRouter(
        array $resolvedModules,
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning mail router...', OutputInterface::VERBOSITY_VERBOSE);

        // Bez core.mail neexistuje core_mail_mailboxes — DS bez mail modulu
        // (např. install.hosting) by tu spadl.
        if (!$this->isModuleActive($resolvedModules, 'core.mail')) {
            $output->writeln('  <comment>[SKIP] core.mail module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $provisioner = new MailRouterProvisioner($dsConnection);
        $result = $provisioner->provision($dsConfig->getId());

        $user = $result['user'];
        $mailbox = $result['mailbox'];

        if ($user['created']) {
            $output->writeln("  [CREATE] user '_mail_router' (id={$user['id']})");
        } else {
            $output->writeln("  [OK]     user '_mail_router' (id={$user['id']})", OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($mailbox['created']) {
            $output->writeln("  [CREATE] mailbox 'default' (id={$mailbox['id']})");
        } elseif (isset($mailbox['skipped_reason'])) {
            $output->writeln("  <comment>[SKIP]   mailbox 'default' — {$mailbox['skipped_reason']}</comment>");
        } else {
            $output->writeln("  [OK]     mailbox 'default' (id={$mailbox['id']})", OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionPreprocessRules(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        if (!$this->isModuleActive($resolvedModules, 'core.mail')) {
            return;
        }

        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning mail preprocess rules...', OutputInterface::VERBOSITY_VERBOSE);

        $result = new PreprocessRulesProvisioner($dsConnection)->provision();

        foreach ($result['created'] as $ruleId) {
            $output->writeln("  [CREATE] preprocess rule '{$ruleId}'");
        }
        foreach ($result['activated'] as $ruleId) {
            $output->writeln("  [ACTIVATE] preprocess rule '{$ruleId}' (catalog phase reached — unarchived)");
        }
        foreach ($result['updated'] as $ruleId) {
            $output->writeln("  [UPDATE] preprocess rule '{$ruleId}'");
        }
        foreach ($result['skipped'] as $ruleId) {
            $output->writeln("  [SKIP]   preprocess rule '{$ruleId}' (not in confirmed state — left as is)", OutputInterface::VERBOSITY_VERBOSE);
        }
        foreach ($result['unchanged'] as $ruleId) {
            $output->writeln("  [OK]     preprocess rule '{$ruleId}'", OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    private function provisionAiAnalyzer(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning AI analyzer...', OutputInterface::VERBOSITY_VERBOSE);

        // core.mail deklaruje dependency na core.ai — druhá podmínka je
        // defenzivní. Bez guardu by bezpodmínečné volání na DS bez mail
        // modulu spadlo na chybějících tabulkách.
        if (!$this->isModuleActive($resolvedModules, 'core.mail')
            || !$this->isModuleActive($resolvedModules, 'core.ai')
        ) {
            $output->writeln('  <comment>[SKIP] core.mail / core.ai module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $provisioner = new AIAnalyzerProvisioner($dsConnection);
        $result = $provisioner->provision();

        $user = $result['user'];
        $backend = $result['backend'];
        $profile = $result['profile'];

        if ($user['created']) {
            $output->writeln("  [CREATE] user '_ai_analyzer' (id={$user['id']})");
        } else {
            $output->writeln("  [OK]     user '_ai_analyzer' (id={$user['id']})", OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($backend['created']) {
            $output->writeln("  [CREATE] backend 'default' (id={$backend['id']})");
            $output->writeln("           <comment>API key not set — run 'bin/shpd-ds ai-analyzer-set-key' to enable.</comment>");
        } elseif (isset($backend['skipped_reason'])) {
            $output->writeln("  <comment>[SKIP]   backend 'default' — {$backend['skipped_reason']}</comment>");
        } else {
            $output->writeln("  [OK]     backend 'default' (id={$backend['id']})", OutputInterface::VERBOSITY_VERBOSE);
        }

        // Jednorázový rename legacy profilu (czech_invoices → czech_general),
        // po prvním běhu 0 řádků = žádný výpis (viz AIAnalyzerProvisioner).
        $renamed = $result['profile_rename']['renamed'] ?? 0;
        if ($renamed > 0) {
            $output->writeln("  [RENAME] profile 'czech_invoices' → '{$profile['profile_id']}'");
        }

        if ($profile['created']) {
            $output->writeln("  [CREATE] profile '{$profile['profile_id']}' (id={$profile['id']})");
        } elseif (isset($profile['skipped_reason'])) {
            $output->writeln("  <comment>[SKIP]   profile '{$profile['profile_id']}' — {$profile['skipped_reason']}</comment>");
        } else {
            $output->writeln("  [OK]     profile '{$profile['profile_id']}' (id={$profile['id']})", OutputInterface::VERBOSITY_VERBOSE);
        }

        // Datová oprava nafrontovaných archivních zpráv — idempotentní,
        // po prvním běhu no-op (viz AIAnalyzerProvisioner).
        $queueFixed = $result['queue_fix']['fixed'] ?? 0;
        if ($queueFixed > 0) {
            $output->writeln("  [FIX]    analysis_state 10 → 0 for {$queueFixed} archived/trashed message(s)");
        } else {
            $output->writeln('  [OK]     no queued archived messages', OutputInterface::VERBOSITY_VERBOSE);
        }

        // Sync obsahových polí profilu ze šablony v repu — upgrade-only,
        // nikdy downgrade (na ten je 'ai-profile-reload --force'). Rozbitá
        // šablona nesmí shodit celý ds-upgrade.
        try {
            $sync = $provisioner->syncProfileFromTemplate();
        } catch (\RuntimeException $e) {
            $output->writeln("  <comment>[WARN]   profile template — {$e->getMessage()}</comment>");
            return;
        }

        match ($sync['status']) {
            'updated' => $output->writeln(
                "  [UPDATE] profile '{$sync['profile_id']}': {$sync['old_version']} → {$sync['new_version']}",
            ),
            'up_to_date' => $output->writeln(
                "  [OK]     profile '{$sync['profile_id']}' at {$sync['old_version']}",
                OutputInterface::VERBOSITY_VERBOSE,
            ),
            'db_newer' => $output->writeln(
                "  <comment>[WARN]   profile '{$sync['profile_id']}': DB version {$sync['old_version']} is newer than template {$sync['new_version']} — not downgrading. Use 'ai-profile-reload --force' if intended.</comment>",
            ),
            'not_found' => null,
        };
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionUnits(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning units...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'core.units')) {
            $output->writeln('  <comment>[SKIP] core.units module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $seedFile = $this->getModulePathResolver()->getPath('core.units') . '/config/unitsSeed.jsonc';
        $provisioner = new UnitsProvisioner($dsConnection, $seedFile);
        $result = $provisioner->provision();

        $this->logProvisioningResult($output, 'units', $result['units']);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionItemKinds(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning economy.items kinds...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.items')) {
            $output->writeln('  <comment>[SKIP] economy.items module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $seedFile = $this->getModulePathResolver()->getPath('economy.items') . '/config/itemKindsSeed.jsonc';
        $provisioner = new ItemKindsProvisioner($dsConnection, $seedFile);
        $result = $provisioner->provision();

        $this->logProvisioningResult($output, 'item kinds', $result['kinds']);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionAccountChart(
        array $resolvedModules,
        SettingsStore $settings,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning economy.accounting chart of accounts...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.accounting')) {
            $output->writeln('  <comment>[SKIP] economy.accounting module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        // Absence klíče = nerozhodnuto (D2) — bez rozhodnutí se neseeduje (D6).
        // Vypisuje se vždy (ne jen verbose): na čerstvém DS to chce vidět každý.
        $variant = $settings->get('economy.accountChart');
        if ($variant === null) {
            $output->writeln('  <comment>[SKIP] economy.accountChart není rozhodnuto '
                . '— osnova se neseeduje (docs/ds-setup.md D6).</comment>');
            return;
        }

        if ($variant === 'none') {
            $output->writeln(
                "  <comment>[SKIP] accountChart='none' — standardní osnova se neseeduje.</comment>",
                OutputInterface::VERBOSITY_VERBOSE,
            );
            return;
        }

        $file = match ($variant) {
            'default' => 'accountChartDefault.jsonc',
            'npo'     => 'accountChartNpo.jsonc',
            default   => null,
        };
        if ($file === null) {
            // Hodnotu validuje ds-setting při zápisu — neznámá varianta je
            // porucha, ne stav k tichému dorovnání na default.
            $output->writeln("  <comment>[WARN] Unknown economy.accountChart variant '{$variant}' — nothing seeded.</comment>");
            return;
        }

        $seedFile = $this->getModulePathResolver()->getPath('economy.accounting') . '/config/' . $file;
        if (!is_file($seedFile)) {
            $output->writeln("  <comment>[SKIP] Account chart seed file not found: {$file}</comment>");
            return;
        }

        $provisioner = new AccountChartProvisioner($dsConnection, $seedFile);
        $result = $provisioner->provision();

        $this->logProvisioningResult($output, 'account chart', $result['accountChart']);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionClearingInfrastructure(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning clearing infrastructure...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.accbal')
            || !$this->isModuleActive($resolvedModules, 'economy.accounting')
        ) {
            $output->writeln(
                '  <comment>[SKIP] economy.accbal / economy.accounting module not active</comment>',
                OutputInterface::VERBOSITY_VERBOSE,
            );
            return;
        }

        $provisioner = new ClearingInfrastructureProvisioner($dsConnection);
        $result = $provisioner->provision();

        $this->logProvisioningResult($output, 'clearing accounts', $result['accounts']);
        $this->logProvisioningResult($output, 'clearing balance group', $result['group']);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionTransitAccounts(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning transit accounts (261100)...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.accounting')) {
            $output->writeln(
                '  <comment>[SKIP] economy.accounting module not active</comment>',
                OutputInterface::VERBOSITY_VERBOSE,
            );
            return;
        }

        $result = (new TransitAccountsProvisioner($dsConnection))->provision();

        $this->logProvisioningResult($output, 'transit accounts', $result);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionAccbalBalances(
        array $resolvedModules,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
        bool $createGroups = true,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning economy.accbal balances...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.accbal')) {
            $output->writeln('  <comment>[SKIP] economy.accbal module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $seedFile = $this->getModulePathResolver()->getPath('economy.accbal') . '/config/balancesDefault.cz.jsonc';
        if (!is_file($seedFile)) {
            $output->writeln('  <comment>[SKIP] Balances seed file not found: balancesDefault.cz.jsonc</comment>');
            return;
        }

        $provisioner = new BalancesProvisioner($dsConnection, $seedFile);
        $result = $provisioner->provision($createGroups);

        if ($createGroups) {
            $this->logProvisioningResult($output, 'accbal balances', $result['balances']);
        }
        if (($result['accounts']['added'] ?? 0) > 0) {
            $output->writeln(sprintf(
                '  [CREATE] accbal balance accounts — added to existing groups: %d',
                $result['accounts']['added'],
            ));
        }
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionFiscalYears(
        array $resolvedModules,
        string $dsDir,
        SettingsStore $settings,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning economy.codebooks fiscal years...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.codebooks')) {
            $output->writeln('  <comment>[SKIP] economy.codebooks module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $compiledFile = $dsDir . '/config/configuration/compiled.cs.json';
        if (!is_file($compiledFile)) {
            $output->writeln('  <comment>[SKIP] config not compiled yet</comment>');
            return;
        }

        // Absence klíče = nerozhodnuto (D2) — fiskální roky se odkládají za
        // rozhodnutí o prvním měsíci (D6) I o domácí měně: měna je součástí
        // zakládaného záznamu, seedovat s odhadnutou by D2 obcházelo.
        // Vypisuje se vždy (ne jen verbose).
        $startMonth = $settings->get('economy.fiscalYearStartMonth');
        $homeCurrency = $settings->get('economy.homeCurrency');
        $missing = [];
        if ($startMonth === null) {
            $missing[] = 'economy.fiscalYearStartMonth';
        }
        if (!is_string($homeCurrency) || $homeCurrency === '') {
            $missing[] = 'economy.homeCurrency';
        }
        if ($missing !== []) {
            $output->writeln('  <comment>[SKIP] ' . implode(', ', $missing) . ' není rozhodnuto '
                . '— fiskální roky se neseedují (docs/ds-setup.md D6).</comment>');
            return;
        }

        $config = ConfigRuntime::load($dsDir, 'cs');
        $provisioner = new FiscalYearsProvisioner($dsConnection, $config, (int) $startMonth, $homeCurrency);
        $result = $provisioner->provision();

        $this->logProvisioningResult($output, 'fiscal years', $result['fiscalYears']);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionVatPeriods(
        array $resolvedModules,
        string $dsDir,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning economy.vat report periods...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'economy.vat')) {
            $output->writeln('  <comment>[SKIP] economy.vat module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        // Instance tvrzení pokrývající dnešek a zítřek pro aktivní registrace
        // (D9) — stejná logika jako denní cron vat-periods-ensure. Zákonné
        // počátky výstupů (#58) z právě zkompilovaného configu.
        $validFrom = VatOutputsMapping::fromConfig(ConfigRuntime::load($dsDir, 'cs'))?->validFromByType() ?? [];
        $result = (new ReportPeriodsProvisioner($dsConnection, $validFrom))->ensureAll();

        $this->logProvisioningResult($output, 'vat report periods', $result);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionDocCoreNumberSeries(
        array $resolvedModules,
        string $dsDir,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning docs.core number series...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'docs.core')) {
            $output->writeln('  <comment>[SKIP] docs.core module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        $compiledFile = $dsDir . '/config/configuration/compiled.cs.json';
        if (!is_file($compiledFile)) {
            $output->writeln('  <comment>[SKIP] config not compiled yet</comment>');
            return;
        }

        $config = ConfigRuntime::load($dsDir, 'cs');
        $provisioner = new NumberSeriesProvisioner($dsConnection, $config);
        $result = $provisioner->provision();

        $this->logProvisioningResult($output, 'number series', $result['numberSeries']);
    }

    /**
     * Řady vázané na entitu (pokladna/sklad) — běží mimo skipProvisioning gate,
     * viz komentář u volání v execute().
     *
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function provisionDocCoreBoundNumberSeries(
        array $resolvedModules,
        string $dsDir,
        DataSourceConnection $dsConnection,
        OutputInterface $output,
    ): void {
        $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('Provisioning docs.core bound number series...', OutputInterface::VERBOSITY_VERBOSE);

        if (!$this->isModuleActive($resolvedModules, 'docs.core')) {
            $output->writeln('  <comment>[SKIP] docs.core module not active</comment>', OutputInterface::VERBOSITY_VERBOSE);
            return;
        }

        if (!is_file($dsDir . '/config/configuration/compiled.cs.json')) {
            $output->writeln('  <comment>[SKIP] config not compiled yet</comment>');
            return;
        }

        $config = ConfigRuntime::load($dsDir, 'cs');
        $result = (new BoundNumberSeriesProvisioner($dsConnection, $config))->provision();

        $this->logProvisioningResult($output, 'bound number series', $result);
    }

    /**
     * @param list<\Shipard\Core\Module\ModuleDefinition> $resolvedModules
     */
    private function isModuleActive(array $resolvedModules, string $moduleId): bool
    {
        foreach ($resolvedModules as $module) {
            if ($module->id === $moduleId) {
                return true;
            }
        }
        return false;
    }
}
