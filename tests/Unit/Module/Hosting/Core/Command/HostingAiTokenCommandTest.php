<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Hosting\Core\Command\HostingAiTokenCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableHostingAiTokenCommand extends HostingAiTokenCommand
{
    public function __construct(
        DataSourceConfig $dsConfig,
        DataSourceConnection $dsConnection,
        DsSecretCipher $cipher,
        private readonly string $dsDir,
    ) {
        parent::__construct($dsConfig, $dsConnection, $cipher);
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }
}

class HostingAiTokenCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;
    private DsSecretCipher $cipher;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_aitoken_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
        $this->cipher = DsSecretCipher::fromKey(str_repeat('k', 32));
    }

    protected function tearDown(): void
    {
        $this->rmdir($this->tempDir);
    }

    private function tester(): CommandTester
    {
        $command = new TestableHostingAiTokenCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->cipher,
            $this->tempDir,
        );
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testGenerateStoresPrefixHashAndEncryptedTokenAndPrintsOnce(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(
            ['id' => 7, 'ds_id' => 'abcd-efgh-ijkl-mnop', 'name' => 'Test DS', 'lifecycle' => 'active'],
        );

        $captured = null;
        $this->dsConnection->expects($this->once())
            ->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $this->assertSame('hosting_core_ai_tokens', $table);
                $captured = $data;
                return 1;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--ds' => '7', '--generate' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Gateway token generated: Test DS (abcd-efgh-ijkl-mnop)', $output);
        $this->assertMatchesRegularExpression('/shpd_gw_[A-Za-z0-9_-]{43}/', $output);

        preg_match('/shpd_gw_[A-Za-z0-9_-]{43}/', $output, $m);
        $token = $m[0];

        $this->assertSame(7, $captured['data_source']);
        $this->assertSame(substr($token, strlen('shpd_gw_'), 12), $captured['token_prefix']);
        $this->assertSame(hash('sha256', $token), $captured['token_hash']);
        $this->assertSame(1, $captured['active']);
        $this->assertNotEmpty($captured['created']);

        // Plaintext se ukládá jen šifrovaně (queue payload, D5).
        $this->assertNotSame($token, $captured['token_encrypted']);
        $this->assertSame($token, $this->cipher->decrypt((string) $captured['token_encrypted']));
    }

    public function testGenerateWarnsOnInactiveLifecycle(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(
            ['id' => 7, 'ds_id' => 'abcd-efgh-ijkl-mnop', 'name' => 'Test DS', 'lifecycle' => 'request'],
        );
        $this->dsConnection->method('insertRow')->willReturn(1);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--ds' => '7', '--generate' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString("lifecycle is 'request'", $tester->getDisplay());
    }

    public function testRevokeSetsActiveFalse(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(
            ['id' => 3, 'token_prefix' => 'abc123def456', 'active' => 1],
        );

        $captured = null;
        $this->dsConnection->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $this->assertSame('hosting_core_ai_tokens', $table);
                $captured = $data;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--revoke' => '3']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('Token revoked: abc123def456…', $tester->getDisplay());
        $this->assertSame(0, $captured['active']);
    }

    public function testRequiresExactlyOneAction(): void
    {
        $tester = $this->tester();

        $exitCode = $tester->execute([]);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('exactly one of --generate or --revoke', $tester->getDisplay());

        $exitCode = $tester->execute(['--ds' => '7', '--generate' => true, '--revoke' => '3']);
        $this->assertSame(Command::FAILURE, $exitCode);
    }

    public function testGenerateRequiresDsOption(): void
    {
        $tester = $this->tester();
        $exitCode = $tester->execute(['--generate' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--ds <id> is required', $tester->getDisplay());
    }

    public function testFailsOnUnknownDataSource(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--ds' => '9', '--generate' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("data source '9' not found", $tester->getDisplay());
    }

    public function testFailsOnUnknownToken(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->expects($this->never())->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['--revoke' => '99']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("token '99' not found", $tester->getDisplay());
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
