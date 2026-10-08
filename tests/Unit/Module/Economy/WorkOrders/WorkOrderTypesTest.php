<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Příznaky typů zakázek nad skutečným cfgItem (docs/work-orders.md D14,
 * §5.1): externí typy mají stranu, jednorázové smí mít nadřazenou zakázku,
 * fakturuje jen periodická a ta nemůže být nadřazenou (P4).
 */
class WorkOrderTypesTest extends TestCase
{
    private const CONFIG = __DIR__ . '/../../../../../modules/economy/workOrders/config/types.jsonc';

    private function types(?array $cfg = null): WorkOrderTypes
    {
        $cfg ??= JsoncParser::parseFile(self::CONFIG);
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnMap([[WorkOrderTypes::CFG_ITEM, $cfg]]);
        return new WorkOrderTypes($config);
    }

    public function testFlagsOfTheFourTypes(): void
    {
        $types = $this->types();

        $this->assertSame(['periodic', 'project', 'overhead', 'internal'], array_keys($types->all()));

        $this->assertTrue($types->isExternal('periodic'));
        $this->assertFalse($types->isOneOff('periodic'));
        $this->assertSame(WorkOrderTypes::INVOICING_PERIODIC, $types->invoicing('periodic'));
        $this->assertFalse($types->canBeParent('periodic'), 'P4: pod nájemní smlouvou se podzakázky nesčítají');

        $this->assertTrue($types->isExternal('project'));
        $this->assertTrue($types->isOneOff('project'));
        $this->assertNull($types->invoicing('project'));
        $this->assertTrue($types->canBeParent('project'));

        $this->assertFalse($types->isExternal('overhead'));
        $this->assertFalse($types->isOneOff('overhead'));
        $this->assertTrue($types->canBeParent('overhead'));

        $this->assertFalse($types->isExternal('internal'));
        $this->assertTrue($types->isOneOff('internal'));
        $this->assertTrue($types->canBeParent('internal'));
    }

    public function testLabelsAndOptionsComeFromConfig(): void
    {
        $types = $this->types(['project' => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true]]);

        $this->assertSame('Externí jednorázová', $types->label('project'));
        $this->assertSame('other', $types->label('other'));
        $this->assertSame([['value' => 'project', 'label' => 'Externí jednorázová']], $types->options());
        $this->assertFalse($types->isUnknown('project'));
        $this->assertTrue($types->isUnknown('other'));
    }

    public function testWithoutConfigNothingIsUnknownAndFlagsAreOff(): void
    {
        $types = new WorkOrderTypes(null);

        $this->assertSame([], $types->all());
        $this->assertFalse($types->isUnknown('periodic'));
        $this->assertFalse($types->isExternal('periodic'));
        $this->assertFalse($types->isOneOff('project'));
        $this->assertNull($types->invoicing('periodic'));
        $this->assertTrue($types->canBeParent('periodic'));
        $this->assertSame([], $types->options());
    }
}
