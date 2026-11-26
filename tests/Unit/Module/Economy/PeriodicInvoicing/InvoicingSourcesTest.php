<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\PeriodicInvoicing\Source\AbstractPeriodicInvoicingSource;
use Shipard\Module\Economy\PeriodicInvoicing\Source\InvoicingSources;

/** Registr zdrojů periodické fakturace (#175 D3). */
class InvoicingSourcesTest extends TestCase
{
    private function config(mixed $declared): ConfigRuntime
    {
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === InvoicingSources::CFG_ITEM ? $declared : null,
        );
        return $config;
    }

    public function testBuildsSourcesFromCfgItemAndInjectsDependencies(): void
    {
        $config = $this->config(['registry' => ['class' => RegistryTestSource::class]]);
        $db = $this->createMock(Connection::class);

        $sources = InvoicingSources::fromConfig($config, $db);

        $this->assertSame(['registry'], $sources->ids());
        $source = $sources->get('registry');
        $this->assertInstanceOf(RegistryTestSource::class, $source);
        $this->assertSame($db, $source->dbOf());
        $this->assertSame($config, $source->configOf());
        $this->assertSame($source, $sources->forTable('test_registry_heads'));
        $this->assertNull($sources->forTable('economy_work_orders_heads'));
        $this->assertNull($sources->get('other'));
        $this->assertSame([$source], $sources->all());
    }

    public function testMissingCfgItemGivesEmptyRegistry(): void
    {
        $this->assertTrue(InvoicingSources::fromConfig(null)->isEmpty());
        $this->assertTrue(InvoicingSources::fromConfig($this->config(null))->isEmpty());
        $this->assertSame([], InvoicingSources::empty()->all());
    }

    public function testBrokenRegistrationIsAConfigurationError(): void
    {
        foreach ([
            ['x' => ['class' => 'Nope\\Missing']],
            ['x' => ['class' => \stdClass::class]],
            // Id z registrace a id, které hlásí třída, se musí shodovat.
            ['other' => ['class' => RegistryTestSource::class]],
        ] as $declared) {
            try {
                InvoicingSources::fromConfig($this->config($declared));
                $this->fail('LogicException expected');
            } catch (\LogicException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSameIdTwiceIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        new InvoicingSources([new FakeInvoicingSource(), new FakeInvoicingSource()]);
    }
}

class RegistryTestSource extends AbstractPeriodicInvoicingSource
{
    public function dbOf(): ?Connection
    {
        return $this->db;
    }

    public function configOf(): ?ConfigRuntime
    {
        return $this->config;
    }

    public function id(): string
    {
        return 'registry';
    }

    public function table(): string
    {
        return 'test_registry_heads';
    }

    public function tableId(): int
    {
        return 9001;
    }

    public function periodsTable(): string
    {
        return 'test_registry_periods';
    }

    public function periodsRecordColumn(): string
    {
        return 'record';
    }

    public function viewerId(): string
    {
        return 'test.registry.heads';
    }

    public function candidates(?int $recordId): array
    {
        return [];
    }

    public function record(int $id): ?array
    {
        return null;
    }

    public function recordIdByNumber(string $number): ?int
    {
        return null;
    }

    public function kind(array $record): ?array
    {
        return null;
    }

    public function rows(array $record): array
    {
        return [];
    }

    public function dimensions(array $record): array
    {
        return [];
    }

    public function recordLabel(array $record): string
    {
        return '';
    }

    public function recordNoun(): string
    {
        return 'Record';
    }

    public function openActionLabel(): string
    {
        return 'Open record';
    }
}
