<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Hosting\Core\Command\HostingRouterKeyCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableHostingRouterKeyCommand extends HostingRouterKeyCommand
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

class HostingRouterKeyCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_routerkey_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableHostingRouterKeyCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testGenerateStoresPrefixAndHashAndPrintsTokenOnce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(
            ['id' => 3, 'name' => 'Mail EU-1', 'domains' => 'shipard.email'],
        );

        $captured = null;
        $this->dsConnection->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $this->assertSame('hosting_core_mail_routers', $table);
                $captured = $data;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--router' => '3', '--generate' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('API key generated: Mail EU-1 (shipard.email)', $output);
        $this->assertMatchesRegularExpression('/shpd_hk_[A-Za-z0-9_-]{43}/', $output);

        $this->assertSame(12, strlen($captured['api_key_prefix']));
        $this->assertSame(64, strlen($captured['api_key_hash']));

        // Hash odpovídá vytištěnému tokenu, prefix je začátek náhodné části.
        preg_match('/shpd_hk_[A-Za-z0-9_-]{43}/', $output, $m);
        $token = $m[0];
        $this->assertSame(hash('sha256', $token), $captured['api_key_hash']);
        $this->assertSame(substr($token, strlen('shpd_hk_'), 12), $captured['api_key_prefix']);
    }

    public function testRevokeClearsPrefixAndHash(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(
            ['id' => 3, 'name' => 'Mail EU-1', 'domains' => 'shipard.email'],
        );

        $captured = null;
        $this->dsConnection->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = $data;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--router' => '3', '--revoke' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('API key revoked', $tester->getDisplay());
        $this->assertNull($captured['api_key_prefix']);
        $this->assertNull($captured['api_key_hash']);
    }

    public function testRequiresRouterOption(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--generate' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--router <id> is required', $tester->getDisplay());
    }

    public function testRequiresExactlyOneAction(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute(['--router' => '3']);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('exactly one of --generate or --revoke', $tester->getDisplay());

        $exitCode = $tester->execute(['--router' => '3', '--generate' => true, '--revoke' => true]);
        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testFailsOnUnknownRouter(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--router' => '9', '--generate' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("router '9' not found", $tester->getDisplay());
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
