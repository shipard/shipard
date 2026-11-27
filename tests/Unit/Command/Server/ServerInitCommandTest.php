<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Command\Server\ServerInitCommand;
use Shipard\Core\Server\CompletionInstaller;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

class InitStubCompletionInstaller extends CompletionInstaller
{
    /** @var list<string> */
    public array $installedBinaries = [];

    public function install(string $binaryName): array
    {
        $this->installedBinaries[] = $binaryName;
        return ['status' => 'up-to-date', 'message' => '/etc/bash_completion.d/' . $binaryName];
    }
}

class TestableServerInitCommand extends ServerInitCommand
{
    public bool $rootResult = false;
    public bool $mysqladminResult = true;
    public bool $wsl = false;
    public ?string $lastMysqladminPassword = null;
    /** @var list<array{path: string, owner: string, group: string, mode: int}> */
    public array $ownershipApplied = [];
    public InitStubCompletionInstaller $completionInstaller;

    public function __construct(string $tempConfigPath)
    {
        parent::__construct();
        $this->serverConfigPath = $tempConfigPath;
        $this->completionInstaller = new InitStubCompletionInstaller();
    }

    protected function createCompletionInstaller(): CompletionInstaller
    {
        return $this->completionInstaller;
    }

    protected function isRunningAsRoot(): bool
    {
        return $this->rootResult;
    }

    protected function isWsl(): bool
    {
        return $this->wsl;
    }

    protected function generatePassword(): string
    {
        return 'TestPassword1234567890!@#$%^&*';
    }

    protected function runMysqladmin(string $password): bool
    {
        $this->lastMysqladminPassword = $password;
        return $this->mysqladminResult;
    }

    protected function setOwnership(string $path, string $owner, string $group, int $mode, OutputInterface $output): void
    {
        // Don't actually chown/chmod in tests — record and only apply chmod
        // (which works without root on owned files) for path-existence sanity.
        $this->ownershipApplied[] = [
            'path' => $path,
            'owner' => $owner,
            'group' => $group,
            'mode' => $mode,
        ];
    }
}

class ServerInitCommandTest extends TestCase
{
    private string $tempConfigPath;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd-server-init-test-' . uniqid();
        mkdir($this->tempDir, 0750, true);
        $this->tempConfigPath = $this->tempDir . '/server.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigPath)) {
            @chmod($this->tempConfigPath, 0700);
            @unlink($this->tempConfigPath);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    private function createTester(TestableServerInitCommand $command): CommandTester
    {
        $app = new Application();
        $app->add($command);
        return new CommandTester($command);
    }

    public function testFailsWhenNotRoot(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = false;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--user' => 'sebik']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('must be run as root', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tempConfigPath);
    }

    public function testFailsOnInvalidMode(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--mode' => 'staging', '--user' => 'sebik']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString("Invalid --mode 'staging'", $tester->getDisplay());
    }

    public function testFailsWhenUserDoesNotExist(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--user' => 'nonexistent-user-' . uniqid()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function testFailsWhenCannotDetermineUser(): void
    {
        // development mode + no --user + no SUDO_USER → cannot determine
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $previousSudoUser = getenv('SUDO_USER');
        putenv('SUDO_USER');  // unset

        try {
            $tester = $this->createTester($command);
            $exitCode = $tester->execute([]);

            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString('Cannot determine shipard user', $tester->getDisplay());
        } finally {
            if ($previousSudoUser !== false) {
                putenv('SUDO_USER=' . $previousSudoUser);
            }
        }
    }

    public function testSucceedsWhenAlreadyInitialized(): void
    {
        file_put_contents($this->tempConfigPath, '{}');

        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--user' => $this->existingUser()]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('already initialized', $tester->getDisplay());
        $this->assertNull($command->lastMysqladminPassword);

        // Ownership is re-applied even on already-init
        $paths = array_column($command->ownershipApplied, 'path');
        $this->assertContains($this->tempConfigPath, $paths);
    }

    public function testSuccessfulDevelopmentInit(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;
        $user = $this->existingUser();

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--mode' => 'development', '--user' => $user]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('initialized successfully', $tester->getDisplay());
        $this->assertStringContainsString('Mode:    development', $tester->getDisplay());
        $this->assertFileExists($this->tempConfigPath);

        $config = json_decode(file_get_contents($this->tempConfigPath), true);
        $this->assertSame('127.0.0.1', $config['host']);
        $this->assertSame(3306, $config['port']);
        $this->assertSame('root', $config['admin_user']);
        $this->assertSame('development', $config['mode']);
        $this->assertSame('TestPassword1234567890!@#$%^&*', $config['admin_password']);
        // Nová vývojová instalace mimo WSL dostane https na IP (#183 D3, U2).
        $this->assertSame(['httpsIp' => true], $config['devAccess']);
        $this->assertStringContainsString('sudo shpd-server dev-https', $tester->getDisplay());

        // Ownership was applied: config dir + config file, owner=root, group=user
        $applied = $command->ownershipApplied;
        $byPath = [];
        foreach ($applied as $a) {
            $byPath[$a['path']] = $a;
        }
        $this->assertArrayHasKey($this->tempDir, $byPath);
        $this->assertSame(['owner' => 'root', 'group' => $user, 'mode' => 0750], [
            'owner' => $byPath[$this->tempDir]['owner'],
            'group' => $byPath[$this->tempDir]['group'],
            'mode' => $byPath[$this->tempDir]['mode'],
        ]);
        $this->assertArrayHasKey($this->tempConfigPath, $byPath);
        $this->assertSame(['owner' => 'root', 'group' => $user, 'mode' => 0640], [
            'owner' => $byPath[$this->tempConfigPath]['owner'],
            'group' => $byPath[$this->tempConfigPath]['group'],
            'mode' => $byPath[$this->tempConfigPath]['mode'],
        ]);
    }

    public function testDevelopmentInitInWslLeavesHttpsOff(): void
    {
        // U12: ve WSL chodí prohlížeč přes localhost — zabezpečený kontext bez certifikátu.
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;
        $command->wsl = true;

        $tester = $this->createTester($command);
        $this->assertSame(0, $tester->execute(['--mode' => 'development', '--user' => $this->existingUser()]));

        $config = json_decode(file_get_contents($this->tempConfigPath), true);
        $this->assertArrayNotHasKey('devAccess', $config);
        $this->assertStringNotContainsString('dev-https', $tester->getDisplay());
    }

    public function testProductionInitHasNoDevAccess(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $this->assertSame(0, $tester->execute(['--mode' => 'production', '--user' => $this->existingUser()]));

        $config = json_decode(file_get_contents($this->tempConfigPath), true);
        $this->assertSame('production', $config['mode']);
        $this->assertArrayNotHasKey('devAccess', $config);
    }

    public function testAlreadyInitializedServerKeepsItsConfig(): void
    {
        // Stávající server se bez zásahu nezmění — žádné devAccess dodatečně.
        file_put_contents($this->tempConfigPath, '{"host":"127.0.0.1","port":3306,"admin_user":"root","admin_password":"x","mode":"development"}');
        $before = file_get_contents($this->tempConfigPath);
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $this->assertSame(0, $tester->execute(['--mode' => 'development', '--user' => $this->existingUser()]));

        $this->assertSame($before, file_get_contents($this->tempConfigPath));
    }

    public function testInitInstallsShellCompletion(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--mode' => 'development', '--user' => $this->existingUser()]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['shpd-server', 'shpd-ds'], $command->completionInstaller->installedBinaries);
    }

    public function testAlreadyInitializedStillInstallsShellCompletion(): void
    {
        file_put_contents($this->tempConfigPath, '{}');
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--user' => $this->existingUser()]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['shpd-server', 'shpd-ds'], $command->completionInstaller->installedBinaries);
    }

    public function testDefaultModeIsDevelopment(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--user' => $this->existingUser()]);

        $this->assertSame(0, $exitCode);
        $config = json_decode(file_get_contents($this->tempConfigPath), true);
        $this->assertSame('development', $config['mode']);
    }

    public function testProductionModeUsesShipardUserByDefault(): void
    {
        // 'shipard' user may not exist on the test machine; we only verify
        // the default-resolution path. If 'shipard' doesn't exist, init fails
        // with "user does not exist" — that itself proves the default kicked in.
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--mode' => 'production']);

        if (posix_getpwnam('shipard') === false) {
            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString("User 'shipard' does not exist", $tester->getDisplay());
        } else {
            $this->assertSame(0, $exitCode);
            $config = json_decode(file_get_contents($this->tempConfigPath), true);
            $this->assertSame('production', $config['mode']);
        }
    }

    public function testMysqladminFailure(): void
    {
        $command = new TestableServerInitCommand($this->tempConfigPath);
        $command->rootResult = true;
        $command->mysqladminResult = false;

        $tester = $this->createTester($command);
        $exitCode = $tester->execute(['--user' => $this->existingUser()]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to set MariaDB root password', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->tempConfigPath);
    }

    private function existingUser(): string
    {
        $info = posix_getpwuid(posix_getuid());
        return $info['name'];
    }
}
