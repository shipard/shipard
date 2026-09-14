<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\OpenItemLookupLoader;
use Shipard\Core\Accounting\AbstractOpenItemLookup;
use Shipard\Core\Accounting\NullOpenItemLookup;
use Shipard\Core\Accounting\OpenItem;
use Shipard\Core\Module\ModuleDefinition;

/**
 * OpenItemLookupLoader::fromModules — jeden poskytovatel per DS: bez
 * registrace Null objekt, dvě registrace chyba, třída musí implementovat
 * rozhraní, služby se injektují přes AbstractOpenItemLookup.
 */
class OpenItemLookupLoaderTest extends TestCase
{
    public function testNoRegistrationYieldsNullLookup(): void
    {
        $lookup = OpenItemLookupLoader::fromModules([
            $this->module('core.system'),
            $this->module('economy.bank'),
        ]);

        $this->assertInstanceOf(NullOpenItemLookup::class, $lookup);
        $this->assertNull($lookup->findOpenRequest(1, '123', '', 'czk', 1, 1));
    }

    public function testRegisteredClassIsInstantiatedWithServices(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);

        $lookup = OpenItemLookupLoader::fromModules([
            $this->module('core.system'),
            $this->module('economy.accbal', FakeOpenItemLookup::class),
        ], $db);

        $this->assertInstanceOf(FakeOpenItemLookup::class, $lookup);
        $this->assertSame($db, $lookup->exposeDb());
    }

    public function testTwoRegistrationsThrow(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('only one provider per data source');
        OpenItemLookupLoader::fromModules([
            $this->module('economy.accbal', FakeOpenItemLookup::class),
            $this->module('economy.other', FakeOpenItemLookup::class),
        ]);
    }

    public function testUnknownClassThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not found');
        OpenItemLookupLoader::fromModules([
            $this->module('economy.accbal', 'Shipard\\Nope\\MissingLookup'),
        ]);
    }

    public function testClassWithoutInterfaceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement OpenItemLookup');
        OpenItemLookupLoader::fromModules([
            $this->module('economy.accbal', \stdClass::class),
        ]);
    }

    private function module(string $id, ?string $openItemLookup = null): ModuleDefinition
    {
        $data = ['id' => $id, 'name' => $id];
        if ($openItemLookup !== null) {
            $data['openItemLookup'] = $openItemLookup;
        }
        return ModuleDefinition::fromArray($data);
    }
}

class FakeOpenItemLookup extends AbstractOpenItemLookup
{
    public function findOpenRequest(
        int $partner,
        string $paymentReference,
        string $specificSymbol,
        string $currency,
        int $direction,
        ?int $fiscalYear,
        ?string $excludeSourceKind = null,
        ?int $excludeSourceId = null,
    ): ?OpenItem {
        return null;
    }

    public function exposeDb(): ?\Dibi\Connection
    {
        return $this->db;
    }
}
