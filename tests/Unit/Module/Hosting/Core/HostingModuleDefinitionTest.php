<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Hosting\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Utils\JsoncParser;

/**
 * Guards declarative contracts of hosting.core / install.hosting, na kterých
 * závisí runtime: `adminOnly` na všech hosting tabulkách (D9 — bez něj by
 * generické CRUD/viewer/form cesty pustily ne-adminy k evidenci hostingu)
 * a minimální závislosti dedikovaného install modulu (D11).
 */
class HostingModuleDefinitionTest extends TestCase
{
    private const MODULE_PATH = '/modules/hosting/core';

    public function testModuleDeclaresNineTables(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . self::MODULE_PATH);

        $this->assertSame('hosting.core', $module->id);
        $this->assertSame(
            [
                'hosting_core_servers',
                'hosting_core_data_sources',
                'hosting_core_ds_users',
                'hosting_core_oidc_codes',
                'hosting_core_mail_routers',
                'hosting_core_ai_tokens',
                'hosting_core_ai_usage',
                'hosting_core_ds_stats',
            ],
            $module->tables,
        );
    }

    public function testAllTablesAreAdminOnly(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . self::MODULE_PATH);

        foreach ($module->tables as $tableFile) {
            $raw = JsoncParser::parseFile(
                dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/' . $tableFile . '.jsonc',
            );
            $def = TableDefinition::fromArray($raw);

            $this->assertTrue($def->adminOnly, "Tabulka {$tableFile} musí mít adminOnly=true (D9)");
        }
    }

    public function testServersTableShape(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_servers.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        foreach (['name', 'fqdn', 'can_provision', 'provision_default'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }
    }

    public function testServersHaveDocumentClass(): void
    {
        // Jediný default server (hosting-08 D1) vynucuje HostingServerDocument
        // v afterPersist — bez registrace by servery jely přes DefaultDocument.
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . self::MODULE_PATH);

        $byTable = [];
        foreach ($module->documentClasses as $dc) {
            $byTable[$dc['table']] = $dc['class'];
        }
        $this->assertSame(
            'Shipard\Module\Hosting\Core\HostingServerDocument',
            $byTable['hosting_core_servers'] ?? null,
        );
    }

    public function testDataSourcesTableShape(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_data_sources.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        foreach (['ds_id', 'name', 'web_id', 'server', 'url_app', 'install_module', 'lifecycle',
            'oidc_client_secret', 'oidc_redirect_uri', 'mail_token'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }
        // Token externího AI analyzeru zanikl s registrem analyzerů (#85 D22).
        $this->assertNotContains('analyzer_token', $columnIds);

        // Secrety nesmí uniknout do API/form odpovědí (D2, D4)
        // — sensitive flag hlídá TableAccessGuard.
        foreach ($def->columns as $col) {
            if ($col->id === 'oidc_client_secret') {
                $this->assertTrue($col->sensitive, 'oidc_client_secret musí být sensitive');
            }
            if ($col->id === 'mail_token') {
                $this->assertTrue($col->sensitive, "{$col->id} musí být sensitive");
                $this->assertSame('encrypted_text', $col->type, "{$col->id} musí být encrypted_text");
            }
        }

        $uniqueIndexes = [];
        foreach ($def->indexes as $index) {
            if ($index->type === 'unique') {
                $uniqueIndexes[] = $index->id;
            }
        }
        $this->assertContains('unq_ds_id', $uniqueIndexes);
        $this->assertContains('unq_web_id', $uniqueIndexes);
    }

    public function testMailRoutersTableShape(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_mail_routers.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        foreach (['name', 'domains', 'api_key_prefix', 'api_key_hash', 'last_seen', 'note'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }

        // Hash klíče nesmí uniknout do API/form odpovědí — sensitive flag
        // hlídá TableAccessGuard (stejný vzor jako hosting_core_servers).
        foreach ($def->columns as $col) {
            if ($col->id === 'api_key_hash') {
                $this->assertTrue($col->sensitive, 'api_key_hash musí být sensitive');
            }
        }
    }

    public function testAiTokensTableShape(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_ai_tokens.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        foreach (['data_source', 'token_prefix', 'token_hash', 'token_encrypted',
            'active', 'note', 'last_used'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }

        // Token nesmí uniknout do API/form odpovědí (D5) — sensitive flag
        // hlídá TableAccessGuard.
        foreach ($def->columns as $col) {
            if ($col->id === 'token_prefix' || $col->id === 'token_hash') {
                $this->assertTrue($col->sensitive, "{$col->id} musí být sensitive");
            }
            if ($col->id === 'token_encrypted') {
                $this->assertTrue($col->sensitive, 'token_encrypted musí být sensitive');
                $this->assertSame('encrypted_text', $col->type, 'token_encrypted musí být encrypted_text');
            }
        }

        $uniqueIndexes = [];
        foreach ($def->indexes as $index) {
            if ($index->type === 'unique') {
                $uniqueIndexes[] = $index->id;
            }
        }
        $this->assertContains('unq_token_prefix', $uniqueIndexes);
    }

    public function testAiUsageTableIsAppendOnlyLog(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_ai_usage.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        // Append-only log — bez docStates a bez docState sloupců.
        $this->assertNull($def->docStates);
        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        $this->assertNotContains('docState', $columnIds);

        foreach (['data_source', 'model', 'input_tokens', 'output_tokens',
            'cache_creation_tokens', 'cache_read_tokens', 'http_status',
            'stream', 'duration_ms', 'created'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }
    }

    public function testDsStatsTableIsSnapshotWithUniqueDataSource(): void
    {
        $raw = JsoncParser::parseFile(
            dirname(__DIR__, 5) . self::MODULE_PATH . '/tables/hosting_core_ds_stats.jsonc',
        );
        $def = TableDefinition::fromArray($raw);

        // Snapshot (D7) — bez docStates, jeden řádek per DS (unique).
        $this->assertNull($def->docStates);
        $columnIds = array_map(static fn ($c) => $c->id, $def->columns);
        $this->assertNotContains('docState', $columnIds);
        foreach (['data_source', 'alerts_count', 'mail_count', 'collected_at'] as $expected) {
            $this->assertContains($expected, $columnIds);
        }

        $uniqueIndexes = [];
        foreach ($def->indexes as $index) {
            if ($index->type === 'unique') {
                $uniqueIndexes[] = $index->id;
            }
        }
        $this->assertContains('unq_data_source', $uniqueIndexes);
    }

    public function testInstallHostingHasMinimalDependencies(): void
    {
        $module = ModuleLoader::loadModule(dirname(__DIR__, 5) . '/modules/install/hosting');

        $this->assertSame('install.hosting', $module->id);
        $this->assertSame(['core.system', 'core.alerts', 'hosting.core'], $module->dependencies);
    }
}
