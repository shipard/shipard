<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\AddressList;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\MailServiceFactory;
use Shipard\Core\Mail\OutboundMessage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Smoke test transportu při zřizování — synchronně odešle testovací
 * zprávu (enqueue + okamžitý pokus), výsledek vč. SMTP odpovědi na
 * stdout. Zpráva se zapíše do outboxu; při selhání ji převezme cron.
 * Zásah pojistky odchozí pošty (#95) výstup řekne — zachycená zpráva
 * končí jako odeslaná, i když nikam neodešla.
 */
class MailSendTestCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?MailOutboxService $service = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-send-test')
            ->setDescription('Send a test message through the outbound mail transport')
            ->addOption('to', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Recipient address (repeatable)')
            ->addOption('cc', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Copy address (repeatable)')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'From address (default: mail.defaultFrom setting)')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject', 'Shipard mail-send-test');
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

        $to = AddressList::parse((array) $input->getOption('to'));
        if ($to === []) {
            $output->writeln('<error>Error: --to is required</error>');
            return Command::FAILURE;
        }

        try {
            $dsConfig     = $this->dsConfig ?? new DataSourceConfig($dsDir);
            $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
            $service      = $this->service ?? MailServiceFactory::create($dsConfig, $dsConnection);

            $message = new OutboundMessage(
                to: $to,
                subject: (string) $input->getOption('subject'),
                sourceModule: 'core.mail',
                from: $input->getOption('from'),
                bodyText: sprintf(
                    "Shipard outbound mail test.\n\nHost: %s\nData source: %s\nTime: %s\n",
                    gethostname() ?: 'unknown',
                    $dsConfig->getId(),
                    date('Y-m-d H:i:s'),
                ),
                sourceRef: 'send-test',
                cc: AddressList::parse((array) $input->getOption('cc')),
            );

            $id = $service->enqueue($message);
            $sent = $service->attemptSend($id);

            $row = $dsConnection->fetchRow('SELECT * FROM core_mail_outbox WHERE id = %i', $id);
            $log = $dsConnection->fetchRow(
                'SELECT * FROM core_mail_outbox_log WHERE outbox_id = %i ORDER BY id DESC LIMIT 1',
                $id,
            );

            $output->writeln("Outbox #{$id}: state '" . ($row['state'] ?? '?') . "'");
            if ($log !== null) {
                $output->writeln('Transport: ' . $log['transport']);
                $output->writeln('Duration:  ' . ($log['duration_ms'] ?? '?') . ' ms');
                if (($log['smtp_response'] ?? '') !== '') {
                    $output->writeln('Response:  ' . $log['smtp_response']);
                }
            }
            $safety = match ((string) ($row['safety_action'] ?? '')) {
                'dropped'    => 'held by mail safety — nothing was sent',
                'redirected' => ($row['safety_target'] ?? '') !== ''
                    ? 'redirected to ' . $row['safety_target']
                    : 'recipients restricted by mail safety',
                default      => null,
            };
            if ($safety !== null) {
                $output->writeln("<comment>Safety:    {$safety}</comment>");
            }
            if (!$sent && ($row['last_error'] ?? '') !== '') {
                $output->writeln('<error>Error: ' . $row['last_error'] . '</error>');
            }

            return $sent ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }
}
