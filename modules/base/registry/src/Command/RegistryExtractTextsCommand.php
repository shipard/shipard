<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Registry\Command;

use Shipard\Api\TableLoader;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Module\ModuleClassLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Base\Registry\ExtractedTextFiller;
use Shipard\Module\Core\Attachments\AttachmentService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfill `extracted_text` dokumentů Spisovny z jejich příloh
 * (ExtractedTextFiller — stejná skladba jako při zařazení). Default doplní
 * jen dokumenty bez textu; `--all` přegeneruje všechny živé. Použití:
 * jednorázově po nasazení Fáze 4 (dokumenty z Fáze 1 text nemají).
 */
class RegistryExtractTextsCommand extends Command
{
    private const REGISTRY_TABLE = 'base_registry_documents';
    private const LIVE_STATES = [10, 40, 80];

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?ExtractedTextFiller $filler = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('registry-extract-texts')
            ->setDescription('Fill registry documents extracted_text from attachments (default: missing only)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Regenerate all live documents, not just those without text')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N documents (0 = no limit)', '0');
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

        $limit = (int) $input->getOption('limit');
        if ($limit < 0) {
            $output->writeln('<error>Error: --limit must be >= 0</error>');
            return Command::FAILURE;
        }

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $filler       = $this->filler ?? $this->buildFiller($dsConfig, $dsConnection);

        $sql = 'SELECT `id` FROM %n WHERE `docState` IN %in';
        if (!(bool) $input->getOption('all')) {
            $sql .= " AND (`extracted_text` IS NULL OR `extracted_text` = '')";
        }
        $sql .= ' ORDER BY `id` ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }
        $rows = $dsConnection->fetchAll($sql, self::REGISTRY_TABLE, self::LIVE_STATES);

        $filled = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result = $filler->fill($id);
            if ($result['chars'] > 0) {
                $filled++;
            }
            if ($output->isVerbose()) {
                $output->writeln(sprintf(
                    '#%d: %d attachment(s), %d chars',
                    $id, $result['attachments'], $result['chars'],
                ));
            }
        }

        $output->writeln(sprintf('Processed %d document(s), filled %d.', count($rows), $filled));
        return Command::SUCCESS;
    }

    private function buildFiller(DataSourceConfig $dsConfig, DataSourceConnection $dsConnection): ExtractedTextFiller
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            $resolver = ModulePathResolver::fromServerConfig($sc, dirname(__DIR__, 5) . '/modules');
        } catch (\Throwable) {
            $resolver = new ModulePathResolver([dirname(__DIR__, 5) . '/modules']);
        }
        ModuleClassLoader::register($resolver);

        $tables = TableLoader::load($dsConfig, $resolver, $dsConfig->getDefaultLanguage());
        $attachments = new AttachmentService($dsConnection, $dsConfig->getDataSourceDir(), $tables);
        return new ExtractedTextFiller($dsConnection, $attachments);
    }
}
