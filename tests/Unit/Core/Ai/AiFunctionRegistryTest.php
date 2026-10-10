<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Ai\AiFunction;
use Shipard\Core\Ai\AiFunctionRegistry;
use Shipard\Core\Config\ConfigRuntime;

/**
 * Registr AI funkcí nad cfgItem `core.ai.functions` (tasks/ai-functions.md
 * D36): čtení z compiled configu, pořadí deklarací, požadavky, výchozí
 * ladění; nevalidní položka (neznámá úroveň, neznámý požadavek, chybějící
 * strop) je chyba; bez configu prázdný registr.
 */
final class AiFunctionRegistryTest extends TestCase
{
    private const ITEMS = [
        'mail.extraction' => [
            'name' => 'Vytěžení došlé pošty', 'description' => 'Popis', 'tier' => 'balanced',
            'defaults' => ['maxTokens' => 32768, 'temperature' => 0.0], 'requires' => ['pdf'],
        ],
        'exchange.contentTags' => ['name' => 'Štítky', 'tier' => 'fast', 'defaults' => ['maxTokens' => 8000], 'requires' => []],
        'chat.default' => ['name' => 'Chat', 'tier' => 'balanced', 'defaults' => ['maxTokens' => 4096], 'requires' => ['tools']],
    ];

    public function testReadsFunctionsInDeclarationOrder(): void
    {
        $registry = AiFunctionRegistry::fromArray(self::ITEMS);

        $this->assertFalse($registry->isEmpty());
        $this->assertSame(['mail.extraction', 'exchange.contentTags', 'chat.default'], $registry->ids());
        $this->assertSame(['mail.extraction', 'exchange.contentTags', 'chat.default'], array_map(
            static fn (AiFunction $f): string => $f->id,
            $registry->all(),
        ));

        $extraction = $registry->get('mail.extraction');
        $this->assertNotNull($extraction);
        $this->assertSame('Vytěžení došlé pošty', $extraction->name);
        $this->assertSame('Popis', $extraction->description);
        $this->assertSame('balanced', $extraction->tier);
        $this->assertSame(32768, $extraction->maxTokens);
        $this->assertSame(0.0, $extraction->temperature);
        $this->assertTrue($extraction->requiresPdf());
        $this->assertFalse($extraction->requiresTools());

        $tags = $registry->get('exchange.contentTags');
        $this->assertNull($tags?->temperature, 'bez deklarované teploty se neposílá');
        $this->assertSame('', $tags->description);
        $this->assertFalse($tags->requiresPdf());

        $this->assertTrue($registry->get('chat.default')?->requiresTools());
        $this->assertTrue($registry->has('chat.default'));
        $this->assertFalse($registry->has('chat.deep'));
        $this->assertNull($registry->get('chat.deep'));
    }

    public function testFromConfigReadsTheCfgItemAndToleratesMissing(): void
    {
        $this->assertTrue(AiFunctionRegistry::fromConfig(null)->isEmpty());

        $cfg = $this->createMock(ConfigRuntime::class);
        $cfg->method('cfgItem')->willReturnCallback(
            fn (string $id) => $id === AiFunctionRegistry::CFG_ITEM ? self::ITEMS : null,
        );
        $this->assertSame(3, count(AiFunctionRegistry::fromConfig($cfg)->all()));

        $without = $this->createMock(ConfigRuntime::class);
        $without->method('cfgItem')->willReturn(null);
        $this->assertTrue(AiFunctionRegistry::fromConfig($without)->isEmpty());
        $this->assertSame([], AiFunctionRegistry::empty()->all());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidFunctions(): iterable
    {
        $base = ['name' => 'X', 'tier' => 'fast', 'defaults' => ['maxTokens' => 100]];
        yield 'unknown tier' => [['tier' => 'huge'] + $base];
        yield 'missing tier' => [['name' => 'X', 'defaults' => ['maxTokens' => 100]]];
        yield 'missing name' => [['tier' => 'fast', 'defaults' => ['maxTokens' => 100]]];
        yield 'missing maxTokens' => [['name' => 'X', 'tier' => 'fast']];
        yield 'zero maxTokens' => [['defaults' => ['maxTokens' => 0]] + $base];
        yield 'unknown requirement' => [['requires' => ['vision']] + $base];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidFunctions')]
    public function testInvalidFunctionIsRejected(array $data): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AiFunctionRegistry::fromArray(['mail.extraction' => $data]);
    }

    public function testInvalidIdOrEntryShapeIsRejected(): void
    {
        try {
            AiFunctionRegistry::fromArray(['extraction' => ['name' => 'X', 'tier' => 'fast', 'defaults' => ['maxTokens' => 1]]]);
            $this->fail('id bez oblasti musí být chyba');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("invalid function id 'extraction'", $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        AiFunctionRegistry::fromArray(['mail.extraction' => 'not an object']);
    }
}
