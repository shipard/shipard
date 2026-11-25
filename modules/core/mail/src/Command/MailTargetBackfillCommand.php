<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\MessageTargetWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill partnera a titulku u zpráv navázaných na doklad
 * (tasks/mail-import-partner-title.md D7). Pokrývá tři věci najednou:
 * historii z importu ze starého Shipardu (zprávy s `analysis_state = 0`
 * se do AI fronty nikdy nedostanou, takže je `/result` nikdy nepotká),
 * zprávy aplikované přes Použít před #43 a zprávy, jejichž doklad se
 * doimportoval později (částečné běhy runneru s `--limit`).
 *
 * Idempotentní: zapisuje **jen do NULL sloupců**, takže druhý běh hlásí
 * `updated = 0` a ručně vybraného partnera ani titulek od AI nepřepíše.
 * Exit kód je 0 i když se nic nezmění — nenulový jen pro chybu volání
 * nebo infrastruktury.
 */
class MailTargetBackfillCommand extends Command
{
    private const MESSAGES_TABLE = 'core_mail_incoming_messages';
    private const DEFAULT_BATCH = 500;

    /** Kolik titulků vypsat v `--dry-run` k namátkové kontrole. */
    private const SAMPLE_SIZE = 10;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?MessageTargetWriter $writer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-target-backfill')
            ->setDescription('Fill partner and title of incoming messages linked to a document (NULL columns only; --dry-run, --limit, --batch)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count and print a few titles, write nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N messages (0 = no limit)', '0')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Rows fetched per query', (string) self::DEFAULT_BATCH);
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /**
     * Namátková ukázka pro `--dry-run`: `ai_title = Faktura přijatá …`.
     *
     * @param array<string, int|string> $fillable
     */
    private static function describe(array $fillable): string
    {
        $parts = [];
        foreach ($fillable as $column => $value) {
            $parts[] = $column . ' = ' . (string) $value;
        }
        return implode(', ', $parts);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $limit = (int) $input->getOption('limit');
        $batch = (int) $input->getOption('batch');
        if ($limit < 0) {
            $output->writeln('<error>Error: --limit must be >= 0</error>');
            return Command::FAILURE;
        }
        if ($batch < 1) {
            $output->writeln('<error>Error: --batch must be >= 1</error>');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $db = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $writer = $this->writer ?? MessageTargetWriter::forDataSource($db, $dsConfig);

        $scanned = 0;
        $updated = 0;
        $skipped = 0;
        $unchanged = 0;
        /** @var list<string> $samples */
        $samples = [];
        $after = 0;

        while (true) {
            $take = $limit > 0 ? min($batch, $limit - $scanned) : $batch;
            if ($take < 1) {
                break;
            }

            // Keyset přes id — tabulka má na produkci 100k+ řádků, OFFSET
            // ani načtení celé tabulky nepřipadá v úvahu (P6).
            $rows = $db->fetchAll(
                'SELECT id, target_table_id, target_row, partner_person, partner_name, ai_title'
                . ' FROM %n WHERE id > %i AND target_row IS NOT NULL'
                . ' AND (partner_person IS NULL OR partner_name IS NULL OR ai_title IS NULL)'
                . ' ORDER BY id LIMIT %i',
                self::MESSAGES_TABLE, $after, $take,
            );
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $scanned++;
                $after = (int) $row['id'];

                $facts = $writer->factsFor(
                    isset($row['target_table_id']) ? (string) $row['target_table_id'] : null,
                    (int) $row['target_row'],
                );
                if (MessageTargetWriter::isEmptyFacts($facts)) {
                    // Cíl mimo docs_core_heads, nebo doklad, který v DS není.
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $fillable = MessageTargetWriter::fillableColumns($facts, $row);
                    if ($fillable === []) {
                        $unchanged++;
                        continue;
                    }
                    $updated++;
                    if (count($samples) < self::SAMPLE_SIZE) {
                        $samples[] = sprintf('#%d: %s', (int) $row['id'], self::describe($fillable));
                    }
                    continue;
                }

                // Fakta jsou už načtená — writer je nesmí číst podruhé (P6).
                if ($writer->backfillRow($row, $facts)) {
                    $updated++;
                } else {
                    $unchanged++;
                }
            }

            if (count($rows) < $take) {
                break;
            }
        }

        if ($dryRun && $samples !== []) {
            $output->writeln('Sample of pending changes:');
            foreach ($samples as $sample) {
                $output->writeln('  ' . $sample);
            }
        }

        $output->writeln(sprintf(
            '%sscanned %d, updated %d, skipped %d, unchanged %d.',
            $dryRun ? '[dry-run] ' : '',
            $scanned, $updated, $skipped, $unchanged,
        ));

        return Command::SUCCESS;
    }
}
