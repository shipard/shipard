<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Export;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Export\DocumentExporter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;

class DocumentExporterTest extends TestCase
{
    /** @return array<string, mixed> */
    private function headRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 100, 'doc_type' => 'invni', 'number_series' => 2, 'sequence_number' => 7,
            'doc_number' => 'FP-2026-0007', 'doc_text' => 'Konzultace červen', 'partner_doc_number' => '2026031',
            'partner' => 5, 'partner_address' => 9, 'partner_bank' => 3,
            'partner_bank_account' => '123456789/0100', 'partner_bank_iban' => 'CZ6501000000000123456789', 'partner_bank_bic' => 'KOMBCZPP',
            'issue_date' => new \DateTimeImmutable('2026-06-01'), 'due_date' => '2026-06-15', 'accounting_date' => '2026-06-01',
            'vat_duzp' => '2026-06-01', 'vat_dppd' => null, 'period_from' => null, 'period_to' => null,
            'fiscal_year' => 1, 'fiscal_month' => 6, 'vat_registration' => 1, 'vat_period' => 12,
            'vat_mode' => 1, 'vat_calc_source' => 0, 'vat_place' => 0,
            'doc_currency' => 'czk', 'home_currency' => 'czk', 'exchange_rate' => null,
            'total_rounding_mode' => 0, 'vat_rounding_mode' => 0,
            'total_base' => '1500.00', 'total_vat' => '315.00', 'total_amount' => '1815.00', 'total_rounding' => '0.00',
            'total_base_dom' => '1500.00', 'total_vat_dom' => '315.00', 'total_amount_dom' => '1815.00', 'total_rounding_dom' => '0.00',
            'payment_method' => 1, 'bank_account' => 4, 'payment_reference' => '2026031', 'specific_symbol' => null, 'constant_symbol' => '0308',
            'source_kind' => 'aiExtraction', 'source_message' => 55, 'source_extracted_at' => new \DateTimeImmutable('2026-06-01 08:00:00'),
            'supplier_snapshot' => '{}', 'customer_snapshot' => null,
            'notice' => 'interní', 'doc_notice' => null,
            'docState' => 40, 'docStateMain' => 4, 'doc_state_changed_at' => null,
            'accounting_state' => 1, 'accounting_messages' => null,
            // joins
            'doc_number_code' => '1', 'vat_reg_country' => 'cz', 'own_bank_code' => 'MAIN',
        ], $overrides);
    }

    private function db(array $rows = [], array $recap = [], bool $hasAccounting = true, bool $partner = true): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (string $sql) use ($hasAccounting, $partner): ?Row {
            if (str_contains($sql, 'SHOW TABLES')) {
                return $hasAccounting ? new Row(['t' => 'economy_accounting_accounts']) : null;
            }
            if (str_contains($sql, 'FROM [base_persons_persons]')) {
                return $partner ? new Row([
                    'id' => 5, 'full_name' => 'Dodavatel a.s.', 'company_id' => '87654321', 'tax_id' => 'CZ87654321',
                    'vat_id' => 'CZ87654321', 'court_registration' => null, 'email' => 'fakturace@dodavatel.example',
                    'phone' => null, 'web' => 'https://dodavatel.example',
                ]) : null;
            }
            if (str_contains($sql, 'FROM [base_persons_addresses]')) {
                return new Row([
                    'street' => 'Krátká', 'house_number' => '1', 'city' => 'Brno', 'city_part' => null,
                    'zip' => '60200', 'country' => 'CZ', 'registry_code' => null,
                ]);
            }
            return null;
        });
        $db->method('fetchAll')->willReturnCallback(function (string $sql) use ($rows, $recap): array {
            $data = match (true) {
                str_contains($sql, 'FROM [docs_core_rows]')      => $rows,
                str_contains($sql, 'FROM [docs_core_vat_recap]') => $recap,
                default => [],
            };
            return array_map(static fn(array $r) => new Row($r), $data);
        });
        return $db;
    }

    /** @return array<string, mixed> */
    private function itemRowRow(): array
    {
        return [
            'id' => 1, 'doc_head' => 100, 'row_kind' => 1, 'operation' => 'purchase.services', 'order_pos' => 1,
            'item' => 11, 'description' => 'Konzultace IT – červen', 'unit' => 3, 'quantity' => '1.0000',
            'unit_price' => '1500.0000', 'total_price' => '1500.0000', 'price_calc_mode' => 0,
            'discount_pct' => null, 'discount_amount' => null, 'vat_code' => 'highEU', 'vat_pct' => '21.00',
            'vat_base' => '1500.00', 'vat_amount' => '315.00', 'vat_total' => '1815.00',
            'vat_base_dom' => '1500.00', 'vat_amount_dom' => '315.00', 'vat_total_dom' => '1815.00',
            'partner' => null, 'payment_reference' => null, 'specific_symbol' => null, 'constant_symbol' => null,
            'due_date' => null, 'account' => null, 'acc_side' => null,
            'item_code' => 'K-001', 'item_name' => 'Konzultace IT', 'unit_code' => 'hour', 'unit_shortcut' => 'h',
            'account_number' => null,
        ];
    }

    public function testReceivedInvoiceMapsToCanonicalAndValidates(): void
    {
        $db = $this->db(
            rows: [$this->itemRowRow()],
            recap: [['id' => 1, 'doc_head' => 100, 'vat_code' => 'highEU', 'vat_pct' => '21.00', 'base' => '1500.00',
                     'tax' => '315.00', 'total' => '1815.00', 'is_reverse_pair' => 0, 'order_pos' => 0]],
        );
        $exporter = new DocumentExporter($db);
        $record = $exporter->exportDocument($this->headRow());
        $c = $record->data;

        $this->assertSame(100, $record->id);
        $this->assertSame('FP-2026-0007', $record->slug);
        $this->assertSame('invoiceReceived', $c['docType']);
        $this->assertSame('2026031', $c['docNumber'], 'canonical docNumber is the partner\'s number');
        $this->assertSame('Konzultace červen', $c['docText']);
        $this->assertSame('customer', $c['selfParty']);
        $this->assertArrayNotHasKey('customer', $c);
        $this->assertSame(['kind' => 'aiExtraction', 'extractedAt' => '2026-06-01T08:00:00'], $c['source']);

        $supplier = $c['supplier'];
        $this->assertSame('Dodavatel a.s.', $supplier['name']);
        $this->assertSame('cz', $supplier['country']);
        $this->assertSame('87654321', $supplier['companyId']);
        $this->assertSame(['street' => 'Krátká', 'houseNumber' => '1', 'city' => 'Brno', 'zip' => '60200', 'country' => 'cz'], $supplier['address']);
        $this->assertSame(['email' => 'fakturace@dodavatel.example', 'web' => 'https://dodavatel.example'], $supplier['contact']);
        $this->assertSame(
            ['accountNumber' => '123456789/0100', 'iban' => 'CZ6501000000000123456789', 'bic' => 'KOMBCZPP'],
            $supplier['bankAccount'],
        );

        $this->assertSame(
            ['issueDate' => '2026-06-01', 'dueDate' => '2026-06-15', 'accountingDate' => '2026-06-01', 'taxPointDate' => '2026-06-01'],
            $c['dates'],
        );
        $this->assertSame('CZK', $c['currency']);
        $this->assertSame(
            [
                'mode' => 'fromBase', 'place' => 'domestic', 'registrationCountry' => 'cz',
                'recapSource' => 'computed', 'calcSource' => 'header', 'controlStatementMode' => 'auto',
            ],
            $c['vat'],
            'autorita rekapitulace i metoda výpočtu musí přežít round-trip',
        );
        $this->assertSame(['method' => 'bankTransfer', 'paymentReference' => '2026031', 'constantSymbol' => '0308'], $c['payment']);
        $this->assertSame(['internal' => 'interní'], $c['notes']);

        $row = $c['rows'][0];
        $this->assertSame('item', $row['rowKind']);
        $this->assertSame('purchase.services', $row['operation']);
        $this->assertSame(1, $row['orderPos']);
        $this->assertSame(['ourCode' => 'K-001', 'name' => 'Konzultace IT'], $row['item']);
        $this->assertSame('hour', $row['unit']);
        $this->assertSame(1.0, $row['quantity']);
        $this->assertSame(1500.0, $row['unitPrice']);
        $this->assertSame('fromUnitPrice', $row['priceCalcMode']);
        $this->assertSame(['code' => 'highEU', 'pct' => 21.0], $row['vat']);
        $this->assertSame(['vatBase' => 1500.0, 'vatAmount' => 315.0, 'vatTotal' => 1815.0], $row['computed']);
        $this->assertSame('Konzultace IT – červen', $row['description']);
        $this->assertArrayNotHasKey('account', $row);

        $this->assertSame([['vatCode' => 'highEU', 'vatPct' => 21.0, 'base' => 1500.0, 'tax' => 315.0, 'total' => 1815.0]], $c['vatRecap']);
        $this->assertSame(['totalBase' => 1500.0, 'totalVat' => 315.0, 'totalAmount' => 1815.0, 'totalRounding' => 0.0], $c['totals'], '0.0 is a value, not empty — kept');

        $this->assertSame([
            'targetDocState'       => 40,
            'importNumber'         => ['docNumber' => 'FP-2026-0007', 'sequenceNumber' => 7],
            'numberSeriesCode'     => '1',
            'importOwnBankAccount' => 'MAIN',
        ], $c['applyOptions']);

        $this->assertSame([], $exporter->getWarnings());

        $schema = (new SchemaValidator(SchemaLoader::default()))->validate($c, 'shpd.docs.document', '1');
        $this->assertSame([], $schema, 'exported canonical must validate against the docs schema');
        $errors = array_filter((new DocumentValidator())->validate($c), static fn(array $i): bool => $i['severity'] === 'error');
        $this->assertSame([], array_values($errors), 'exported canonical must pass DocumentValidator');
    }

    /** Ruční zařazení do KH musí přežít dump → seed (#77). */
    public function testFiscalPeriodTypeRoundTrips(): void
    {
        // #69 D20: uzávěrkový doklad musí přežít dump → seed, jinak by ho
        // cílový DS zařadil do běžného měsíce a saldo vzalo jako úhradu.
        $exporter = new DocumentExporter($this->db());

        $this->assertSame('closing', $exporter->exportDocument($this->headRow(['fiscal_period_type' => 'closing']))->data['fiscalPeriodType']);
        $this->assertNull($exporter->exportDocument($this->headRow())->data['fiscalPeriodType']);
    }

    public function testManualControlStatementModeIsExported(): void
    {
        $exporter = new DocumentExporter($this->db(rows: [$this->itemRowRow()]));

        $record = $exporter->exportDocument($this->headRow(['cs_mode' => 3]));
        $this->assertSame('exclude', $record->data['vat']['controlStatementMode']);

        $record = $exporter->exportDocument($this->headRow(['cs_mode' => 1]));
        $this->assertSame('detail', $record->data['vat']['controlStatementMode']);
    }

    public function testIssuedInvoicePutsPartnerOnCustomerSide(): void
    {
        $c = (new DocumentExporter($this->db()))->exportDocument($this->headRow([
            'doc_type' => 'invno', 'doc_number' => 'FV-2026-0001', 'sequence_number' => 1,
        ]))->data;

        $this->assertSame('invoiceIssued', $c['docType']);
        $this->assertSame('supplier', $c['selfParty']);
        $this->assertArrayNotHasKey('supplier', $c);
        $this->assertSame('Dodavatel a.s.', $c['customer']['name']);
        $this->assertArrayNotHasKey('bankAccount', $c['customer'], 'partner bank columns describe the supplier only');
    }

    public function testDraftWithoutNumberHasNoImportNumberAndDraftSlug(): void
    {
        $c = (new DocumentExporter($this->db(partner: false)))->exportDocument($this->headRow([
            'doc_number' => null, 'sequence_number' => null, 'partner' => null, 'docState' => 10, 'own_bank_code' => null, 'bank_account' => null,
        ]));

        $this->assertSame('koncept invni', $c->slug);
        $this->assertSame(['targetDocState' => 10, 'numberSeriesCode' => '1'], $c->data['applyOptions']);
        $this->assertArrayNotHasKey('supplier', $c->data);
    }

    public function testNullSequenceNumberIsPreservedAsExplicitNull(): void
    {
        $c = (new DocumentExporter($this->db()))->exportDocument($this->headRow([
            'doc_number' => 'FP-2026-0007-2', 'sequence_number' => null,
        ]))->data;

        $this->assertSame(['docNumber' => 'FP-2026-0007-2', 'sequenceNumber' => null], $c['applyOptions']['importNumber']);
    }

    public function testStateEightyWithoutNumberFallsBackToDraft(): void
    {
        $c = (new DocumentExporter($this->db()))->exportDocument($this->headRow([
            'doc_number' => null, 'sequence_number' => null, 'docState' => 80,
        ]))->data;

        $this->assertSame(10, $c['applyOptions']['targetDocState']);
    }

    public function testAccountingDocumentRowsCarryAccountAndSide(): void
    {
        $row = $this->itemRowRow();
        $row['operation'] = 'acc.record';
        $row['item'] = null;
        $row['item_code'] = null;
        $row['item_name'] = null;
        $row['account'] = 9;
        $row['account_number'] = '518100';
        $row['acc_side'] = 1;
        $row['price_calc_mode'] = 1;
        $row['partner'] = 5;

        $exporter = new DocumentExporter($this->db(rows: [$row], partner: false));
        $c = $exporter->exportDocument($this->headRow([
            'doc_type' => 'cmnbkp', 'doc_number' => 'UD-2026-0003', 'sequence_number' => 3, 'partner' => null,
        ]))->data;

        $this->assertSame('accountingDocument', $c['docType']);
        $this->assertArrayNotHasKey('selfParty', $c);
        $this->assertSame('518100', $c['rows'][0]['account']);
        $this->assertSame('credit', $c['rows'][0]['accSide']);
        $this->assertSame('fromTotal', $c['rows'][0]['priceCalcMode']);
        $this->assertArrayNotHasKey('item', $c['rows'][0]);
        $this->assertCount(1, $exporter->getWarnings());
        $this->assertStringContainsString('řádek 1 má partnera', $exporter->getWarnings()[0]);
    }

    public function testOwnBankWithoutCodeProducesWarning(): void
    {
        $exporter = new DocumentExporter($this->db());
        $c = $exporter->exportDocument($this->headRow(['own_bank_code' => null]))->data;

        $this->assertArrayNotHasKey('importOwnBankAccount', $c['applyOptions']);
        $this->assertCount(1, $exporter->getWarnings());
        $this->assertStringContainsString('nemá kód', $exporter->getWarnings()[0]);
    }

    public function testExportAllOrdersByNaturalKeysAndSkipsTrash(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(null);
        $db->expects($this->atLeastOnce())->method('fetchAll')->willReturnCallback(function (string $sql) {
            if (str_contains($sql, 'FROM [docs_core_heads] AS [h]')) {
                $this->assertStringContainsString('[h.docState] <> 90', $sql);
                $this->assertStringContainsString(
                    'ORDER BY [h.doc_type], [ns.doc_number_code], [h.sequence_number], [h.doc_number], [h.issue_date], [h.id]',
                    $sql,
                );
                return [];
            }
            return [];
        });

        $this->assertSame([], (new DocumentExporter($db))->exportAll());
    }
}
