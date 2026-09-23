<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;
use Shipard\Api\JournalContributorLoader;
use Shipard\Core\Accounting\AbstractJournalContributor;
use Shipard\Core\Accounting\JournalContributor;
use Shipard\Core\Accounting\JournalContributorSet;
use Shipard\Core\Accounting\JournalLineRequest;
use Shipard\Core\Accounting\JournalSourceContext;
use Shipard\Core\Module\ModuleDefinition;

/**
 * JournalContributorLoader::fromModules (#79 D3b) — sada v pořadí modulů
 * × pořadí pole, prázdná bez registrace, třída musí existovat a
 * implementovat rozhraní, služby se injektují přes AbstractJournalContributor.
 */
class JournalContributorLoaderTest extends TestCase
{
    public function testNoRegistrationYieldsEmptySet(): void
    {
        $set = JournalContributorLoader::fromModules([
            $this->module('core.system'),
            $this->module('economy.bank'),
        ]);

        $this->assertInstanceOf(JournalContributorSet::class, $set);
        $this->assertTrue($set->isEmpty());
        $this->assertCount(0, $set);
    }

    public function testContributorsKeepModuleAndListOrder(): void
    {
        $set = JournalContributorLoader::fromModules([
            $this->module('economy.accbal', [FakeJournalContributorA::class, FakeJournalContributorB::class]),
            $this->module('economy.other', [FakeJournalContributorC::class]),
        ]);

        $this->assertCount(3, $set);
        $this->assertSame(
            [FakeJournalContributorA::class, FakeJournalContributorB::class, FakeJournalContributorC::class],
            array_map(static fn(JournalContributor $c): string => $c::class, iterator_to_array($set)),
        );
    }

    public function testServicesAreInjectedIntoAbstractContributor(): void
    {
        $db = $this->createMock(\Dibi\Connection::class);

        $set = JournalContributorLoader::fromModules([
            $this->module('economy.accbal', [FakeJournalContributorA::class]),
        ], $db);

        $contributor = iterator_to_array($set)[0];
        $this->assertInstanceOf(FakeJournalContributorA::class, $contributor);
        $this->assertSame($db, $contributor->exposeDb());
    }

    public function testPlainInterfaceImplementationIsAccepted(): void
    {
        $set = JournalContributorLoader::fromModules([
            $this->module('economy.accbal', [FakeJournalContributorC::class]),
        ]);

        $this->assertCount(1, $set);
    }

    public function testUnknownClassThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not found');
        JournalContributorLoader::fromModules([
            $this->module('economy.accbal', ['Shipard\\Nope\\MissingContributor']),
        ]);
    }

    public function testClassWithoutInterfaceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not implement JournalContributor');
        JournalContributorLoader::fromModules([
            $this->module('economy.accbal', [\stdClass::class]),
        ]);
    }

    public function testSetRejectsForeignObjects(): void
    {
        $this->expectException(\LogicException::class);
        new JournalContributorSet([new \stdClass()]);
    }

    /**
     * @param list<string> $contributors
     */
    private function module(string $id, array $contributors = []): ModuleDefinition
    {
        $data = ['id' => $id, 'name' => $id];
        if ($contributors !== []) {
            $data['journalContributors'] = $contributors;
        }
        return ModuleDefinition::fromArray($data);
    }
}

class FakeJournalContributorA extends AbstractJournalContributor
{
    public function contribute(JournalSourceContext $context, array $lines): array
    {
        return [];
    }

    public function exposeDb(): ?\Dibi\Connection
    {
        return $this->db;
    }
}

class FakeJournalContributorB extends AbstractJournalContributor
{
    public function contribute(JournalSourceContext $context, array $lines): array
    {
        return [];
    }
}

/** Implementace bez báze — loader ji přijme, jen neinjektuje služby. */
class FakeJournalContributorC implements JournalContributor
{
    /** @return list<JournalLineRequest> */
    public function contribute(JournalSourceContext $context, array $lines): array
    {
        return [];
    }
}
