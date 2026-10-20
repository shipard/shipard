<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiEvalCommand;
use Shipard\Core\Ai\AiBackendResolver;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\AiFunctionResolver;
use Shipard\Core\Ai\LlmChatParams;
use Shipard\Core\Ai\LlmChatResult;
use Shipard\Core\Ai\LlmClient;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Logging\ErrorLogger;
use Shipard\Module\Core\Exchange\Dataset\DatasetReader;
use Shipard\Module\Core\Mail\Analysis\AnalysisComputing;
use Shipard\Module\Core\Mail\Analysis\AnalysisOutcome;
use Shipard\Module\Core\Mail\Eval\EvalDataSource;
use Shipard\Module\Core\Mail\Eval\EvalMessages;
use Shipard\Tests\Unit\Module\Core\Mail\Eval\ArrayEvalDataSource;
use Shipard\Tests\Unit\Module\Core\Mail\Eval\EvalRunnerTest;
use Shipard\Tests\Unit\Module\Core\Mail\Eval\ScriptedComputation;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAiEvalCommand extends AiEvalCommand
{
    public string $serverMode = 'development';
    /** @var array{commit: ?string, dirty: ?bool} */
    public array $git = ['commit' => 'abc1234', 'dirty' => false];
    public ?AnalysisComputing $computation = null;
    public int $computationsCreated = 0;
    /** Co má příkaz považovat za terminál na STDIN (test se skutečného STDIN nikdy nedotkne). */
    public bool $terminal = false;

    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $db,
        private readonly EvalDataSource $dataSource,
        private readonly AiFunctionResolver $resolver,
        private readonly ConfigRuntime $config,
        private readonly string $runs,
    ) {
        parent::__construct($dsConfig, $db);
    }

    protected function getDataSourceDir(): string
    {
        return '/nonexistent';
    }

    protected function getServerMode(): string
    {
        return $this->serverMode;
    }

    protected function stdinIsTerminal(): bool
    {
        return $this->terminal;
    }

    protected function loadConfig(string $dsDir, string $language): ConfigRuntime
    {
        return $this->config;
    }

    protected function createDataSource(DataSourceConnection $db): EvalDataSource
    {
        return $this->dataSource;
    }

    protected function createResolver(DataSourceConnection $db, ConfigRuntime $config, DataSourceConfig $dsConfig): AiFunctionResolver
    {
        return $this->resolver;
    }

    protected function createLlmClient(ModelCatalog $catalog): LlmClient
    {
        return new class implements LlmClient {
            public function streamChat(LlmChatParams $params, callable $onTextDelta, ?callable $onThinking = null): LlmChatResult
            {
                throw new \LogicException('the command must not call the model directly');
            }
        };
    }

    protected function createComputation(DataSourceConnection $db, DataSourceConfig $dsConfig, ConfigRuntime $config, string $dsDir, LlmClient $llm, ModelCatalog $catalog, \Shipard\Module\Core\Mail\Eval\EvalVariant $variant): AnalysisComputing
    {
        $this->computationsCreated++;
        return $this->computation ?? throw new \LogicException('no scripted computation');
    }

    protected function gitState(): array
    {
        return $this->git;
    }

    protected function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-15T10:00:00+02:00');
    }

    protected function runsDir(?string $out, string $setName): string
    {
        return $out !== null && $out !== '' ? $out : $this->runs . '/' . $setName;
    }
}

/**
 * `shpd-ds ai-eval` (tasks/testing-phase4.md D34, D39, D41): preflight
 * (režim serveru, cizí data, použité doklady, pravidla, profil, varianta),
 * odhad a potvrzení (neinteraktivně bez --yes exit 2), běh nad skriptovaným
 * výpočtem se souborem výsledků a `--json`, exit podle druhu selhání.
 * Sada = skutečná `tests/datasets/cz/vat-payer`.
 */
final class AiEvalCommandTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768, 'temperature' => 0.0], 'requires' => ['pdf']],
        'exchange.contentTags' => ['name' => 'Štítky', 'tier' => 'fast', 'defaults' => ['maxTokens' => 8000]],
    ];
    private const MODELS = [
        'claude-sonnet-5-5' => ['name' => 'Claude Sonnet 5.5', 'order' => 20, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-5-5'], 'prices' => ['input' => 2, 'output' => 10]],
        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'order' => 22, 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
        'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'order' => 30, 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 1, 'output' => 5]],
    ];
    private const TIERS = [
        'fast' => ['name' => 'Rychlý', 'order' => 10, 'model' => 'claude-haiku-5-5'],
        'balanced' => ['name' => 'Vyvážený', 'order' => 20, 'model' => 'claude-sonnet-5-5'],
    ];
    private const PLATFORMS = [
        'anthropic' => ['name' => 'Anthropic', 'dialect' => 'anthropic-messages', 'baseUrl' => 'https://api.anthropic.com', 'modelIds' => 'anthropic', 'modelIdPrefix' => '', 'priceMultiplier' => 1],
    ];
    private const CONNECTION = ['id' => 1, 'backend_id' => 'default', 'name' => 'Anthropic', 'provider' => 'anthropic', 'model' => null, 'base_url' => null, 'api_key' => 'enc', 'is_default' => 1, 'is_active' => 1];
    private const PROFILE = ['id' => 4, 'profile_id' => 'czech_general', 'prompt_version' => 'v4.7.1', 'prompt_template' => 'T', 'output_schema' => '{"type":"object"}', 'backend' => null, 'model' => null, 'max_tokens' => 0];

    private string $tmp;

    protected function setUp(): void
    {
        ErrorLogger::setLogLevel('error');
        $this->tmp = sys_get_temp_dir() . '/shpd_aieval_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0750, true);
    }

    protected function tearDown(): void
    {
        ErrorLogger::resetForTesting();
        DatasetReader::removeTree($this->tmp);
    }

    private static function datasetDir(): string
    {
        return dirname(__DIR__, 4) . '/tests/datasets/cz/vat-payer';
    }

    private static function variantPath(): string
    {
        return dirname(__DIR__, 4) . '/tests/eval/variants/baseline.jsonc';
    }

    /** @return array<string, int> messageId → ndx (pořadí sady) */
    private static function ndxByCode(): array
    {
        $out = [];
        foreach (EvalMessages::fromDataset(DatasetReader::open(self::datasetDir())) as $i => $m) {
            $out[$m->messageId] = $i + 1;
        }
        return $out;
    }

    /**
     * @param array<string, int>        $ndxByCode
     * @param array<int, int|null>      $docs
     * @param array<string, mixed>|null $profile
     */
    private function command(array $ndxByCode, array $docs = [], int $tagRules = 0, ?array $profile = self::PROFILE): TestableAiEvalCommand
    {
        $dsConfig = $this->createMock(DataSourceConfig::class);
        $dsConfig->method('getCountry')->willReturn('cz');
        $dsConfig->method('getDefaultLanguage')->willReturn('cs');
        $dsConfig->method('getId')->willReturn('test-ds');
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn(null);

        $items = [
            AiFunctionRegistry::CFG_ITEM => self::FUNCTIONS,
            ModelCatalog::CFG_ITEM => self::MODELS,
            ModelCatalog::TIERS_CFG_ITEM => self::TIERS,
            AiFunctionResolver::PLATFORMS_CFG_ITEM => self::PLATFORMS,
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $items[$id] ?? null);
        $backends = $this->createMock(AiBackendResolver::class);
        $backends->method('defaultBackend')->willReturn(self::CONNECTION);
        $backends->method('backendByNdx')->willReturn(null);
        $backends->method('backendByCode')->willReturn(null);
        $backends->method('apiKey')->willReturn('sk-test');
        $resolver = new AiFunctionResolver($db, $config, null, null, null, $backends);

        $command = new TestableAiEvalCommand(
            $dsConfig,
            $db,
            new ArrayEvalDataSource($db, $ndxByCode, $docs, $tagRules, $profile),
            $resolver,
            $config,
            $this->tmp . '/runs',
        );
        (new Application())->add($command);
        return $command;
    }

    /** @param array<string, mixed> $args */
    private static function execute(TestableAiEvalCommand $command, array $args, bool $interactive = false): CommandTester
    {
        $tester = new CommandTester($command);
        $tester->execute($args + ['path' => self::datasetDir(), '--variant' => self::variantPath()], ['interactive' => $interactive]);
        return $tester;
    }

    public function testPreflightRefusesWrongServerModeDirtyDataSourceAndMissingProfile(): void
    {
        $command = $this->command(self::ndxByCode());
        $command->serverMode = 'production';
        $tester = self::execute($command, ['--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('jen na vývojovém serveru', $tester->getDisplay());
        $this->assertSame(0, $command->computationsCreated);

        $command = $this->command(['MSG-FOREIGN' => 99] + self::ndxByCode(), docs: [5 => 3], tagRules: 2);
        $tester = self::execute($command, ['--yes' => true]);
        $display = $tester->getDisplay();
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('zpráva #99 (MSG-FOREIGN) není ze sady', $display);
        $this->assertStringContainsString('1 doklad(ů)', $display);
        $this->assertStringContainsString('2 pravidel IČO → štítek', $display);
        $this->assertStringContainsString('dataset-seed', $display);

        $command = $this->command(self::ndxByCode(), profile: null);
        $tester = self::execute($command, ['--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString("profil pošty 'czech_general'", $tester->getDisplay());

        $missing = array_slice(self::ndxByCode(), 1, null, true);
        $tester = self::execute($this->command($missing), ['--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('ze sady ve zdroji dat není', $tester->getDisplay());
    }

    public function testInputProblemsAreRefusedBeforeAnything(): void
    {
        $command = $this->command(self::ndxByCode());
        $tester = new CommandTester($command);
        $tester->execute(['path' => self::datasetDir()], ['interactive' => false]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--variant', $tester->getDisplay());

        $tester = self::execute($command, ['--only' => 'nope', '--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--only', $tester->getDisplay());

        $tester = self::execute($command, ['--repeat' => '0', '--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());

        $tester = self::execute($command, ['--variant' => $this->tmp . '/missing.jsonc', '--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('neexistuje', $tester->getDisplay());
    }

    public function testNonInteractiveRunNeedsYesBecauseItCostsMoney(): void
    {
        $command = $this->command(self::ndxByCode());
        $tester = self::execute($command, ['--only' => '0101']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 zpráv × 1 opakování = 1 volání', $display);
        $this->assertStringContainsString('model claude-sonnet-5-5 (připojení default — default)', $display);
        $this->assertStringContainsString('Odhad ceny: ~', $display);
        $this->assertStringContainsString('z ceníku katalogu', $display);
        $this->assertStringContainsString('stojí peníze', $display);
        $this->assertSame(0, $command->computationsCreated);
    }

    public function testEstimateFallsBackToTokensOfARunWithAModelOfTheSameSeries(): void
    {
        // D44 / D56: bez běhu se stejným modelem tokeny na doklad z běhu jiné varianty (i nad
        // jiným hashem) s modelem téže řady (Haiku 5.5) × ceník plánovaného modelu (Sonnet 5.5):
        // 9267 × 2 + 2325 × 10 = 41 784 / 1M = 0,041784 + štítky Haiku 0,0021 = 0,043884.
        mkdir($this->tmp . '/runs/cz-vat-payer', 0750, true);
        file_put_contents($this->tmp . '/runs/cz-vat-payer/2026-10-14-other.json', json_encode([
            'format' => 'shpd.eval.run.v1',
            'set' => ['name' => 'cz-vat-payer', 'hash' => 'old-hash'],
            'variant' => ['name' => 'other', 'effective' => ['catalogModel' => 'claude-haiku-5-5']],
            'startedAt' => '2026-10-14T10:00:00+00:00',
            'metrics' => ['cost.usd' => 3.76, 'calls.total' => 60, 'tokens.inputPerDocument' => 9267, 'tokens.outputPerDocument' => 2325],
        ]));
        $command = $this->command(self::ndxByCode());
        $tester = self::execute($command, ['--only' => '0101']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Odhad ceny: ~0.0439 USD (0.0439 USD / volání z ceníku a tokenů na doklad podle běhu 2026-10-14-other.json — model claude-haiku-5-5 téže řady)', $display);
        $this->assertSame(0, $command->computationsCreated);
    }

    public function testPromptNeedsARealTerminalEvenWhenInputCountsAsInteractive(): void
    {
        // stdin z /dev/null nebo z roury: Symfony 7 interakci nevypne, QuestionHelper by vrátil „ne“ a běh by skončil exit 0
        $command = $this->command(self::ndxByCode());
        $command->terminal = false;
        $tester = self::execute($command, ['--only' => '0101'], interactive: true);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('stojí peníze', $display);
        $this->assertStringNotContainsString('Spustit?', $display, 'bez terminálu se neptá');
        $this->assertStringNotContainsString('Aborted', $display);
        $this->assertSame(0, $command->computationsCreated);
    }

    public function testAnsweringNoOnTheTerminalAbortsWithExitZero(): void
    {
        $command = $this->command(self::ndxByCode());
        $command->terminal = true;
        $tester = new CommandTester($command);
        $tester->setInputs(['n']);
        $tester->execute(['path' => self::datasetDir(), '--variant' => self::variantPath(), '--only' => '0101'], ['interactive' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Spustit? [y/N]', $display);
        $this->assertStringContainsString('Aborted.', $display);
        $this->assertSame(0, $command->computationsCreated);
    }

    public function testEstimateOnlyPrintsJsonAndRunsNothing(): void
    {
        $command = $this->command(self::ndxByCode());
        $tester = self::execute($command, ['--only' => '0101', '--repeat' => '2', '--estimate' => true, '--json' => true, '--max-cost' => '0.5']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(0, $command->computationsCreated);
        $this->assertDirectoryDoesNotExist($this->tmp . '/runs/cz-vat-payer', 'odhad nic nezapíše');
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $l): bool => str_starts_with($l, '{"ok":true')));
        $this->assertCount(1, $lines, 'jeden JSON objekt na stdout');
        $data = json_decode($lines[0], true);
        $this->assertSame('cz-vat-payer', $data['set']['name']);
        $this->assertSame(1, $data['set']['messages']);
        $this->assertSame('baseline', $data['variant']);
        $this->assertSame(2, $data['repeat']);
        $this->assertSame(2, $data['calls']);
        $this->assertSame('claude-sonnet-5-5', $data['model']);
        $this->assertSame('claude-sonnet-5-5', $data['catalogModel']);
        $this->assertSame('default', $data['connection']);
        $this->assertSame('claude-haiku-5-5', $data['tagsModel']);
        $this->assertSame('catalog', $data['estimate']['basis']);
        $this->assertIsFloat($data['estimate']['total']);
        $this->assertSame(0.5, $data['maxCostUsd']);

        $tester = self::execute($command, ['--only' => '0101', '--estimate' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('nic se nespustilo', $tester->getDisplay());
        $this->assertSame(0, $command->computationsCreated);
    }

    public function testMaxCostAndProgressFileAreValidatedBeforeAnything(): void
    {
        $command = $this->command(self::ndxByCode());

        $tester = self::execute($command, ['--max-cost' => '0', '--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--max-cost', $tester->getDisplay());
        $tester = self::execute($command, ['--max-cost' => 'abc', '--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());

        $tester = self::execute($command, ['--progress-file' => $this->tmp . '/missing-dir/progress.jsonl', '--yes' => true]);
        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $this->assertStringContainsString('--progress-file', $tester->getDisplay());
        $this->assertSame(0, $command->computationsCreated);
    }

    public function testJsonRefusalCarriesTheProblems(): void
    {
        $command = $this->command(self::ndxByCode());
        $command->serverMode = 'production';
        $tester = self::execute($command, ['--estimate' => true, '--json' => true]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $l): bool => str_starts_with($l, '{"ok":false')));
        $this->assertCount(1, $lines);
        $data = json_decode($lines[0], true);
        $this->assertFalse($data['ok']);
        $this->assertStringContainsString('development', implode(' ', $data['problems']));
    }

    public function testRunWritesTheResultsFileAndJson(): void
    {
        $messages = EvalMessages::filter(EvalMessages::fromDataset(DatasetReader::open(self::datasetDir())), ['0101'], null);
        $expected = $messages[0]->expectedCanonical;
        $tweaked = $expected;
        $tweaked['supplier']['vatId'] = 'cz 430 75894';
        $tweaked['totals']['totalAmount'] = 1;

        $command = $this->command(self::ndxByCode());
        $command->computation = new ScriptedComputation([
            EvalRunnerTest::okOutcome($expected),
            EvalRunnerTest::okOutcome($tweaked),
        ]);
        $tester = self::execute($command, ['--only' => '0101', '--repeat' => '2', '--yes' => true, '--json' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        $this->assertSame(1, $command->computationsCreated);
        $this->assertStringContainsString('✓ 0101-domestic-goods', $display);
        $this->assertStringContainsString('Shoda polí', $display);
        $this->assertStringContainsString('Doklady: bez odchylky 50,0 %', $display);

        $file = $this->tmp . '/runs/cz-vat-payer/2026-10-15-baseline.json';
        $this->assertFileExists($file);
        $run = json_decode((string) file_get_contents($file), true);
        $this->assertSame('shpd.eval.run.v1', $run['format']);
        $this->assertSame('cz-vat-payer', $run['set']['name']);
        $this->assertSame(1, $run['set']['messages']);
        $this->assertSame(20, $run['set']['messagesInSet']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $run['set']['hash']);
        $this->assertSame(['commit' => 'abc1234', 'dirty' => false], $run['git']);
        $this->assertSame('baseline', $run['variant']['name']);
        $this->assertSame('default', $run['variant']['effective']['connection']);
        $this->assertSame('default', $run['variant']['effective']['connectionOrigin']);
        $this->assertSame('claude-sonnet-5-5', $run['variant']['effective']['model']);
        $this->assertSame('v4.7.1', $run['variant']['effective']['promptVersion']);
        $this->assertSame(hash('sha256', 'T'), $run['variant']['effective']['promptHash']);
        $this->assertSame('claude-haiku-5-5', $run['variant']['effective']['contentTags']['model']);
        $this->assertSame(2, $run['repeat']);
        $this->assertSame(2, $run['metrics']['calls.total']);
        $this->assertSame(0.5, $run['metrics']['documents.clean']);
        $this->assertSame(0.5, $run['metrics']['documents.amountsCorrect']);
        $this->assertSame(['min' => 0.0, 'max' => 1.0], $run['range']['documents.clean']);
        $this->assertSame(0.0, $run['fieldStability']['totals.totalAmount'], 'jedna zpráva, dva různé stavy');
        $this->assertSame(1.0, $run['fieldStability']['supplier.vatId'], 'match vs. matchNormalized je táž shoda (D44)');
        $this->assertSame(0.0, $run['formatStability']['supplier.vatId'], 'jiný tvar shodné hodnoty');
        $this->assertArrayNotHasKey('totals.totalAmount', $run['formatStability'], 'bez shody v obou opakováních se formát neměří');
        $this->assertSame(1.0, $run['fieldStability']['docNumber']);
        $this->assertSame(['totals.totalAmount' => 1], $run['deviationsByField']);
        $this->assertCount(2, $run['calls']);
        $this->assertSame([], $run['calls'][0]['deviations']);
        $this->assertSame('supplier.vatId', $run['calls'][1]['normalized'][0]['path']);
        $this->assertArrayHasKey('actual', $run['calls'][1]['normalized'][0], 'shoda po normalizaci nese tvar výstupu (D53)');
        $this->assertSame('totals.totalAmount', $run['calls'][1]['deviations'][0]['path']);
        $this->assertSame(['domestic-goods'], array_keys($run['byVariant']));

        $jsonLine = array_values(array_filter(explode("\n", $display), static fn (string $l): bool => str_starts_with($l, '{"file"')));
        $this->assertCount(1, $jsonLine, '--json = jeden objekt na stdout');
        $this->assertSame($file, json_decode($jsonLine[0], true)['file']);

        // Druhý běh téhož dne: další soubor, odhad podle posledního běhu.
        $command->computation = new ScriptedComputation([EvalRunnerTest::okOutcome($expected)]);
        $tester = self::execute($command, ['--only' => '0101', '--yes' => true]);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('podle posledního běhu 2026-10-15-baseline.json se stejným modelem claude-sonnet-5-5', $tester->getDisplay());
        $this->assertFileExists($this->tmp . '/runs/cz-vat-payer/2026-10-15-baseline-2.json');
    }

    /**
     * #103 D76: zpráva podoby PDF + ISDOC jde cestou `isdoc` — odhad ji počítá
     * jen za štítky, volání nese `path`, pole, která ISDOC nenese, jsou
     * `notCarried`, běh má rozpad `byPath` a souhrn řádek po cestách.
     */
    public function testIsdocPathInEstimateRunFileAndReport(): void
    {
        $messages = EvalMessages::filter(EvalMessages::fromDataset(DatasetReader::open(self::datasetDir())), ['0101', '0102'], null);
        usort($messages, static fn ($a, $b): int => strcmp($a->messageId, $b->messageId));
        [$m0101, $m0102] = $messages;
        $this->assertSame('pdfIsdoc', $m0102->form);
        $isdoc = AnalysisOutcome::success(
            ['message_classification' => ['primary_type' => 'invoiceReceived'], 'document' => $m0102->expectedCanonical],
            [],
            new \Shipard\Module\Core\Mail\Analysis\ValidatedCanonical((string) json_encode($m0102->expectedCanonical), true, EvalRunnerTest::tags(0.001)),
            0, 0, 12, 0.0, '', 'isdoc', null,
        );

        $command = $this->command(self::ndxByCode());
        $command->computation = new ScriptedComputation([EvalRunnerTest::okOutcome($m0101->expectedCanonical), $isdoc], ['ai', 'isdoc']);
        $tester = self::execute($command, ['--only' => '0101,0102', '--yes' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Z toho 1 volání cestou ISDOC', $display);
        $this->assertStringContainsString('Po cestách (ai = extrakce modelem, isdoc = import ISDOC + štítky): ai 1 volání', $display);
        $this->assertStringContainsString('isdoc 1 volání, shoda 100,0 %, bez odchylky 100,0 %, cena 0.0010 USD', $display);

        $run = json_decode((string) file_get_contents($this->tmp . '/runs/cz-vat-payer/2026-10-15-baseline.json'), true);
        $calls = array_column($run['calls'], null, 'messageId');
        $this->assertSame('ai', $calls['MSG-CORPUS-VP-0101']['path']);
        $this->assertSame('isdoc', $calls['MSG-CORPUS-VP-0102']['path']);
        $this->assertSame('isdoc', $calls['MSG-CORPUS-VP-0102']['model']);
        $this->assertSame(0.0, $calls['MSG-CORPUS-VP-0102']['costUsd']);
        $this->assertGreaterThan(0, $calls['MSG-CORPUS-VP-0102']['counts']['notCarried']);
        $this->assertSame(0, $calls['MSG-CORPUS-VP-0101']['counts']['notCarried']);
        $this->assertSame([], $calls['MSG-CORPUS-VP-0102']['deviations']);
        $this->assertSame(['ai', 'isdoc'], array_keys($run['byPath']));
        $this->assertSame(0.0, $run['byPath']['isdoc']['cost.usd']);
        $this->assertSame(0.001, $run['byPath']['isdoc']['tags.usd']);
        $this->assertSame(1, $run['byPath']['ai']['calls.total']);
        $this->assertGreaterThan(0, $run['metrics']['fields.all.notCarried']);
        $this->assertSame(1.0, $run['metrics']['documents.clean']);

        // Odhad: ISDOC volání za štítky; `--estimate --json` nese aiCalls / isdocCalls.
        $command = $this->command(self::ndxByCode());
        $tester = self::execute($command, ['--only' => '0101,0102', '--repeat' => '2', '--estimate' => true, '--json' => true]);
        $lines = array_values(array_filter(explode("\n", $tester->getDisplay()), static fn (string $l): bool => str_starts_with($l, '{"ok":true')));
        $estimate = json_decode($lines[0], true)['estimate'];
        $this->assertSame([4, 2, 2], [$estimate['calls'], $estimate['aiCalls'], $estimate['isdocCalls']]);
        $this->assertSame('sameModel', $estimate['basis']);
    }

    /** F6b D61: strop útraty zastaví další volání, běh je neúplný; průběh v JSON řádcích vč. ceny štítků. */
    public function testMaxCostStopsTheRunAndProgressFileCarriesTheEvents(): void
    {
        $messages = EvalMessages::filter(EvalMessages::fromDataset(DatasetReader::open(self::datasetDir())), ['0101'], null);
        $expected = $messages[0]->expectedCanonical;
        $command = $this->command(self::ndxByCode());
        $command->computation = new ScriptedComputation([
            EvalRunnerTest::okOutcome($expected, tags: EvalRunnerTest::tags(0.001)),
            EvalRunnerTest::okOutcome($expected, tags: EvalRunnerTest::tags(0.001)),
            EvalRunnerTest::okOutcome($expected),
        ]);
        $progress = $this->tmp . '/progress.jsonl';
        $tester = self::execute($command, ['--only' => '0101', '--repeat' => '3', '--yes' => true, '--json' => true, '--max-cost' => '0.03', '--progress-file' => $progress]);

        $display = $tester->getDisplay();
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        $this->assertStringContainsString('Strop útraty: 0.0300 USD', $display);
        $this->assertStringContainsString('Běh neúplný: dosažen strop útraty 0.0300 USD — 2 z 3 volání.', $display);
        $run = json_decode((string) file_get_contents($this->tmp . '/runs/cz-vat-payer/2026-10-15-baseline.json'), true);
        $this->assertSame('maxCost', $run['stoppedBy']);
        $this->assertSame(3, $run['plannedCalls']);
        $this->assertSame(2, $run['metrics']['calls.total']);
        $this->assertSame(0.042, $run['metrics']['cost.totalUsd'], '2 × (0,02 + 0,001)');
        $this->assertSame(0.002, $run['metrics']['tags.usd']);
        $this->assertSame(EvalRunnerTest::tags(0.001), $run['calls'][0]['tags']);

        $events = array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string) file_get_contents($progress)))));
        $this->assertSame(['started', 'call', 'call', 'finished'], array_column($events, 'event'));
        $this->assertSame(3, $events[0]['calls']);
        $this->assertSame(0.03, $events[0]['maxCostUsd']);
        $this->assertSame('claude-sonnet-5-5', $events[0]['model']);
        $this->assertSame(['messageId' => 'MSG-CORPUS-VP-0101', 'repeat' => 2, 'status' => 'ok', 'spentUsd' => 0.042, 'done' => 2, 'total' => 3], array_intersect_key($events[2], array_flip(['messageId', 'repeat', 'status', 'done', 'total', 'spentUsd'])));
        $this->assertSame(0.001, $events[2]['tagsCostUsd']);
        $this->assertSame('maxCost', $events[3]['stoppedBy']);
        $this->assertStringEndsWith('2026-10-15-baseline.json', $events[3]['file']);
        $this->assertSame(0.042, $events[3]['spentUsd']);
    }

    /** F6b D61: SIGTERM = žádné další volání; s výsledkem soubor `stoppedBy: cancelled` a exit 130, bez výsledku nic. */
    public function testCancellationSavesThePartialRunOrNothing(): void
    {
        $messages = EvalMessages::filter(EvalMessages::fromDataset(DatasetReader::open(self::datasetDir())), ['0101'], null);
        $expected = $messages[0]->expectedCanonical;
        $command = $this->command(self::ndxByCode());
        $command->computation = new class($command, EvalRunnerTest::okOutcome($expected)) implements AnalysisComputing {
            public int $calls = 0;
            public function __construct(private readonly AiEvalCommand $command, private readonly AnalysisOutcome $outcome) {}
            public function compute(\Shipard\Module\Core\Mail\Analysis\AnalysisRequest $request): AnalysisOutcome
            {
                $this->calls++;
                $this->command->handleSignal(15); // SIGTERM během prvního volání
                return $this->outcome;
            }
        };
        $tester = self::execute($command, ['--only' => '0101', '--repeat' => '2', '--yes' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(AiEvalCommand::EXIT_CANCELLED, $tester->getStatusCode(), $display);
        $this->assertSame(1, $command->computation->calls, 'druhé volání nezačalo');
        $this->assertStringContainsString('Běh neúplný: zrušeno (SIGTERM) — 1 z 2 volání.', $display);
        $run = json_decode((string) file_get_contents($this->tmp . '/runs/cz-vat-payer/2026-10-15-baseline.json'), true);
        $this->assertSame('cancelled', $run['stoppedBy']);
        $this->assertSame(1, $run['metrics']['calls.total']);

        // Zrušení před prvním voláním: žádný soubor.
        $command = $this->command(self::ndxByCode());
        $command->computation = new ScriptedComputation([]);
        $command->requestCancel();
        $tester = self::execute($command, ['--only' => '0101', '--yes' => true, '--out' => $this->tmp . '/none']);
        $this->assertSame(AiEvalCommand::EXIT_CANCELLED, $tester->getStatusCode());
        $this->assertStringContainsString('Zrušeno před prvním dokončeným voláním', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->tmp . '/none');
        $this->assertFalse($command->handleSignal(15), 'false = Symfony proces neukončí');
    }

    public function testExitCodeFollowsTheKindOfFailure(): void
    {
        $command = $this->command(self::ndxByCode());
        $command->computation = new ScriptedComputation([AnalysisOutcome::failure('ai_error', 'anthropic transient: HTTP 529', true, null, null)]);
        $tester = self::execute($command, ['--only' => '0101', '--yes' => true]);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode(), 'infrastruktura = exit 1');
        $this->assertStringContainsString('✗ 0101-domestic-goods', $tester->getDisplay());
        $this->assertStringContainsString('selhání: ai_error 1', $tester->getDisplay());

        $command->computation = new ScriptedComputation([AnalysisOutcome::failure('schema_error', 'not json', false, 500, 0.01)]);
        $tester = self::execute($command, ['--only' => '0101', '--yes' => true, '--out' => $this->tmp . '/elsewhere']);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), 'výsledek měření = exit 0');
        $this->assertFileExists($this->tmp . '/elsewhere/2026-10-15-baseline.json');
    }
}
