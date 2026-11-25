<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\MailRouterProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Idempotentně vytvoří systémového uživatele `_mail_router` a výchozí schránku,
 * pokud chybí. Volá se automaticky z `ds-upgrade`, případně ručně pro existující
 * DS založené před Fází 2a.
 */
class MailRouterBootstrapCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-router-bootstrap')
             ->setDescription('Ensure _mail_router system user and default mailbox exist');
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

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $provisioner = new MailRouterProvisioner($dsConnection);
        $result = $provisioner->provision($dsConfig->getId());

        $this->reportUser($output, $result['user']);
        $this->reportMailbox($output, $result['mailbox']);

        return Command::SUCCESS;
    }

    /** @param array{id: int, created: bool} $user */
    private function reportUser(OutputInterface $output, array $user): void
    {
        if ($user['created']) {
            $output->writeln("<info>Created system user '_mail_router' (id={$user['id']})</info>");
        } else {
            $output->writeln("System user '_mail_router' already exists (id={$user['id']})");
        }
    }

    /** @param array{id: int, created: bool, skipped_reason?: string} $mailbox */
    private function reportMailbox(OutputInterface $output, array $mailbox): void
    {
        if ($mailbox['created']) {
            $output->writeln("<info>Created default mailbox (id={$mailbox['id']})</info>");
            return;
        }

        if (isset($mailbox['skipped_reason'])) {
            $output->writeln("<comment>Default mailbox 'default' not created: {$mailbox['skipped_reason']}</comment>");
            return;
        }

        $output->writeln("Default mailbox 'default' already exists (id={$mailbox['id']})");
    }
}
