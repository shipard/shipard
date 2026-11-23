<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Database;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ColumnDefinition;

class ColumnDefinitionTest extends TestCase
{
    public function testReferenceLoaded(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'contact_id',
            'name' => 'Contact',
            'type' => 'int',
            'reference' => 'base_persons_contacts',
        ]);

        $this->assertSame('base_persons_contacts', $col->reference);
    }

    public function testReferenceDefaultsToNull(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'contact_id',
            'name' => 'Contact',
            'type' => 'int',
        ]);

        $this->assertNull($col->reference);
    }

    public function testEncryptedTextTypeIsAccepted(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
            'nullable' => true,
            'group' => 'credentials',
        ]);

        $this->assertSame('encrypted_text', $col->type);
        $this->assertTrue($col->nullable);
    }

    public function testEncryptedTextIsSensitiveWithoutFlag(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testEncryptedTextIsSensitiveWithExplicitFlag(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
            'sensitive' => true,
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testEncryptedTextOverridesExplicitSensitiveFalse(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'api_key',
            'name' => 'API key',
            'type' => 'encrypted_text',
            'sensitive' => false,
        ]);

        $this->assertTrue($col->sensitive);
    }

    public function testNonEncryptedTypeIsNotSensitiveByDefault(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'note',
            'name' => 'Note',
            'type' => 'text',
        ]);

        $this->assertFalse($col->sensitive);
    }

    public function testStructuredSchemaOnJsonColumn(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'filing_profile',
            'name' => 'Filing profile',
            'type' => 'json',
            'nullable' => true,
            'schema' => 'economy.vat.filingProfileCz',
        ]);

        $this->assertSame('economy.vat.filingProfileCz', $col->schema);
    }

    public function testSchemaDefaultsToNull(): void
    {
        $col = ColumnDefinition::fromArray([
            'id' => 'snapshot',
            'name' => 'Snapshot',
            'type' => 'json',
        ]);

        $this->assertNull($col->schema);
    }

    public function testCollationOnTextColumns(): void
    {
        foreach ([['varchar', ['length' => 60]], ['text', []], ['mediumtext', []], ['longtext', []]] as [$type, $extra]) {
            $col = ColumnDefinition::fromArray(['id' => 'c', 'name' => 'C', 'type' => $type, 'collation' => 'utf8mb4_bin'] + $extra);
            $this->assertSame('utf8mb4_bin', $col->collation, $type);
        }
        $this->assertNull(ColumnDefinition::fromArray(['id' => 'c', 'name' => 'C', 'type' => 'text'])->collation);
    }

    public function testCollationOnNonTextColumnThrows(): void
    {
        foreach ([
            ['type' => 'int'],
            ['type' => 'json'],
            ['type' => 'encrypted_text'],
            // enumString je vždy ascii — collation by s ní byla v rozporu.
            ['type' => 'enumString', 'length' => 10, 'cfgItem' => 'x.y'],
        ] as $definition) {
            try {
                ColumnDefinition::fromArray(['id' => 'c', 'name' => 'C', 'collation' => 'utf8mb4_bin'] + $definition);
                $this->fail("Typ {$definition['type']} collation přijal.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("attribute 'collation' is allowed only for types", $e->getMessage());
            }
        }
    }

    public function testCollationMustBeACollationName(): void
    {
        // Hodnota jde do DDL beze změny: jen název collation, ne znaková
        // sada sama ani nic, co by SQL rozšířilo.
        foreach (['utf8mb4', 'ascii', '', 'utf8mb4_bin; DROP TABLE x', 'UTF8MB4_BIN', 'utf8mb4 bin', 42] as $value) {
            try {
                ColumnDefinition::fromArray(['id' => 'c', 'name' => 'C', 'type' => 'varchar', 'length' => 10, 'collation' => $value]);
                $this->fail('Hodnota ' . json_encode($value) . ' prošla.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("attribute 'collation' must be a collation name", $e->getMessage());
            }
        }
    }

    public function testSchemaOnNonJsonColumnThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("attribute 'schema' is allowed only for type 'json'");

        ColumnDefinition::fromArray([
            'id' => 'note',
            'name' => 'Note',
            'type' => 'text',
            'schema' => 'economy.vat.filingProfileCz',
        ]);
    }
}
