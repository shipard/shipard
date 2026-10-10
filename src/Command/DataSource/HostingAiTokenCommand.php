<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Hosting\AiGwToken;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Hosting\Core\HostingAiTokenDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Gateway tokeny AI gateway (D5) — ruční vydání/revokace nad
 * hosting_core_ai_tokens (backfill existujících DS; nové požadavky
 * mintuje queue payload sám).
 *
 * --generate vygeneruje token `shpd_gw_` + 43 url-safe znaků, uloží
 * prefix (lookup) + SHA-256 hash + šifrovaný plaintext (queue payload)
 * a token vytiskne JEDNOU — patří do `ai-backend-set-key --api-key`
 * na klientském DS. --revoke nastaví active = 0 (gateway token okamžitě
 * odmítá, 401).
 *
 * Spec: tasks/hosting-05-ai-gateway.md, docs/hosting.md §5.5.
 */
class HostingAiTokenCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?DsSecretCipher $cipher = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hosting-ai-token')
             ->setDescription('Generate or revoke an AI gateway token for a data source')
             ->addOption('ds', null, InputOption::VALUE_REQUIRED, 'Data source row id (hosting_core_data_sources.id)')
             ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate a new token and print it once')
             ->addOption('revoke', null, InputOption::VALUE_REQUIRED, 'Revoke a token by its row id (hosting_core_ai_tokens.id)')
             ->addOption('note', null, InputOption::VALUE_REQUIRED, 'Optional note stored on the token row');
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

        $generate = (bool) $input->getOption('generate');
        $revoke = $input->getOption('revoke');

        if ($generate === ($revoke !== null)) {
            $output->writeln('<error>Error: pass exactly one of --generate or --revoke <id></error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        if ($revoke !== null) {
            return $this->revokeToken($dsConnection, (string) $revoke, $output);
        }

        return $this->generateToken($input, $dsConfig, $dsConnection, $output);
    }

    private function revokeToken(DataSourceConnection $db, string $tokenId, OutputInterface $output): int
    {
        if ($tokenId === '' || !ctype_digit($tokenId)) {
            $output->writeln('<error>Error: --revoke expects a numeric token row id</error>');
            return Command::FAILURE;
        }

        $row = $db->fetchRow(
            'SELECT id, token_prefix, active FROM hosting_core_ai_tokens WHERE id = %i',
            (int) $tokenId,
        );
        if ($row === null) {
            $output->writeln("<error>Error: token '{$tokenId}' not found in hosting_core_ai_tokens.</error>");
            return Command::FAILURE;
        }

        $db->updateWhere(
            'hosting_core_ai_tokens',
            [
                'active' => 0,
                'modified' => date('Y-m-d H:i:s'),
            ],
            'id = %i',
            (int) $row['id'],
        );

        $output->writeln("<info>Token revoked: {$row['token_prefix']}…</info>");
        return Command::SUCCESS;
    }

    private function generateToken(
        InputInterface $input,
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        OutputInterface $output,
    ): int {
        $dsId = $input->getOption('ds');
        if (!is_string($dsId) || $dsId === '' || !ctype_digit($dsId)) {
            $output->writeln('<error>Error: --ds <id> is required for --generate</error>');
            return Command::FAILURE;
        }

        $ds = $db->fetchRow(
            'SELECT id, ds_id, name, lifecycle FROM hosting_core_data_sources WHERE id = %i',
            (int) $dsId,
        );
        if ($ds === null) {
            $output->writeln("<error>Error: data source '{$dsId}' not found in hosting_core_data_sources.</error>");
            return Command::FAILURE;
        }

        try {
            $cipher = $this->cipher ?? DsSecretCipher::forConfig($dsConfig);
        } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            $output->writeln("Run 'shpd-ds ds-secrets-health' for details.");
            return Command::FAILURE;
        }

        $minted = AiGwToken::mint();

        $doc = new HostingAiTokenDocument();
        $doc->setSecretCipher($cipher);
        $data = [
            'data_source'     => (int) $ds['id'],
            'token_prefix'    => $minted['prefix'],
            'token_hash'      => $minted['hash'],
            'token_encrypted' => $minted['token'],
            'active'          => 1,
        ];
        $note = $input->getOption('note');
        if (is_string($note) && $note !== '') {
            $data['note'] = $note;
        }
        $doc->beforeSave($data);

        $db->insertRow('hosting_core_ai_tokens', $data);

        $dsLabel = trim((string) ($ds['name'] ?? '')) !== '' ? $ds['name'] : $ds['ds_id'];
        $output->writeln("<info>Gateway token generated: {$dsLabel} ({$ds['ds_id']})</info>");
        if ((string) $ds['lifecycle'] !== 'active') {
            $output->writeln("<comment>Warning: data source lifecycle is '{$ds['lifecycle']}' — the gateway only accepts tokens of active data sources.</comment>");
        }
        $output->writeln('');
        $output->writeln('<comment>Gateway token (shown only once — use it as --api-key of ai-backend-set-key on the client data source):</comment>');
        $output->writeln($minted['token']);

        return Command::SUCCESS;
    }
}
