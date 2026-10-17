<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\UserCreateCommand;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\DataSourceConnection;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestableUserCreateCommand extends UserCreateCommand
{
    /** Co „přijde“ jako první řádek STDIN při --password-stdin (null = EOF). */
    public ?string $stdinLine = null;

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

    protected function readPasswordLine(): ?string
    {
        return $this->stdinLine;
    }
}

class UserCreateCommandTest extends TestCase
{
    private string $tempDir;
    private MockObject $dsConfig;
    private MockObject $dsConnection;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/shpd_usercreate_test_' . uniqid();
        mkdir($this->tempDir . '/config', 0755, true);
        file_put_contents($this->tempDir . '/config/main.json', '{}');

        $this->dsConfig = $this->createMock(DataSourceConfig::class);
        $this->dsConnection = $this->createMock(DataSourceConnection::class);
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->tempDir);
    }

    private function createCommandTester(?string $stdinLine = null): CommandTester
    {
        $command = new TestableUserCreateCommand(
            $this->dsConfig,
            $this->dsConnection,
            $this->tempDir,
        );
        $command->stdinLine = $stdinLine;

        $app = new Application();
        $app->add($command);

        return new CommandTester($command);
    }

    public function testSuccessfulUserCreation(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->method('insertRow')->willReturn(42);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'admin',
            '--password' => 'heslo123',
            '--name'     => 'Administrator',
            '--email'    => 'admin@example.com',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('User created successfully.', $display);
        $this->assertStringContainsString('ID:    42', $display);
        $this->assertStringContainsString('Login: admin', $display);
        $this->assertStringContainsString('Name:  Administrator', $display);
        $this->assertStringContainsString('Email: admin@example.com', $display);
    }

    public function testSuccessfulUserCreationWithoutEmail(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $this->dsConnection->method('insertRow')->willReturn(1);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'jane',
            '--password' => 'secret',
            '--name'     => 'Jane Doe',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('(none)', $tester->getDisplay());
    }

    public function testDuplicateLoginIsRejected(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(['id' => 5]);
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'admin',
            '--password' => 'pass',
            '--name'     => 'Admin',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString("User with login 'admin' already exists.", $tester->getDisplay());
    }

    public function testMissingLoginOption(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--password' => 'pass',
            '--name'     => 'Admin',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--login is required', $tester->getDisplay());
    }

    public function testMissingPasswordCreatesUserWithoutLocalPassword(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);

        $captured = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$captured): int {
                $captured = $data;
                return 5;
            });

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'novy',
            '--name'  => 'Nový uživatel',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNull($captured['password_hash']);
        $this->assertStringContainsString('send an invitation', $tester->getDisplay());
    }

    public function testMissingNameOption(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login'    => 'admin',
            '--password' => 'pass',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('--name is required', $tester->getDisplay());
    }

    public function testPasswordIsHashed(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);

        $capturedData = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $tester = $this->createCommandTester();
        $tester->execute([
            '--login'    => 'admin',
            '--password' => 'plaintext_password',
            '--name'     => 'Admin',
        ]);

        $this->assertNotNull($capturedData);
        $this->assertNotSame('plaintext_password', $capturedData['password_hash']);
        $this->assertTrue(password_verify('plaintext_password', $capturedData['password_hash']));
    }

    // -------------------------------------------------------------------------
    // --password-stdin (#103 D65): heslo z prvního řádku stdin, nikdy z argv
    // -------------------------------------------------------------------------

    public function testPasswordStdinReadsTheFirstLineWithoutItsLineEnding(): void
    {
        $this->dsConnection->method('fetchRow')->willReturn(null);
        $capturedData = null;
        $this->dsConnection->method('insertRow')
            ->willReturnCallback(function (string $table, array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 7;
            });

        $tester = $this->createCommandTester(stdinLine: "stdin-secret-fake\r\n");
        $exitCode = $tester->execute([
            '--login'          => 'admin',
            '--name'           => 'Admin',
            '--admin'          => true,
            '--password-stdin' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertNotNull($capturedData);
        $this->assertTrue(password_verify('stdin-secret-fake', $capturedData['password_hash']), 'konec řádku se do hesla nepočítá');
        $this->assertSame(1, $capturedData['is_admin']);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('User created successfully.', $display);
        $this->assertStringNotContainsString('stdin-secret-fake', $display, 'heslo se nikdy nevypisuje');
        $this->assertStringNotContainsString('Password: (none', $display, 'účet má lokální heslo');
    }

    public function testPasswordStdinConflictsWithPasswordOption(): void
    {
        $this->dsConnection->expects($this->never())->method('fetchRow');
        $this->dsConnection->expects($this->never())->method('insertRow');

        $tester = $this->createCommandTester(stdinLine: "whatever\n");
        $exitCode = $tester->execute([
            '--login'          => 'admin',
            '--name'           => 'Admin',
            '--password'       => 'argv-secret',
            '--password-stdin' => true,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
    }

    public function testPasswordStdinWithoutInputFails(): void
    {
        $this->dsConnection->expects($this->never())->method('insertRow');

        foreach ([null, "\n", "\r\n"] as $stdinLine) {
            $tester = $this->createCommandTester(stdinLine: $stdinLine);
            $exitCode = $tester->execute([
                '--login'          => 'admin',
                '--name'           => 'Admin',
                '--password-stdin' => true,
            ]);

            $this->assertSame(Command::FAILURE, $exitCode, var_export($stdinLine, true));
            $this->assertStringContainsString('No password on STDIN', $tester->getDisplay());
        }
    }

    // -------------------------------------------------------------------------
    // --if-not-exists + identity options (D3 provisioning agent)
    // -------------------------------------------------------------------------

    /**
     * Mock DB: login lookup + identity lookup dle SQL podřetězce, inserty
     * se zachytávají per tabulka.
     *
     * @param array<string, mixed>|null $existingUser řádek pro login lookup
     * @param array<string, mixed>|null $existingIdentity řádek pro (issuer, subject) lookup
     * @param array<string, list<array<string, mixed>>> $inserts referenční zásobník insertů
     */
    private function mockDb(?array $existingUser, ?array $existingIdentity, array &$inserts): void
    {
        $this->dsConnection->method('fetchRow')->willReturnCallback(
            function (mixed ...$args) use ($existingUser, $existingIdentity): ?array {
                $sql = (string) $args[0];
                if (str_contains($sql, 'core_system_user_identities')) {
                    return $existingIdentity;
                }
                return $existingUser;
            },
        );
        $this->dsConnection->method('insertRow')->willReturnCallback(
            function (string $table, array $data) use (&$inserts): int {
                $inserts[$table][] = $data;
                return $table === 'core_system_users' ? 42 : 100;
            },
        );
    }

    public function testIfNotExistsWithExistingLoginSucceedsWithoutInsert(): void
    {
        $inserts = [];
        $this->mockDb(['id' => 5], null, $inserts);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'admin',
            '--name'  => 'Admin',
            '--if-not-exists' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $inserts);
        $this->assertStringContainsString('already exists (ID 5) — nothing to create', $tester->getDisplay());
    }

    public function testIdentityOptionsRequireEachOther(): void
    {
        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'admin',
            '--name'  => 'Admin',
            '--identity-issuer' => 'https://portal.example.com',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('must be used together', $tester->getDisplay());
    }

    public function testNewUserWithIdentityCreatesBothRows(): void
    {
        $inserts = [];
        $this->mockDb(null, null, $inserts);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'owner@example.com',
            '--email' => 'owner@example.com',
            '--name'  => 'Owner',
            '--admin' => true,
            '--identity-provider' => 'shipard-id',
            '--identity-issuer'   => 'https://portal.example.com/api/v1/_hosting/oidc/',
            '--identity-subject'  => '7',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertCount(1, $inserts['core_system_users']);
        $this->assertSame(1, $inserts['core_system_users'][0]['is_admin']);

        $identity = $inserts['core_system_user_identities'][0];
        $this->assertSame(42, $identity['user_id']);
        $this->assertSame('shipard-id', $identity['provider']);
        // Issuer se kanonizuje bez trailing slash — stejně jako RP config.
        $this->assertSame('https://portal.example.com/api/v1/_hosting/oidc', $identity['issuer']);
        $this->assertSame('7', $identity['subject']);
        $this->assertSame('owner@example.com', $identity['email_at_link']);
        $this->assertStringContainsString('Identity linked', $tester->getDisplay());
    }

    public function testExistingUserWithIfNotExistsGetsIdentityLinked(): void
    {
        $inserts = [];
        $this->mockDb(['id' => 5], null, $inserts);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'owner@example.com',
            '--name'  => 'Owner',
            '--if-not-exists' => true,
            '--identity-issuer'  => 'https://portal.example.com',
            '--identity-subject' => '7',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertArrayNotHasKey('core_system_users', $inserts);
        $this->assertSame(5, $inserts['core_system_user_identities'][0]['user_id']);
    }

    public function testExistingIdentityForSameUserIsNoOp(): void
    {
        $inserts = [];
        $this->mockDb(['id' => 5], ['id' => 9, 'user_id' => 5], $inserts);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'owner@example.com',
            '--name'  => 'Owner',
            '--if-not-exists' => true,
            '--identity-issuer'  => 'https://portal.example.com',
            '--identity-subject' => '7',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame([], $inserts);
        $this->assertStringContainsString('Identity already linked', $tester->getDisplay());
    }

    public function testExistingIdentityForDifferentUserFails(): void
    {
        $inserts = [];
        $this->mockDb(['id' => 5], ['id' => 9, 'user_id' => 6], $inserts);

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([
            '--login' => 'owner@example.com',
            '--name'  => 'Owner',
            '--if-not-exists' => true,
            '--identity-issuer'  => 'https://portal.example.com',
            '--identity-subject' => '7',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame([], $inserts);
        $this->assertStringContainsString('already linked to user ID 6', $tester->getDisplay());
    }

    private function rmdirRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
