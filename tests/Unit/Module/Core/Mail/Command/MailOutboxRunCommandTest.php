<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Command\MailOutboxRunCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailOutboxService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailOutboxRunCommand extends MailOutboxRunCommand
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

class MailOutboxRunCommandTest extends TestCase
{
    private function makeTester(MailOutboxService $service): CommandTester
    {
        $command = new TestableMailOutboxRunCommand(
            $this->createMock(DataSourceConfig::class),
            $this->createMock(DataSourceConnection::class),
            $service,
            sys_get_temp_dir(),
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testProcessesQueueAndReportsStats(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->expects($this->once())->method('processQueue')->with(50)->willReturn([
            'requeued' => 1, 'processed' => 4, 'sent' => 2, 'retried' => 1, 'failed' => 1,
        ]);

        $tester = $this->makeTester($service);
        $exitCode = $tester->execute([]);

        // failed zprávy nejsou chyba příkazu — reportuje je alert check
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString(
            'Processed 4: 2 sent, 1 retried, 1 failed (terminal), 1 requeued',
            $tester->getDisplay(),
        );
    }

    public function testCustomLimitIsPassedThrough(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->expects($this->once())->method('processQueue')->with(10)->willReturn([
            'requeued' => 0, 'processed' => 0, 'sent' => 0, 'retried' => 0, 'failed' => 0,
        ]);

        $tester = $this->makeTester($service);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--limit' => '10']));
    }

    public function testInvalidLimitFails(): void
    {
        $tester = $this->makeTester($this->createMock(MailOutboxService::class));

        $this->assertSame(Command::FAILURE, $tester->execute(['--limit' => '0']));
        $this->assertStringContainsString('--limit', $tester->getDisplay());
    }

    public function testInfraErrorFails(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->method('processQueue')->willThrowException(new \RuntimeException('DB gone'));

        $tester = $this->makeTester($service);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('DB gone', $tester->getDisplay());
    }
}
