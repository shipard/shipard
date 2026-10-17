<?php

declare(strict_types=1);

namespace Shipard\Command\Server;

use Shipard\Core\Config\DataClass;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DatabaseManager;
use Shipard\Core\DevJobs\JobStore;
use Shipard\Core\Server\DataSourceInventory;
use Shipard\Core\Utils\IdGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * `shpd-server ds-delete <id> [--dry-run] [--yes]` — tvrdé smazání volného
 * zdroje dat mimo produkci (tasks/ds-delete.md, #103 D68–D69): granty
 * ostatních účtů na databázi (`DROP DATABASE` je neodebere), databáze,
 * DB uživatel a adresář zdroje dat, v tomto pořadí. Bez koše a bez zálohy.
 *
 * Preflight nic nemění a odmítne: režim `production` (i s `--yes`), zdroj
 * dat bez čitelného `main.json`, třídu dat jinou než `free` (reálná kopie
 * se maže jen po vědomém `shpd-ds ds-class set free`, žádný `--force`)
 * a běžící úlohu vývojářského dashboardu ({@see JobStore::activeJobFor()}).
 * Výjimka: úloha, která tento příkaz sama spouští — dashboard ji předá
 * v `--job=<id>` (jinak by úloha `delete` nikdy neprošla vlastním
 * preflightem). Potvrzení = opsání ID v interaktivním terminálu; `--yes`
 * ho přeskočí (neinteraktivní běh, úloha dashboardu), preflight ne.
 *
 * Opakovatelné: chybějící databáze nebo uživatel nejsou chyba (`IF EXISTS`),
 * adresář se maže až nakonec, takže po částečném selhání jde příkaz pustit
 * znovu. Logy (`/opt/shipard/log`) a adresáře úloh dashboardu zůstávají —
 * jsou to historie. Produkce a hosting (záloha před smazáním, portál) jsou
 * samostatný návrh (M5).
 */
class DsDeleteCommand extends Command
{
    public function __construct(
        private readonly ?ServerConfig $serverConfig = null,
        private readonly ?DatabaseManager $databaseManager = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ds-delete')
             ->setDescription('Delete a free data source outside production: grants, database, DB user and directory (no backup, no trash)')
             ->addArgument('id', InputArgument::REQUIRED, 'Data source ID (xxxx-xxxx-xxxx-xxxx)')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be deleted; nothing changes')
             ->addOption('yes', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation (the preflight checks still apply)')
             ->addOption('job', null, InputOption::VALUE_REQUIRED, 'Developer-dashboard job running this command — the running-job check ignores it (set by the dashboard, not by hand)');
    }

    // ── Seamy pro testy ────────────────────────────────────────────────────

    protected function getDataSourcesDir(ServerConfig $config): string
    {
        return $config->getDataSourcesDir();
    }

    protected function jobStore(): JobStore
    {
        return new JobStore(JobStore::defaultDir());
    }

    protected function createDatabaseManager(ServerConfig $config): DatabaseManager
    {
        return $this->databaseManager ?? new DatabaseManager($config);
    }

    /** Stejná pojistka jako u `ds-class set free`: opsání ID jen z terminálu, ne z přesměrovaného stdin. */
    protected function stdinIsTerminal(): bool
    {
        return \defined('STDIN') && @stream_isatty(STDIN);
    }

    // ── Běh ────────────────────────────────────────────────────────────────

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = (string) $input->getArgument('id');
        if (!preg_match(IdGenerator::ID_PATTERN, $id)) {
            return $this->fail($output, "Invalid data source ID: {$id}", 'Must match pattern: xxxx-xxxx-xxxx-xxxx (a-z0-9)');
        }

        $config = $this->serverConfig ?? new ServerConfig();
        try {
            $config->load();
        } catch (\RuntimeException $e) {
            return $this->fail($output, 'Failed to load server config: ' . $e->getMessage());
        }

        // D69: na produkci se příkaz nespustí ani s --yes — mazání ostrého
        // zdroje dat (záloha, portál hostingu) je samostatný postup.
        if ($config->getMode() === 'production') {
            return $this->fail(
                $output,
                'ds-delete is not available in production mode — nothing deleted.',
                'Deleting a production data source needs a backup first; it is a separate procedure (roadmap M5).',
            );
        }

        $dataSourcesDir = rtrim($this->getDataSourcesDir($config), '/');
        $dsDir = $dataSourcesDir . '/' . $id;
        if (is_link($dsDir)) {
            return $this->fail($output, "Data source directory is a symlink, refusing to delete through it: {$dsDir}");
        }
        if (!is_dir($dsDir)) {
            return $this->fail($output, "Data source not found: {$dsDir}");
        }

        $info = (new DataSourceInventory($dataSourcesDir))->read($dsDir);
        if ($info === null || !$info->readable) {
            return $this->fail(
                $output,
                "Data source {$id} has no readable config/main.json — nothing deleted.",
                'Fix the file (or its permissions) first; ds-delete never deletes a data source it cannot identify.',
            );
        }

        // D69: jen třída free. Reálná kopie (i chybějící nebo rozbitá třída —
        // DataClass::fromConfig je fail-safe na realCopy) se nemaže nikdy
        // bez vědomého přepnutí; žádný --force.
        if ($info->dataClass !== DataClass::Free) {
            return $this->fail(
                $output,
                sprintf(
                    'Data source %s (%s) is not a free data source (data class %s%s) — real copies are never deleted by ds-delete.',
                    $id,
                    $info->name,
                    $info->dataClass->value,
                    $info->dataClassExplicit ? '' : ', not set in main.json',
                ),
                "Switch it consciously first: cd {$dsDir} && shpd-ds ds-class set free",
            );
        }

        // Úloha dashboardu, která nás spouští, se nepočítá (--job); jiná aktivní
        // úloha na tomtéž zdroji dat (reseed, evaluace…) smazání blokuje.
        $ownJob = $input->getOption('job');
        $ownJob = is_string($ownJob) && JobStore::isValidId($ownJob) ? $ownJob : null;
        $job = $this->jobStore()->activeJobFor($id);
        if ($job !== null && (string) $job['id'] !== $ownJob) {
            return $this->fail(
                $output,
                sprintf(
                    'Data source %s has a running developer-dashboard job %s (%s, %s) — nothing deleted.',
                    $id,
                    (string) $job['id'],
                    (string) $job['type'],
                    (string) $job['status'],
                ),
                'Wait for the job or cancel it (/_dev/testing/jobs/' . (string) $job['id'] . '), then run ds-delete again.',
            );
        }

        $dbName = $info->databaseName ?? IdGenerator::toDatabaseName($id);
        $dbUser = $this->databaseUserFrom($dsDir) ?? IdGenerator::toDatabaseUser($id);

        // Plán (i pro --dry-run) potřebuje admin spojení: granty ostatních
        // účtů a to, zda databáze a uživatel ještě existují.
        try {
            $db = $this->createDatabaseManager($config);
            $grants = array_values(array_filter(
                $db->listDatabaseGrants($dbName),
                static fn (array $grant): bool => $grant['user'] !== $dbUser,
            ));
            $dbExists = $db->databaseExists($dbName);
            $userExists = $db->userExists($dbUser);
        } catch (\Throwable $e) {
            return $this->fail($output, 'Cannot inspect the database server: ' . $e->getMessage());
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $output->writeln('');
        $output->writeln($dryRun ? '<info>Dry run — this would be deleted:</info>' : '<info>This will be deleted:</info>');
        $output->writeln('');
        $output->writeln("  Data source:   <comment>{$id}</comment> ({$info->name})");
        $output->writeln("  Directory:     <comment>{$dsDir}</comment>");
        $output->writeln("  Database:      <comment>{$dbName}</comment>" . ($dbExists ? '' : ' (already gone)'));
        $output->writeln("  DB user:       <comment>{$dbUser}@localhost</comment>" . ($userExists ? '' : ' (already gone)'));
        if ($grants === []) {
            $output->writeln('  Other grants:  none');
        } else {
            $output->writeln('  Other grants:  ' . implode(', ', array_map(
                static fn (array $grant): string => self::grantee($grant) . ' on ' . $grant['db'] . ($grant['grantOption'] ? ' (with GRANT OPTION)' : ''),
                $grants,
            )));
        }
        $output->writeln('');

        if ($dryRun) {
            $output->writeln('<comment>Dry run — nothing deleted.</comment>');
            return Command::SUCCESS;
        }

        if (!$input->getOption('yes')) {
            if (!$input->isInteractive() || !$this->stdinIsTerminal()) {
                return $this->fail(
                    $output,
                    'Deleting needs a confirmation: type the ID in an interactive terminal, or pass --yes for a non-interactive run.',
                );
            }
            $question = new Question("Type the data source ID ({$id}) to confirm the deletion: ");
            $answer = $this->getHelper('question')->ask($input, $output, $question);
            if (trim((string) $answer) !== $id) {
                $output->writeln('<comment>Aborted — the ID does not match, nothing deleted.</comment>');
                return Command::FAILURE;
            }
            $output->writeln('');
        }

        // 1. Granty ostatních účtů — jen varování: databáze se smaže tak jako
        //    tak, osiřelý řádek v mysql.db je kosmetika, kterou opraví člověk.
        foreach ($grants as $grant) {
            $who = self::grantee($grant);
            try {
                $db->revokeDatabasePrivileges($grant['db'], $grant['user'], $grant['host'], $grant['grantOption']);
                $output->writeln("  [OK]   Revoked privileges of {$who} on {$grant['db']}");
            } catch (\Throwable $e) {
                $output->writeln("<comment>  [WARN] Cannot revoke privileges of {$who} on {$grant['db']}: {$e->getMessage()} — revoke them by hand</comment>");
            }
        }

        // 2. Databáze — selhání končí příkaz, adresář zůstává (opakovatelnost).
        try {
            $db->dropDatabase($dbName);
            $output->writeln($dbExists ? "  [OK]   Dropped database {$dbName}" : "  [OK]   Database {$dbName} was already gone");
        } catch (\Throwable $e) {
            return $this->fail(
                $output,
                "Failed to drop database {$dbName}: " . $e->getMessage(),
                'The data source directory is kept — fix the cause and run ds-delete again.',
            );
        }

        // 3. DB uživatel.
        try {
            $db->dropUser($dbUser);
            $output->writeln($userExists ? "  [OK]   Dropped DB user {$dbUser}@localhost" : "  [OK]   DB user {$dbUser}@localhost was already gone");
        } catch (\Throwable $e) {
            return $this->fail(
                $output,
                "Failed to drop DB user {$dbUser}: " . $e->getMessage(),
                'The data source directory is kept — fix the cause and run ds-delete again.',
            );
        }

        // 4. Adresář — až nakonec; první selhání je chyba, žádný tichý úspěch.
        try {
            $this->removeTree($dsDir);
            $output->writeln("  [OK]   Removed directory {$dsDir}");
        } catch (\RuntimeException $e) {
            return $this->fail(
                $output,
                'Failed to remove the data source directory: ' . $e->getMessage(),
                'Database and DB user are already gone — fix the permissions and run ds-delete again to finish.',
            );
        }

        $output->writeln('');
        $output->writeln("<info>Data source {$id} deleted</info> (logs and dashboard job directories are kept).");
        $output->writeln('');

        return Command::SUCCESS;
    }

    // ── Pomocné ────────────────────────────────────────────────────────────

    /** `database_user` z main.json; null, když chybí (pak se odvodí z ID jako v ds-create). */
    private function databaseUserFrom(string $dsDir): ?string
    {
        $raw = @file_get_contents($dsDir . '/config/main.json');
        $config = $raw === false ? null : json_decode($raw, true);
        $user = is_array($config) ? ($config['database_user'] ?? null) : null;
        return is_string($user) && $user !== '' ? $user : null;
    }

    /** @param array{user: string, host: string} $grant */
    private static function grantee(array $grant): string
    {
        return $grant['host'] === '' ? $grant['user'] : $grant['user'] . '@' . $grant['host'];
    }

    /**
     * Rekurzivní smazání bez následování symlinků (symlink se odstraní jako
     * soubor). Na rozdíl od úklidu úloh v JobStore tady selhání vyhodí —
     * zbytek stromu zůstane a příkaz se pustí znovu po opravě práv.
     *
     * @throws \RuntimeException
     */
    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException("cannot delete {$path}");
            }
            return;
        }
        $entries = @scandir($path);
        if ($entries === false) {
            throw new \RuntimeException("cannot read {$path}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException("cannot remove {$path}");
        }
    }

    private function fail(OutputInterface $output, string $message, ?string $hint = null): int
    {
        $output->writeln('<error>' . $message . '</error>');
        if ($hint !== null) {
            $output->writeln('<comment>' . $hint . '</comment>');
        }
        return Command::FAILURE;
    }
}
