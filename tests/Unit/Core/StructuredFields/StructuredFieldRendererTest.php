<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\StructuredFields;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\StructuredFields\StructuredFieldRenderer;
use Shipard\Core\StructuredFields\StructuredSchema;

class StructuredFieldRendererTest extends TestCase
{
    private function schema(): StructuredSchema
    {
        return StructuredSchema::fromArray('test.profile', [
            'version' => '2026',
            'groups'  => [
                ['id' => 'office', 'name' => 'Finanční úřad'],
                ['id' => 'contact', 'name' => 'Kontakt'],
            ],
            'fields' => [
                [
                    'id' => 'c_ufo', 'type' => 'enumString', 'length' => 5,
                    'cfgItem' => 'test.taxOffices', 'group' => 'office', 'name' => 'Finanční úřad',
                ],
                ['id' => 'email', 'type' => 'varchar', 'length' => 255, 'group' => 'contact', 'name' => 'E-mail'],
                ['id' => 'born', 'type' => 'date', 'group' => 'contact', 'name' => 'Narození'],
                ['id' => 'ratio', 'type' => 'numeric', 'precision' => 5, 'scale' => 2,
                    'group' => 'contact', 'name' => 'Podíl'],
                ['id' => 'signed', 'type' => 'boolean', 'group' => 'contact', 'name' => 'Podepsáno'],
            ],
        ]);
    }

    private function renderer(): StructuredFieldRenderer
    {
        $items = [
            'test.taxOffices'          => ['464' => ['name' => 'Zlínský kraj']],
            'core.system.formDefaults' => [
                'booleanYes'      => ['name' => 'Ano'],
                'booleanNo'       => ['name' => 'Ne'],
                'generalTabLabel' => ['name' => 'Obecné'],
            ],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(fn(string $id) => $items[$id] ?? null);
        return new StructuredFieldRenderer($config);
    }

    public function testGroupsCarryLabelsAndFormattedValues(): void
    {
        $groups = $this->renderer()->properties($this->schema(), json_encode([
            '_schema' => 'test.profile/2026',
            'c_ufo'   => '464',
            'email'   => 'a@b.cz',
            'born'    => '1980-02-29',
            'ratio'   => '12.50',
            'signed'  => true,
        ]));

        $this->assertSame([
            [
                'title' => 'Finanční úřad',
                'items' => [['label' => 'Finanční úřad', 'value' => 'Zlínský kraj']],
            ],
            [
                'title' => 'Kontakt',
                'items' => [
                    ['label' => 'E-mail', 'value' => 'a@b.cz'],
                    ['label' => 'Narození', 'value' => '1980-02-29'],
                    ['label' => 'Podíl', 'value' => '12,50'],
                    ['label' => 'Podepsáno', 'value' => 'Ano'],
                ],
            ],
        ], $groups);
    }

    public function testEmptyItemsAndEmptyGroupsAreOmitted(): void
    {
        $groups = $this->renderer()->properties($this->schema(), [
            '_schema' => 'test.profile/2026',
            'email'   => 'a@b.cz',
            'born'    => null,
            'ratio'   => '',
        ]);

        $this->assertSame([
            ['title' => 'Kontakt', 'items' => [['label' => 'E-mail', 'value' => 'a@b.cz']]],
        ], $groups);
    }

    public function testUnknownKeyIsShownUnderItsRawKey(): void
    {
        $groups = $this->renderer()->properties($this->schema(), [
            '_schema' => 'test.profile/2019',
            'email'   => 'a@b.cz',
            'zruseno' => 'stará hodnota',
        ]);

        $this->assertSame([
            ['title' => 'Kontakt', 'items' => [['label' => 'E-mail', 'value' => 'a@b.cz']]],
            ['title' => 'Obecné', 'items' => [['label' => 'zruseno', 'value' => 'stará hodnota']]],
        ], $groups);
    }

    public function testUngroupedFieldsAndUnknownKeysShareOneBlock(): void
    {
        $schema = StructuredSchema::fromArray('test.flat', [
            'version' => '1',
            'fields'  => [['id' => 'note', 'type' => 'text', 'name' => 'Poznámka']],
        ]);

        $groups = $this->renderer()->properties($schema, ['note' => 'x', 'zruseno' => 'y']);

        $this->assertSame([
            ['title' => 'Obecné', 'items' => [
                ['label' => 'Poznámka', 'value' => 'x'],
                ['label' => 'zruseno', 'value' => 'y'],
            ]],
        ], $groups);
    }

    public function testUnknownEnumValueFallsBackToRawValue(): void
    {
        $groups = $this->renderer()->properties($this->schema(), ['c_ufo' => '999']);

        $this->assertSame('999', $groups[0]['items'][0]['value']);
    }

    public function testEmptyAndNullValuesGiveNoGroups(): void
    {
        $this->assertSame([], $this->renderer()->properties($this->schema(), null));
        $this->assertSame([], $this->renderer()->properties($this->schema(), ''));
        $this->assertSame([], $this->renderer()->properties($this->schema(), '{"_schema":"test.profile/2026"}'));
    }

    public function testWithoutConfigBooleanAndEnumDegradeGracefully(): void
    {
        $renderer = new StructuredFieldRenderer(null);

        $groups = $renderer->properties($this->schema(), ['c_ufo' => '464', 'signed' => false]);

        $this->assertSame('464', $groups[0]['items'][0]['value']);
        $this->assertSame('No', $groups[1]['items'][0]['value']);
    }
}
