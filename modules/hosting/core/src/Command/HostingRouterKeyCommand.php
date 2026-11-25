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
 * API klíč mail-routeru pro endpoint GET /_hosting/mail/lookup (D4) —
 * zrcadlo `hosting-server-key` nad hosting_core_mail_routers.
 *
 * --generate vygeneruje token `shpd_hk_` + 43 url-safe znaků, na řádek
 * routeru uloží jen prefix (lookup) + SHA-256 hash a token vytiskne
 * JEDNOU — patří do sekce `lookup_sync.api_key` v config.yaml
 * mail-router stroje. --revoke prefix i hash vynuluje (router se
 * okamžitě odpojí, jede na stale lookup).
 *
 * Formát validuje sdílený HostingApiKeyAuthenticator (prefix lookup +
 * hash_equals nad sha256 celého tokenu).
 *
 * Spec: tasks/hosting-04-mail-router.md, docs/hosting.md §5.3.
 */
class HostingRouterKeyCommand extends Command
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
        $this->setName('hosting-router-key')
             ->setDescription('Generate or revoke the lookup API key of a hosting mail router')
             ->addOption('router', null, InputOption::VALUE_REQUIRED, 'Router row id (hosting_core_mail_routers.id)')
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

        $routerId = $input->getOption('router');
        $generate = (bool) $input->getOption('generate');
        $revoke = (bool) $input->getOption('revoke');

        if (!is_string($routerId) || $routerId === '' || !ctype_digit($routerId)) {
            $output->writeln('<error>Error: --router <id> is required</error>');
            return Command::FAILURE;
        }
        if ($generate === $revoke) {
            $output->writeln('<error>Error: pass exactly one of --generate or --revoke</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $row = $dsConnection->fetchRow(
            'SELECT id, name, domains FROM hosting_core_mail_routers WHERE id = %i',
            (int) $routerId,
        );
        if ($row === null) {
            $output->writeln("<error>Error: router '{$routerId}' not found in hosting_core_mail_routers.</error>");
            return Command::FAILURE;
        }

        if ($revoke) {
            $dsConnection->updateWhere(
                'hosting_core_mail_routers',
                [
                    'api_key_prefix' => null,
                    'api_key_hash' => null,
                    'modified' => date('Y-m-d H:i:s'),
                ],
                'id = %i',
                (int) $row['id'],
            );
            $output->writeln("<info>API key revoked: {$row['name']} ({$row['domains']})</info>");
            return Command::SUCCESS;
        }

        $random = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = self::TOKEN_PREFIX . $random;

        $dsConnection->updateWhere(
            'hosting_core_mail_routers',
            [
                'api_key_prefix' => substr($random, 0, self::KEY_PREFIX_LENGTH),
                'api_key_hash' => hash('sha256', $token),
                'modified' => date('Y-m-d H:i:s'),
            ],
            'id = %i',
            (int) $row['id'],
        );

        $output->writeln("<info>API key generated: {$row['name']} ({$row['domains']})</info>");
        $output->writeln('');
        $output->writeln('<comment>Router key (shown only once — put it into config.yaml "lookup_sync.api_key" on the mail-router machine):</comment>');
        $output->writeln($token);

        return Command::SUCCESS;
    }
}
