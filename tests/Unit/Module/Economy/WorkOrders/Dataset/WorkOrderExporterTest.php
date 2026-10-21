<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\WorkOrders\Dataset\WorkOrderExporter;

/**
 * Sekce workOrders při dumpu (#131 fáze 2b, D30): přirozené klíče místo id,
 * předpis a jen vystavená období, pořadí nadřazené → podřízené → číslo,
 * zakázka řady bez kódu se nepřenese.
 */
class WorkOrderExporterTest extends TestCase
{
    private const HEAD_DEFAULTS = [
        'number_series' => 1, 'kind' => 1, 'type' => 'periodic', 'number' => null, 'sequence_number' => null,
        'fiscal_year' => null, 'title' => '', 'cost_center' => null, 'internal_note' => null,
        'customer' => null, 'currency' => null, 'payment_reference' => null, 'parent' => null,
        'date_start' => null, 'date_end' => null,
        'inv_periodicity' => null, 'inv_from' => null, 'inv_doc_text' => null, 'inv_doc_type' => null,
        'inv_number_series' => null, 'inv_due_days' => null, 'inv_timing' => null, 'inv_vat_mode' => null,
        'inv_payment_method' => null, 'inv_bank_account' => null, 'docState' => 40, 'docStateMain' => 3,
    ];

    /** @return list<array<string, mixed>> */
    private function heads(): array
    {
        return [
            ['id' => 1, 'number' => 'S0001', 'sequence_number' => 1, 'title' => 'Správa IT — Kopretina',
             'customer' => 5, 'currency' => 'czk', 'date_start' => new \DateTimeImmutable('2023-04-01'),
             'inv_periodicity' => 'month', 'inv_from' => '2026-01-01', 'inv_doc_text' => 'Správa IT — paušál {období}',
             'inv_number_series' => 7, 'inv_bank_account' => 4, 'inv_vat_mode' => 1, 'inv_payment_method' => 1] + self::HEAD_DEFAULTS,
            ['id' => 2, 'number_series' => 2, 'type' => 'project', 'number' => 'P0001', 'sequence_number' => 1,
             'title' => 'Výměna sítě', 'customer' => 5, 'currency' => 'czk', 'cost_center' => 3,
             'date_start' => '2026-03-17', 'date_end' => '2026-04-22', 'docState' => 70] + self::HEAD_DEFAULTS,
            ['id' => 4, 'number_series' => 3, 'type' => 'internal', 'number' => 'I0001', 'sequence_number' => 1,
             'title' => 'Interní podzakázka', 'parent' => 2, 'date_start' => '2026-03-20', 'docState' => 80] + self::HEAD_DEFAULTS,
            ['id' => 9, 'number_series' => 7, 'type' => 'periodic', 'number' => 'X0001', 'title' => 'Řada bez kódu'] + self::HEAD_DEFAULTS,
        ];
    }

    /** @param list<string> $sql */
    private function db(array &$sql): Connection
    {
        $heads = $this->heads();
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use (&$sql, $heads): array {
            $query = (string) $args[0];
            $sql[] = $query;
            if (str_contains($query, 'FROM [economy_work_orders_heads]')) {
                $rows = $heads;
                if (str_contains($query, '[id] IN %in')) {
                    $ids = (array) $args[2];
                    $rows = array_values(array_filter($rows, static fn(array $r): bool => in_array($r['id'], $ids, true)));
                }
                return array_map(static fn(array $r): Row => new Row($r), $rows);
            }
            if (str_contains($query, 'FROM [economy_work_orders_rows]')) {
                $this->assertStringContainsString('ORDER BY [r].[order_pos], [r].[id]', $query);
                return (int) $args[1] !== 1 ? [] : [
                    new Row(['id' => 10, 'work_order' => 1, 'order_pos' => 1, 'item' => 11, 'description' => 'Správa IT — měsíční paušál',
                             'quantity' => '1.0000', 'unit' => 3, 'unit_price' => '16000.0000', 'vat_code' => 'cz-120', 'operation' => null,
                             'valid_from' => null, 'valid_to' => new \DateTimeImmutable('2026-05-31'), 'contributor' => null,
                             'item_code' => 'SPRAVA-IT', 'unit_code' => 'mnth', 'unit_shortcut' => 'měs']),
                    new Row(['id' => 11, 'work_order' => 1, 'order_pos' => 2, 'item' => 11, 'description' => 'Správa IT — měsíční paušál',
                             'quantity' => '1.0000', 'unit' => 3, 'unit_price' => '17500.0000', 'vat_code' => 'cz-120', 'operation' => 'sale.services',
                             'valid_from' => '2026-06-01', 'valid_to' => null, 'contributor' => null,
                             'item_code' => 'SPRAVA-IT', 'unit_code' => null, 'unit_shortcut' => 'měs']),
                ];
            }
            if (str_contains($query, 'FROM [economy_work_orders_periods]')) {
                $this->assertStringContainsString('ORDER BY [p].[period_from], [p].[id]', $query);
                return (int) $args[1] !== 1 ? [] : [
                    new Row(['period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'state' => 'issued', 'doc_number' => '1260001', 'doc_state' => 40]),
                    new Row(['period_from' => '2026-02-01', 'period_to' => '2026-02-28', 'state' => 'issued', 'doc_number' => '1260013', 'doc_state' => 90]),
                    new Row(['period_from' => '2026-03-01', 'period_to' => '2026-03-31', 'state' => 'planned', 'doc_number' => null, 'doc_state' => null]),
                    new Row(['period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'state' => 'issued', 'doc_number' => '!0000000155', 'doc_state' => 10]),
                ];
            }
            $this->fail("unexpected fetchAll: {$query}");
        });
        $db->method('fetch')->willReturnCallback(function (string $query, mixed ...$args): ?Row {
            $id = (int) ($args[0] ?? 0);
            if (str_contains($query, 'FROM [economy_work_orders_number_series]')) {
                return match ($id) {
                    1 => new Row(['number_code' => 'S']),
                    2 => new Row(['number_code' => 'P']),
                    3 => new Row(['number_code' => 'I']),
                    7 => new Row(['number_code' => null]),
                    default => null,
                };
            }
            if (str_contains($query, 'FROM [economy_work_orders_heads]')) {
                return $id === 2 ? new Row(['number' => 'P0001']) : null;
            }
            if (str_contains($query, 'FROM [base_persons_persons]')) {
                return $id === 5 ? new Row([
                    'id' => 5, 'full_name' => 'Autodoprava Kopretina s.r.o.', 'company_id' => '12345678', 'tax_id' => 'CZ12345678',
                    'vat_id' => 'CZ12345678', 'court_registration' => 'C 1 KS Brno', 'email' => 'info@kopretina.example', 'phone' => null, 'web' => null,
                ]) : null;
            }
            if (str_contains($query, 'FROM [base_persons_addresses]')) {
                return new Row(['street' => 'Dlouhá', 'house_number' => '1', 'city' => 'Brno', 'city_part' => null, 'zip' => '60200', 'country' => 'CZ', 'registry_code' => null]);
            }
            if (str_contains($query, 'FROM [economy_codebooks_cost_centers]')) {
                return $id === 3 ? new Row(['code' => 'S01']) : null;
            }
            if (str_contains($query, 'FROM [docs_core_number_series]')) {
                return $id === 7 ? new Row(['doc_type' => 'invno', 'doc_number_code' => null]) : null;
            }
            if (str_contains($query, 'FROM [economy_codebooks_bank_accounts]')) {
                return $id === 4 ? new Row(['code' => 'BU2']) : null;
            }
            $this->fail("unexpected fetch: {$query}");
        });
        return $db;
    }

    private function config(): ConfigRuntime
    {
        $types = JsoncParser::parseFile(dirname(__DIR__, 6) . '/modules/economy/workOrders/config/types.jsonc');
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn(string $id): mixed => $id === 'economy.workOrders.types' ? $types : null,
        );
        return $config;
    }

    public function testExportsNaturalKeysPredpisAndIssuedPeriodsInParentFirstOrder(): void
    {
        $sql = [];
        $exporter = new WorkOrderExporter($this->db($sql), $this->config());
        $this->assertSame('workOrders', $exporter->section());

        $records = $exporter->exportAll();

        $this->assertStringContainsString('FROM [economy_work_orders_heads] WHERE [docState] <> %i', $sql[0]);
        $this->assertSame(['P0001', 'S0001', 'I0001'], array_map(static fn($r) => $r->slug, $records), 'nadřazené první, pak podle čísla; X0001 bez kódu řady vynechána');
        $this->assertSame([2, 1, 4], array_map(static fn($r) => $r->id, $records));

        $periodic = $records[1]->data;
        $this->assertSame(['format', 'workOrder', 'rows', 'periods'], array_keys($periodic));
        $this->assertSame(WorkOrderExporter::FORMAT, $periodic['format']);
        $this->assertSame([
            'number' => 'S0001', 'sequenceNumber' => 1, 'numberSeries' => 'S', 'title' => 'Správa IT — Kopretina', 'state' => 'confirmed',
            'dateStart' => '2023-04-01',
            'customer' => ['name' => 'Autodoprava Kopretina s.r.o.', 'country' => 'cz', 'companyId' => '12345678', 'taxId' => 'CZ12345678', 'vatId' => 'CZ12345678'],
            'currency' => 'czk',
            'invoicing' => [
                'periodicity' => 'month', 'invoiceFrom' => '2026-01-01', 'docText' => 'Správa IT — paušál {období}',
                'numberSeries' => ['docType' => 'invno'], 'vatMode' => 'fromBase', 'paymentMethod' => 'bankTransfer', 'bankAccount' => 'BU2',
            ],
        ], $periodic['workOrder'], 'štíhlá strana, řada dokladů {docType} bez kódu (D37), účet kódem, bez null hodnot');
        $this->assertSame([
            ['item' => 'SPRAVA-IT', 'description' => 'Správa IT — měsíční paušál', 'quantity' => 1.0, 'unit' => 'mnth', 'unitPrice' => 16000.0, 'vatCode' => 'cz-120', 'validTo' => '2026-05-31'],
            ['item' => 'SPRAVA-IT', 'description' => 'Správa IT — měsíční paušál', 'quantity' => 1.0, 'unit' => 'měs', 'unitPrice' => 17500.0, 'vatCode' => 'cz-120', 'operation' => 'sale.services', 'validFrom' => '2026-06-01'],
        ], $periodic['rows'], 'položka ourCode, jednotka system_code (zkratka jako fallback), čísla float');
        $this->assertSame([
            ['from' => '2026-01-01', 'to' => '2026-01-31', 'state' => 'issued', 'docNumber' => '1260001'],
            ['from' => '2026-10-01', 'to' => '2026-10-31', 'state' => 'issued', 'docNumber' => '!0000000155'],
        ], $periodic['periods'], 'jen vystavená s dokladem mimo koš; koncept zástupným číslem');

        $project = $records[0]->data;
        $this->assertSame(['format', 'workOrder'], array_keys($project), 'projekt: bez předpisu, řádků a období');
        $this->assertSame('finished', $project['workOrder']['state']);
        $this->assertSame('2026-04-22', $project['workOrder']['dateEnd']);
        $this->assertSame('S01', $project['workOrder']['costCenter']);
        $this->assertArrayNotHasKey('invoicing', $project['workOrder']);

        $child = $records[2]->data['workOrder'];
        $this->assertSame('P0001', $child['parent'], 'nadřazená číslem');
        $this->assertSame('confirmed', $child['state'], 'V opravě se přenáší jako V pořádku');
        $this->assertArrayNotHasKey('customer', $child);

        $warnings = $exporter->getWarnings();
        $this->assertCount(2, $warnings, implode("\n", $warnings));
        $this->assertStringContainsString('zakázka S0001: 2 období bez vystaveného dokladu', $warnings[0]);
        $this->assertStringContainsString('zakázka X0001: řada zakázek bez kódu', $warnings[1]);

        $validator = new SchemaValidator(new SchemaLoader(dirname(__DIR__, 6) . '/modules/core/exchange/schemas'));
        foreach ($records as $record) {
            $decoded = json_decode(DatasetWriter::encode($record->data), true);
            $this->assertSame([], $validator->validate($decoded, 'shpd.dataset.workOrder', '1'), "{$record->slug} musí projít schématem obálky");
        }
    }

    public function testExportByIdsFiltersAndEmptyListQueriesNothing(): void
    {
        $sql = [];
        $exporter = new WorkOrderExporter($this->db($sql), $this->config());

        $this->assertSame([], $exporter->exportByIds([]));
        $this->assertSame([], $sql);

        $records = $exporter->exportByIds([2, 2]);
        $this->assertStringContainsString('[id] IN %in', $sql[0]);
        $this->assertSame(['P0001'], array_map(static fn($r) => $r->slug, $records));
    }

    public function testWithoutTypesConfigPeriodicityDecidesPredpis(): void
    {
        $sql = [];
        $records = (new WorkOrderExporter($this->db($sql)))->exportByIds([1, 2]);

        $this->assertArrayHasKey('invoicing', $records[1]->data['workOrder'], 'S0001 má periodicitu → předpis');
        $this->assertArrayNotHasKey('invoicing', $records[0]->data['workOrder'], 'P0001 bez periodicity → bez předpisu');
    }
}
