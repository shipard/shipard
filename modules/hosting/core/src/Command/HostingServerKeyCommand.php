<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * API klíč serveru pro provisioning endpointy /_hosting/server/* (D3).
 *
 * --generate vygeneruje token `shpd_hk_` + 43 url-safe znaků, na řádek
 * serveru uloží jen prefix (lookup) + SHA-256 hash a token vytiskne
 * JEDNOU — patří do sekce `hosting.apiKey` v server.json DS serveru.
 * --revoke prefix i hash vynuluje (server se okamžitě odpojí).
 *
 * Formát musí zůstat kompatibilní s HostingServerController (prefix
 * lookup + hash_equals nad sha256 celého tokenu — vzor
 * AuthMiddleware::handleApiKey).
 *
 * Spec: tasks/hosting-03-provisioning-agent.md, docs/hosting.md §5.1/§5.2.
 */
class HostingServerKeyCommand extends Command
{
    public const TOKEN_PREFIX = 'shpd_hk_';
    public const KEY_PREFIX_LENGTH = 12;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hosting-server-key')
             ->setDescription('Generate or revoke the provisioning API key of a hosting server')
             ->addOption('server', null, InputOption::VALUE_REQUIRED, 'Server row id (hosting_core_servers.id)')
             ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a new key and print it once')
             ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke the current key');
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

        $serverId = $input->getOption('server');
        $generate = (bool) $input->getOption('generate');
        $revoke = (bool) $input->getOption('revoke');

        if (!is_string($serverId) || $serverId === '' || !ctype_digit($serverId)) {
            $output->writeln('<error>Error: --server <id> is required</error>');
            return Command::FAILURE;
        }
        if ($generate === $revoke) {
            $output->writeln('<error>Error: pass exactly one of --generate or --revoke</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $row = $dsConnection->fetchRow(
            'SELECT id, name, fqdn FROM hosting_core_servers WHERE id = %i',
            (int) $serverId,
        );
        if ($row === null) {
            $output->writeln("<error>Error: server '{$serverId}' not found in hosting_core_servers.</error>");
            return Command::FAILURE;
        }

        if ($revoke) {
            $dsConnection->updateWhere(
                'hosting_core_servers',
                [
                    'api_key_prefix' => null,
                    'api_key_hash' => null,
                    'modified' => date('Y-m-d H:i:s'),
                ],
                'id = %i',
                (int) $row['id'],
            );
            $output->writeln("<info>API key revoked: {$row['name']} ({$row['fqdn']})</info>");
            return Command::SUCCESS;
        }

        $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = self::TOKEN_PREFIX . $random;

        $dsConnection->updateWhere(
            'hosting_core_servers',
            [
                'api_key_prefix' => substr($random, 0, self::KEY_PREFIX_LENGTH),
                'api_key_hash' => hash('sha256', $token),
                'modified' => date('Y-m-d H:i:s'),
            ],
            'id = %i',
            (int) $row['id'],
        );

        $output->writeln("<info>API key generated: {$row['name']} ({$row['fqdn']})</info>");
        $output->writeln('');
        $output->writeln('<comment>Server key (shown only once — put it into server.json "hosting.apiKey" on the DS server):</comment>');
        $output->writeln($token);

        return Command::SUCCESS;
    }
}
