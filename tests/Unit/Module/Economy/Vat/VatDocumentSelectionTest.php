<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Vat;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Module\Economy\Vat\VatDocumentSelection;

/**
 * Pojistka výběru dokladů pro DPH výstupy (#79 D1): nedaňové typy z configu
 * (`docTypes[].tax_document: false`) jdou do `NOT IN`, bez configu se nic
 * nevyřazuje.
 */
final class VatDocumentSelectionTest extends TestCase
{
    /** @param array<int, mixed> $captured */
    private function capturingDb(array &$captured): DataSourceConnection
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args) use (&$captured): array {
                $captured = $args;
                return [];
            },
        );
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    public function testExcludesNonTaxTypesFromConfig(): void
    {
        $captured = [];
        $docs = (new VatDocumentSelection($this->capturingDb($captured), $this->config()))
            ->load(5, 'vat_period');

        $this->assertSame([], $docs);
        $sql = (string) ($captured[0] ?? '');
        $this->assertStringContainsString('[h].[doc_type] NOT IN %in', $sql);
        $this->assertStringContainsString('WHERE %n = %i AND [h].[docState] = %i', $sql);
        $this->assertSame('h.vat_period', $captured[1]);
        $this->assertSame(5, $captured[2]);
        $this->assertSame(40, $captured[3]);
        $this->assertSame(['invpo'], $captured[4], 'seznam nedaňových typů z configu, ne natvrdo');
    }

    public function testWithoutConfigNothingIsExcluded(): void
    {
        $captured = [];
        (new VatDocumentSelection($this->capturingDb($captured)))->load(5, 'cs_period');

        $sql = (string) ($captured[0] ?? '');
        $this->assertStringNotContainsString('NOT IN', $sql);
        $this->assertCount(4, $captured);
        $this->assertSame('h.cs_period', $captured[1]);
    }

    public function testRejectsUnknownPeriodColumn(): void
    {
        $captured = [];
        $this->expectException(\InvalidArgumentException::class);
        (new VatDocumentSelection($this->capturingDb($captured)))->load(5, 'docState');
    }
}
