<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Command\MailPreprocessCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Attachments\AttachmentService;
use Shipard\Module\Core\Mail\Preprocess\ActionRegistry;
use Shipard\Module\Core\Mail\Preprocess\PreprocessRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailPreprocessCommand extends MailPreprocessCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        PreprocessRunner $runner,
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
 * Volby příkazu (--message xor --sweep, --force jen s --message) a mapování
 * výsledků runneru na výstup/exit kód. Logiku runneru kryje PreprocessRunnerTest.
 */
class MailPreprocessCommandTest extends TestCase
{
    /** @param list<array<string, mixed>> $sweepRows */
    private function makeTester(?array $message = null, array $sweepRows = [], int $affectedOnClaim = 1): CommandTester
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($message);
        $db->method('fetchAll')->willReturn($sweepRows);
        $db->method('getAffectedRows')->willReturn($affectedOnClaim);

        $runner = new PreprocessRunner($db, $this->createMock(AttachmentService::class), new ActionRegistry());

        $command = new TestableMailPreprocessCommand(
            $this->createMock(DataSourceConfig::class),
            $db,
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

    public function testForceCannotBeCombinedWithSweep(): void
    {
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--sweep' => true, '--force' => true]));
        $this->assertStringContainsString('--force cannot be combined with --sweep', $tester->getDisplay());
    }

    public function testMessageMustBePositiveInteger(): void
    {
        $tester = $this->makeTester();

        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => 'abc']));
        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => '0']));
    }

    public function testSweepWithoutStuckMessagesSucceeds(): void
    {
        $tester = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['--sweep' => true]));
        $this->assertStringContainsString('No stuck messages', $tester->getDisplay());
    }

    public function testSweepReportsRequeuedIds(): void
    {
        $tester = $this->makeTester(null, [
            ['id' => 12, 'preprocess_state' => 10, 'preprocess_log' => null],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--sweep' => true]));
        $this->assertStringContainsString('Requeued 1 message(s): 12', $tester->getDisplay());
    }

    public function testLostClaimIsSuccessWithSkippedNote(): void
    {
        $tester = $this->makeTester(null, [], 0);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--message' => '5']));
        $this->assertStringContainsString('Skipped', $tester->getDisplay());
    }

    public function testRunPrintsActionResultsAndFinalState(): void
    {
        $message = [
            'id' => 5,
            'raw_source_attachment' => null,
            'analysis_state' => 10,
            'preprocess_state' => 10,
            'preprocess_log' => json_encode(['plan' => [[
                'ruleId' => 'bolt',
                'ruleNdx' => 1,
                'actions' => [['action' => 'fetchLinkedDocument']],
            ]]]),
        ];
        $tester = $this->makeTester($message);

        // Registr je prázdný → neznámá akce = selhání, ale příkaz uspěje (zpráva doteče do 40).
        $this->assertSame(Command::SUCCESS, $tester->execute(['--message' => '5']));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('[FAIL] bolt/fetchLinkedDocument', $display);
        $this->assertStringContainsString('unknown action', $display);
        $this->assertStringContainsString('done_with_errors', $display);
    }

    public function testMissingMessageFails(): void
    {
        $tester = $this->makeTester(null);

        $this->assertSame(Command::FAILURE, $tester->execute(['--message' => '5', '--force' => true]));
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }
}
