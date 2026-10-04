<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons\Send;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigCompiler;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Persons\Send\SendPurposes;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

class SendPurposesTest extends TestCase
{
    public function testDecodeAcceptsJsonTextListAndEmpty(): void
    {
        $this->assertSame(['invoices', 'reminders'], SendPurposes::decode('["invoices","reminders"]'));
        $this->assertSame(['invoices'], SendPurposes::decode(['invoices']));
        $this->assertSame([], SendPurposes::decode(null));
        $this->assertSame([], SendPurposes::decode(''));
    }

    public function testDecodeRejectsAnythingButListOfIds(): void
    {
        $this->assertNull(SendPurposes::decode('{"a":"invoices"}'));
        $this->assertNull(SendPurposes::decode('not json'));
        $this->assertNull(SendPurposes::decode([1, 2]));
        $this->assertNull(SendPurposes::decode(['invoices', '']));
    }

    public function testEncodeStoresNullForEmptySelection(): void
    {
        $this->assertNull(SendPurposes::encode([]));
        $this->assertSame('["invoices","reports"]', SendPurposes::encode(['invoices', 'reports', 'invoices']));
    }

    public function testLabelsFollowConfigOrderAndKeepUnknownIds(): void
    {
        $config = ConfigRuntimeFactory::fromItems([
            ConfigCompiler::SEND_PURPOSES_ITEM => [
                'invoices'  => ['name' => 'Faktury a daňové doklady', 'order' => 10],
                'reminders' => ['name' => 'Upomínky', 'order' => 20],
            ],
        ]);

        $this->assertSame(
            ['Faktury a daňové doklady', 'Upomínky', 'zrusenyUcel'],
            SendPurposes::labels(['zrusenyUcel', 'reminders', 'invoices'], $config),
        );
        $this->assertSame(['invoices'], SendPurposes::labels(['invoices'], null));
    }

    public function testBasePersonsDeclaresInitialPurposesInEveryLanguage(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(
            __DIR__ . '/../../../../../../modules/base/persons/module.jsonc',
        ));

        $this->assertSame(
            ['invoices', 'reminders', 'offersOrders', 'reports'],
            array_column($module->sendPurposes, 'id'),
        );
        // Účel se ukazuje v rozhraní (cs, en) i v konfiguraci jazyků dokumentů.
        foreach ($module->sendPurposes as $purpose) {
            foreach (['name', 'name:cs', 'name:en', 'name:sk', 'name:de'] as $key) {
                $this->assertNotSame('', $purpose[$key] ?? '', "{$purpose['id']}: {$key}");
            }
        }
    }
}
