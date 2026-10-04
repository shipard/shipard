<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\ServerConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\AddressList;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Prints\PrintBuildException;
use Shipard\Core\Prints\PrintLanguageNotCompiledException;
use Shipard\Core\Prints\PrintNotAvailableException;
use Shipard\Core\Prints\PrintNotFoundException;
use Shipard\Core\Prints\PrintRecordNotFoundException;
use Shipard\Core\Prints\PrintRenderException;
use Shipard\Module\Core\Mail\Sent\RecordSendException;
use Shipard\Module\Core\Mail\Sent\RecordSendService;
use Shipard\Module\Core\Mail\Sent\RecordSendServiceFactory;
use Shipard\Module\Core\Mail\Sent\SendRequest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `shpd-ds print-send <printId> <recordId> [--to=…]… [--cc=…]… [--from=…]
 * [--language=…] [--dry-run]` — odešle záznam e-mailem (#90 D42): vytvoří
 * zprávu v Odeslané poště s PDF tisku a zařadí ji do fronty odchozí pošty
 * (odešle ji worker `mail-outbox-run`).
 *
 * `--dry-run` vypíše návrh (JSON na stdout) a nic nevytvoří.
 *
 * Bez `--dry-run` je `--to` povinné: zdroj dat neví, jestli nese ostrá data
 * nebo jejich kopii, takže příkaz nikdy sám neposílá na adresy partnerů
 * dohledané z kontaktů (pojistku na úrovni serveru řeší #95).
 */
class PrintSendCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?RecordSendService $service = null,
        private readonly ?ServerConfig $serverConfig = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('print-send')
             ->setDescription('Odešle záznam e-mailem: zpráva v Odeslané poště s PDF tisku + řádek fronty; --dry-run jen vypíše návrh (JSON)')
             ->addArgument('printId', InputArgument::REQUIRED, 'Id tisku (např. docs.invoicesOut.invoice)')
             ->addArgument('recordId', InputArgument::REQUIRED, 'Id záznamu v tabulce tisku')
             ->addOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Příjemce (lze opakovat); bez --dry-run povinné')
             ->addOption('cc', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Kopie (lze opakovat)')
             ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Adresa odesílatele — jedna z povolených; výchozí podle číselné řady / nastavení')
             ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Jazyk zprávy a tisku (cs | en | sk | de); výchozí podle partnera')
             ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Jen vypíše návrh odeslání, nic nevytvoří');
    }

    protected function getDataSourceDir(): string
    {
        return (string) getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $recordRaw = $input->getArgument('recordId');
        if (!is_string($recordRaw) || !ctype_digit($recordRaw) || (int) $recordRaw <= 0) {
            $err->writeln(sprintf(
                "<error>Invalid recordId '%s' (expected a positive integer)</error>",
                is_string($recordRaw) ? $recordRaw : '',
            ));
            return Command::INVALID;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $to     = AddressList::parse((array) $input->getOption('to'));
        $cc     = AddressList::parse((array) $input->getOption('cc'));
        if (!$dryRun && $to === []) {
            $err->writeln('<error>--to is required (the command never sends to addresses looked up from contacts); use --dry-run to see the proposal</error>');
            return Command::INVALID;
        }

        $from     = $input->getOption('from');
        $language = $input->getOption('language');

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $err->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $request = new SendRequest(
            printId: (string) $input->getArgument('printId'),
            recordId: (int) $recordRaw,
            language: is_string($language) && $language !== '' ? $language : null,
            from: is_string($from) && $from !== '' ? $from : null,
            to: $to === [] ? null : $to,
            cc: $cc === [] ? null : $cc,
            trigger: SendRequest::TRIGGER_CLI,
        );

        try {
            $service = $this->service ?? $this->createService($dsDir);

            if ($dryRun) {
                $output->write(
                    (string) json_encode(
                        $service->prepare($request)->toArray(),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                    ) . "\n",
                    false,
                    OutputInterface::OUTPUT_RAW,
                );
                return Command::SUCCESS;
            }

            $result = $service->send($request);
        } catch (PrintNotFoundException | \InvalidArgumentException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (RecordSendException $e) {
            $err->writeln("<error>{$e->errorCode}: {$e->getMessage()}</error>");
            return Command::FAILURE;
        } catch (PrintRecordNotFoundException | PrintNotAvailableException | PrintBuildException | PrintLanguageNotCompiledException $e) {
            $err->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        } catch (PrintRenderException $e) {
            $err->writeln("<error>PDF render failed ({$e->errorKind->value}): {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln("Sent message #{$result->sentMessageId}: outbox #{$result->outboxId}, transport '{$result->transportState}'");
        foreach ($result->messages as $message) {
            $err->writeln(sprintf('<comment>%s [%s]: %s</comment>', $message['severity'], $message['code'], $message['text']));
        }

        return Command::SUCCESS;
    }

    private function createService(string $dsDir): RecordSendService
    {
        $serverConfig = $this->serverConfig;
        if ($serverConfig === null) {
            $serverConfig = new ServerConfig();
            $serverConfig->load();
        }
        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);

        return RecordSendServiceFactory::create(
            $dsConfig,
            new DataSourceConnection($dsConfig),
            ModulePathResolver::fromServerConfig($serverConfig, dirname(__DIR__, 3) . '/modules'),
            $dsConfig->getDefaultLanguage(),
            $serverConfig,
        );
    }
}
