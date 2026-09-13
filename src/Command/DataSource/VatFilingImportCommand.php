<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentEventHandlerLoader;
use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Api\TableLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Economy\Vat\Import\FilingImportException;
use Shipard\Module\Economy\Vat\Import\FilingImportRequest;
use Shipard\Module\Economy\Vat\Import\FilingImportResult;
use Shipard\Module\Economy\Vat\Import\FilingImportService;
use Shipard\Module\Economy\Vat\Xml\FilingFilesService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * shpd-ds vat-filing-import --period=<id> --type=return|cs|rs --kind=<druh>
 *   [--xml=<soubor>] [--name=…] [--date-issue=…] [--date-filed=…]
 *   [--date-found=…] [--acc-document=<id>] [--attach=<soubor>]… [--dry-run]
 *
 * Ruční doplnění jednoho starého podání (papírové, jiný systém) — obálka
 * nad `FilingImportService` (#55 D38): založí podání, nahraje XML a další
 * soubory jako původní přílohy a podá ho. `--dry-run` sestaví snapshot
 * a porovná ho s XML v transakci s rollbackem — nic nezůstane (D39 nad
 * `btpg-p` bez runneru).
 */
class VatFilingImportCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('vat-filing-import')
            ->setDescription('Importuje staré podání DPH z původního XML — snapshot composerem, podané hodnoty z XML, přílohy, stav Podáno; --dry-run jen porovná')
            ->addOption('period', null, InputOption::VALUE_REQUIRED, 'Id instance daňového tvrzení (economy_vat_report_periods)')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Typ tvrzení: return, cs, rs (musí sedět s instancí)')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'Druh podání (regular, corrective, supplementary, subsequent)', 'regular')
            ->addOption('xml', null, InputOption::VALUE_REQUIRED, 'Původní podané XML pro EPO (podané hodnoty přiznání, porovnání hlášení)')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Název podání ze starého systému (default se složí z instance)')
            ->addOption('date-issue', null, InputOption::VALUE_REQUIRED, 'Datum sestavení YYYY-MM-DD (default = datum podání)')
            ->addOption('date-filed', null, InputOption::VALUE_REQUIRED, 'Datum podání YYYY-MM-DD (default dnes)')
            ->addOption('date-found', null, InputOption::VALUE_REQUIRED, 'Datum zjištění důvodů YYYY-MM-DD (dodatečné, následné; jinak z XML d_zjist)')
            ->addOption('acc-document', null, InputOption::VALUE_REQUIRED, 'Id účetního dokladu přiznání (cmnbkp); chybějící = varování')
            ->addOption('attach', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Další původní soubor jako příloha (opis PDF…), lze opakovat')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Sestavit a porovnat v transakci s rollbackem — nic nezapsat');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function buildResolver(): ModulePathResolver
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            return ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 3) . '/modules');
        } catch (\Throwable) {
            return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
        }
    }

    /**
     * Služba příloh nad adresářem DS — seam pro testy (izolované úložiště).
     *
     * @param array<string, TableDefinition> $tables
     */
    protected function attachmentService(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection, array $tables): AttachmentService
    {
        return new AttachmentService($dsConnection, $dsConfig->getDataSourceDir(), $tables);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $periodId = (int) $input->getOption('period');
        $type     = (string) $input->getOption('type');
        if ($periodId <= 0 || $type === '') {
            $output->writeln('<error>Zadejte --period=<id instance> a --type=return|cs|rs.</error>');
            return Command::INVALID;
        }
        $dryRun  = (bool) $input->getOption('dry-run');
        $xmlPath = $input->getOption('xml');
        $xml     = null;
        if ($xmlPath !== null) {
            $xml = $this->readXml((string) $xmlPath, $output);
            if ($xml === null) {
                return Command::INVALID;
            }
        }
        $attachments = [];
        foreach ([...($xmlPath !== null ? [(string) $xmlPath] : []), ...array_map(strval(...), $input->getOption('attach'))] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                $output->writeln("<error>Soubor přílohy nelze číst: {$path}</error>");
                return Command::INVALID;
            }
            $attachments[] = $path;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $dibi         = $dsConnection->getDibiConnection();

        if (!in_array('economy_vat_filings', $dsConnection->getAllTableNames(), true)) {
            $output->writeln('<error>economy.vat není aktivní — tabulka podání neexistuje (spusťte ds-upgrade).</error>');
            return Command::FAILURE;
        }

        $language = $dsConfig->getDefaultLanguage();
        $config   = ConfigRuntime::load($dsDir, $language);
        $resolver = $this->buildResolver();
        $tables   = TableLoader::load($dsConfig, $resolver, $language);
        $registry = DocumentLoader::load($dsConfig, $resolver);

        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config);
        $dispatcher    = DocumentEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $journalEvents);

        $service = new FilingImportService($dibi, $config, $dsConfig, $registry, $tables, $dispatcher);

        try {
            $request = FilingImportRequest::fromArray([
                'reportPeriodId' => $periodId,
                'reportType'     => $type,
                'filingKind'     => (string) $input->getOption('kind'),
                'name'           => $input->getOption('name'),
                'dateIssue'      => $input->getOption('date-issue'),
                'dateFiled'      => $input->getOption('date-filed') ?? date('Y-m-d'),
                'dateFound'      => $input->getOption('date-found'),
                'accDocumentId'  => $input->getOption('acc-document'),
                'xml'            => $xml,
                'legacy'         => ['source' => 'cli', 'xmlFile' => $xmlPath !== null ? basename((string) $xmlPath) : null],
            ]);
            $result = $service->import($request, $dryRun);
        } catch (FilingImportException $e) {
            $this->printFailure($output, $e);
            return $e->errorCode === 'BAD_REQUEST' ? Command::INVALID : Command::FAILURE;
        }

        $this->printResult($output, $result);
        if ($dryRun) {
            $output->writeln('<comment>Dry-run — nic se nezapsalo.</comment>');
            return Command::SUCCESS;
        }

        $attachmentService = $this->attachmentService($dsConfig, $dsConnection, $tables);
        foreach ($attachments as $path) {
            if (!$this->upload($attachmentService, $result->filingId, $path, $output)) {
                $output->writeln(sprintf(
                    '<error>Podání #%d zůstalo jako koncept — přílohu nahrajte ručně a dokončete přes POST /_vat/filing-import-finish, nebo koncept zrušte.</error>',
                    $result->filingId,
                ));
                return Command::FAILURE;
            }
        }

        try {
            $finish = $service->finish($result->filingId);
        } catch (FilingImportException $e) {
            $this->printFailure($output, $e);
            return Command::FAILURE;
        }
        $output->writeln(sprintf(
            '<info>Podání #%d podáno</info> (stav %d, původních souborů: %d).',
            $finish['filingId'],
            $finish['docState'],
            $finish['files'],
        ));
        return Command::SUCCESS;
    }

    /** Obsah XML v UTF-8 — starý soubor může být ve windows-1250 podle deklarace. */
    private function readXml(string $path, OutputInterface $output): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            $output->writeln("<error>Soubor XML nelze číst: {$path}</error>");
            return null;
        }
        $xml = (string) file_get_contents($path);
        if (preg_match('/^\s*<\?xml[^>]*encoding="([^"]+)"/i', $xml, $m) === 1 && strtoupper($m[1]) !== 'UTF-8') {
            $converted = @mb_convert_encoding($xml, 'UTF-8', $m[1]);
            if (!is_string($converted)) {
                $output->writeln("<error>Kódování {$m[1]} nejde převést na UTF-8.</error>");
                return null;
            }
            $xml = $converted;
        } elseif (!mb_check_encoding($xml, 'UTF-8')) {
            $xml = mb_convert_encoding($xml, 'UTF-8', 'windows-1250');
        }
        return $xml;
    }

    private function upload(AttachmentService $service, int $filingId, string $path, OutputInterface $output): bool
    {
        // Upload zdrojový soubor přesouvá — pracuje se s kopií.
        $tmp = tempnam(sys_get_temp_dir(), 'vfi');
        if ($tmp === false || !copy($path, $tmp)) {
            $output->writeln("<error>Nelze připravit kopii souboru {$path}.</error>");
            return false;
        }
        try {
            $uploaded = $service->upload(FilingFilesService::TABLE_ID, $filingId, basename($path), $tmp);
        } finally {
            @unlink($tmp);
        }
        if (($uploaded['success'] ?? false) !== true) {
            $output->writeln(sprintf('<error>Přílohu %s nelze uložit: %s</error>', basename($path), (string) ($uploaded['error'] ?? '?')));
            return false;
        }
        $output->writeln(sprintf('  příloha %s (#%d)', basename($path), (int) $uploaded['data']['id']));
        return true;
    }

    private function printFailure(OutputInterface $output, FilingImportException $e): void
    {
        $output->writeln("<error>{$e->errorCode}: {$e->getMessage()}</error>");
        foreach ($e->details as $key => $detail) {
            $output->writeln('  ' . (is_array($detail)
                ? implode(' ', array_map(static fn ($k, $v): string => "{$k}=" . json_encode($v, JSON_UNESCAPED_UNICODE), array_keys($detail), $detail))
                : "{$key}=" . json_encode($detail, JSON_UNESCAPED_UNICODE)));
        }
    }

    private function printResult(OutputInterface $output, FilingImportResult $result): void
    {
        $output->writeln(sprintf(
            '%s #%d, pořadí v instanci %d — rozdílů řádků přiznání: %d, řádků hlášení: %d',
            $result->dryRun ? 'Podání (dry-run)' : 'Podání',
            $result->filingId,
            $result->sequence,
            $result->mismatchRows,
            $result->lineMismatches,
        ));

        $rows  = [];
        $lines = [];
        foreach ($result->messages as $message) {
            $code = (string) ($message['code'] ?? '');
            if ($code === FilingImportService::MSG_ROW_MISMATCH) {
                foreach ($message['filed'] as $slot => $filed) {
                    $composed = $message['composed'][$slot] ?? 0.0;
                    if (abs((float) $filed - (float) $composed) < 0.005) {
                        continue;
                    }
                    $rows[] = [(string) $message['row'], (string) $slot, $this->money((float) $composed), $this->money((float) $filed)];
                }
            } elseif ($code === FilingImportService::MSG_ROW_UNMAPPED) {
                $rows[] = [$message['veta'] . '/@' . $message['attribute'], '—', '', (string) $message['value']];
            } elseif ($code === FilingImportService::MSG_LINE_MISMATCH) {
                $lines[] = [
                    (string) $message['section'], (string) $message['key'], (string) $message['field'],
                    (string) ($message['composed'] ?? '—'), (string) ($message['filed'] ?? '—'), (string) $message['kind'],
                ];
            }
        }
        if ($rows !== []) {
            $table = new Table($output);
            $table->setHeaders(['Řádek', 'Sloupec', 'Sestaveno', 'Podáno'])->setRows($rows)->render();
        }
        if ($lines !== []) {
            $table = new Table($output);
            $table->setHeaders(['Sekce', 'Klíč', 'Pole', 'Sestaveno', 'Podáno', 'Druh'])->setRows($lines)->render();
        }
        if ($result->flags !== []) {
            $output->writeln('Příznaky: ' . implode(', ', $result->flags));
        }
        foreach ($result->warnings as $warning) {
            $output->writeln("<comment>{$warning}</comment>");
        }
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
