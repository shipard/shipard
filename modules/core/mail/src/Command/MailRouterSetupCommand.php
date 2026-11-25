<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Mail\Command;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\MailRouterProvisioner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Vygeneruje (nebo zrotuje) API klíč pro systémového uživatele `_mail_router`.
 * Plaintext klíč se zobrazí jen jednou — ukládá se pouze sha256 hash.
 *
 * S `--json` je stdout jediný JSON objekt {"api_key": ..., "user_id": N}
 * (žádné dekorace, chyby jdou na stderr) — strojové rozhraní pro
 * provisioning agenta `hosting-sync` (D4).
 */
class MailRouterSetupCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('mail-router-setup')
             ->setDescription('Generate (or rotate) the API key used by the external mail-router')
             ->addOption('force', null, InputOption::VALUE_NONE, 'Deactivate existing active key and create a new one')
             ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Restrict the key to a single source IP address')
             ->addOption('json', null, InputOption::VALUE_NONE, 'Print a single JSON object {"api_key", "user_id"} to stdout');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        // V json módu jdou lidské hlášky (chyby, poznámky) na stderr, aby
        // stdout zůstal parsovatelný. CommandTester dává OutputInterface bez
        // getErrorOutput() — fallback na hlavní output.
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $human = $json ? $err : $output;

        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $human->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $provisioner = new MailRouterProvisioner($dsConnection);
        $user = $provisioner->ensureRouterUser();

        if ($user['created'] && !$json) {
            $output->writeln("<info>Created system user '_mail_router' (id={$user['id']})</info>");
        }

        $force = (bool) $input->getOption('force');
        $ip = $input->getOption('ip');
        if ($ip !== null && filter_var((string) $ip, FILTER_VALIDATE_IP) === false) {
            $human->writeln('<error>Error: --ip value is not a valid IP address</error>');
            return Command::FAILURE;
        }

        $existingActive = $dsConnection->fetchRow(
            'SELECT id FROM core_system_api_keys WHERE user_id = %i AND name = %s AND is_active = %i',
            $user['id'],
            'mail-router',
            1,
        );

        if ($existingActive !== null && !$force) {
            $human->writeln('<error>Error: An active mail-router API key already exists. Use --force to rotate it.</error>');
            return Command::FAILURE;
        }

        if ($existingActive !== null && $force) {
            $dsConnection->execute(
                'UPDATE core_system_api_keys SET is_active = %i, modified = %s WHERE user_id = %i AND name = %s AND is_active = %i',
                0,
                date('Y-m-d H:i:s'),
                $user['id'],
                'mail-router',
                1,
            );
            if (!$json) {
                $output->writeln('<comment>Existing key deactivated.</comment>');
            }
        }

        $plaintext = self::generateToken();
        $keyPart = substr($plaintext, strlen('shpd_ak_'));
        $keyPrefix = substr($keyPart, 0, 12);
        $keyHash = hash('sha256', $plaintext);

        $now = date('Y-m-d H:i:s');
        $dsConnection->insertRow('core_system_api_keys', [
            'user_id' => $user['id'],
            'name' => 'mail-router',
            'key_hash' => $keyHash,
            'key_prefix' => $keyPrefix,
            'expires_at' => null,
            'allowed_ips' => $ip !== null ? json_encode([$ip]) : null,
            'is_active' => 1,
            'last_used_at' => null,
            'created' => $now,
            'modified' => $now,
        ]);

        if ($json) {
            $output->writeln((string) json_encode(
                ['api_key' => $plaintext, 'user_id' => (int) $user['id']],
                JSON_UNESCAPED_SLASHES,
            ));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<info>API Key created for data source ' . $dsConfig->getId() . ':</info>');
        $output->writeln('');
        $output->writeln('    ' . $plaintext);
        $output->writeln('');
        $output->writeln('<comment>IMPORTANT: This is the only time this key will be displayed.</comment>');
        $output->writeln('Store it in the mail-router configuration (e.g. /etc/shipard-mail-router/lookup.json).');

        if ($ip !== null) {
            $output->writeln('Allowed source IP: ' . $ip);
        }

        return Command::SUCCESS;
    }

    /**
     * `shpd_ak_` + 32 hex chars — konzistentní s AuthMiddleware očekávaným formátem.
     */
    public static function generateToken(): string
    {
        return 'shpd_ak_' . bin2hex(random_bytes(16));
    }
}
