<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Mail\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Mail\Command\MailRouterSetupCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableMailRouterSetupCommand extends MailRouterSetupCommand
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

class MailRouterSetupCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_setup_test_' . uniqid();
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
        $command = new TestableMailRouterSetupCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testGeneratesKeyOnFirstRun(): void
    {
        // user already exists → no insertRow for user
        // no existing active API key → insertRow for key returns new id
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],   // user lookup → found
            null,          // active key lookup → none
        );

        $capturedApiKeyRow = null;
        $this->dsConnection->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedApiKeyRow): int {
                $this->assertSame('core_system_api_keys', $table);
                $capturedApiKeyRow = $data;
                return 99;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('API Key created for data source abcd-efgh-ijkl-mnop', $output);
        $this->assertMatchesRegularExpression('/shpd_ak_[0-9a-f]{32}/', $output);
        $this->assertSame('mail-router', $capturedApiKeyRow['name']);
        $this->assertSame(2, $capturedApiKeyRow['user_id']);
        $this->assertSame(1, $capturedApiKeyRow['is_active']);
        $this->assertSame(12, strlen($capturedApiKeyRow['key_prefix']));
        $this->assertSame(64, strlen($capturedApiKeyRow['key_hash']));
    }

    public function testCreatesSystemUserIfMissing(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            null,          // user lookup → miss
            null,          // active key lookup → none
        );

        $this->dsConnection->expects($this->exactly(2))
            ->method('insertRow')
            ->willReturnOnConsecutiveCalls(2, 99);

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("Created system user '_mail_router'", $tester->getDisplay());
    }

    public function testRefusesToRotateWithoutForce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],
            ['id' => 50],  // existing active key
        );
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('active mail-router API key already exists', $tester->getDisplay());
    }

    public function testRotatesWithForce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],
            ['id' => 50],
        );
        $this->dsConnection->expects($this->once())->method('execute');
        $this->dsConnection->expects($this->once())->method('insertRow')->willReturn(99);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--force' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Existing key deactivated', $tester->getDisplay());
    }

    public function testStoresAllowedIp(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(['id' => 2], null);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 99;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--ip' => '10.0.0.5']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(json_encode(['10.0.0.5']), $captured['allowed_ips']);
        $this->assertStringContainsString('Allowed source IP: 10.0.0.5', $tester->getDisplay());
    }

    public function testJsonOutputIsSingleJsonObject(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            null,          // user lookup → miss (created line se v json módu tiskne na stderr/potlačí)
            null,          // active key lookup → none
        );
        $this->dsConnection->method('insertRow')->willReturnOnConsecutiveCalls(2, 99);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        // Stdout = jediný JSON objekt, žádné dekorace.
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertSame(['api_key', 'user_id'], array_keys($decoded));
        $this->assertMatchesRegularExpression('/^shpd_ak_[0-9a-f]{32}$/', $decoded['api_key']);
        $this->assertSame(2, $decoded['user_id']);
    }

    public function testJsonWithForceRotatesSilently(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(
            ['id' => 2],
            ['id' => 50],  // existing active key
        );
        $this->dsConnection->expects($this->once())->method('execute');
        $this->dsConnection->method('insertRow')->willReturn(99);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--force' => true, '--json' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($decoded);
        $this->assertMatchesRegularExpression('/^shpd_ak_[0-9a-f]{32}$/', $decoded['api_key']);
    }

    public function testWithoutJsonOutputIsUnchanged(): void
    {
        $this->dsConnection->method('fetchRow')->willReturnOnConsecutiveCalls(['id' => 2], null);
        $this->dsConnection->method('insertRow')->willReturn(99);

        $tester = $this->tester();
        $tester->execute([]);

        $this->assertStringContainsString('IMPORTANT: This is the only time', $tester->getDisplay());
    }

    public function testRejectsInvalidIp(): void
    {
        // User lookup may happen before IP validation — provide a valid mock
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 2]);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--ip' => 'not-an-ip']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('not a valid IP', $tester->getDisplay());
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
