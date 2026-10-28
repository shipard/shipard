<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Command\DataSource;

use PHPUnit\Framework\TestCase;
use Shipard\Command\DataSource\DsSecretCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Tester\CommandTester;

class TestableDsSecretCommand extends DsSecretCommand
{
    /** Stdin je terminál (skrytý dotaz) — v testech napevno. */
    public bool $terminal = true;
    /** Co přijde pipe na stdin, když terminál není. */
    public string $stdin = '';

    public function __construct(private readonly string $dsDir)
    {
        parent::__construct();
    }

    protected function getDataSourceDir(): string
    {
        return $this->dsDir;
    }

    protected function stdinIsTerminal(): bool
    {
        return $this->terminal;
    }

    protected function readStdin(): string
    {
        return $this->stdin;
    }
}

/**
 * `shpd-ds ds-secret` (#126 B1, U4): list bez hodnot, set ze skrytého dotazu
 * nebo stdin, db.password odmítnut, odebrání z main.json. Hodnoty v testech
 * jsou zjevně falešné.
 */
class DsSecretCommandTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd-ds-secret-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->dsDir . '/config', 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (['config', 'secrets'] as $sub) {
            $dir = $this->dsDir . '/' . $sub;
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            } elseif (is_file($dir)) {
                @unlink($dir);
            }
        }
        @rmdir($this->dsDir);
    }

    /** @param array<string, mixed> $extra */
    private function writeMainJson(array $extra = []): void
    {
        file_put_contents($this->dsDir . '/config/main.json', json_encode([
            'id'            => 'abcd-efgh-ijkl-mnop',
            'name'          => 'Test DS',
            'database_name' => 'abcd_efgh_ijkl_mnop',
            'database_user' => 'shpd_abcdefgh',
            'created'       => '2026-03-12T10:00:00+01:00',
        ] + $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, mixed> */
    private function mainJson(): array
    {
        return json_decode((string) file_get_contents($this->dsDir . '/config/main.json'), true);
    }

    private function writeSecret(string $name, string $raw): void
    {
        if (!is_dir($this->dsDir . '/secrets')) {
            mkdir($this->dsDir . '/secrets', 0700);
        }
        file_put_contents($this->dsDir . '/secrets/' . $name, $raw);
    }

    private function secret(string $name): ?string
    {
        $path = $this->dsDir . '/secrets/' . $name;
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** @return array{TestableDsSecretCommand, CommandTester} */
    private function makeTester(bool $terminal = true, string $stdin = ''): array
    {
        $cmd = new TestableDsSecretCommand($this->dsDir);
        $cmd->terminal = $terminal;
        $cmd->stdin = $stdin;
        $cmd->setHelperSet(new HelperSet([new QuestionHelper()]));
        return [$cmd, new CommandTester($cmd)];
    }

    /** @return array<string, mixed> */
    private function provider(string $id, array $extra = []): array
    {
        return ['id' => $id, 'label' => $id, 'issuer' => 'https://idp.example/' . $id, 'clientId' => 'c-' . $id] + $extra;
    }

    // ── list ────────────────────────────────────────────────────────────────

    public function testListShowsStatusesWithoutValues(): void
    {
        // Hodnoty v main.json se ignorují (B2): status nese stav souboru a k němu ignorovanou hodnotu.
        $this->writeMainJson([
            'database_password' => 'db-pw-fake',
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u']],
            'auth' => ['providers' => [$this->provider('shipard-id'), $this->provider('github', ['clientSecret' => 'oidc-fake'])]],
        ]);
        $this->writeSecret('oidc-shipard-id.client-secret', 'file-oidc-fake');
        $this->writeSecret('oidc-github.client-secret', 'file-github-fake');
        $this->writeSecret('secrets.key', str_repeat('k', 32));
        [, $tester] = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        $this->assertMatchesRegularExpression('/db\.password\s+\|\s+database\s+\|\s+missing; main\.json value ignored \(run ds-upgrade\)/', $display);
        $this->assertMatchesRegularExpression('/mail-relay\.password\s+\|\s+mail\.relay\s+\|\s+missing\s+\|/', $display);
        $this->assertMatchesRegularExpression('/oidc-shipard-id\.client-secret\s+\|\s+auth\.providers\[shipard-id\]\s+\|\s+present\s+\|/', $display);
        $this->assertMatchesRegularExpression('/oidc-github\.client-secret\s+\|\s+auth\.providers\[github\]\s+\|\s+present; main\.json value ignored \(run ds-upgrade\)/', $display);
        $this->assertStringContainsString('Other files in secrets/: 1 (not managed by ds-secret)', $display);
        $this->assertStringContainsString('2 secret(s) still in config/main.json — ignored; shpd-ds ds-upgrade moves them to secrets/ and overwrites the files.', $display);
        $this->assertStringNotContainsString('fake', $display);
        $this->assertStringNotContainsString('kkkk', $display);
    }

    public function testListOnACleanDataSourceWithoutSecretsDirectory(): void
    {
        $this->writeMainJson(['mail' => ['relay' => ['host' => 'localhost', 'port' => 25, 'security' => 'none']]]);
        [, $tester] = $this->makeTester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'list']));
        $display = $tester->getDisplay();
        $this->assertMatchesRegularExpression('/db\.password\s+\|\s+database\s+\|\s+missing\s+\|/', $display);
        $this->assertMatchesRegularExpression('/mail-relay\.password\s+\|\s+mail\.relay\s+\|\s+missing \(optional\)/', $display);
        $this->assertStringContainsString('Other files in secrets/: 0', $display);
        $this->assertStringNotContainsString('still in config/main.json', $display);
    }

    public function testFailsOutsideADataSourceDirectoryAndOnUnknownAction(): void
    {
        [, $tester] = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('Not a Shipard data source directory', $tester->getDisplay());

        $this->writeMainJson();
        [, $tester] = $this->makeTester();
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'show']));
        $this->assertStringContainsString("Unknown action 'show'", $tester->getDisplay());
    }

    // ── set ─────────────────────────────────────────────────────────────────

    public function testSetFromStdinStripsOneTrailingNewlineAndWritesTheFile(): void
    {
        $this->writeMainJson(['mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u']]]);
        [, $tester] = $this->makeTester(terminal: false, stdin: "relay-pw-fake\n");

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'name' => 'mail-relay.password']));

        $this->assertSame('relay-pw-fake', $this->secret('mail-relay.password'));
        $this->assertSame(0600, fileperms($this->dsDir . '/secrets/mail-relay.password') & 0777);
        $this->assertSame(0700, fileperms($this->dsDir . '/secrets') & 0777);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Secret mail-relay.password written.', $display);
        $this->assertStringNotContainsString('fake', $display);
        $this->assertStringNotContainsString('[WARN]', $display);
    }

    public function testSetFromHiddenPromptInATerminal(): void
    {
        $this->writeMainJson(['auth' => ['providers' => [$this->provider('github')]]]);
        [, $tester] = $this->makeTester(terminal: true);
        $tester->setInputs(['oidc-prompt-fake']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'name' => 'oidc-github.client-secret']));

        $this->assertSame('oidc-prompt-fake', $this->secret('oidc-github.client-secret'));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Value for oidc-github.client-secret (input hidden)', $display);
        $this->assertStringContainsString('Secret oidc-github.client-secret written.', $display);
        $this->assertStringNotContainsString('fake', $display);
    }

    public function testSetRemovesTheOldValueFromMainJson(): void
    {
        // B2 U2: hodnotu v main.json by jinak příští ds-upgrade přesunul přes nově zadanou.
        $this->writeMainJson([
            'mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u', 'password' => 'old-relay-fake']],
            'auth' => ['providers' => [$this->provider('github', ['clientSecret' => 'old-oidc-fake']), $this->provider('other', ['clientSecret' => 'other-fake'])]],
        ]);
        [, $tester] = $this->makeTester(terminal: false, stdin: 'new-relay-fake');
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'name' => 'mail-relay.password']));
        $this->assertStringContainsString('the old value was removed from config/main.json', $tester->getDisplay());

        [, $tester] = $this->makeTester(terminal: false, stdin: 'new-oidc-fake');
        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'name' => 'oidc-github.client-secret']));

        $config = $this->mainJson();
        $this->assertSame(['host' => 'smtp.example', 'username' => 'u'], $config['mail']['relay']);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][0]);
        $this->assertSame('other-fake', $config['auth']['providers'][1]['clientSecret'], 'cizí provider se nemění');
        $this->assertSame('new-relay-fake', $this->secret('mail-relay.password'));
        $this->assertSame('new-oidc-fake', $this->secret('oidc-github.client-secret'));
        $this->assertSame(0600, fileperms($this->dsDir . '/config/main.json') & 0777);
    }

    public function testSetReportsWhenTheOldValueCannotBeRemovedFromMainJson(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root zapíše i do adresáře bez práv');
        }
        $this->writeMainJson(['mail' => ['relay' => ['host' => 'smtp.example', 'username' => 'u', 'password' => 'old-relay-fake']]]);
        chmod($this->dsDir . '/config', 0500);
        [, $tester] = $this->makeTester(terminal: false, stdin: 'new-relay-fake');
        try {
            $exit = $tester->execute(['action' => 'set', 'name' => 'mail-relay.password']);
        } finally {
            chmod($this->dsDir . '/config', 0750);
        }

        $this->assertSame(Command::FAILURE, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Secret mail-relay.password written, but the old value could not be removed from config/main.json', $display);
        $this->assertStringContainsString('it is ignored now, but the next ds-upgrade would move it over the new value; remove it by hand.', $display);
        $this->assertSame('new-relay-fake', $this->secret('mail-relay.password'), 'soubor je zapsaný');
        $this->assertSame('old-relay-fake', $this->mainJson()['mail']['relay']['password'], 'main.json zůstal');
        $this->assertStringNotContainsString('fake', $display);
    }

    public function testSetForAnUnknownProviderWritesWithAWarning(): void
    {
        $this->writeMainJson();
        [, $tester] = $this->makeTester(terminal: false, stdin: 'oidc-fake');

        $this->assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', 'name' => 'oidc-entra.client-secret']));
        $this->assertStringContainsString("[WARN] No auth provider 'entra' in config/main.json", $tester->getDisplay());
        $this->assertSame('oidc-fake', $this->secret('oidc-entra.client-secret'));
    }

    public function testSetRefusesDbPassword(): void
    {
        $this->writeMainJson();
        [, $tester] = $this->makeTester(terminal: false, stdin: 'x');

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'name' => 'db.password']));
        $this->assertStringContainsString('db.password must match the database user', $tester->getDisplay());
        $this->assertNull($this->secret('db.password'));
    }

    public function testSetRefusesUnknownNamesAndMissingName(): void
    {
        $this->writeMainJson();
        [, $tester] = $this->makeTester(terminal: false, stdin: 'x');
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'name' => 'secrets.key']));
        $this->assertStringContainsString("Unknown secret name 'secrets.key'", $tester->getDisplay());

        [, $tester] = $this->makeTester(terminal: false, stdin: 'x');
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'name' => '../oidc-x.client-secret']));

        [, $tester] = $this->makeTester(terminal: false, stdin: 'x');
        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set']));
        $this->assertStringContainsString('Usage: ds-secret set', $tester->getDisplay());
        $this->assertDirectoryDoesNotExist($this->dsDir . '/secrets');
    }

    public function testEmptyValueFails(): void
    {
        $this->writeMainJson();
        [, $tester] = $this->makeTester(terminal: false, stdin: "\n");

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'name' => 'mail-relay.password']));
        $this->assertStringContainsString('No value given', $tester->getDisplay());
        $this->assertNull($this->secret('mail-relay.password'));
    }

    public function testNonInteractiveRunWithATerminalGetsNoValue(): void
    {
        $this->writeMainJson();
        [, $tester] = $this->makeTester(terminal: true);

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'name' => 'mail-relay.password'], ['interactive' => false]));
        $this->assertStringContainsString('No value given', $tester->getDisplay());
    }

    public function testWriteFailureIsReported(): void
    {
        $this->writeMainJson();
        file_put_contents($this->dsDir . '/secrets', 'a file, so mkdir secrets/ fails');
        [, $tester] = $this->makeTester(terminal: false, stdin: 'x');

        $this->assertSame(Command::FAILURE, $tester->execute(['action' => 'set', 'name' => 'mail-relay.password']));
        $this->assertStringContainsString('Failed to write secrets/mail-relay.password', $tester->getDisplay());
    }
}
