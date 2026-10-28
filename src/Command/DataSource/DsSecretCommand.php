<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Config\DataSourceSecrets;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\SecretsFileWriter;
use Shipard\Core\Server\MainConfigFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Tajemství zdroje dat v `secrets/` (#126 B1, U4, tasks/ds-secrets-split.md §5):
 *
 *   ds-secret                                  list (výchozí akce)
 *   ds-secret set mail-relay.password          heslo relay pošty
 *   ds-secret set oidc-<id>.client-secret      clientSecret OIDC providera <id>
 *
 * Ruční zadání toho, co správce dřív psal do `config/main.json` (GitHub,
 * Google, „Shipard ID“ z `hosting-oidc-client --generate`, relay pošty).
 * Hodnota **nikdy z argumentu**: interaktivní terminál → skrytý dotaz, jinak
 * stdin do EOF (jedno koncové `\n` se odstraní). `db.password` nastavit
 * nejde — musí sedět na uživatele databáze (zapisuje `ds-create`, přesouvá
 * `ds-upgrade`). Hodnota, která zůstala v `main.json`, se za běhu ignoruje
 * (B2); `list` ji ukáže vedle stavu souboru a `set` ji odtud odebere — jinak
 * by ji příští `ds-upgrade` přesunul přes nově zadanou hodnotu (B2 U2).
 * `list` čte jen přítomnost souborů, nikdy hodnoty ani délky.
 */
class DsSecretCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('ds-secret')
             ->setDescription('List or set data source secrets in secrets/ (db.password, mail-relay.password, oidc-<id>.client-secret): list | set <name> — value from a hidden prompt or STDIN, never an argument')
             ->addArgument('action', InputArgument::OPTIONAL, 'list | set', 'list')
             ->addArgument('name', InputArgument::OPTIONAL, 'Secret to set: ' . DataSourceSecrets::MAIL_RELAY_PASSWORD . ' | oidc-<id>.client-secret');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /** Stdin je terminál → skrytý dotaz; jinak se hodnota čte ze stdin (pipe, soubor). */
    protected function stdinIsTerminal(): bool
    {
        return \defined('STDIN') && @stream_isatty(STDIN);
    }

    /** Celý stdin do EOF — šev pro testy. */
    protected function readStdin(): string
    {
        $raw = \defined('STDIN') ? stream_get_contents(STDIN) : false;
        return $raw === false ? '' : $raw;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dsDir = $this->getDataSourceDir();
        if (!file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        try {
            $config = new DataSourceConfig($dsDir);
        } catch (\RuntimeException $e) {
            return $this->fail($output, 'config/main.json is unusable: ' . $e->getMessage());
        }

        $action = (string) $input->getArgument('action');
        return match ($action) {
            'list' => $this->runList($config, $dsDir, $output),
            'set' => $this->runSet($config, $dsDir, $input, $output),
            default => $this->fail($output, "Unknown action '{$action}'. Use: list | set " . DataSourceSecrets::MAIL_RELAY_PASSWORD . ' | set oidc-<id>.client-secret'),
        };
    }

    private function runList(DataSourceConfig $config, string $dsDir, OutputInterface $output): int
    {
        $secrets = $config->secrets();
        $slots = $config->getSecretSlots();
        $knownNames = [];
        $inMainConfig = 0;

        $table = new Table($output);
        $table->setHeaders(['Name', 'Used by', 'Status']);
        foreach ($slots as $slot) {
            $knownNames[] = $slot->name;
            if ($secrets->has($slot->name)) {
                $status = 'present';
            } else {
                $status = $slot->required ? 'missing' : 'missing (optional)';
            }
            if ($slot->inMainConfig) {
                $status .= '; main.json value ignored (run ds-upgrade)';
                $inMainConfig++;
            }
            $table->addRow([$slot->name, $slot->usedBy, $status]);
        }
        $table->render();

        $others = 0;
        $secretsDir = DsSecretCipher::secretsDirPath($dsDir);
        if (is_dir($secretsDir)) {
            foreach (scandir($secretsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || in_array($entry, $knownNames, true)) {
                    continue;
                }
                $others++;
            }
        }
        $output->writeln(sprintf('Other files in secrets/: %d (not managed by ds-secret)', $others));

        if ($inMainConfig > 0) {
            $output->writeln(sprintf(
                '<comment>%d secret(s) still in config/main.json — ignored; shpd-ds ds-upgrade moves them to secrets/ and overwrites the files.</comment>',
                $inMainConfig,
            ));
        }
        return Command::SUCCESS;
    }

    private function runSet(DataSourceConfig $config, string $dsDir, InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('name');
        if (!is_string($name) || $name === '') {
            return $this->fail($output, 'Usage: ds-secret set ' . DataSourceSecrets::MAIL_RELAY_PASSWORD . ' | oidc-<id>.client-secret');
        }
        if (!DataSourceSecrets::isValidName($name)) {
            return $this->fail($output, "Unknown secret name '{$name}'. Use: " . DataSourceSecrets::MAIL_RELAY_PASSWORD . ' | oidc-<id>.client-secret');
        }
        if ($name === DataSourceSecrets::DB_PASSWORD) {
            return $this->fail($output, DataSourceSecrets::DB_PASSWORD . ' must match the database user — ds-create writes it and ds-upgrade moves it; it cannot be set here.');
        }

        $value = $this->readSecretInput($name, $input, $output);
        if ($value === null) {
            return $this->fail($output, 'No value given — enter it at the prompt or pipe it to STDIN (never pass it as an argument).');
        }

        $slot = null;
        foreach ($config->getSecretSlots() as $candidate) {
            if ($candidate->name === $name) {
                $slot = $candidate;
                break;
            }
        }
        $providerId = DataSourceSecrets::oidcProviderIdFromName($name);
        if ($providerId !== null && $slot === null) {
            $output->writeln("<comment>  [WARN] No auth provider '{$providerId}' in config/main.json — the secret is written, add the provider to auth.providers to use it.</comment>");
        }

        try {
            $warnings = SecretsFileWriter::write($dsDir, $name, $value);
        } catch (\RuntimeException $e) {
            return $this->fail($output, 'Failed to write secrets/' . $name . ': ' . $e->getMessage());
        }
        foreach ($warnings as $warning) {
            $output->writeln('<comment>  [WARN] ' . $warning . '</comment>');
        }

        if ($slot !== null && $slot->inMainConfig) {
            try {
                MainConfigFile::patch($dsDir, static fn (array $raw): array => self::withoutSecret($raw, $name));
            } catch (\RuntimeException $e) {
                return $this->fail($output, "Secret {$name} written, but the old value could not be removed from config/main.json (" . $e->getMessage() . ') — it is ignored now, but the next ds-upgrade would move it over the new value; remove it by hand.');
            }
            $output->writeln("Secret {$name} written; the old value was removed from config/main.json.");
            return Command::SUCCESS;
        }

        $output->writeln("Secret {$name} written.");
        return Command::SUCCESS;
    }

    /**
     * Hodnota ze skrytého dotazu (terminál) nebo ze stdin do EOF (pipe);
     * jedno koncové `\n` / `\r\n` se odstraní (U2). Null = nic nezadáno.
     */
    protected function readSecretInput(string $name, InputInterface $input, OutputInterface $output): ?string
    {
        if (!$this->stdinIsTerminal()) {
            $value = DataSourceSecrets::stripTrailingNewline($this->readStdin());
            return $value !== '' ? $value : null;
        }

        if (!$input->isInteractive()) {
            return null;
        }

        $question = new Question("Value for {$name} (input hidden): ");
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable(false);

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $answer = $helper->ask($input, $output, $question);
        if (!is_string($answer)) {
            return null;
        }
        // QuestionHelper bez trimu nechává koncový řádek — stejné pravidlo jako u stdin (U2).
        $answer = DataSourceSecrets::stripTrailingNewline($answer);

        return $answer !== '' ? $answer : null;
    }

    /**
     * Odebere z dekódovaného main.json klíč tajemství `$name`
     * (`mail.relay.password` nebo `auth.providers[].clientSecret` providera).
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function withoutSecret(array $raw, string $name): array
    {
        if ($name === DataSourceSecrets::MAIL_RELAY_PASSWORD) {
            if (is_array($raw['mail']['relay'] ?? null)) {
                unset($raw['mail']['relay']['password']);
            }
            return $raw;
        }
        $providerId = DataSourceSecrets::oidcProviderIdFromName($name);
        if ($providerId !== null && is_array($raw['auth']['providers'] ?? null)) {
            foreach ($raw['auth']['providers'] as $i => $provider) {
                if (is_array($provider) && ($provider['id'] ?? null) === $providerId) {
                    unset($raw['auth']['providers'][$i]['clientSecret']);
                }
            }
        }
        return $raw;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln("<error>{$message}</error>");
        return Command::FAILURE;
    }
}
