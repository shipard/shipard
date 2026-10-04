<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Mail\RecordSenderProvider;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\Core\NumberSeriesSenderProvider;

class NumberSeriesSenderProviderTest extends TestCase
{
    /** @param ?array<string, mixed> $series Řádek číselné řady. */
    private function db(?array $series): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturn($series);
        return $db;
    }

    public function testSeriesAddressAndNameBecomeRecordSender(): void
    {
        $sender = (new NumberSeriesSenderProvider())->recordSender(
            ['id' => 5, 'number_series' => 3],
            $this->db(['email_from' => ' fakturace@firma.example ', 'email_from_name' => 'Fakturace']),
        );

        $this->assertSame('fakturace@firma.example', $sender->email);
        $this->assertSame('Fakturace', $sender->name);
    }

    public function testAutomaticSeriesGivesNoAddress(): void
    {
        $sender = (new NumberSeriesSenderProvider())->recordSender(
            ['id' => 5, 'number_series' => 3],
            $this->db(['email_from' => null, 'email_from_name' => '']),
        );

        $this->assertNull($sender->email);
        $this->assertNull($sender->name);
    }

    public function testDocumentWithoutSeriesHasNoRecordSender(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->expects($this->never())->method('fetchRow');

        $this->assertNull((new NumberSeriesSenderProvider())->recordSender(['id' => 5, 'number_series' => null], $db));
        $this->assertNull((new NumberSeriesSenderProvider())->recordSender(['id' => 5, 'number_series' => 3], $this->db(null)));
    }

    public function testDocsCoreRegistersProviderForDocumentHeads(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(
            __DIR__ . '/../../../../../modules/docs/core/module.jsonc',
        ));

        $this->assertSame(
            [['table' => 'docs_core_heads', 'class' => NumberSeriesSenderProvider::class]],
            $module->recordSenderProviders,
        );
        $this->assertTrue(is_subclass_of(NumberSeriesSenderProvider::class, RecordSenderProvider::class));
    }
}
