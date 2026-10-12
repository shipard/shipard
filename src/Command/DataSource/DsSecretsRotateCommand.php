<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\SchemaIntrospector;
use Shipard\Core\Database\SchemaLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Server\FileOwnership;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DsSecretsRotateCommand extends Command
{
    /**
     * @param FileOwnership|null $ownership šev pro testy bez roota; výchozí
     *   implementace volá chown/chgrp (tasks/secrets-rotate-key-owner.md)
     */
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ServerConfig $serverConfig = null,
        private readonly ?FileOwnership $ownership = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-secrets-rotate')
             ->setDescription('Rotate the per-DS secrets.key — re-encrypts all encrypted_text columns')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be re-encrypted without modifying anything');
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
        $dryRun = (bool) $input->getOption('dry-run');
        $dsDir = $this->getDataSourceDir();
        $modulePathResolver = $this->getModulePathResolver();
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);

        if ($dryRun) {
            $output->writeln('<info>Dry-run mode — no changes will be made.</info>');
        }

        try {
            $oldCipher = DsSecretCipher::forConfig($dsConfig);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to load existing secrets.key: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $loaded = SchemaLoader::loadResolvedTables($modulePathResolver, $dsConfig->getModules());
        if (!empty($loaded['errors'])) {
            foreach ($loaded['errors'] as $err) {
                $output->writeln('<error>Module loading: ' . $err . '</error>');
            }
            return Command::FAILURE;
        }

        $encryptedColumns = SchemaIntrospector::findEncryptedColumns($loaded['tables']);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if ($dryRun) {
            return $this->executeDryRun($output, $dsConnection, $encryptedColumns);
        }

        return $this->executeRotation($output, $dsConfig, $dsConnection, $oldCipher, $encryptedColumns, $dsDir);
    }

    /**
     * @param list<array{table: string, column: string}> $encryptedColumns
     */
    private function executeDryRun(
        OutputInterface $output,
        DataSourceConnection $dsConnection,
        array $encryptedColumns,
    ): int {
        $totalRows = 0;
        foreach ($encryptedColumns as $entry) {
            $count = $this->countNonNull($dsConnection, $entry['table'], $entry['column']);
            $output->writeln(sprintf(
                '  Would re-encrypt %s.%s — %d rows',
                $entry['table'], $entry['column'], $count,
            ));
            $totalRows += $count;
        }
        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Dry-run: would re-encrypt %d rows across %d columns. Key file would be rotated.</info>',
            $totalRows, count($encryptedColumns),
        ));
        return Command::SUCCESS;
    }

    /**
     * @param list<array{table: string, column: string}> $encryptedColumns
     */
    private function executeRotation(
        OutputInterface $output,
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        DsSecretCipher $oldCipher,
        array $encryptedColumns,
        string $dsDir,
    ): int {
        $newKeyBytes = random_bytes(DsSecretCipher::KEY_BYTES);
        $newCipher = DsSecretCipher::fromKey($newKeyBytes);

        $keyFile = DsSecretCipher::keyFilePath($dsDir);
        $tmpFile = $keyFile . '.tmp';

        // Step 1: pre-write tmp file BEFORE DB commit so we know disk is writable.
        if (!$this->writeTmpKeyFile($tmpFile, $newKeyBytes, $output)) {
            return Command::FAILURE;
        }

        // Step 1b: the command is documented with sudo, so the tmp file is
        // typically root:root — the runtime (shipard-user) could not read the
        // key after the swap and every encrypted_text column would be lost to
        // it. Align the owner with the data source now, still before any DB
        // change; failure aborts the rotation with nothing modified. The .bak
        // keeps the old key's owner through rename and needs no fix-up.
        try {
            ($this->ownership ?? new FileOwnership())
                ->matchTo($tmpFile, FileOwnership::dataSourceReference($dsDir));
        } catch (\RuntimeException $e) {
            @unlink($tmpFile);
            $output->writeln('<error>Refusing to rotate: the new key would be unreadable for the data source owner.</error>');
            $output->writeln('<error>  ' . $e->getMessage() . '</error>');
            $output->writeln('<error>Nothing was changed (no DB update, secrets.key untouched).</error>');
            return Command::FAILURE;
        }

        // Step 2: DB transaction — re-encrypt all rows.
        $totalRows = 0;
        $dsConnection->begin();
        try {
            foreach ($encryptedColumns as $entry) {
                $rows = $dsConnection->fetchAll(sprintf(
                    'SELECT id, `%s` AS val FROM `%s` WHERE `%s` IS NOT NULL',
                    $entry['column'], $entry['table'], $entry['column'],
                ));
                foreach ($rows as $row) {
                    $plain = $oldCipher->decrypt((string) $row['val']);
                    $newCt = $newCipher->encrypt($plain);
                    $dsConnection->updateWhere(
                        $entry['table'],
                        [$entry['column'] => $newCt],
                        'id = %i', $row['id'],
                    );
                }
                $totalRows += count($rows);
                $output->writeln(sprintf(
                    '  Re-encrypted %s.%s (%d rows)',
                    $entry['table'], $entry['column'], count($rows),
                ));
            }
            $dsConnection->commit();
        } catch (\Throwable $e) {
            $dsConnection->rollback();
            @unlink($tmpFile);
            $output->writeln('<error>Re-encryption failed (rolled back): ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // Step 3: atomic key file swap.
        $timestamp = gmdate('Y-m-d\THis\Z');
        $bakBase = $keyFile . '.' . $timestamp;
        $bakFile = $bakBase . '.bak';
        $i = 1;
        while (file_exists($bakFile)) {
            $bakFile = $bakBase . '-' . $i . '.bak';
            $i++;
        }

        if (!@rename($keyFile, $bakFile)) {
            $output->writeln('<error>CRITICAL: DB committed with new key but failed to back up old key.</error>');
            $output->writeln('<error>  Old key still at: ' . $keyFile . '</error>');
            $output->writeln('<error>  New key tmp:      ' . $tmpFile . '</error>');
            $output->writeln('<error>Manually move tmp into place and rerun ds-secrets-health.</error>');
            return Command::FAILURE;
        }

        if (!@rename($tmpFile, $keyFile)) {
            $output->writeln('<error>CRITICAL: DB committed with new key, old key backed up, but failed to install new key.</error>');
            $output->writeln('<error>  Backup: ' . $bakFile . '</error>');
            $output->writeln('<error>  Tmp:    ' . $tmpFile . '</error>');
            $output->writeln('<error>To recover: mv ' . $tmpFile . ' ' . $keyFile . '</error>');
            return Command::FAILURE;
        }
        @chmod($keyFile, 0600);

        DsSecretCipher::resetCache();

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Rotation complete: %d rows re-encrypted across %d columns.</info>',
            $totalRows, count($encryptedColumns),
        ));
        $output->writeln('  Old key backed up to: ' . $bakFile);

        $warnings = DsSecretCipher::healthCheck($dsConfig);
        foreach ($warnings as $warning) {
            $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
        }

        return Command::SUCCESS;
    }

    private function writeTmpKeyFile(string $tmpFile, string $keyBytes, OutputInterface $output): bool
    {
        if (is_file($tmpFile)) {
            @unlink($tmpFile);
        }

        $fp = @fopen($tmpFile, 'wb');
        if ($fp === false) {
            $output->writeln('<error>Failed to open ' . $tmpFile . ' for writing</error>');
            return false;
        }
        @chmod($tmpFile, 0600);

        try {
            $written = fwrite($fp, $keyBytes);
            if ($written !== DsSecretCipher::KEY_BYTES) {
                throw new \RuntimeException('Short write to ' . $tmpFile);
            }
            if (!fflush($fp)) {
                throw new \RuntimeException('fflush failed on ' . $tmpFile);
            }
            if (!fsync($fp)) {
                throw new \RuntimeException('fsync failed on ' . $tmpFile);
            }
        } catch (\Throwable $e) {
            fclose($fp);
            @unlink($tmpFile);
            $output->writeln('<error>Failed to pre-write tmp key file: ' . $e->getMessage() . '</error>');
            return false;
        }

        fclose($fp);
        return true;
    }

    private function countNonNull(DataSourceConnection $conn, string $table, string $column): int
    {
        $rows = $conn->fetchAll(sprintf(
            'SELECT COUNT(*) AS c FROM `%s` WHERE `%s` IS NOT NULL',
            $table, $column,
        ));
        return (int) ($rows[0]['c'] ?? 0);
    }
}
