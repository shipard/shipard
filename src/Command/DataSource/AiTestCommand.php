<?php

declare(strict_types=1);

namespace Shipard\Command\DataSource;

use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\AiTexts;
use Shipard\Core\Ai\AnthropicLlmClient;
use Shipard\Core\Ai\Exception\AiFunctionException;
use Shipard\Core\Ai\Exception\LlmApiException;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Ai\RequestShaper;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test AI funkcí (tasks/ai-settings-section.md D52): pro jednu nebo
 * všechny deklarované funkce provede rozhodnutí resolveru
 * ({@see AiFunctionResolver::preview()}) a pošle modelu **minimální
 * požadavek** — „Reply with OK.“, malý strop, bez teploty a nástrojů,
 * thinking vypnutý, kde ho model umí vypnout, effort `low`. Vypíše
 * připojení, model, čas, tokeny a cenu, nebo chybu stejnou větou jako
 * detail funkce v Nastavení ({@see AiTexts}). Nenulový návratový kód,
 * když kterákoli funkce selže.
 *
 * Stojí peníze (zlomky centu na funkci) a na hostovaném zdroji dat jde
 * přes gateway do společného limitu — jen ručně, nikdy z cronu ani
 * automaticky z UI. S `--json` je stdout jediný JSON objekt, lidské
 * řádky jdou na stderr.
 */
class AiTestCommand extends Command
{
    public const string PROMPT = 'Reply with OK.';
    public const int MAX_TOKENS = 64;
    public const string EFFORT = 'low';
    private const int STALL_TIMEOUT_SECONDS = 30;
    private const int TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly ?DataSourceConfig $dsConfig = null,
        private readonly ?DataSourceConnection $dsConnection = null,
        private readonly ?LlmClient $llm = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('ai-test')
            ->setDescription('Test AI functions: resolver decision + a minimal live request per function (costs a fraction of a cent)')
            ->addOption('function', null, InputOption::VALUE_REQUIRED, 'Test only this AI function (id, e.g. mail.extraction); default = all declared functions')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print a single JSON object {"ok", "functions": [...]} to stdout (human lines go to stderr)');
    }

    protected function getDataSourceDir(): string
    {
        return getcwd();
    }

    /** Seam pro testy — compiled config v jazyce zdroje dat. */
    protected function loadConfig(string $dsDir, string $language): ConfigRuntime
    {
        return ConfigRuntime::load($dsDir, $language);
    }

    /** Seam pro testy — resolver nad DB, compiled configem a konfigurací zdroje dat. */
    protected function createResolver(DataSourceConnection $db, ConfigRuntime $config, DataSourceConfig $dsConfig): AiFunctionResolver
    {
        return new AiFunctionResolver($db, $config, $dsConfig);
    }

    /** Klient s katalogem — tvarování požadavku stejné jako v provozu. */
    protected function createLlmClient(?ModelCatalog $catalog = null): LlmClient
    {
        return $this->llm ?? new AnthropicLlmClient($catalog);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $human = $json
            ? ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new NullOutput())
            : $output;

        $dsDir = $this->getDataSourceDir();
        if ($this->dsConfig === null && !file_exists($dsDir . '/config/main.json')) {
            $human->writeln('<error>Error: Not a Shipard data source directory</error>');
            return Command::FAILURE;
        }

        $dsConfig = $this->dsConfig ?? new DataSourceConfig($dsDir);
        $db = $this->dsConnection ?? new DataSourceConnection($dsConfig);
        try {
            $config = $this->loadConfig($dsDir, $dsConfig->getDefaultLanguage());
        } catch (\RuntimeException $e) {
            $human->writeln('<error>Error: compiled configuration is missing — run shpd-ds ds-upgrade (' . $e->getMessage() . ')</error>');
            return Command::FAILURE;
        }

        $resolver = $this->createResolver($db, $config, $dsConfig);
        $registry = $resolver->registry();
        $catalog = $resolver->catalog();
        $texts = new AiTexts($config, $registry);

        $only = $input->getOption('function');
        $ids = is_string($only) && trim($only) !== '' ? [trim($only)] : $registry->ids();
        if ($ids === []) {
            $human->writeln('<error>Error: no AI functions are declared (run shpd-ds ds-upgrade?)</error>');
            return Command::FAILURE;
        }

        $llm = $this->createLlmClient($catalog);
        $results = [];
        foreach ($ids as $id) {
            $result = $this->testFunction($id, $resolver, $llm, $catalog, $texts);
            $results[] = $result;
            $human->writeln($this->formatLine($result));
        }

        $ok = array_all($results, static fn (array $r): bool => $r['ok']);
        if ($json) {
            $output->writeln((string) json_encode(
                ['ok' => $ok, 'functions' => $results],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            ));
        }
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Jedna funkce: rozhodnutí resolveru (bez klíče), dešifrování klíče
     * a minimální volání. Chyba resolveru = bez volání.
     *
     * @return array<string, mixed>
     */
    private function testFunction(string $id, AiFunctionResolver $resolver, LlmClient $llm, ModelCatalog $catalog, AiTexts $texts): array
    {
        $preview = $resolver->preview($id);
        if ($preview->error !== null) {
            return $this->failure($id, $preview->error->errorCode, $texts->error($preview->error));
        }
        try {
            $call = $resolver->resolve($id);
        } catch (AiFunctionException $e) {
            return $this->failure($id, $e->errorCode, $texts->error($e));
        }

        // Thinking vypnout jen u modelu se známými režimy (shaper přepne
        // na between_tools nebo vynechá); model s neznámými schopnostmi
        // dostane hodnotu plánu. Teplota nikdy — řada 5 ji odmítá.
        $spec = $catalog->find($call->shapeAs ?? $call->model);
        $params = new LlmChatParams(
            provider: $call->platform,
            model: $call->model,
            apiKey: $call->apiKey,
            baseUrl: $call->baseUrl,
            system: null,
            messages: [['role' => 'user', 'content' => self::PROMPT]],
            maxTokens: self::MAX_TOKENS,
            temperature: null,
            tools: null,
            stallTimeoutSeconds: self::STALL_TIMEOUT_SECONDS,
            timeoutSeconds: self::TIMEOUT_SECONDS,
            thinking: $spec?->thinkingModes !== null ? RequestShaper::THINKING_DISABLED : $call->thinking,
            effort: self::EFFORT,
            shapeAs: $call->shapeAs,
            dialect: $call->dialect,
        );

        $base = ['id' => $id, 'connection' => $call->connectionCode, 'model' => $call->model];
        $start = microtime(true);
        try {
            $result = $llm->streamChat($params, static function (string $delta): void {});
        } catch (LlmApiException $e) {
            return $this->failure($id, 'API_' . ($e->statusCode > 0 ? $e->statusCode : $e->errorType), $texts->apiError($e, $call->model), [
                'elapsedMs' => (int) round((microtime(true) - $start) * 1000),
                'apiStatus' => $e->statusCode,
                'apiType' => $e->errorType,
                'apiMessage' => $e->getMessage(),
            ] + $base);
        } catch (\Throwable $e) {
            return $this->failure($id, 'ERROR', $e->getMessage(), ['elapsedMs' => (int) round((microtime(true) - $start) * 1000)] + $base);
        }
        $elapsedMs = (int) round((microtime(true) - $start) * 1000);

        $inputTokens = (int) ($result->inputTokens ?? 0);
        $outputTokens = (int) ($result->outputTokens ?? 0);
        // Vlastní ID mimo katalog nemá cenu (D43) — costUsd by jen varovalo.
        $costUsd = $call->catalogModel !== null ? $catalog->costUsd($call->catalogModel, $inputTokens, $outputTokens) : null;

        return $base + [
            'ok' => true,
            'elapsedMs' => $elapsedMs,
            'inputTokens' => $result->inputTokens,
            'outputTokens' => $result->outputTokens,
            'costUsd' => $costUsd,
            'stopReason' => $result->stopReason,
            'reply' => trim($result->text),
        ];
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function failure(string $id, string $code, string $error, array $extra = []): array
    {
        return ['id' => $id, 'ok' => false, 'code' => $code, 'error' => $error] + $extra;
    }

    /** @param array<string, mixed> $r */
    private function formatLine(array $r): string
    {
        $id = str_pad((string) $r['id'], 28);
        if (!$r['ok']) {
            return "<error>FAIL</error>  {$id} {$r['error']}";
        }
        $elapsed = sprintf('%.1f s', ((int) $r['elapsedMs']) / 1000);
        $tokens = 'in ' . ($r['inputTokens'] ?? '?') . ' / out ' . ($r['outputTokens'] ?? '?');
        $cost = $r['costUsd'] !== null ? '$' . number_format((float) $r['costUsd'], 6, '.', '') : '—';
        return "<info>OK</info>    {$id} {$r['connection']} · {$r['model']}   {$elapsed}   {$tokens}   {$cost}   " . ($r['stopReason'] ?? '');
    }
}
