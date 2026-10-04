<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\MailSendTestCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailOutboxService;
use Shipard\Core\Mail\OutboundMessage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailSendTestCommand extends MailSendTestCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        MailOutboxService $service,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection, $service);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class MailSendTestCommandTest extends TestCase
{
    private function makeTester(
        MailOutboxService $service,
        DataSourceConnection $conn,
        ?OutboundMessage &$capturedMessage = null,
    ): CommandTester {
        $config = $this->createMock(DataSourceConfig::class);
        $config->method('getId')->willReturn('test-test-test-test');

        $command = new TestableMailSendTestCommand($config, $conn, $service, sys_get_temp_dir());
        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testSuccessfulSendReportsTransport(): void
    {
        $captured = null;
        $service = $this->createMock(MailOutboxService::class);
        $service->method('enqueue')->willReturnCallback(
            function (OutboundMessage $m) use (&$captured) {
                $captured = $m;
                return 15;
            },
        );
        $service->expects($this->once())->method('attemptSend')->with(15)->willReturn(true);

        $conn = $this->createMock(DataSourceConnection::class);
        $conn->method('fetchRow')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'outbox_log')
                ? ['transport' => 'relay.example.com:587', 'duration_ms' => 120, 'smtp_response' => '250 OK']
                : ['state' => 'sent', 'last_error' => null],
        );

        $tester = $this->makeTester($service, $conn);
        $exitCode = $tester->execute([
            '--to' => ['test@example.com', 'druhy@example.com'],
            '--cc' => ['kopie@example.com'],
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString("Outbox #15: state 'sent'", $display);
        $this->assertStringContainsString('relay.example.com:587', $display);
        $this->assertStringContainsString('250 OK', $display);

        $this->assertSame(['test@example.com', 'druhy@example.com'], $captured->to);
        $this->assertSame(['kopie@example.com'], $captured->cc);
        $this->assertSame('core.mail', $captured->sourceModule);
        $this->assertSame('send-test', $captured->sourceRef);
    }

    public function testFailedSendReportsErrorAndFails(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->method('enqueue')->willReturn(16);
        $service->method('attemptSend')->willReturn(false);

        $conn = $this->createMock(DataSourceConnection::class);
        $conn->method('fetchRow')->willReturnCallback(
            static fn (string $sql) => str_contains($sql, 'outbox_log')
                ? ['transport' => 'unresolved', 'duration_ms' => 3, 'smtp_response' => 'no relay']
                : ['state' => 'pending', 'last_error' => 'no relay configured'],
        );

        $tester = $this->makeTester($service, $conn);

        $this->assertSame(Command::FAILURE, $tester->execute(['--to' => ['test@example.com']]));
        $this->assertStringContainsString('no relay configured', $tester->getDisplay());
    }

    public function testMissingToFails(): void
    {
        $tester = $this->makeTester(
            $this->createMock(MailOutboxService::class),
            $this->createMock(DataSourceConnection::class),
        );

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('--to', $tester->getDisplay());
    }
}
