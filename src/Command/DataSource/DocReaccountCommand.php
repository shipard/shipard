<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Api\DocumentLoader;
use Shipard\Api\JournalContributorLoader;
use Shipard\Api\JournalEventHandlerLoader;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Document\DocumentLockReason;
use Shipard\Core\Document\DocumentLockRegistry;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Module\Economy\Accounting\AccountingEngine;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds doc-reaccount <docId> [--force]` — přegeneruje účetní deník
 * dokladu ve stavu 40 (totéž co POST /_accounting/reaccount).
 *
 * Zamčený doklad (documentLockProviders — zamčená instance tvrzení DPH,
 * zamčený fiskální měsíc, #55 D27) příkaz odmítne s výčtem důvodů; `--force`
 * zámek vědomě obejde a použití zaloguje (warn, shipard.log). Deník je
 * derivát dokladu — bez změny dokladu se přegenerováním nesmí změnit, takže
 * force slouží k opravě rozvrhu / předpisu nad uzavřeným obdobím.
 */
class DocReaccountCommand extends Command
{
    private const DOC_STATE_OK = 40;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('doc-reaccount')
            ->setDescription('Re-generate the accounting journal of one document (state 40); --force bypasses period locks')
            ->addArgument('docId', InputArgument::REQUIRED, 'docs_core_heads.id')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Bypass document locks (locked VAT period / fiscal month); logged');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function buildResolver(): ModulePathResolver
    {
        return new ModulePathResolver([dirname(__DIR__, 3) . '/modules']);
    }

    /** Log dle server.json (vzor MailPreprocessCommand); bez něj default cesta loggeru. */
    protected function getLogPath(): ?string
    {
        try {
            $sc = new ServerConfig();
            $sc->load();
            ErrorLogger::setLogLevel($sc->getLogLevel());
            return $sc->getLogFile();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Není zdroj dat: chybí config/main.json v aktuálním adresáři.</error>');
            return Command::FAILURE;
        }
        $docId = (int) $input->getArgument('docId');
        if ($docId <= 0) {
            $output->writeln('<error>docId musí být kladné číslo.</error>');
            return Command::FAILURE;
        }
        $force = (bool) $input->getOption('force');

        $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $dibi         = $dsConnection->getDibiConnection();
        $config       = is_file($dsDir . '/config/configuration/compiled.cs.json')
            ? ConfigRuntime::load($dsDir, 'cs')
            : null;
        $resolver = $this->buildResolver();

        $head = $dsConnection->fetchRow('SELECT * FROM docs_core_heads WHERE id = %i', $docId);
        if ($head === null) {
            $output->writeln("<error>Doklad #{$docId} nenalezen.</error>");
            return Command::FAILURE;
        }
        if ((int) $head['docState'] !== self::DOC_STATE_OK) {
            $output->writeln("<error>Doklad #{$docId} není ve stavu 40 (V pořádku) — přeúčtovat lze jen zaúčtovaný doklad.</error>");
            return Command::FAILURE;
        }

        $documents = DocumentLoader::load($dsConfig, $resolver);
        $reasons = $documents->hasLockProviders('docs_core_heads')
            ? DocumentLockRegistry::forDocuments($documents, $dibi, $config, $dsConfig)
                ->reasons('docs_core_heads', (array) $head, (array) $head)
            : [];
        if ($reasons !== []) {
            foreach ($reasons as $reason) {
                $output->writeln(($force ? '<comment>' : '<error>') . 'Zámek: ' . $reason->title . ($force ? '</comment>' : '</error>'));
            }
            if (!$force) {
                $output->writeln('<error>Doklad je uzamčený — přeúčtování odmítnuto. Vědomé obejití: --force (zaloguje se).</error>');
                return Command::FAILURE;
            }
            ErrorLogger::setLogPath($this->getLogPath());
            ErrorLogger::setDsId($dsConfig->getId());
            ErrorLogger::setRequestContext('cli: doc-reaccount --force');
            ErrorLogger::warn('document lock bypassed by force (doc-reaccount)', [
                'table'   => 'docs_core_heads',
                'id'      => $docId,
                'reasons' => array_map(
                    static fn(DocumentLockReason $r): string => $r->source . ':' . (string) ($r->subjectRowId ?? ''),
                    $reasons,
                ),
            ]);
            $output->writeln('<comment>--force: zámek obejit, použití zalogováno.</comment>');
        }

        $contributors  = JournalContributorLoader::load($dsConfig, $resolver, $dibi, $config);
        $journalEvents = JournalEventHandlerLoader::load($dsConfig, $resolver, $dibi, $config, $contributors);
        $result = (new AccountingEngine($dibi, $config, $journalEvents, $contributors))->accountDocument($docId);

        $output->writeln(sprintf('Doklad #%d (%s): accounting_state = %d', $docId, (string) ($head['doc_number'] ?? ''), $result['state']));
        foreach ($result['messages'] as $message) {
            $output->writeln(sprintf('  [%s] %s', (string) ($message['code'] ?? ''), (string) ($message['message'] ?? '')));
        }
        return Command::SUCCESS;
    }
}
