<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\DataSourceSecretsMigrator;
use Shipard\Core\Server\MainConfigFile;

/**
 * Přesun tajemství z `config/main.json` do `secrets/` (#126 D4, U3, U5):
 * tři druhy tajemství, jen dotčené klíče, přepis jiné hodnoty s varováním,
 * neplatné id providera, idempotence, dokončení po pádu, nevalidní soubor.
 * Hodnoty v testech jsou zjevně falešné.
 */
final class DataSourceSecretsMigratorTest extends TestCase
{
    private string $dsDir;

    protected function setUp(): void
    {
        $this->dsDir = sys_get_temp_dir() . '/shpd-secrets-migrator-' . bin2hex(random_bytes(4));
        mkdir($this->dsDir . '/config', 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (['config', 'secrets'] as $sub) {
            $dir = $this->dsDir . '/' . $sub;
            if (is_dir($dir)) {
                @chmod($dir, 0700);
                foreach (glob($dir . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($dir);
            }
        }
        @rmdir($this->dsDir);
    }

    /** @param array<string, mixed> $extra */
    private function writeMainJson(array $extra = []): string
    {
        $json = json_encode([
            'id'            => 'aaaa-bbbb-cccc-dddd',
            'name'          => 'Test DS',
            'modules'       => ['install.base'],
            'database_name' => 'aaaa_bbbb_cccc_dddd',
            'database_user' => 'shpd_aaaabbbb',
            'created'       => '2026-10-16T10:00:00+02:00',
        ] + $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(MainConfigFile::path($this->dsDir), $json);
        return $json;
    }

    /** @return array<string, mixed> */
    private function mainJson(): array
    {
        return json_decode((string) file_get_contents(MainConfigFile::path($this->dsDir)), true);
    }

    private function secret(string $name): ?string
    {
        $path = $this->dsDir . '/secrets/' . $name;
        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** @return array<string, mixed> */
    private function provider(string $id, ?string $secret, array $extra = []): array
    {
        $p = ['id' => $id, 'label' => $id, 'issuer' => 'https://idp.example/' . $id, 'clientId' => 'client-' . $id] + $extra;
        if ($secret !== null) {
            $p['clientSecret'] = $secret;
        }
        return $p;
    }

    public function testMovesAllThreeKindsAndRemovesOnlyTheSecretKeys(): void
    {
        $this->writeMainJson([
            'database_password' => 'db-pw-fake',
            'mail' => ['relay' => ['host' => 'smtp.example', 'port' => 587, 'username' => 'mailer', 'password' => 'relay-pw-fake']],
            'auth' => ['local' => true, 'providers' => [
                $this->provider('shipard-id', 'oidc-fake-1', ['autoLinkEmail' => true]),
                $this->provider('github', 'oidc-fake-2', ['kind' => 'github']),
            ]],
        ]);

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);

        $this->assertSame(
            ['db.password', 'mail-relay.password', 'oidc-shipard-id.client-secret', 'oidc-github.client-secret'],
            $result->moved,
        );
        $this->assertSame([], $result->warnings);

        $this->assertSame('db-pw-fake', $this->secret('db.password'));
        $this->assertSame('relay-pw-fake', $this->secret('mail-relay.password'));
        $this->assertSame('oidc-fake-1', $this->secret('oidc-shipard-id.client-secret'));
        $this->assertSame('oidc-fake-2', $this->secret('oidc-github.client-secret'));
        $this->assertSame(0700, fileperms($this->dsDir . '/secrets') & 0777);
        $this->assertSame(0600, fileperms($this->dsDir . '/secrets/db.password') & 0777);

        $config = $this->mainJson();
        $this->assertArrayNotHasKey('database_password', $config);
        $this->assertSame(['host' => 'smtp.example', 'port' => 587, 'username' => 'mailer'], $config['mail']['relay']);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][0]);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][1]);
        $this->assertSame('shipard-id', $config['auth']['providers'][0]['id']);
        $this->assertTrue($config['auth']['providers'][0]['autoLinkEmail']);
        $this->assertSame('github', $config['auth']['providers'][1]['kind']);
        $this->assertTrue($config['auth']['local']);
        $this->assertSame('shpd_aaaabbbb', $config['database_user']);
        $this->assertSame(0600, fileperms(MainConfigFile::path($this->dsDir)) & 0777);
    }

    public function testWithoutSecretsIsANoopThatLeavesMainJsonByteIdentical(): void
    {
        $original = $this->writeMainJson([
            'mail' => ['relay' => ['host' => 'localhost', 'port' => 25, 'security' => 'none']],
            'auth' => ['providers' => [$this->provider('shipard-id', null)]],
        ]);

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);

        $this->assertTrue($result->isNoop());
        $this->assertSame($original, file_get_contents(MainConfigFile::path($this->dsDir)));
        $this->assertDirectoryDoesNotExist($this->dsDir . '/secrets');
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->writeMainJson(['database_password' => 'db-pw-fake']);
        $migrator = new DataSourceSecretsMigrator();

        $this->assertSame(['db.password'], $migrator->migrate($this->dsDir)->moved);
        $after = file_get_contents(MainConfigFile::path($this->dsDir));

        $this->assertTrue($migrator->migrate($this->dsDir)->isNoop());
        $this->assertSame($after, file_get_contents(MainConfigFile::path($this->dsDir)));
        $this->assertSame('db-pw-fake', $this->secret('db.password'));
    }

    public function testEmptyValuesAreSkipped(): void
    {
        $original = $this->writeMainJson([
            'database_password' => '',
            'mail' => ['relay' => ['host' => 'smtp.example', 'password' => '']],
            'auth' => ['providers' => [$this->provider('x', '')]],
        ]);

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);

        $this->assertTrue($result->isNoop());
        $this->assertSame($original, file_get_contents(MainConfigFile::path($this->dsDir)));
    }

    public function testDifferentValueInFileIsOverwrittenWithAWarning(): void
    {
        // U3: v přechodném období má main.json přednost — soubor se srovná podle něj.
        $this->writeMainJson(['database_password' => 'new-pw-fake']);
        mkdir($this->dsDir . '/secrets', 0700);
        file_put_contents($this->dsDir . '/secrets/db.password', "old-pw-fake\n");

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);

        $this->assertSame(['db.password'], $result->moved);
        $this->assertSame(
            ['secrets/db.password had a different value — overwritten with the value from config/main.json'],
            $result->warnings,
        );
        $this->assertSame('new-pw-fake', $this->secret('db.password'));
        $this->assertArrayNotHasKey('database_password', $this->mainJson());
    }

    public function testCompletesAfterACrashBetweenFileWriteAndConfigPatch(): void
    {
        // Soubory už zapsané se stejnou hodnotou, main.json ještě plný — bez varování.
        $this->writeMainJson(['database_password' => 'db-pw-fake']);
        mkdir($this->dsDir . '/secrets', 0700);
        file_put_contents($this->dsDir . '/secrets/db.password', 'db-pw-fake');

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);

        $this->assertSame(['db.password'], $result->moved);
        $this->assertSame([], $result->warnings);
        $this->assertArrayNotHasKey('database_password', $this->mainJson());
    }

    public function testProviderWithInvalidIdKeepsItsSecretInMainJsonWithAWarning(): void
    {
        $this->writeMainJson([
            'database_password' => 'db-pw-fake',
            'auth' => ['providers' => [
                $this->provider('Entra ID', 'oidc-fake-bad'),
                $this->provider('ok', 'oidc-fake-ok'),
                ['label' => 'bez id', 'clientSecret' => 'oidc-fake-noid'],
            ]],
        ]);

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);

        $this->assertSame(['db.password', 'oidc-ok.client-secret'], $result->moved);
        $this->assertSame([
            'auth.providers[0] has an invalid id — its clientSecret stays in config/main.json (fix the id, then run ds-upgrade again)',
            'auth.providers[2] has an invalid id — its clientSecret stays in config/main.json (fix the id, then run ds-upgrade again)',
        ], $result->warnings);

        $config = $this->mainJson();
        $this->assertSame('oidc-fake-bad', $config['auth']['providers'][0]['clientSecret']);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][1]);
        $this->assertSame('oidc-fake-noid', $config['auth']['providers'][2]['clientSecret']);
        $this->assertSame('oidc-fake-ok', $this->secret('oidc-ok.client-secret'));
        $this->assertNull($this->secret('oidc-Entra ID.client-secret'));
        // Varování nenese hodnotu.
        $this->assertStringNotContainsString('oidc-fake', implode("\n", $result->warnings));
    }

    public function testInvalidMainJsonThrowsAndChangesNothing(): void
    {
        file_put_contents(MainConfigFile::path($this->dsDir), '{not json');

        try {
            (new DataSourceSecretsMigrator())->migrate($this->dsDir);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invalid JSON', $e->getMessage());
        }
        $this->assertSame('{not json', file_get_contents(MainConfigFile::path($this->dsDir)));
        $this->assertDirectoryDoesNotExist($this->dsDir . '/secrets');
    }

    public function testMissingMainJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read');
        (new DataSourceSecretsMigrator())->migrate($this->dsDir);
    }

    public function testResultNeverCarriesValues(): void
    {
        $this->writeMainJson([
            'database_password' => 'db-pw-fake',
            'mail' => ['relay' => ['host' => 'smtp.example', 'password' => 'relay-pw-fake']],
        ]);
        mkdir($this->dsDir . '/secrets', 0700);
        file_put_contents($this->dsDir . '/secrets/db.password', 'older-fake');

        $result = (new DataSourceSecretsMigrator())->migrate($this->dsDir);
        $dump = json_encode($result);

        $this->assertStringNotContainsString('fake', (string) $dump);
    }

    // ── souběžná změna main.json mezi zápisem souborů a patchem (#126 B2, U3) ──

    /** Migrátor, který mezi zápisem souborů a patchem `main.json` pustí zásah „jiného zapisovatele“. */
    private function migratorWithConcurrentWrite(\Closure $between): DataSourceSecretsMigrator
    {
        return new class($between) extends DataSourceSecretsMigrator {
            public function __construct(private readonly \Closure $between)
            {
                parent::__construct();
            }

            protected function beforeConfigPatch(string $dsDir): void
            {
                ($this->between)($dsDir);
            }
        };
    }

    private function rewriteMainJson(\Closure $change): void
    {
        $config = $this->mainJson();
        $config = $change($config);
        file_put_contents(MainConfigFile::path($this->dsDir), json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function testConcurrentReorderOfProvidersRemovesTheClientSecretByIdNotIndex(): void
    {
        // hosting-sync mezi čtením a patchem prohodí pořadí providerů: tajemství zmizí tomu, komu patří.
        $this->writeMainJson(['auth' => ['providers' => [
            $this->provider('shipard-id', 'oidc-fake-1'),
            $this->provider('github', null, ['kind' => 'github']),
        ]]]);
        $migrator = $this->migratorWithConcurrentWrite(function (): void {
            $this->rewriteMainJson(static function (array $config): array {
                $config['auth']['providers'] = array_reverse($config['auth']['providers']);
                return $config;
            });
        });

        $result = $migrator->migrate($this->dsDir);

        $this->assertSame(['oidc-shipard-id.client-secret'], $result->moved);
        $this->assertSame([], $result->warnings);
        $config = $this->mainJson();
        $this->assertSame('github', $config['auth']['providers'][0]['id']);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][0]);
        $this->assertSame('shipard-id', $config['auth']['providers'][1]['id']);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][1]);
        $this->assertSame('oidc-fake-1', $this->secret('oidc-shipard-id.client-secret'));
    }

    public function testConcurrentlyChangedValueStaysInMainJsonWithAWarningAndIsNotReportedAsMoved(): void
    {
        $this->writeMainJson(['database_password' => 'db-pw-fake', 'mail' => ['relay' => ['host' => 'smtp.example', 'password' => 'relay-pw-fake']]]);
        $migrator = $this->migratorWithConcurrentWrite(function (): void {
            $this->rewriteMainJson(static function (array $config): array {
                $config['database_password'] = 'db-pw-rotated-fake';
                return $config;
            });
        });

        $result = $migrator->migrate($this->dsDir);

        $this->assertSame(['mail-relay.password'], $result->moved);
        $this->assertSame(['db.password changed in config/main.json during migration — left there, run ds-upgrade again'], $result->warnings);
        $this->assertSame('db-pw-rotated-fake', $this->mainJson()['database_password'], 'změněná hodnota zůstává');
        $this->assertArrayNotHasKey('password', $this->mainJson()['mail']['relay']);
        $this->assertSame('db-pw-fake', $this->secret('db.password'), 'soubor se starší hodnotou nevadí');

        // Další běh ji přesune a soubor přepíše (U2).
        $again = (new DataSourceSecretsMigrator())->migrate($this->dsDir);
        $this->assertSame(['db.password'], $again->moved);
        $this->assertSame(['secrets/db.password had a different value — overwritten with the value from config/main.json'], $again->warnings);
        $this->assertSame('db-pw-rotated-fake', $this->secret('db.password'));
        $this->assertArrayNotHasKey('database_password', $this->mainJson());
    }

    public function testConcurrentlyRemovedProviderLeavesAWarningAndMovesTheRest(): void
    {
        $this->writeMainJson(['database_password' => 'db-pw-fake', 'auth' => ['providers' => [
            $this->provider('shipard-id', 'oidc-fake-1'),
            $this->provider('github', 'oidc-fake-2', ['kind' => 'github']),
        ]]]);
        $migrator = $this->migratorWithConcurrentWrite(function (): void {
            $this->rewriteMainJson(static function (array $config): array {
                $config['auth']['providers'] = [$config['auth']['providers'][0]];
                return $config;
            });
        });

        $result = $migrator->migrate($this->dsDir);

        $this->assertSame(['db.password', 'oidc-shipard-id.client-secret'], $result->moved);
        $this->assertSame(['oidc-github.client-secret changed in config/main.json during migration — left there, run ds-upgrade again'], $result->warnings);
        $this->assertStringNotContainsString('fake', implode("\n", $result->warnings));
        $config = $this->mainJson();
        $this->assertArrayNotHasKey('database_password', $config);
        $this->assertCount(1, $config['auth']['providers']);
        $this->assertArrayNotHasKey('clientSecret', $config['auth']['providers'][0]);
        $this->assertSame('oidc-fake-2', $this->secret('oidc-github.client-secret'), 'soubor zapsaný, další běh bez providera ho nechá');
    }
}
