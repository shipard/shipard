<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\DevAiKeyCommand;
use Shipard\Core\Ai\DevAiKeyStore;
use Shipard\Core\Config\ServerConfig;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDevAiKeyCommand extends DevAiKeyCommand
{
    public function __construct(
        ServerConfig $serverConfig,
        private readonly ?string $keyInput,
        private readonly string $ownerRef,
    ) {
        parent::__construct($serverConfig);
    }

    protected function readKeyInput(InputInterface $input, OutputInterface $output): ?string
    {
        return $this->keyInput;
    }

    protected function ownerReference(): ?string
    {
        return $this->ownerRef;
    }
}

/**
 * `shpd-server dev-ai-key` (tasks/testing-phase4.md D40): klíč skrytým
 * vstupem do `<configDir>/secrets/dev-ai-<platforma>.key` (0600), rotace,
 * `--status` bez obsahu, odmítnutí mimo `development`.
 */
class DevAiKeyCommandTest extends TestCase
{
    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = sys_get_temp_dir() . '/shpd_devaikeycmd_' . bin2hex(random_bytes(4));
        mkdir($this->configDir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDir . '/secrets/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->configDir . '/secrets');
        @unlink($this->configDir . '/server.json');
        @rmdir($this->configDir);
    }

    /** @param array<string, mixed> $extra */
    private function tester(?string $keyInput, string $mode = 'development', array $extra = []): CommandTester
    {
        file_put_contents($this->configDir . '/server.json', json_encode([
            'host' => '127.0.0.1', 'port' => 3306, 'admin_user' => 'root', 'admin_password' => 'x', 'mode' => $mode,
        ] + $extra));
        $command = new TestableDevAiKeyCommand(new ServerConfig($this->configDir . '/server.json'), $keyInput, $this->configDir);
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testSetKeyWritesTheKeyFileWithoutPrintingIt(): void
    {
        $tester = $this->tester('sk-ant-dev-key');
        $exit = $tester->execute(['--set-key' => true]);

        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Server AI key created', $display);
        $this->assertStringContainsString('Platform:', $display);
        $this->assertStringContainsString('useServerConnection', $display);
        $this->assertStringNotContainsString('sk-ant-dev-key', $display);

        $file = DevAiKeyStore::keyFilePath($this->configDir, 'anthropic');
        $this->assertFileExists($file);
        $this->assertSame(0600, fileperms($file) & 0777);
        $this->assertSame('sk-ant-dev-key', trim((string) file_get_contents($file)));
    }

    public function testSetKeyRotatesAndFollowsTheDevAiPlatform(): void
    {
        DevAiKeyStore::write($this->configDir, 'bedrock-eu', 'old', $this->configDir);
        $tester = $this->tester('new', extra: ['devAi' => ['platform' => 'bedrock-eu']]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--set-key' => true]));
        $this->assertStringContainsString('Server AI key rotated', $tester->getDisplay());
        $this->assertSame('new', trim((string) file_get_contents(DevAiKeyStore::keyFilePath($this->configDir, 'bedrock-eu'))));
    }

    public function testRefusesOutsideDevelopmentAndWithoutExactlyOneOption(): void
    {
        $tester = $this->tester('sk', 'production');
        $this->assertSame(Command::FAILURE, $tester->execute(['--set-key' => true]));
        $this->assertStringContainsString('development servers only', $tester->getDisplay());
        $this->assertFileDoesNotExist(DevAiKeyStore::keyFilePath($this->configDir, 'anthropic'));

        $tester = $this->tester('sk');
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('exactly one of', $tester->getDisplay());
        $this->assertSame(Command::FAILURE, $tester->execute(['--set-key' => true, '--status' => true]));

        $tester = $this->tester('');
        $this->assertSame(Command::FAILURE, $tester->execute(['--set-key' => true]));
        $this->assertStringContainsString('no key provided', $tester->getDisplay());
    }

    public function testStatusReportsPresenceWithoutContent(): void
    {
        $tester = $this->tester(null);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--status' => true]));
        $this->assertStringContainsString('missing', $tester->getDisplay());

        DevAiKeyStore::write($this->configDir, 'anthropic', 'sk-ant-dev-key', $this->configDir);
        $tester = $this->tester(null);
        $this->assertSame(Command::SUCCESS, $tester->execute(['--status' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('present', $display);
        $this->assertStringContainsString('0600', $display);
        $this->assertStringNotContainsString('sk-ant-dev-key', $display);

        chmod(DevAiKeyStore::keyFilePath($this->configDir, 'anthropic'), 0640);
        $tester = $this->tester(null);
        $this->assertSame(Command::FAILURE, $tester->execute(['--status' => true]));
        $this->assertStringContainsString('must be 0600', $tester->getDisplay());
    }
}
