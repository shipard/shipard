<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Security;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\DataSourceConfig;
use Shipard\Core\Database\SchemaIntrospector;
use Shipard\Core\Database\SqlGenerator;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Security\DsSecretCipher;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Tests\Fixtures\Module\Test\Secrets\TestSecretDocument;

/**
 * End-to-end sanity check for the encrypted_text infrastructure: schema
 * parsing, SQL generation, Document beforeSave encryption, controller-style
 * decryption — all wired together against a canary table fixture that lives
 * only under tests/Fixtures/.
 */
class CanaryTableTest extends TestCase
{
    private const FIXTURE_TABLE_PATH = __DIR__ . '/../../../Fixtures/modules/test/secrets/tables/core_test_secrets.jsonc';

    private string $tmpDir;
    private DataSourceConfig $config;
    private DsSecretCipher $cipher;

    protected function setUp(): void
    {
        DsSecretCipher::resetCache();
        $this->tmpDir = sys_get_temp_dir() . '/shpd-canary-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir . '/config', 0700, true);
        file_put_contents($this->tmpDir . '/config/main.json', json_encode([
            'id' => 'test-test-test-test',
            'name' => 'Canary',
            'database_name' => 'canary_db',
            'database_user' => 'canary',
            'created' => date('c'),
        ]));

        DsSecretCipher::generateKey($this->tmpDir);
        $this->config = new DataSourceConfig($this->tmpDir);
        $this->cipher = DsSecretCipher::forConfig($this->config);
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

    private function loadFixtureTable(): TableDefinition
    {
        return TableDefinition::fromArray(JsoncParser::parseFile(self::FIXTURE_TABLE_PATH));
    }

    private function makeDoc(): TestSecretDocument
    {
        $doc = new TestSecretDocument();
        $doc->setCipher($this->cipher);
        return $doc;
    }

    public function testFixtureTableParsesAndIntrospects(): void
    {
        $table = $this->loadFixtureTable();

        $found = SchemaIntrospector::findEncryptedColumns(['core_test_secrets' => $table]);
        $this->assertSame(
            [['table' => 'core_test_secrets', 'column' => 'api_key']],
            $found,
        );
    }

    public function testFixtureTableGeneratesNullableTextColumn(): void
    {
        $table = $this->loadFixtureTable();
        $sql = SqlGenerator::generateCreateTable('core_test_secrets', $table);

        $this->assertStringContainsString('`api_key` TEXT NULL', $sql);
        $this->assertStringNotContainsString('`api_key` TEXT NOT NULL', $sql);
    }

    public function testBeforeSaveEncryptsApiKey(): void
    {
        $doc = $this->makeDoc();
        $data = ['label' => 'OpenAI', 'api_key' => 'sk-proj-deadbeef-1234567890'];
        $doc->beforeSave($data);

        $this->assertSame('OpenAI', $data['label']);
        $this->assertNotSame('sk-proj-deadbeef-1234567890', $data['api_key']);
        $this->assertStringStartsWith('v1:', $data['api_key']);
    }

    public function testRoundTripThroughDocumentAndDecrypt(): void
    {
        $doc = $this->makeDoc();
        $original = 'sk-roundtrip-secret-✓';
        $data = ['label' => 'Test', 'api_key' => $original];

        $doc->beforeSave($data);
        $this->assertNotSame($original, $data['api_key']);

        // Simulate row coming back from DB and controller decrypting it (§7.2).
        $decrypted = $doc->decryptApiKey($data);
        $this->assertSame($original, $decrypted);
    }

    public function testNullApiKeyIsNotEncrypted(): void
    {
        $doc = $this->makeDoc();
        $data = ['label' => 'Empty', 'api_key' => null];

        $doc->beforeSave($data);

        $this->assertNull($data['api_key']);
        $this->assertNull($doc->decryptApiKey($data));
    }

    public function testEmptyStringApiKeyIsNotEncrypted(): void
    {
        $doc = $this->makeDoc();
        $data = ['label' => 'Empty', 'api_key' => ''];

        $doc->beforeSave($data);

        $this->assertSame('', $data['api_key']);
    }

    public function testMissingApiKeyKeyIsNotAdded(): void
    {
        $doc = $this->makeDoc();
        $data = ['label' => 'OnlyLabel'];

        $doc->beforeSave($data);

        $this->assertArrayNotHasKey('api_key', $data);
    }

    public function testEachSaveProducesDifferentCiphertext(): void
    {
        $doc = $this->makeDoc();
        $a = ['api_key' => 'same-secret'];
        $b = ['api_key' => 'same-secret'];

        $doc->beforeSave($a);
        $doc->beforeSave($b);

        $this->assertNotSame($a['api_key'], $b['api_key']);
        $this->assertSame('same-secret', $doc->decryptApiKey($a));
        $this->assertSame('same-secret', $doc->decryptApiKey($b));
    }

    public function testPlaintextIsNotPresentAnywhereInPersistedData(): void
    {
        $doc = $this->makeDoc();
        $secret = 'sk-this-must-never-leak-1234567890';
        $data = ['label' => 'Sensitive', 'api_key' => $secret];

        $doc->beforeSave($data);

        // After beforeSave, no field of $data should contain the plaintext.
        // This mimics what would be persisted to the DB row.
        foreach ($data as $key => $value) {
            $this->assertStringNotContainsString(
                $secret,
                (string) $value,
                "Plaintext leaked into '{$key}' after beforeSave()",
            );
        }
    }
}
