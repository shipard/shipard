<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Server;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataClass;
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
                // Položka z doby před migrací (#126 B1) ještě nese clientSecret.
                $this->provider(['label' => 'Old', 'clientSecret' => 'legacy-secret-fake']),
            ],
        ];

        $result = MainConfigPatcher::mergeAuthProvider($config, $this->provider(['label' => 'New']));

        $providers = $result['auth']['providers'];
        $this->assertCount(2, $providers);
        // Cizí provider i pořadí zůstávají, položka se stejným id se nahradí celá.
        $this->assertSame('keycloak', $providers[0]['id']);
        $this->assertSame('New', $providers[1]['label']);
        $this->assertArrayNotHasKey('clientSecret', $providers[1]);
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

    public function testProviderWithClientSecretIsRejected(): void
    {
        // Pojistka #126 D1: tajemství patří do secrets/, žádný zapisovatel ho do main.json nevrátí.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('secrets/oidc-shipard-id.client-secret');
        MainConfigPatcher::mergeAuthProvider($this->baseConfig(), $this->provider(['clientSecret' => 'x']));
    }

    public function testWithDataClassSetsTheKeysAndDropsTheLegacyServerAiFlag(): void
    {
        // #126 D6 + D9/U8: tři klíče třídy; zaniklý ai.useServerConnection zmizí, ostatní klíče ai zůstávají.
        $config = ['ai' => ['other' => 1, 'useServerConnection' => true]] + $this->baseConfig();
        $original = $config;

        $result = MainConfigPatcher::withDataClass($config, DataClass::Free, 'dataset-seed cz-vat-payer', '2026-10-16T12:00:00Z');

        $this->assertSame('free', $result['dataClass']);
        $this->assertSame('dataset-seed cz-vat-payer', $result['origin']);
        $this->assertSame('2026-10-16T12:00:00Z', $result['dataClassChanged']);
        $this->assertSame(['other' => 1], $result['ai']);
        $this->assertSame('Test DS', $result['name']);
        $this->assertSame(['install.base'], $result['modules']);
        $this->assertSame($original, $config, 'vstup se nemění');
    }

    public function testWithDataClassRemovesAnEmptyAiSectionAndLeavesConfigsWithoutOneAlone(): void
    {
        $only = MainConfigPatcher::withDataClass(
            ['ai' => ['useServerConnection' => true]] + $this->baseConfig(),
            DataClass::RealCopy,
            'ds-class',
            '2026-10-16T12:00:00Z',
        );
        $this->assertSame('realCopy', $only['dataClass']);
        $this->assertArrayNotHasKey('ai', $only, 'prázdná sekce mizí');

        $none = MainConfigPatcher::withDataClass($this->baseConfig(), DataClass::Free, 'ds-create', '2026-10-16T12:00:00Z');
        $this->assertArrayNotHasKey('ai', $none);
        $this->assertSame('ds-create', $none['origin']);
    }

    public function testWithPurposeSetsOrRemovesTheKey(): void
    {
        $with = MainConfigPatcher::withPurpose($this->baseConfig(), 'Ověřování importu');
        $this->assertSame('Ověřování importu', $with['purpose']);

        $this->assertArrayNotHasKey('purpose', MainConfigPatcher::withPurpose($with, null));
        $this->assertArrayNotHasKey('purpose', MainConfigPatcher::withPurpose($with, ''));
        $this->assertArrayNotHasKey('purpose', MainConfigPatcher::withPurpose($this->baseConfig(), null));
    }

    public function testNormalizePurposeTrimsAndAcceptsTheLimit(): void
    {
        $this->assertSame('Ruční zkoušení UI', MainConfigPatcher::normalizePurpose("  Ruční zkoušení UI \n"));
        $this->assertSame('', MainConfigPatcher::normalizePurpose('   '), 'prázdný = bez účelu');

        $limit = str_repeat('ě', MainConfigPatcher::PURPOSE_MAX_LENGTH);
        $this->assertSame($limit, MainConfigPatcher::normalizePurpose($limit), 'délka ve znacích, ne bajtech');
    }

    #[DataProvider('invalidPurposes')]
    public function testNormalizePurposeRejectsMultilineControlCharactersAndLength(string $raw): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MainConfigPatcher::normalizePurpose($raw);
    }

    /** @return array<string, array{string}> */
    public static function invalidPurposes(): array
    {
        return [
            'víceřádkový'     => ["první řádek\ndruhý"],
            'CR'              => ["a\rb"],
            'tabulátor'       => ["a\tb"],
            'řídicí znak'     => ["a\x01b"],
            'dlouhý'          => [str_repeat('x', MainConfigPatcher::PURPOSE_MAX_LENGTH + 1)],
            'dlouhý vícebajt' => [str_repeat('ě', MainConfigPatcher::PURPOSE_MAX_LENGTH + 1)],
        ];
    }
}
