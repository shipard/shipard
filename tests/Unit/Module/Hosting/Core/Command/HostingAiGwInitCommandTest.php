<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core\Command;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Hosting\Core\Command\HostingAiGwInitCommand;
use Shipard\Core\Hosting\AiGwKeyStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class TestableHostingAiGwInitCommand extends HostingAiGwInitCommand
{
    public function __construct(
        private readonly string $dsDir,
        private readonly ?string $keyInput,
    ) {
        parent::__construct();
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function readKeyInput(InputInterface $input, OutputInterface $output): ?string
    {
        return $this->keyInput;
    }
}

class HostingAiGwInitCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_aigwinit_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');
        AiGwKeyStore::resetCache();
    }

    protected function tearDown(): void
    {
        AiGwKeyStore::resetCache();
        $this->rmdir($this->tempDir);
    }

    private function tester(?string $keyInput): CommandTester
    {
        $command = new TestableHostingAiGwInitCommand($this->tempDir, $keyInput);
        (new Application())->add($command);
        return new CommandTester($command);
    }

    public function testSetKeyWritesFileWith0600(): void
    {
        $tester = $this->tester('sk-ant-test-key');
        $exitCode = $tester->execute(['--set-key' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('AI gateway org key created', $tester->getDisplay());

        $keyFile = AiGwKeyStore::keyFilePath($this->tempDir);
        $this->assertFileExists($keyFile);
        $this->assertSame(0600, fileperms($keyFile) & 0777);
        $this->assertSame('sk-ant-test-key', trim((string) file_get_contents($keyFile)));

        // Klíč se nikdy nevypisuje.
        $this->assertStringNotContainsString('sk-ant-test-key', $tester->getDisplay());
    }

    public function testSetKeyRotatesExistingKey(): void
    {
        AiGwKeyStore::write($this->tempDir, 'old-key');

        $tester = $this->tester('new-key');
        $exitCode = $tester->execute(['--set-key' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('AI gateway org key rotated', $tester->getDisplay());
        $this->assertSame('new-key', trim((string) file_get_contents(AiGwKeyStore::keyFilePath($this->tempDir))));
    }

    public function testSetKeyFailsOnEmptyInput(): void
    {
        $tester = $this->tester('');
        $exitCode = $tester->execute(['--set-key' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('no key provided', $tester->getDisplay());
    }

    public function testStatusReportsMissingKey(): void
    {
        $tester = $this->tester(null);
        $exitCode = $tester->execute(['--status' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('missing', $tester->getDisplay());
    }

    public function testStatusReportsPresentKeyWithoutContent(): void
    {
        AiGwKeyStore::write($this->tempDir, 'sk-ant-test-key');

        $tester = $this->tester(null);
        $exitCode = $tester->execute(['--status' => true]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('present', $output);
        $this->assertStringContainsString('0600', $output);
        $this->assertStringNotContainsString('sk-ant-test-key', $output);
    }

    public function testStatusFailsOnInsecurePermissions(): void
    {
        AiGwKeyStore::write($this->tempDir, 'sk-ant-test-key');
        chmod(AiGwKeyStore::keyFilePath($this->tempDir), 0644);

        $tester = $this->tester(null);
        $exitCode = $tester->execute(['--status' => true]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('must be 0600', $tester->getDisplay());
    }

    public function testRequiresExactlyOneAction(): void
    {
        $tester = $this->tester(null);

        $exitCode = $tester->execute([]);
        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('exactly one of --set-key or --status', $tester->getDisplay());

        $exitCode = $tester->execute(['--set-key' => true, '--status' => true]);
        $this->assertSame(Command::FAILURE, $exitCode);
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
