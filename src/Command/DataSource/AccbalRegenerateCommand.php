<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Accbal\LedgerGenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hromadná re-derivace saldo pohybů z účetního deníku (#69 D13,
 * docs/accbal.md §4.6). Běžně ledger drží událost `journalWritten` per
 * zdroj; tenhle příkaz projde zdroje dávkově — po změně generátoru, po
 * ds-upgrade s novým klíčem pohybu, při podezření na rozjetý ledger.
 *
 * Zdroje = sjednocení zdrojů v deníku a zdrojů v ledgeru (osiřelý pohyb
 * bez deníku se tak smaže). Pro každý zdroj {@see LedgerGenerator::generate}
 * — idempotentní UPSERT podle `movement_key`, žádné `journalWritten`
 * (deník se nemění, routing clearingu zůstává věcí `accbal-match`).
 *
 * Bez `--all` / `--doc` / `--fiscal-year` příkaz nic neudělá (vyžádá si
 * rozsah). `--dry-run` jen spočítá, kolik pohybů by vložil / aktualizoval
 * / smazal.
 */
class AccbalRegenerateCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('accbal-regenerate')
            ->setDescription('Přegeneruje saldo pohyby z účetního deníku (všechny zdroje, jeden doklad nebo fiskální rok)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Všechny zdroje deníku i ledgeru')
            ->addOption('doc', null, InputOption::VALUE_REQUIRED, 'Jen tento doklad (id)')
            ->addOption('fiscal-year', null, InputOption::VALUE_REQUIRED, 'Jen zdroje tohoto fiskálního roku (id)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen spočítej změny, nic neměň');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = (bool) $input->getOption('all');
        $docOpt = $input->getOption('doc');
        $fyOpt = $input->getOption('fiscal-year');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!$all && $docOpt === null && $fyOpt === null) {
            $output->writeln('<error>Vyžaduje --all, --doc <id> nebo --fiscal-year <id>. Pro náhled přidej --dry-run.</error>');
            return Command::FAILURE;
        }
        if ($docOpt !== null && (!ctype_digit((string) $docOpt) || (int) $docOpt <= 0)) {
            $output->writeln('<error>--doc musí být kladné celé číslo (id dokladu).</error>');
            return Command::FAILURE;
        }
        if ($fyOpt !== null && (!ctype_digit((string) $fyOpt) || (int) $fyOpt <= 0)) {
            $output->writeln('<error>--fiscal-year musí být kladné celé číslo (id fiskálního roku).</error>');
            return Command::FAILURE;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($this->getDataSourceDir());
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $dibi         = $dsConnection->getDibiConnection();

        $sources = $docOpt !== null
            ? [['kind' => 'doc', 'id' => (int) $docOpt]]
            : self::listSources($dibi, $fyOpt !== null ? (int) $fyOpt : null);

        // Stejné odvození domácí měny jako JournalLedgerHandler — settings, ne main.json.
        $home = (new SettingsStore($dsConnection))->get('economy.homeCurrency');
        $generator = new LedgerGenerator($dibi, null, is_string($home) && $home !== '' ? $home : null);

        $total = ['inserted' => 0, 'updated' => 0, 'deleted' => 0];
        $perKind = [];
        foreach ($sources as $source) {
            $stats = $generator->generate($source['kind'], $source['id'], $dryRun);
            $perKind[$source['kind']] = ($perKind[$source['kind']] ?? 0) + 1;
            foreach ($total as $k => $_) {
                $total[$k] += $stats[$k];
            }
            if ($output->isVerbose() && ($stats['inserted'] > 0 || $stats['deleted'] > 0)) {
                $output->writeln(sprintf(
                    '%s #%d: +%d ~%d -%d',
                    $source['kind'],
                    $source['id'],
                    $stats['inserted'],
                    $stats['updated'],
                    $stats['deleted'],
                ));
            }
        }

        $kinds = [];
        foreach ($perKind as $kind => $count) {
            $kinds[] = "{$kind}: {$count}";
        }
        $output->writeln(sprintf('Zdrojů: %d%s', count($sources), $kinds !== [] ? ' (' . implode(', ', $kinds) . ')' : ''));
        $output->writeln(sprintf(
            '  %s vloženo: %d, aktualizováno: %d, smazáno: %d',
            $dryRun ? 'plán —' : 'pohyby —',
            $total['inserted'],
            $total['updated'],
            $total['deleted'],
        ));
        if ($dryRun) {
            $output->writeln('  (dry-run, nic se nezapsalo)');
        }
        return Command::SUCCESS;
    }

    /**
     * Zdroje k re-derivaci: sjednocení zdrojů deníku a ledgeru (osiřelý
     * pohyb bez deníku → generate s prázdným deníkem ho smaže), volitelně
     * jen zdroje daného fiskálního roku (deník i ledger nesou `fiscal_year`).
     * Deterministicky seřazené (kind, id).
     *
     * @return list<array{kind: string, id: int}>
     */
    public static function listSources(\Dibi\Connection $db, ?int $fiscalYear): array
    {
        $where = $fiscalYear !== null ? ' WHERE [fiscal_year] = %i' : '';
        $params = $fiscalYear !== null ? [$fiscalYear] : [];
        $journal = $db->fetchAll(
            'SELECT [source_kind] AS kind,
                    CASE WHEN [source_kind] = %s THEN [bank_transaction] ELSE [doc_head] END AS id
             FROM [economy_accounting_journal]' . $where . ' GROUP BY 1, 2',
            'bankTransaction',
            ...$params,
        );
        $ledger = $db->fetchAll(
            'SELECT [source_kind] AS kind, [source_id] AS id
             FROM [economy_accbal_ledger]' . $where . ' GROUP BY 1, 2',
            ...$params,
        );

        $set = [];
        foreach ([$journal, $ledger] as $rows) {
            foreach ($rows as $row) {
                if ($row['id'] === null) {
                    continue;
                }
                $kind = (string) $row['kind'];
                $id = (int) $row['id'];
                $set[$kind . '|' . $id] = ['kind' => $kind, 'id' => $id];
            }
        }
        usort($set, static fn(array $a, array $b) => [$a['kind'], $a['id']] <=> [$b['kind'], $b['id']]);
        return array_values($set);
    }
}
