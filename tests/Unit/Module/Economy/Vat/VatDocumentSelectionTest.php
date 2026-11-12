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
            'invni' => ['trade_dir' => 2],
            'invpi' => ['trade_dir' => 2, 'tax_document' => false],
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
        $this->assertSame(['invpo', 'invpi'], $captured[4], 'seznam nedaňových typů z configu, ne natvrdo');
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


    /** Řádek rekapitulace nese předmět plnění jako řetězec (#89 D13); prázdný = null. */
    public function testRecapRowsCarryReverseChargeSubject(): void
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchAll')->willReturnCallback(
            static function (mixed ...$args): array {
                if (str_contains((string) $args[0], 'docs_core_vat_recap')) {
                    $line = ['doc_head' => 7, 'vat_pct' => '21.00', 'base_dom' => '100.00', 'tax_dom' => '21.00'];
                    return [
                        $line + ['vat_code' => 'cz-115', 'is_reverse_pair' => 0, 'reverse_charge_code' => '4a'],
                        $line + ['vat_code' => 'cz-203', 'is_reverse_pair' => 1, 'reverse_charge_code' => '4a'],
                        $line + ['vat_code' => 'cz-110', 'is_reverse_pair' => 0, 'reverse_charge_code' => null],
                        $line + ['vat_code' => 'cz-110', 'is_reverse_pair' => 0, 'reverse_charge_code' => ''],
                    ];
                }
                return [[
                    'id' => 7, 'doc_type' => 'invni', 'doc_number' => 'FP-1', 'partner_doc_number' => 'D-1',
                    'total_amount_dom' => '100.00', 'vat_duzp' => '2026-04-10', 'vat_dppd' => '2026-04-12',
                    'cs_mode' => 0, 'customer_snapshot' => null, 'supplier_snapshot' => null,
                ]];
            },
        );

        $docs = (new VatDocumentSelection($db))->load(5, 'cs_period');

        $this->assertSame(
            ['4a', '4a', null, null],
            array_column($docs[0]['recap'], 'reverse_charge_code'),
        );
    }
}
