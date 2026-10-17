<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UserCreateCommand extends Command
{
    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('user-create')
             ->setDescription('Create a new user in the data source')
             ->addOption('login', null, InputOption::VALUE_REQUIRED, 'Login name')
             ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'Password on the command line (prefer --password-stdin; omit both to create the account without a local password — send an invitation instead)')
             ->addOption('password-stdin', null, InputOption::VALUE_NONE, 'Read the password from the first line of STDIN — never lands in argv, shell history or process listings')
             ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Full name')
             ->addOption('email', null, InputOption::VALUE_OPTIONAL, 'E-mail address')
             ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant administrator rights')
             ->addOption('if-not-exists', null, InputOption::VALUE_NONE, 'Existing login is not an error — reuse the account (idempotent provisioning)')
             ->addOption('identity-issuer', null, InputOption::VALUE_REQUIRED, 'OIDC issuer for pre-linked identity (requires --identity-subject)')
             ->addOption('identity-subject', null, InputOption::VALUE_REQUIRED, 'OIDC subject for pre-linked identity (requires --identity-issuer)')
             ->addOption('identity-provider', null, InputOption::VALUE_REQUIRED, 'Provider id stored with the identity (default: oidc)', 'oidc');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /**
     * První řádek STDIN tak, jak přišel (včetně konce řádku); null = žádný
     * vstup (EOF, zavřený stdin). Šev pro testy (#103 D65).
     */
    protected function readPasswordLine(): ?string
    {
        if (!\defined('STDIN')) {
            return null;
        }
        $line = fgets(STDIN);
        return $line === false ? null : $line;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $login = $input->getOption('login');
        $password = $input->getOption('password');
        $passwordStdin = (bool) $input->getOption('password-stdin');
        $fullName = $input->getOption('name');
        $email = $input->getOption('email');
        $isAdmin = (bool) $input->getOption('admin');
        $ifNotExists = (bool) $input->getOption('if-not-exists');
        $identityIssuer = $input->getOption('identity-issuer');
        $identitySubject = $input->getOption('identity-subject');

        if (empty($login)) {
            $output->writeln('<error>Error: Option --login is required</error>');
            return Command::FAILURE;
        }
        if (empty($fullName)) {
            $output->writeln('<error>Error: Option --name is required</error>');
            return Command::FAILURE;
        }
        if (($identityIssuer === null) !== ($identitySubject === null)) {
            $output->writeln('<error>Error: --identity-issuer and --identity-subject must be used together</error>');
            return Command::FAILURE;
        }

        // Heslo ze stdin (#103 D65): první řádek bez konce řádku; s --password
        // zároveň je to chyba, prázdný nebo žádný řádek také. Hodnota jde jen
        // do hashe — nikdy do výstupu.
        if ($passwordStdin) {
            if ($password !== null) {
                $output->writeln('<error>Error: --password and --password-stdin are mutually exclusive</error>');
                return Command::FAILURE;
            }
            $line = $this->readPasswordLine();
            $password = $line === null ? '' : rtrim($line, "\r\n");
            if ($password === '') {
                $output->writeln('<error>Error: No password on STDIN (--password-stdin reads the first line)</error>');
                return Command::FAILURE;
            }
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $dsConnection = $this->dsConnection ?? new DataSourceConnection($dsConfig);

        $existing = $dsConnection->fetchRow(
            'SELECT id FROM core_system_users WHERE login = %s',
            $login,
        );

        if ($existing !== null && !$ifNotExists) {
            $output->writeln("<error>Error: User with login '{$login}' already exists.</error>");
            return Command::FAILURE;
        }

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $output->writeln("User with login '{$login}' already exists (ID {$id}) — nothing to create.");
        } else {
            // Bez hesla = účet bez lokálního loginu (NULL hash) — heslo si
            // uživatel nastaví přes pozvánku (POST /_users/{id}/invite).
            $passwordHash = empty($password) ? null : password_hash($password, PASSWORD_DEFAULT);

            $id = $dsConnection->insertRow('core_system_users', [
                'login'         => $login,
                'password_hash' => $passwordHash,
                'full_name'     => $fullName,
                'email'         => $email ?: null,
                'is_active'     => 1,
                'is_admin'      => $isAdmin ? 1 : 0,
            ]);

            $output->writeln('User created successfully.');
            $output->writeln("  ID:    {$id}");
            $output->writeln("  Login: {$login}");
            $output->writeln("  Name:  {$fullName}");
            $output->writeln('  Email: ' . ($email ?: '(none)'));
            $output->writeln('  Admin: ' . ($isAdmin ? 'yes' : 'no'));
            if ($passwordHash === null) {
                $output->writeln('  Password: (none — send an invitation to let the user set one)');
            }
        }

        if ($identityIssuer !== null && $identitySubject !== null) {
            $linked = $this->ensureIdentity(
                $dsConnection,
                $output,
                $id,
                (string) $input->getOption('identity-provider'),
                (string) $identityIssuer,
                (string) $identitySubject,
                is_string($email) && $email !== '' ? $email : null,
            );
            if (!$linked) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Předpropojení identity (U2): zajistí řádek core_system_user_identities
     * (issuer, subject) → user. Existující vazba na téhož uživatele = no-op
     * (idempotence agenta), na jiného uživatele = chyba.
     */
    private function ensureIdentity(
        DataSourceConnection $dsConnection,
        OutputInterface $output,
        int $userId,
        string $provider,
        string $issuer,
        string $subject,
        ?string $email,
    ): bool {
        $issuer = rtrim($issuer, '/');

        $existing = $dsConnection->fetchRow(
            'SELECT id, user_id FROM core_system_user_identities WHERE issuer = %s AND subject = %s',
            $issuer,
            $subject,
        );

        if ($existing !== null) {
            if ((int) $existing['user_id'] !== $userId) {
                $output->writeln(
                    "<error>Error: identity ({$issuer}, {$subject}) is already linked to user ID "
                    . "{$existing['user_id']} — refusing to relink.</error>",
                );
                return false;
            }
            $output->writeln('Identity already linked — nothing to do.');
            return true;
        }

        $dsConnection->insertRow('core_system_user_identities', [
            'user_id'       => $userId,
            'provider'      => $provider,
            'issuer'        => $issuer,
            'subject'       => $subject,
            'email_at_link' => $email,
            'created'       => date('Y-m-d H:i:s'),
            'last_login'    => null,
        ]);

        $output->writeln('Identity linked:');
        $output->writeln("  Issuer:  {$issuer}");
        $output->writeln("  Subject: {$subject}");
        return true;
    }
}
