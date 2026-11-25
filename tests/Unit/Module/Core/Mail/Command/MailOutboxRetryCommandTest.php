<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Command\MailOutboxRetryCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\MailOutboxService;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailOutboxRetryCommand extends MailOutboxRetryCommand
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

class MailOutboxRetryCommandTest extends TestCase
{
    private function makeTester(MailOutboxService $service, ?DataSourceConnection $conn = null): CommandTester
    {
        $command = new TestableMailOutboxRetryCommand(
            $this->createMock(DataSourceConfig::class),
            $conn ?? $this->createMock(DataSourceConnection::class),
            $service,
            sys_get_temp_dir(),
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testRetrySucceeds(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->expects($this->once())->method('retry')->with(7)->willReturn(true);

        $tester = $this->makeTester($service);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--id' => '7']));
        $this->assertStringContainsString('Outbox #7 re-queued', $tester->getDisplay());
    }

    public function testRetryNotFound(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->method('retry')->willReturn(false);
        $conn = $this->createMock(DataSourceConnection::class);
        $conn->method('fetchSingle')->willReturn(null);

        $tester = $this->makeTester($service, $conn);

        $this->assertSame(Command::FAILURE, $tester->execute(['--id' => '7']));
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testRetryWrongStateExplains(): void
    {
        $service = $this->createMock(MailOutboxService::class);
        $service->method('retry')->willReturn(false);
        $conn = $this->createMock(DataSourceConnection::class);
        $conn->method('fetchSingle')->willReturn('sent');

        $tester = $this->makeTester($service, $conn);

        $this->assertSame(Command::FAILURE, $tester->execute(['--id' => '7']));
        $this->assertStringContainsString("state 'sent'", $tester->getDisplay());
    }

    public function testMissingIdFails(): void
    {
        $tester = $this->makeTester($this->createMock(MailOutboxService::class));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('--id', $tester->getDisplay());
    }
}
