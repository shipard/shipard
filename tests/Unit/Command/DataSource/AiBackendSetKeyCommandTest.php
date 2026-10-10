<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\AiBackendSetKeyCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Security\DsSecretCipher;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableAiBackendSetKeyCommand extends AiBackendSetKeyCommand
{
    /** Co „zadá“ skrytý prompt / STDIN (null = nic). */
    public ?string $promptedKey = null;
    public int $promptCalls = 0;

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

    protected function readKeyInput(InputInterface $input, OutputInterface $output): ?string
    {
        $this->promptCalls++;
        return $this->promptedKey;
    }
}

/**
 * `ai-backend-set-key`: klíč z `--api-key` nebo skrytého vstupu, šifrování
 * přes AIBackendDocument, aktivace backendu, `--base-url`, alias
 * `ai-analyzer-set-key` s upozorněním (tasks/ai-analyzer-removal.md D21).
 */

class AiBackendSetKeyCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tempDir = sys_get_temp_dir() . '/shpd_ai_setkey_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'AISetKey Test',
            'database_name' => 'test_db',
            'database_user' => 'test',
            'database_password' => 'pw',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tempDir);

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConfig->method('getId')->willReturn('test-test-test-test');
        $this->dsConfig->method('getDataSourceDir')->willReturn($this->tempDir);

        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tempDir);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }
        @chmod($dir, 0700);
        @rmdir($dir);
    }

    private TestableAiBackendSetKeyCommand $command;

    private function tester(?string $promptedKey = null): CommandTester
    {
        $this->command = new TestableAiBackendSetKeyCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        $this->command->promptedKey = $promptedKey;
        (new Application())->add($this->command);
        return new CommandTester($this->command);
    }

    public function testFailsWithoutApiKeyWhenNothingIsEntered(): void
    {
        // Neinteraktivní běh bez --api-key a bez pipe = chyba, ne tiché nic.
        $tester = $this->tester(promptedKey: null);
        $exitCode = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(1, $this->command->promptCalls);
        $this->assertStringContainsString('no API key given', $tester->getDisplay());
    }

    public function testReadsKeyFromHiddenInputWhenOptionMissing(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);

        $captured = null;
        $this->dsConnection->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = $data;
            });

        $tester = $this->tester(promptedKey: 'sk-ant-from-prompt');
        $exitCode = $tester->execute(['--backend' => 'default']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(1, $this->command->promptCalls);
        $this->assertStringStartsWith('v1:', $captured['api_key']);
        $this->assertSame(1, $captured['is_active']);
        $this->assertStringNotContainsString('sk-ant-from-prompt', $tester->getDisplay());
    }

    public function testApiKeyOptionSkipsThePrompt(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);
        $this->dsConnection->method('updateWhere');

        $tester = $this->tester(promptedKey: 'unused');
        $exitCode = $tester->execute(['--api-key' => 'sk-ant-test']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(0, $this->command->promptCalls);
    }

    public function testLegacyAliasWorksAndWarns(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);
        $this->dsConnection->method('updateWhere');

        $tester = $this->tester();
        $exitCode = $tester->execute(['command' => 'ai-analyzer-set-key', '--api-key' => 'sk-ant-test']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('deprecated alias', $tester->getDisplay());
        $this->assertStringContainsString('ai-backend-set-key', $tester->getDisplay());
    }

    public function testPrimaryNameDoesNotWarn(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);
        $this->dsConnection->method('updateWhere');

        $tester = $this->tester();
        $tester->execute(['--api-key' => 'sk-ant-test']);

        $this->assertStringNotContainsString('deprecated', $tester->getDisplay());
    }

    public function testFailsWhenBackendNotFound(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);

        $tester = $this->tester();
        $exitCode = $tester->execute(['--api-key' => 'sk-ant-test']);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("backend 'default' not found", $tester->getDisplay());
        $this->assertStringContainsString('ds-upgrade', $tester->getDisplay());
    }

    public function testEncryptsKeyAndActivatesBackend(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);

        $captured = null;
        $this->dsConnection
            ->expects($this->once())
            ->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data, string $where, mixed ...$params) use (&$captured): void {
                $captured = ['table' => $table, 'data' => $data, 'where' => $where, 'params' => $params];
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--backend' => 'default',
            '--api-key' => 'sk-ant-plaintext-secret-✓',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull($captured);
        $this->assertSame('core_ai_backends', $captured['table']);
        $this->assertSame([17], $captured['params']);

        // Klíčové bezpečnostní invarianty:
        $this->assertArrayHasKey('api_key', $captured['data']);
        $this->assertNotSame('sk-ant-plaintext-secret-✓', $captured['data']['api_key']);
        $this->assertStringStartsWith('v1:', $captured['data']['api_key']);
        $this->assertSame(1, $captured['data']['is_active']);

        // Plaintext nesmí prosáknout do výstupu (CLI ho nemá logovat)
        $this->assertStringNotContainsString('sk-ant-plaintext-secret', $tester->getDisplay());
    }

    public function testBaseUrlIsStoredWhenGiven(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);

        $captured = null;
        $this->dsConnection->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = $data;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--api-key' => 'shpd_gw_' . str_repeat('a', 43),
            '--base-url' => 'https://portal.example.com/api/v1/_hosting/ai-gw',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('https://portal.example.com/api/v1/_hosting/ai-gw', $captured['base_url']);
        $this->assertStringContainsString('Base URL set to', $tester->getDisplay());
    }

    public function testEmptyBaseUrlResetsToNull(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);

        $captured = null;
        $this->dsConnection->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = $data;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--api-key' => 'sk-ant-test', '--base-url' => '']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertArrayHasKey('base_url', $captured);
        $this->assertNull($captured['base_url']);
        $this->assertStringContainsString('Base URL cleared', $tester->getDisplay());
    }

    public function testOmittedBaseUrlLeavesColumnUntouched(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 17]);

        $captured = null;
        $this->dsConnection->method('updateWhere')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): void {
                $captured = $data;
            });

        $tester = $this->tester();
        $exitCode = $tester->execute(['--api-key' => 'sk-ant-test']);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertArrayNotHasKey('base_url', $captured);
    }

    public function testFailsWhenSecretsKeyMissing(): void
    {
        // Smažeme secrets.key — DsSecretCipher::forConfig hodí výjimku
        @chmod($this->tempDir . '/secrets/secrets.key', 0600);
        unlink($this->tempDir . '/secrets/secrets.key');
        DsSecretCipher::resetCache();

        $tester = $this->tester();
        $exitCode = $tester->execute([
            '--backend' => 'default',
            '--api-key' => 'sk-ant-test',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Secrets key error', $tester->getDisplay());
        $this->assertStringContainsString('ds-secrets-health', $tester->getDisplay());
    }
}
