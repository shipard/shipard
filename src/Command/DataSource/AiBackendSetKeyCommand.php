<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use SensitiveParameter;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Security\Exception\SecretsKeyInsecureException;
use Shipard\Core\Security\Exception\SecretsKeyMissingException;
use Shipard\Module\Core\Ai\AIBackendDocument;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

/**
 * Nastaví API klíč existujícímu AI backendu — pro všechny AI cesty
 * (analýza pošty, chat, shrnutí dashboardu, obsahové štítky). Klíč šifruje
 * přes DsSecretCipher (Document hook AIBackendDocument::beforeSave)
 * a nastaví is_active=1.
 *
 * Klíč přijímá skrytým vstupem (interaktivní prompt, nebo STDIN pipe),
 * případně přes `--api-key` — ten zůstává pro provisioning agenta
 * hostingu (`HostingSyncRunner`), kde argv nejde přes shell. Příkaz
 * hodnotu nikdy neloguje — threat model je „plaintext nesmí ležet v DB
 * ani logu“ (CLAUDE.md „Citlivá data“, docs/operations/secrets.md).
 *
 * `ai-analyzer-set-key` je alias z doby externího analyzeru
 * (tasks/ai-analyzer-removal.md D21) — jedno vydání s upozorněním na stderr.
 */
class AiBackendSetKeyCommand extends Command
{
    public const NAME = 'ai-backend-set-key';
    public const LEGACY_ALIAS = 'ai-analyzer-set-key';

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName(self::NAME)
             ->setAliases([self::LEGACY_ALIAS])
             ->setDescription('Set (or rotate) the API key of an AI backend — hidden prompt or --api-key; encrypts via DsSecretCipher')
             ->addOption('backend', null, InputOption::VALUE_REQUIRED, 'Backend code (default: "default")', 'default')
             ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Plaintext API key (otherwise read hidden from the prompt / STDIN)')
             ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL of the API (empty string resets to direct Anthropic)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if ($input->getFirstArgument() === self::LEGACY_ALIAS) {
            $err->writeln(sprintf(
                '<comment>Warning: "%s" is a deprecated alias — use "%s".</comment>',
                self::LEGACY_ALIAS,
                self::NAME,
            ));
        }

        $dsDir = $this->getDataSourceDir();

        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $output->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $backendCode = (string) $input->getOption('backend');
        $apiKey = $input->getOption('api-key');
        if ($apiKey === null || $apiKey === '') {
            $apiKey = $this->readKeyInput($input, $output);
        }
        if ($apiKey === null || $apiKey === '') {
            $output->writeln('<error>Error: no API key given — enter it at the prompt, pipe it to STDIN, or pass --api-key</error>');
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
            'SELECT id FROM core_ai_backends WHERE backend_id = %s',
            $backendCode,
        );
        if ($row === null) {
            $output->writeln("<error>Error: backend '{$backendCode}' not found.</error>");
            $output->writeln('<comment>Run "shpd-ds ds-upgrade" first — it creates the default backend.</comment>');
            return Command::FAILURE;
        }

        $backendId = (int) $row['id'];

        // --base-url: hodnota → nastavit (AI gateway, D5/D6); prázdný string
        // → NULL = přímé Anthropic; nezadaná option → sloupec netknout.
        $baseUrl = $input->getOption('base-url');

        $this->encryptAndStoreKey($dsConnection, $backendId, $cipher, (string) $apiKey, $baseUrl);

        $output->writeln("<info>API key updated for backend '{$backendCode}' (id={$backendId}).</info>");
        if ($baseUrl !== null) {
            $output->writeln($baseUrl !== ''
                ? "Base URL set to: {$baseUrl}"
                : 'Base URL cleared (direct Anthropic).');
        }
        $output->writeln('Backend is now active.');

        return Command::SUCCESS;
    }

    /**
     * Klíč z interaktivního promptu (skrytý vstup) nebo ze STDIN pipe —
     * vzor HostingAiGwInitCommand; protected kvůli test seamu. Null =
     * nic nezadáno (neinteraktivní běh bez pipe).
     */
    protected function readKeyInput(InputInterface $input, OutputInterface $output): ?string
    {
        if (function_exists('stream_isatty') && !@stream_isatty(STDIN)) {
            $piped = stream_get_contents(STDIN);
            $piped = $piped === false ? '' : trim($piped);
            return $piped !== '' ? $piped : null;
        }

        if (!$input->isInteractive()) {
            return null;
        }

        $question = new Question('API key (input hidden): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setTrimmable(true);

        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $answer = $helper->ask($input, $output, $question);

        return is_string($answer) && $answer !== '' ? $answer : null;
    }

    /**
     * Šifrování přes Document beforeSave — ten je single source of truth pro
     * encrypted_text columns, viz docs/operations/secrets.md a CLAUDE.md.
     * Nikdy nešifrujeme inline v CLI, abychom se nerozcházeli s aplikační
     * vrstvou (nonce semantics, error mapping).
     */
    private function encryptAndStoreKey(
        DataSourceConnection $dsConnection,
        int $backendId,
        DsSecretCipher $cipher,
        #[SensitiveParameter]
        string $apiKey,
        ?string $baseUrl = null,
    ): void {
        $doc = new AIBackendDocument();
        $doc->setSecretCipher($cipher);

        $now = date('Y-m-d H:i:s');
        $data = [
            'id' => $backendId,
            'api_key' => $apiKey,
        ];
        $doc->beforeSave($data);

        // beforeSave nahradil plaintext ciphertextem; prázdný klíč by pole
        // odstranil — ten ale odmítá guard v execute().
        if (!array_key_exists('api_key', $data)) {
            throw new \RuntimeException('Internal error: api_key disappeared after encryption.');
        }

        $update = [
            'api_key' => $data['api_key'],
            'is_active' => 1,
            'modified' => $now,
        ];
        if ($baseUrl !== null) {
            $update['base_url'] = $baseUrl !== '' ? $baseUrl : null;
        }

        $dsConnection->updateWhere(
            'core_ai_backends',
            $update,
            'id = %i',
            $backendId,
        );
    }
}
