<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Ai\ModelCatalog;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Core\Ai\AiFunctionProvisioner;

/**
 * AiFunctionProvisioner (tasks/ai-functions.md D38, D41, D46): řádek na
 * každou deklarovanou funkci, existující beze změny, bez registru nic;
 * převod nastavení `exchange.contentTag.backend` a ladění výchozího
 * připojení do řádků funkcí, přepis vyřazených modelů, značka hotového
 * převodu.
 */
final class AiFunctionProvisionerTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768]],
        'exchange.contentTags' => ['name' => 'Štítky', 'tier' => 'fast', 'defaults' => ['maxTokens' => 8000]],
        'chat.default' => ['name' => 'Chat', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
    ];

    private const MODELS = [
        'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6', 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-6'], 'prices' => ['input' => 3, 'output' => 15]],
        'claude-sonnet-4-5' => ['name' => 'Claude Sonnet 4.5', 'tier' => 'balanced', 'platformIds' => ['anthropic' => 'claude-sonnet-4-5'], 'prices' => ['input' => 3, 'output' => 15],
            'lifecycle' => ['status' => 'deprecated', 'retiresAt' => '2026-11-30', 'replacedBy' => 'claude-sonnet-4-6']],
        'claude-haiku-5-5' => ['name' => 'Claude Haiku 5.5', 'tier' => 'fast', 'platformIds' => ['anthropic' => 'claude-haiku-5-5'], 'prices' => ['input' => 0.1, 'output' => 0.5]],
    ];

    private function catalog(): ModelCatalog
    {
        return ModelCatalog::fromArray(self::MODELS, ['fast' => ['model' => 'claude-sonnet-4-6'], 'balanced' => ['model' => 'claude-sonnet-4-6']]);
    }

    /** SettingsStore s jednou hodnotou; zachytí smazání. */
    private function settings(mixed $contentTagBackend, ?array &$deleted = null): SettingsStore
    {
        $deleted = [];
        $settings = $this->createMock(SettingsStore::class);
        $settings->method('get')->willReturnCallback(static fn (string $key): mixed => $key === AiFunctionProvisioner::LEGACY_SETTING_CONTENT_TAG_CONNECTION ? $contentTagBackend : null);
        $settings->method('delete')->willReturnCallback(static function (string $key) use (&$deleted): void {
            $deleted[] = $key;
        });
        return $settings;
    }

    public function testCreatesMissingRowsInDeclarationOrder(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([['function_id' => 'exchange.contentTags']]);
        $inserted = [];
        $db->method('insertRow')->willReturnCallback(static function (string $table, array $row) use (&$inserted): int {
            $inserted[] = [$table, $row];
            return count($inserted);
        });

        $result = (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS)))->provision();

        $this->assertSame(['mail.extraction', 'chat.default'], $result['functions']['created']);
        $this->assertSame(1, $result['functions']['existing']);
        $this->assertArrayNotHasKey('skipped_reason', $result['functions']);
        $this->assertCount(2, $inserted);
        $this->assertSame(AiFunctionProvisioner::TABLE, $inserted[0][0]);
        $this->assertSame('mail.extraction', $inserted[0][1]['function_id']);
        $this->assertNull($inserted[0][1]['connection']);
        $this->assertNull($inserted[0][1]['model']);
        $this->assertSame('default', $inserted[0][1]['thinking']);
        $this->assertSame('default', $inserted[0][1]['effort']);
        $this->assertNull($inserted[0][1]['max_tokens']);
        $this->assertNotEmpty($inserted[0][1]['created']);
    }

    public function testIsIdempotentWhenAllRowsExist(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn(array_map(
            static fn (string $id): array => ['function_id' => $id],
            array_keys(self::FUNCTIONS),
        ));
        $db->expects($this->never())->method('insertRow');

        $result = (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS)))->provision();

        $this->assertSame([], $result['functions']['created']);
        $this->assertSame(3, $result['functions']['existing']);
    }

    // --- převod (D41, pravidla 2 a 3) ------------------------------------

    public function testContentTagSettingMovesToTheFunctionRowAndIsDeleted(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getTableColumns')->willReturn([]);
        $db->method('fetchAll')->willReturn([['function_id' => 'exchange.contentTags']]);
        $db->method('fetchRow')->willReturnCallback(static fn (string $sql, mixed ...$args): ?array => match (true) {
            str_contains($sql, 'core_ai_backends') => ['id' => 3, 'backend_id' => 'haiku', 'model' => 'claude-haiku-5-5', 'is_default' => 0],
            str_contains($sql, 'core_ai_function_settings') => ['id' => 4, 'function_id' => 'exchange.contentTags', 'connection' => null, 'model' => null],
            default => null,
        });
        $updates = [];
        $db->method('updateWhere')->willReturnCallback(static function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
            unset($data['modified']);
            $updates[] = [$table, $params[0], $data];
        });

        $provisioner = new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog(), $this->settings('3', $deleted));
        $converted = $provisioner->convertLegacy();

        $this->assertSame([['core_ai_function_settings', 4, ['connection' => 3, 'model' => 'claude-haiku-5-5']]], $updates);
        $this->assertSame([[
            'function_id' => 'exchange.contentTags',
            'changes' => ["connection 'haiku' (from setting exchange.contentTag.backend)", "model claude-haiku-5-5 (from connection 'haiku')"],
        ]], $converted);
        $this->assertSame([AiFunctionProvisioner::LEGACY_SETTING_CONTENT_TAG_CONNECTION], $deleted);
    }

    public function testContentTagSettingPointingAtTheDefaultConnectionWithTierModelIsJustDropped(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getTableColumns')->willReturn([]);
        $db->method('fetchRow')->willReturnCallback(static fn (string $sql, mixed ...$args): ?array => match (true) {
            str_contains($sql, 'core_ai_backends') => ['id' => 1, 'backend_id' => 'default', 'model' => 'claude-sonnet-4-6', 'is_default' => 1],
            str_contains($sql, 'core_ai_function_settings') => ['id' => 4, 'function_id' => 'exchange.contentTags', 'connection' => null, 'model' => null],
            default => null,
        });
        $db->expects($this->never())->method('updateWhere');

        $provisioner = new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog(), $this->settings(1, $deleted));
        $converted = $provisioner->convertLegacy();

        $this->assertSame('exchange.contentTags', $converted[0]['function_id']);
        $this->assertStringContainsString("pointed at the default connection 'default' — dropped", $converted[0]['changes'][0]);
        $this->assertCount(1, $deleted);

        // Bez nastavení / bez SettingsStore se nic neděje.
        $this->assertSame([], (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog(), $this->settings(null)))->convertLegacy());
        $this->assertSame([], (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS)))->convertLegacy());
    }

    public function testDefaultConnectionTuningGoesToEveryFunctionRowStillAtDefault(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getTableColumns')->willReturn(['thinking' => 'char(15)', 'effort' => 'char(10)']);
        $db->method('fetchRow')->willReturnCallback(static fn (string $sql, mixed ...$args): ?array => str_contains($sql, 'is_default')
            ? ['id' => 1, 'backend_id' => 'default', 'thinking' => 'adaptive', 'effort' => 'auto']
            : null);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'function_id' => 'mail.extraction', 'thinking' => 'default', 'effort' => 'default'],
            ['id' => 2, 'function_id' => 'chat.default', 'thinking' => 'auto', 'effort' => 'default'],
        ]);
        $updates = [];
        $db->method('updateWhere')->willReturnCallback(static function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
            unset($data['modified']);
            $updates[] = [$params[0], $data];
        });

        $provisioner = new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog(), $this->settings(null));
        $converted = $provisioner->convertLegacy();

        // `effort` auto na připojení = nic; `thinking` jen do řádku, který má ještě `default`.
        $this->assertSame([[1, ['thinking' => 'adaptive']]], $updates);
        $this->assertSame([['function_id' => 'mail.extraction', 'changes' => ["thinking adaptive (from default connection 'default')"]]], $converted);
    }

    public function testDefaultConnectionTuningIsSkippedWithoutLegacyColumnsOrMarker(): void
    {
        // Nový zdroj dat bez starých sloupců.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getTableColumns')->willReturn(['model' => 'varchar(100)']);
        $db->expects($this->never())->method('fetchRow');
        $this->assertSame([], (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog()))->convertLegacy());

        // Sloupce jsou, ale připojení už značku nemá (druhý běh).
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('getTableColumns')->willReturn(['thinking' => 'char(15)', 'effort' => 'char(10)']);
        $db->method('fetchRow')->willReturn(null);
        $db->expects($this->never())->method('updateWhere');
        $this->assertSame([], (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog()))->convertLegacy());
    }

    public function testRetireModelsRewritesFunctionRows(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([
            ['id' => 1, 'function_id' => 'mail.extraction', 'model' => 'claude-sonnet-4-5-20250929'],
            ['id' => 2, 'function_id' => 'chat.default', 'model' => 'claude-haiku-5-5'],
        ]);
        $updates = [];
        $db->method('updateWhere')->willReturnCallback(static function (string $table, array $data, string $where, mixed ...$params) use (&$updates): void {
            $updates[] = [$table, $params[0], $data['model']];
        });

        $retired = (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS), $this->catalog()))->retireModels();

        $this->assertSame([['core_ai_function_settings', 1, 'claude-sonnet-4-6']], $updates);
        $this->assertSame([['id' => 1, 'function_id' => 'mail.extraction', 'from' => 'claude-sonnet-4-5-20250929', 'to' => 'claude-sonnet-4-6']], $retired);
        $this->assertSame([], (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS)))->retireModels(), 'bez katalogu nic');
    }

    public function testFinishLegacyConversionClearsMarkerAndExistingTuningColumns(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([['id' => 1, 'backend_id' => 'default'], ['id' => 2, 'backend_id' => 'gateway']]);
        $db->method('getTableColumns')->willReturn(['model' => 'varchar(100)', 'thinking' => 'char(15)', 'effort' => 'char(10)', 'max_tokens' => 'int']);
        $db->expects($this->once())->method('updateWhere')->willReturnCallback(
            function (string $table, array $data, string $where, mixed ...$params): void {
                $this->assertSame('core_ai_backends', $table);
                $this->assertSame('model IS NOT NULL', $where);
                unset($data['modified']);
                // `temperature` v DB není → nenastavuje se.
                $this->assertSame(['model' => null, 'max_tokens' => 0, 'thinking' => 'auto', 'effort' => 'auto'], $data);
            },
        );

        $cleared = (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS)))->finishLegacyConversion();
        $this->assertSame(['default', 'gateway'], $cleared);

        // Druhý běh: žádné připojení se značkou → žádný UPDATE.
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturn([]);
        $db->expects($this->never())->method('updateWhere');
        $this->assertSame([], (new AiFunctionProvisioner($db, AiFunctionRegistry::fromArray(self::FUNCTIONS)))->finishLegacyConversion());
    }

    public function testProvisionSkipsConversionWithoutRegistry(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchRow');
        $result = (new AiFunctionProvisioner($db, AiFunctionRegistry::empty(), $this->catalog(), $this->settings('3')))->provision();
        $this->assertSame([], $result['converted']);
        $this->assertSame([], $result['retired']);
    }

    public function testEmptyRegistrySkipsWithReason(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchAll');
        $db->expects($this->never())->method('insertRow');

        $result = (new AiFunctionProvisioner($db, AiFunctionRegistry::empty()))->provision();

        $this->assertSame([], $result['functions']['created']);
        $this->assertStringContainsString('core.ai.functions', $result['functions']['skipped_reason']);
    }
}
