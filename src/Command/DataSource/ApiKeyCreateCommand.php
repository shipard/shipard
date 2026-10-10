<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\ApiKeyService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generický příkaz pro vytvoření API klíče pro libovolného uživatele.
 * Vrací plaintext jen jednou — v DB zůstává SHA-256 hash a 12-znakový prefix.
 *
 * Doplněk k role-specifickému `mail-router-setup`.
 */
class ApiKeyCreateCommand extends Command
{
    private const NAME_MAX_LENGTH = 100;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('api-key-create')
             ->setDescription('Create a new API key for an existing user')
             ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Login, email or numeric ID of an existing user')
             ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Human-readable label (max 100 chars)')
             ->addOption(
                 'ip',
                 null,
                 InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                 'Allowed source IP — repeat or comma-separated. Omit for no IP restriction.',
             )
             ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'Expiration: YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, or relative (+30d, +1 year)');
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

        $userArg = $input->getOption('user');
        $name = $input->getOption('name');

        if (empty($userArg)) {
            $output->writeln('<error>Error: Option --user is required</error>');
            return Command::FAILURE;
        }
        if (empty($name)) {
            $output->writeln('<error>Error: Option --name is required</error>');
            return Command::FAILURE;
        }
        if (strlen((string) $name) > self::NAME_MAX_LENGTH) {
            $output->writeln('<error>Error: --name must be at most ' . self::NAME_MAX_LENGTH . ' characters</error>');
            return Command::FAILURE;
        }

        $allowedIps = $this->parseIps((array) $input->getOption('ip'));
        foreach ($allowedIps as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                $output->writeln("<error>Error: '{$ip}' is not a valid IP address</error>");
                return Command::FAILURE;
            }
        }

        $expiresAt = null;
        $expiresInput = $input->getOption('expires');
        if ($expiresInput !== null && $expiresInput !== '') {
            $parsed = $this->parseExpires((string) $expiresInput);
            if ($parsed === null) {
                $output->writeln(
                    "<error>Error: --expires value '{$expiresInput}' is not a valid date. "
                    . 'Use YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, or relative (+30d, +1 year).</error>',
                );
                return Command::FAILURE;
            }
            $expiresAt = $parsed;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        $service = new ApiKeyService($dsConnection);

        $matches = $service->findUserMatches((string) $userArg);
        if (count($matches) === 0) {
            $output->writeln("<error>Error: User '{$userArg}' not found.</error>");
            return Command::FAILURE;
        }
        if (count($matches) > 1) {
            $output->writeln("<error>Error: User '{$userArg}' is ambiguous — use --user=<id> to disambiguate.</error>");
            return Command::FAILURE;
        }
        $user = $matches[0];

        $result = $service->createKey(
            (int) $user['id'],
            (string) $name,
            $allowedIps,
            $expiresAt,
        );

        $output->writeln('');
        $output->writeln('<info>API Key created for data source ' . $dsConfig->getId() . ':</info>');
        $output->writeln('');
        $output->writeln('    ' . $result['plaintext']);
        $output->writeln('');
        $output->writeln('<comment>IMPORTANT: This is the only time this key will be displayed.</comment>');
        $output->writeln('');
        $output->writeln('User:         ' . $user['login'] . ' (id=' . $user['id'] . ')');
        $output->writeln('Key name:     ' . $name);
        $output->writeln('Key ID:       ' . $result['id']);
        $output->writeln('Key prefix:   ' . $result['keyPrefix']);
        $output->writeln('Allowed IPs:  ' . ($allowedIps === [] ? '(none)' : implode(', ', $allowedIps)));
        $output->writeln('Expires:      ' . ($expiresAt !== null ? $expiresAt->format('Y-m-d H:i:s') : '(never)'));
        $output->writeln('Created:      ' . date('Y-m-d H:i:s'));

        return Command::SUCCESS;
    }

    /**
     * Expanduje shorthand `+30d` / `+1y` na full form `+30 days` / `+1 year`
     * (PHP's parser tyhle krátké tvary ignoruje). Ostatní formáty (YYYY-MM-DD,
     * `+1 month`, `next monday`, …) procházejí beze změny do DateTimeImmutable.
     */
    private function parseExpires(string $raw): ?\DateTimeImmutable
    {
        $expanded = preg_replace_callback(
            '/^([+-]?\d+)\s*([dy])$/i',
            fn(array $m): string => $m[1] . ' ' . (strtolower($m[2]) === 'd' ? 'days' : 'years'),
            $raw,
        ) ?? $raw;

        try {
            return new \DateTimeImmutable($expanded);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * `--ip=a,b --ip=c` → ['a', 'b', 'c']. Trimuje a vyhazuje prázdné položky.
     *
     * @param string[] $raw
     * @return string[]
     */
    private function parseIps(array $raw): array
    {
        $out = [];
        foreach ($raw as $entry) {
            foreach (explode(',', $entry) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $out[] = $piece;
                }
            }
        }
        return $out;
    }

}
