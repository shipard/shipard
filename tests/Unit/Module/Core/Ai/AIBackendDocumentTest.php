<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Module\Core\Ai\AIBackendDocument;

/**
 * AIBackendDocument — validate, beforeSave, encryption flow.
 *
 * Šifrovací logika navazuje na DsSecretCipher (viz tasks/ds-encrypted-secrets.md
 * §7.1) a vzor v tests/Fixtures/Module/Test/Secrets/TestSecretDocument.php.
 * Tento test zajišťuje, že AIBackendDocument splňuje stejné invarianty.
 */
class AIBackendDocumentTest extends TestCase
{
    private string $tmpDir;
    private DsSecretCipher $cipher;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-ai-backend-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'AIBackendCanary',
            'database_name' => 'canary_db',
            'database_user' => 'canary',
            'created' => date('c'),
        ]));
        DsSecretCipher::generateKey($this->tmpDir);
        $config = new DataSourceConfig($this->tmpDir);
        $this->cipher = DsSecretCipher::forConfig($config);
    }

    protected function tearDown(): void
    {
        DsSecretCipher::resetCache();
        $this->rrmdir($this->tmpDir);
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

    private function doc(bool $withCipher = true): AIBackendDocument
    {
        $doc = new AIBackendDocument();
        if ($withCipher) {
            $doc->setSecretCipher($this->cipher);
        }
        return $doc;
    }

    // --- validate -----------------------------------------------------------

    public function testValidatePlatformAgainstCfgItem(): void
    {
        $cfg = $this->createMock(\Shipard\Core\Config\ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(static fn (string $id): mixed => $id === 'core.ai.platforms'
            ? [
                'anthropic' => ['name' => 'Anthropic', 'dialect' => 'anthropic-messages'],
                'bedrock-eu' => ['name' => 'Amazon Bedrock (EU)', 'dialect' => 'anthropic-messages', 'modelIds' => 'bedrock', 'modelIdPrefix' => 'eu.', 'priceMultiplier' => 1.1],
            ]
            : null);
        $doc = $this->doc();
        $doc->setConfig($cfg);

        $data = ['backend_id' => 'default', 'name' => 'X', 'model' => 'claude-sonnet-4-6', 'provider' => 'anthropic'];
        $this->assertTrue($doc->validate($data)->isValid());

        $data['provider'] = 'bedrock-eu';
        $this->assertTrue($doc->validate($data)->isValid(), 'platforma Bedrock EU (tasks/ai-bedrock-eu.md D57)');

        $data['provider'] = 'openai';
        $result = $doc->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertSame('unknown_platform', $result->toArray()[0]['code']);

        $data['provider'] = '';
        $result = $doc->validate($data);
        $this->assertContains('provider', array_column($result->toArray(), 'column'));

        // Bez compiled configu se platforma neověřuje (jen povinnost).
        $data['provider'] = 'openai';
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidateMissingBackendIdFails(): void
    {
        $doc = $this->doc();
        $data = ['name' => 'Anthropic Claude', 'model' => 'claude-sonnet-4-5'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('backend_id', $columns);
    }

    public function testValidateMissingNameFails(): void
    {
        $doc = $this->doc();
        $data = ['backend_id' => 'default', 'model' => 'claude-sonnet-4-5'];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $columns = array_column($result->toArray(), 'column');
        $this->assertContains('name', $columns);
    }

    public function testModelIsNotRequired(): void
    {
        // Model připojení nenese (tasks/ai-functions.md D39) — určuje ho AI funkce.
        $data = ['backend_id' => 'default', 'name' => 'Anthropic'];
        $this->assertTrue($this->doc()->validate($data)->isValid());
    }

    public function testValidateCompleteDataPasses(): void
    {
        $doc = $this->doc();
        $data = [
            'backend_id' => 'default',
            'name' => 'Anthropic',
            'model' => 'claude-sonnet-4-5',
        ];

        $result = $doc->validate($data);

        $this->assertTrue($result->isValid());
    }

    public function testValidateIsDefaultWithExistingDefaultFails(): void
    {
        $row = new \Dibi\Row(['backend_id' => 'claude-opus', 'name' => 'Claude Opus']);
        $db = $this->createMock(\Dibi\Connection::class);
        $db->method('fetch')->willReturn($row);

        $doc = $this->doc();
        $doc->setDb($db);

        $data = [
            'backend_id' => 'default',
            'name' => 'Default',
            'model' => 'claude-sonnet-4-5',
            'is_default' => true,
        ];

        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $errors = array_filter(
            $result->toArray(),
            static fn(array $e): bool => $e['column'] === 'is_default',
        );
        $this->assertCount(1, $errors);
        $this->assertSame('duplicate_default', array_values($errors)[0]['code']);
    }

    // --- beforeSave: encryption --------------------------------------------

    public function testBeforeSaveEncryptsApiKey(): void
    {
        $doc = $this->doc();
        $original = 'sk-ant-deadbeef-1234567890';
        $data = [
            'backend_id' => 'default',
            'name' => 'Anthropic',
            'model' => 'claude-sonnet-4-5',
            'api_key' => $original,
        ];

        $doc->beforeSave($data);

        $this->assertNotSame($original, $data['api_key']);
        $this->assertStringStartsWith('v1:', $data['api_key']);
    }

    public function testRoundTripEncryptThenDecryptApiKey(): void
    {
        $doc = $this->doc();
        $original = 'sk-ant-roundtrip-✓-secret';
        $data = ['backend_id' => 'default', 'name' => 'A', 'model' => 'm', 'api_key' => $original];

        $doc->beforeSave($data);
        $decrypted = $doc->decryptApiKey($data);

        $this->assertSame($original, $decrypted);
    }

    public function testEachSaveProducesDifferentCiphertext(): void
    {
        $doc = $this->doc();
        $a = ['backend_id' => 'a', 'name' => 'a', 'model' => 'm', 'api_key' => 'same-secret'];
        $b = ['backend_id' => 'b', 'name' => 'b', 'model' => 'm', 'api_key' => 'same-secret'];

        $doc->beforeSave($a);
        $doc->beforeSave($b);

        $this->assertNotSame($a['api_key'], $b['api_key']);
        $this->assertSame('same-secret', $doc->decryptApiKey($a));
        $this->assertSame('same-secret', $doc->decryptApiKey($b));
    }

    // --- beforeSave: dirty / placeholder semantics --------------------------

    public function testBeforeSaveStripsApiKeyOnEmptyStringSubmit(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 5,
            'backend_id' => 'default',
            'name' => 'Anthropic',
            'model' => 'claude-sonnet-4-5',
            'api_key' => '',
        ];

        $doc->beforeSave($data);

        // Empty submit = "neměnit" → klíč musí zmizet, jinak by UPDATE přepsal ciphertext.
        $this->assertArrayNotHasKey('api_key', $data);
    }

    public function testBeforeSaveStripsApiKeyOnNullSubmit(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 5,
            'backend_id' => 'default',
            'name' => 'Anthropic',
            'model' => 'claude-sonnet-4-5',
            'api_key' => null,
        ];

        $doc->beforeSave($data);

        $this->assertArrayNotHasKey('api_key', $data);
    }

    public function testBeforeSaveLeavesMissingApiKeyAlone(): void
    {
        $doc = $this->doc();
        $data = [
            'id' => 5,
            'backend_id' => 'default',
            'name' => 'Anthropic',
            'model' => 'claude-sonnet-4-5',
        ];

        $doc->beforeSave($data);

        $this->assertArrayNotHasKey('api_key', $data);
    }

    public function testBeforeSaveWithoutCipherThrowsOnApiKey(): void
    {
        $doc = $this->doc(withCipher: false);
        $data = [
            'backend_id' => 'default',
            'name' => 'Anthropic',
            'model' => 'claude-sonnet-4-5',
            'api_key' => 'sk-ant-something',
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DsSecretCipher/');
        $doc->beforeSave($data);
    }

    public function testDecryptWithoutCipherThrows(): void
    {
        $doc = $this->doc(withCipher: false);
        $row = ['api_key' => 'v1:irrelevant'];

        $this->expectException(\RuntimeException::class);
        $doc->decryptApiKey($row);
    }

    public function testDecryptOnNullApiKeyReturnsNull(): void
    {
        $doc = $this->doc();
        $this->assertNull($doc->decryptApiKey(['api_key' => null]));
        $this->assertNull($doc->decryptApiKey(['api_key' => '']));
        $this->assertNull($doc->decryptApiKey([]));
    }

    // --- beforeSave: flags / audit ------------------------------------------

    public function testBeforeSaveCoercesIsDefaultToInt(): void
    {
        $doc = $this->doc();
        $data = ['backend_id' => 'default', 'is_default' => true];

        $doc->beforeSave($data);

        $this->assertSame(1, $data['is_default']);
    }

    public function testBeforeSaveCoercesIsActiveToInt(): void
    {
        $doc = $this->doc();
        $data = ['backend_id' => 'default', 'is_active' => true];

        $doc->beforeSave($data);

        $this->assertSame(1, $data['is_active']);
    }

    public function testBeforeSaveFillsAuditFieldsForNewRecord(): void
    {
        $doc = $this->doc();
        $data = ['backend_id' => 'default', 'name' => 'A', 'model' => 'm'];

        $doc->beforeSave($data);

        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('modified', $data);
    }

    public function testBeforeSaveOnExistingRecordPreservesCreated(): void
    {
        $doc = $this->doc();
        $existingCreated = '2025-01-01 00:00:00';
        $data = [
            'id' => 7,
            'backend_id' => 'default',
            'name' => 'A',
            'model' => 'm',
            'created' => $existingCreated,
        ];

        $doc->beforeSave($data);

        $this->assertSame($existingCreated, $data['created']);
        $this->assertNotSame($existingCreated, $data['modified']);
    }
}
