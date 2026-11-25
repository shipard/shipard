<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core\Command;

use SensitiveParameter;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Hosting\Core\HostingDataSourceDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Registrace/aktualizace OIDC klienta OP: nastaví oidc_client_secret
 * a oidc_redirect_uri na řádku hosting_core_data_sources. Secret šifruje
 * přes Document hook HostingDataSourceDocument::beforeSave (single source
 * of truth pro encrypted_text — nikdy inline v CLI).
 *
 * --generate vygeneruje secret na serveru a vytiskne ho JEDNOU — stejné
 * rozhraní použije provisioning agent ve Fázi 2. Plaintext se nesmí
 * objevit v DB ani logu.
 *
 * Spec: tasks/hosting-02-oidc-op.md, docs/hosting.md §5.4 (D2).
 */
class HostingOidcClientCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hosting-oidc-client')
             ->setDescription('Register a data source as an OIDC OP client — set client secret and redirect URI')
             ->addOption('ds', null, InputOption::VALUE_REQUIRED, 'Client data source ID (ds_id, format xxxx-xxxx-xxxx-xxxx)')
             ->addOption('redirect-uri', null, InputOption::VALUE_REQUIRED, 'Registered redirect URI (exact match against authorize requests)')
             ->addOption('secret', null, InputOption::VALUE_REQUIRED, 'Plaintext client secret to encrypt and store')
             ->addOption('generate', null, InputOption::VALUE_NONE, 'Generate the client secret server-side and print it once');
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

        $dsId = (string) $input->getOption('ds');
        $redirectUri = $input->getOption('redirect-uri');
        $secret = $input->getOption('secret');
        $generate = (bool) $input->getOption('generate');

        if ($dsId === '') {
            $output->writeln('<error>Error: --ds is required</error>');
            return Command::FAILURE;
        }
        if ($generate && $secret !== null) {
            $output->writeln('<error>Error: use either --secret or --generate, not both</error>');
            return Command::FAILURE;
        }
        if (!$generate && ($secret === null || $secret === '') && ($redirectUri === null || $redirectUri === '')) {
            $output->writeln('<error>Error: nothing to do — pass --redirect-uri and/or --secret|--generate</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        try {
            $cipher = DsSecretCipher::forConfig($dsConfig);
        } catch (SecretsKeyMissingException | SecretsKeyInsecureException $e) {
            $output->writeln('<error>Secrets key error: ' . $e->getMessage() . '</error>');
            $output->writeln('<comment>Run "shpd-ds ds-secrets-health" for diagnostics.</comment>');
            return Command::FAILURE;
        }

        $row = $dsConnection->fetchRow(
            'SELECT id, name FROM hosting_core_data_sources WHERE ds_id = %s',
            $dsId,
        );
        if ($row === null) {
            $output->writeln("<error>Error: data source '{$dsId}' not found in hosting_core_data_sources.</error>");
            return Command::FAILURE;
        }

        if ($generate) {
            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }

        $this->storeClient(
            $dsConnection,
            (int) $row['id'],
            $cipher,
            $secret !== null && $secret !== '' ? (string) $secret : null,
            $redirectUri !== null && $redirectUri !== '' ? (string) $redirectUri : null,
        );

        $output->writeln("<info>OIDC client updated: {$row['name']} ({$dsId})</info>");
        if ($redirectUri !== null && $redirectUri !== '') {
            $output->writeln("redirect_uri: {$redirectUri}");
        }
        if ($generate) {
            $output->writeln('');
            $output->writeln('<comment>Client secret (shown only once — put it into the client DS auth.providers):</comment>');
            $output->writeln((string) $secret);
        } elseif ($secret !== null && $secret !== '') {
            $output->writeln('Client secret stored (encrypted).');
        }

        return Command::SUCCESS;
    }

    private function storeClient(
        DataSourceConnection $dsConnection,
        int $rowId,
        DsSecretCipher $cipher,
        #[SensitiveParameter]
        ?string $secret,
        ?string $redirectUri,
    ): void {
        $doc = new HostingDataSourceDocument();
        $doc->setSecretCipher($cipher);

        $data = ['id' => $rowId];
        if ($secret !== null) {
            $data['oidc_client_secret'] = $secret;
        }
        if ($redirectUri !== null) {
            $data['oidc_redirect_uri'] = $redirectUri;
        }
        $doc->beforeSave($data);

        unset($data['id']);
        $dsConnection->updateWhere(
            'hosting_core_data_sources',
            $data,
            'id = %i',
            $rowId,
        );
    }
}
