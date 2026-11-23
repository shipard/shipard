<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Schema;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Schema\SchemaFingerprint;

/** Otisk schématu (#166 D65, D66) nezávisí na zápisu, jen na obsahu. */
final class SchemaFingerprintTest extends TestCase
{
    private const SCHEMA = '{"type":"object","properties":{"a":{"type":["string","null"]},"b":{"enum":[1,2,null]}},"required":["a"]}';

    public function testSameSchemaGivesSameFingerprintFromEveryForm(): void
    {
        $expected = SchemaFingerprint::of(self::SCHEMA);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $expected);
        $this->assertSame($expected, SchemaFingerprint::of(json_decode(self::SCHEMA, true)));
        $this->assertSame($expected, SchemaFingerprint::of(json_decode(self::SCHEMA)));
        // Jiné formátování a pořadí klíčů objektů.
        $this->assertSame($expected, SchemaFingerprint::of(
            "{\n  \"required\": [\"a\"],\n  \"properties\": {\"b\": {\"enum\": [1, 2, null]}, \"a\": {\"type\": [\"string\", \"null\"]}},\n  \"type\": \"object\"\n}",
        ));
    }

    public function testContentChangesTheFingerprint(): void
    {
        $expected = SchemaFingerprint::of(self::SCHEMA);

        // Pořadí prvků seznamu je obsah (enum, required, type).
        $this->assertNotSame($expected, SchemaFingerprint::of(str_replace('[1,2,null]', '[2,1,null]', self::SCHEMA)));
        $this->assertNotSame($expected, SchemaFingerprint::of(str_replace('"required":["a"]', '"required":["b"]', self::SCHEMA)));
        $this->assertNotSame($expected, SchemaFingerprint::of(str_replace('"a":{', '"c":{', self::SCHEMA)));
    }

    public function testNonObjectHasNoFingerprint(): void
    {
        $this->assertNull(SchemaFingerprint::of(null));
        $this->assertNull(SchemaFingerprint::of(''));
        $this->assertNull(SchemaFingerprint::of('not json'));
        $this->assertNull(SchemaFingerprint::of('[1, 2]'));
        $this->assertNull(SchemaFingerprint::of('"text"'));
    }
}
