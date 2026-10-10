<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Ai;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Core\Ai\AiFunctionProvisioner;

/**
 * AiFunctionProvisioner (tasks/ai-functions.md D38): řádek na každou
 * deklarovanou funkci, existující beze změny, bez registru nic.
 */
final class AiFunctionProvisionerTest extends TestCase
{
    private const FUNCTIONS = [
        'mail.extraction' => ['name' => 'Vytěžení', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 32768]],
        'exchange.contentTags' => ['name' => 'Štítky', 'tier' => 'fast', 'defaults' => ['maxTokens' => 8000]],
        'chat.default' => ['name' => 'Chat', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
    ];

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
