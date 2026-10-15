<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Server\MainConfigPatcher;

class MainConfigPatcherTest extends TestCase
{
    /** @return array<string, mixed> */
    private function baseConfig(): array
    {
        return [
            'id' => 'aaaa-bbbb-cccc-dddd',
            'name' => 'Test DS',
            'modules' => ['install.base'],
            'database_name' => 'db',
            'database_user' => 'user',
            'database_password' => 'pw',
        ];
    }

    /** @return array<string, mixed> */
    private function provider(array $overrides = []): array
    {
        return array_merge([
            'id' => 'shipard-id',
            'label' => 'Shipard',
            'issuer' => 'https://portal.example.com/api/v1/_hosting/oidc',
            'clientId' => 'aaaa-bbbb-cccc-dddd',
            'clientSecret' => 'secret',
            'autoLinkEmail' => false,
        ], $overrides);
    }

    public function testAddsProviderAndCreatesAuthSection(): void
    {
        $result = MainConfigPatcher::mergeAuthProvider($this->baseConfig(), $this->provider());

        $this->assertSame([$this->provider()], $result['auth']['providers']);
        // Nesouvisející klíče beze změny.
        $this->assertSame('Test DS', $result['name']);
        $this->assertSame(['install.base'], $result['modules']);
        $this->assertSame('pw', $result['database_password']);
    }

    public function testReplacesExistingProviderById(): void
    {
        $config = $this->baseConfig();
        $config['auth'] = [
            'local' => true,
            'providers' => [
                ['id' => 'keycloak', 'issuer' => 'https://kc.example.com'],
                $this->provider(['clientSecret' => 'old-secret']),
            ],
        ];

        $result = MainConfigPatcher::mergeAuthProvider($config, $this->provider(['clientSecret' => 'new-secret']));

        $providers = $result['auth']['providers'];
        $this->assertCount(2, $providers);
        // Cizí provider i pořadí zůstávají, položka se stejným id se nahradí.
        $this->assertSame('keycloak', $providers[0]['id']);
        $this->assertSame('new-secret', $providers[1]['clientSecret']);
        // Ostatní klíče auth sekce beze změny.
        $this->assertTrue($result['auth']['local']);
    }

    public function testAppendsNextToForeignProviders(): void
    {
        $config = $this->baseConfig();
        $config['auth'] = ['providers' => [['id' => 'keycloak']]];

        $result = MainConfigPatcher::mergeAuthProvider($config, $this->provider());

        $this->assertCount(2, $result['auth']['providers']);
        $this->assertSame('shipard-id', $result['auth']['providers'][1]['id']);
    }

    public function testDoesNotMutateInput(): void
    {
        $config = $this->baseConfig();
        $original = $config;

        MainConfigPatcher::mergeAuthProvider($config, $this->provider());

        $this->assertSame($original, $config);
    }

    public function testInvalidProviderIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MainConfigPatcher::mergeAuthProvider($this->baseConfig(), $this->provider(['id' => 'Bad Id!']));
    }

    public function testWithServerAiSetsAndClearsTheFlag(): void
    {
        // tasks/testing-phase4.md D40: `ai.useServerConnection` vedle ostatních klíčů `ai`.
        $on = MainConfigPatcher::withServerAi($this->baseConfig(), true);
        $this->assertSame(['useServerConnection' => true], $on['ai']);
        $this->assertSame($this->baseConfig()['name'], $on['name']);

        $off = MainConfigPatcher::withServerAi($on, false);
        $this->assertArrayNotHasKey('ai', $off, 'prázdná sekce mizí');

        $other = MainConfigPatcher::withServerAi(['ai' => ['other' => 1, 'useServerConnection' => true]] + $this->baseConfig(), false);
        $this->assertSame(['other' => 1], $other['ai']);
        $this->assertSame(['other' => 1, 'useServerConnection' => true], MainConfigPatcher::withServerAi($other, true)['ai']);
    }
}
