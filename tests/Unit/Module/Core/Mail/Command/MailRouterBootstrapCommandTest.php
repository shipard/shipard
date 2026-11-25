<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Command\MailRouterBootstrapCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailRouterBootstrapCommand extends MailRouterBootstrapCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class MailRouterBootstrapCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_bootstrap_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('abcd-efgh-ijkl-mnop');

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableMailRouterBootstrapCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testCreatesBothUserAndMailboxOnFreshDs(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->method('insertRow')
            ->willReturnOnConsecutiveCalls(42, 7);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString("Created system user '_mail_router' (id=42)", $output);
        $this->assertStringContainsString('Created default mailbox (id=7)', $output);
    }

    public function testSkipsWhenBothAlreadyExist(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],   // user already exists
            ['id' => 5],   // mailbox 'default' already exists
        );
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString("System user '_mail_router' already exists (id=2)", $output);
        $this->assertStringContainsString("Default mailbox 'default' already exists (id=5)", $output);
    }

    public function testSkipsMailboxWhenOtherMailboxIsAlreadyDefault(): void
    {
        // User doesn't exist → will be created
        // Mailbox 'default' doesn't exist, but another mailbox has is_default=1
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            null,                                          // user lookup miss
            null,                                          // mailbox_id='default' miss
            ['id' => 9, 'mailbox_id' => 'invoices'],       // existing default mailbox
        );
        $this->dsConnection->expects($this->once())
            ->method('insertRow')
            ->with('core_system_users', $this->anything())
            ->willReturn(42);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString("Created system user '_mail_router'", $output);
        $this->assertStringContainsString("Default mailbox 'default' not created", $output);
        $this->assertStringContainsString('invoices', $output);
    }

    public function testFailsWhenNotInDataSourceDirectory(): void
    {
        $command = new MailRouterBootstrapCommand();
        (new Application())->add($command);
        $tester = new CommandTester($command);

        // Switch CWD to a dir without config/main.json
        $prev = getcwd();
        $emptyDir = sys_get_temp_dir() . '/shpd_bootstrap_empty_' . uniqid();
        mkdir($emptyDir);
        chdir($emptyDir);
        try {
            $exitCode = $tester->execute([]);
        } finally {
            chdir($prev);
            @rmdir($emptyDir);
        }

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());
    }

    private function rmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
