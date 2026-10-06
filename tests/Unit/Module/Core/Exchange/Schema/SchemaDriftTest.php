<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Schema;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Utils\JsoncParser;

/**
 * The `.jsonc` is the human-edited source for each exchange schema and the
 * `.json` is the compiled (comment-free) version actually loaded by
 * SchemaLoader. Without a CLI compile step they're maintained by hand and
 * drift is the obvious risk — this test parses both and compares.
 *
 * Covers every module that keeps schemas in the `{formatId}.v{n}` pair
 * convention (exchange, mail datasets).
 */
class SchemaDriftTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function schemaProvider(): array
    {
        return [
            'shpd.docs.document.v1'         => ['modules/core/exchange/schemas', 'shpd.docs.document.v1'],
            'shpd.persons.person.v1'        => ['modules/core/exchange/schemas', 'shpd.persons.person.v1'],
            'shpd.items.item.v1'            => ['modules/core/exchange/schemas', 'shpd.items.item.v1'],
            'shpd.bank.statement.v1'        => ['modules/core/exchange/schemas', 'shpd.bank.statement.v1'],
            'shpd.system.user.v1'           => ['modules/core/exchange/schemas', 'shpd.system.user.v1'],
            'shpd.assets.asset.v1'          => ['modules/core/exchange/schemas', 'shpd.assets.asset.v1'],
            'shpd.mail.incomingMessage.v1'  => ['modules/core/mail/schemas', 'shpd.mail.incomingMessage.v1'],
            'shpd.dataset.setup.v1'         => ['modules/core/exchange/schemas', 'shpd.dataset.setup.v1'],
            'shpd.dataset.registryDocument.v1' => ['modules/base/registry/schemas', 'shpd.dataset.registryDocument.v1'],
        ];
    }

    #[DataProvider('schemaProvider')]
    public function testJsoncAndJsonAreEquivalent(string $dir, string $name): void
    {
        $base = dirname(__DIR__, 6) . '/' . $dir;
        $jsoncPath = "{$base}/{$name}.jsonc";
        $jsonPath = "{$base}/{$name}.json";

        $this->assertFileExists($jsoncPath);
        $this->assertFileExists($jsonPath);

        $fromJsonc = JsoncParser::parse((string) file_get_contents($jsoncPath));
        $fromJson = json_decode((string) file_get_contents($jsonPath), true);

        $this->assertSame(
            $fromJsonc,
            $fromJson,
            "Schema drift between {$name}.jsonc and {$name}.json — recompile the .json from the .jsonc source.",
        );
    }
}
