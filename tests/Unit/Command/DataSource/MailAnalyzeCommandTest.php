<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\MailAnalyzeCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Mail\Analysis\AnalysisRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailAnalyzeCommand extends MailAnalyzeCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        AnalysisRunner $runner,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection, $runner);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function getLogPath(): ?string
    {
        return null;
    }
}

/**
 * Volby příkazu (--message xor --sweep) a mapování výsledků runneru na
 * výstup / exit kód: selhaná analýza není chyba příkazu. Logiku runneru
 * kryje AnalysisRunnerTest.
 */
class MailAnalyzeCommandTest extends TestCase
{
    /** @param array<string, mixed>|null $runResult */
    private function makeTester(?array $runResult = null, ?array $sweepResult = null): CommandTester
    {
        $runner = $this->createMock(AnalysisRunner::class);
        $runner->method('run')->willReturn($runResult ?? ['status' => 'done', 'message' => 1]);
        $runner->method('sweep')->willReturn($sweepResult ?? ['spawned' => [], 'skipped' => null]);

        $command = new TestableMailAnalyzeCommand(
            $this->createMock(DataSourceConfig::class),
            $this->createMock(DataSourceConnection::class),
            $runner,
            sys_get_temp_dir(),
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testRequiresExactlyOneOfMessageOrSweep(): void
    {
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('exactly one of --message <id> or --sweep', $tester->getDisplay());

        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => '5', '--sweep' => true]));
    }

    public function testMessageMustBePositiveInteger(): void
    {
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => 'abc']));
        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => '0']));
        $this->assertStringContainsString('positive integer', $tester->getDisplay());
    }

    public function testDoneIsSuccessWithSummary(): void
    {
        $tester = $this->makeTester(['status' => 'done', 'message' => 5, 'analysisNdx' => 77, 'hasDocument' => true, 'note' => 'tokens 10/5']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--message' => '5']));
        $this->assertStringContainsString('Done message 5: analysis #77 (document: yes; tokens 10/5)', $tester->getDisplay());
    }

    public function testFailedAnalysisIsNotACommandFailure(): void
    {
        $tester = $this->makeTester([
            'status' => 'failed', 'message' => 5, 'errorType' => 'schema_error', 'retryable' => false,
            'newState' => 70, 'note' => 'output is not valid JSON',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--message' => '5']));
        $this->assertStringContainsString('[schema_error] output is not valid JSON → analysis_state 70 (needs a user decision)', $tester->getDisplay());
    }

    public function testQueuedOutcomesAreSuccess(): void
    {
        foreach ([
            ['status' => 'no_slot', 'message' => 5, 'note' => 'x'],
            ['status' => 'not_eligible', 'message' => 5, 'note' => 'x'],
            ['status' => 'not_configured', 'message' => 5, 'note' => 'NO_PROFILE: none'],
            ['status' => 'claim_failed', 'message' => 5, 'note' => 'ALREADY_CLAIMED: taken'],
            ['status' => 'lost_claim', 'message' => 5, 'note' => 'expired'],
            ['status' => 'disabled', 'message' => 5, 'note' => 'off'],
        ] as $result) {
            $tester = $this->makeTester($result);
            $this->assertSame(Command::SUCCESS, $tester->execute(['--message' => '5']), $result['status']);
        }
    }

    public function testCrashIsAFailure(): void
    {
        $tester = $this->makeTester(['status' => 'crashed', 'message' => 5, 'note' => 'RuntimeException: db gone']);

        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => '5']));
        $this->assertStringContainsString('db gone', $tester->getDisplay());
    }

    public function testSweepOutputs(): void
    {
        $tester = $this->makeTester(null, ['spawned' => [3, 4], 'skipped' => null]);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--sweep' => true]));
        $this->assertStringContainsString('Spawned 2 analysis runner(s): 3, 4', $tester->getDisplay());

        $tester = $this->makeTester(null, ['spawned' => [], 'skipped' => 'no usable AI backend']);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--sweep' => true]));
        $this->assertStringContainsString('Skipped: no usable AI backend', $tester->getDisplay());

        $tester = $this->makeTester(null, ['spawned' => [], 'skipped' => null]);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--sweep' => true]));
        $this->assertStringContainsString('No queued messages to analyze.', $tester->getDisplay());
    }

    public function testShorthandToBytes(): void
    {
        $this->assertSame(-1, MailAnalyzeCommand::shorthandToBytes('-1'));
        $this->assertSame(512 * 1024 * 1024, MailAnalyzeCommand::shorthandToBytes('512M'));
        $this->assertSame(1024 * 1024 * 1024, MailAnalyzeCommand::shorthandToBytes('1G'));
        $this->assertSame(128 * 1024, MailAnalyzeCommand::shorthandToBytes('128k'));
        $this->assertSame(4096, MailAnalyzeCommand::shorthandToBytes('4096'));
    }
}
