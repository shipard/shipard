<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\Bank\Dataset;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Dataset\DatasetWriter;
use Shipard\Module\Core\Exchange\Schema\SchemaLoader;
use Shipard\Module\Core\Exchange\Schema\SchemaValidator;
use Shipard\Module\Economy\Bank\Dataset\BankStatementExporter;

/**
 * Dump sekce bank (#131 fáze 2a, D17): výpis = obálka s účtem kódem,
 * transakce jen s tím, co dodá banka (znaménková částka, bez partnera,
 * amount_dom, accounting_state, fingerprintu), výchozí pohyb vynechaný,
 * kurz jen na účtu v cizí měně, pořadí podle data a id, výstup validní
 * proti schématu.
 */
class BankStatementExporterTest extends TestCase
{
    private const STATEMENTS = [
        ['id' => 5, 'statement_number' => '2/2026', 'external_id' => 'BU1-2026-02', 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
         'opening_balance' => '480000.00', 'closing_balance' => '417647.00', 'currency' => 'czk', 'docState' => 40,
         'account_code' => 'BU1', 'account_currency' => 'czk'],
        ['id' => 9, 'statement_number' => '2/2026', 'external_id' => 'BU2-2026-02', 'period_start' => '2026-02-01', 'period_end' => '2026-02-28',
         'opening_balance' => '0.00', 'closing_balance' => '1200.00', 'currency' => 'eur', 'docState' => 40,
         'account_code' => 'BU2', 'account_currency' => 'eur'],
    ];

    private const TX_DEFAULTS = [
        'bank_account' => 1, 'date_value' => null, 'currency' => 'czk', 'amount_dom' => '0.00', 'exchange_rate' => '1.000000',
        'counterparty_account' => null, 'counterparty_name' => null, 'partner' => null, 'payment_reference' => null,
        'specific_symbol' => null, 'constant_symbol' => '', 'message' => null, 'accounting_state' => 1,
        'accounting_messages' => null, 'fingerprint' => 'f1', 'docState' => 40, 'docStateMain' => 3,
    ];

    private const TRANSACTIONS = [
        5 => [
            ['id' => 11, 'statement' => 5, 'direction' => 1, 'date_transaction' => '2026-02-09', 'amount' => '8712.00', 'amount_dom' => '8712.00',
             'counterparty_account' => '2440913574/0300', 'counterparty_name' => 'Autodoprava Kopretina s.r.o.', 'partner' => 7,
             'payment_reference' => '1260005', 'operation' => 'payment.in', 'external_id' => 'BU1-202602-002'],
            ['id' => 12, 'statement' => 5, 'direction' => 2, 'date_transaction' => '2026-02-10', 'amount' => '70896.00',
             'counterparty_account' => '1000000019/0800', 'counterparty_name' => 'Hromadný příkaz — mzdy',
             'message' => 'Výplata mezd 01/2026', 'operation' => 'payment.out', 'external_id' => 'BU1-202602-003'],
            ['id' => 13, 'statement' => 5, 'direction' => 2, 'date_transaction' => '2026-02-28', 'amount' => '169.00',
             'message' => 'Poplatek za vedení účtu', 'operation' => 'fee.out', 'external_id' => 'BU1-202602-004'],
            // Transakce v jiném stavu než výpis (ručně vrácená do Nový) — jde do dumpu s varováním.
            ['id' => 14, 'statement' => 5, 'direction' => 1, 'date_transaction' => '2026-02-05', 'amount' => '1.00',
             'operation' => 'payment.in', 'external_id' => 'BU1-202602-001', 'docState' => 10, 'accounting_state' => 0],
        ],
        9 => [
            ['id' => 21, 'statement' => 9, 'direction' => 1, 'date_transaction' => '2026-02-12', 'amount' => '1200.00', 'currency' => 'eur',
             'amount_dom' => '30096.00', 'exchange_rate' => '25.080000', 'counterparty_account' => 'SK3111000000002612345678',
             'counterparty_name' => 'Tatranka s.r.o.', 'partner' => 13, 'payment_reference' => '1260007', 'operation' => 'payment.in',
             'external_id' => 'BU2-202602-001'],
        ],
    ];

    /** @param list<string> $sql zachycené dotazy */
    private function db(array &$sql, array $statements = self::STATEMENTS): Connection
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetchAll')->willReturnCallback(function (mixed ...$args) use (&$sql, $statements): array {
            $query = (string) $args[0];
            $sql[] = $query;
            if (str_contains($query, '[economy_bank_statements]')) {
                $rows = $statements;
                if (str_contains($query, '[s.id] IN %in')) {
                    $ids = (array) $args[1];
                    $rows = array_values(array_filter($rows, static fn(array $r): bool => in_array($r['id'], $ids, true)));
                }
                return array_map(static fn(array $r): Row => new Row($r), $rows);
            }
            if (str_contains($query, '[economy_bank_transactions]')) {
                $rows = array_map(static fn(array $r): array => array_merge(self::TX_DEFAULTS, $r), self::TRANSACTIONS[(int) $args[1]] ?? []);
                usort($rows, static fn(array $a, array $b): int => [$a['date_transaction'], $a['id']] <=> [$b['date_transaction'], $b['id']]);
                return array_map(static fn(array $r): Row => new Row($r), $rows);
            }
            $this->fail("unexpected query: {$query}");
        });
        return $db;
    }

    private function validator(): SchemaValidator
    {
        return new SchemaValidator(new SchemaLoader(dirname(__DIR__, 6) . '/modules/core/exchange/schemas'));
    }

    public function testExportsStatementsWithBankDataOnlyInNaturalOrder(): void
    {
        $sql = [];
        $exporter = new BankStatementExporter($this->db($sql), 'CZK');
        $this->assertSame('bank', $exporter->section());

        $records = $exporter->exportAll();

        $this->assertStringContainsString(
            'WHERE [s.docState] <> 90 ORDER BY [a.code], [s.period_start], [s.statement_number], [s.id]',
            $sql[0],
            'výpisy podle kódu účtu a období, id jen jako tie-breaker',
        );
        $this->assertStringContainsString('[statement] = %i AND [docState] <> 90 ORDER BY [date_transaction], [id]', $sql[1]);
        $this->assertSame([5, 9], array_map(static fn($r) => $r->id, $records));
        $this->assertSame(['BU1-2026-02', 'BU2-2026-02'], array_map(static fn($r) => $r->slug, $records));

        $bu1 = $records[0]->data;
        $this->assertSame(['format', 'bankAccount', 'statement', 'transactions', 'docState'], array_keys($bu1));
        $this->assertSame(BankStatementExporter::FORMAT, $bu1['format']);
        $this->assertSame('BU1', $bu1['bankAccount']);
        $this->assertSame([
            'statementNumber' => '2/2026', 'externalId' => 'BU1-2026-02', 'periodStart' => '2026-02-01', 'periodEnd' => '2026-02-28',
            'openingBalance' => 480000.0, 'closingBalance' => 417647.0, 'currency' => 'CZK',
        ], $bu1['statement']);
        $this->assertSame(40, $bu1['docState']);

        $tx = $bu1['transactions'];
        $this->assertCount(4, $tx);
        $this->assertSame(['BU1-202602-001', 'BU1-202602-002', 'BU1-202602-003', 'BU1-202602-004'], array_column($tx, 'externalId'), 'podle data, pak id');
        $this->assertSame([
            'externalId' => 'BU1-202602-002', 'amount' => 8712.0, 'dateTransaction' => '2026-02-09',
            'counterpartyAccount' => '2440913574/0300', 'counterpartyName' => 'Autodoprava Kopretina s.r.o.', 'paymentReference' => '1260005',
        ], $tx[1], 'příjem: bez partnera, amount_dom, accounting_state, fingerprintu; výchozí payment.in vynechán; prázdný KS pryč');
        $this->assertSame([
            'externalId' => 'BU1-202602-003', 'amount' => -70896.0, 'dateTransaction' => '2026-02-10',
            'counterpartyAccount' => '1000000019/0800', 'counterpartyName' => 'Hromadný příkaz — mzdy', 'message' => 'Výplata mezd 01/2026',
        ], $tx[2], 'výdaj záporně, bez VS');
        $this->assertSame([
            'externalId' => 'BU1-202602-004', 'amount' => -169.0, 'dateTransaction' => '2026-02-28',
            'message' => 'Poplatek za vedení účtu', 'operation' => 'fee.out',
        ], $tx[3], 'výslovný pohyb poplatku zůstává');
        $this->assertArrayNotHasKey('exchangeRate', $tx[1], 'na účtu v domácí měně kurz nejde');

        $bu2 = $records[1]->data;
        $this->assertSame('EUR', $bu2['statement']['currency']);
        $this->assertSame(25.08, $bu2['transactions'][0]['exchangeRate'], 'účet v cizí měně nese kurz transakce');
        $this->assertSame('SK3111000000002612345678', $bu2['transactions'][0]['counterpartyAccount']);

        $warnings = $exporter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('transakce #14 je ve stavu 10, výpis ve 40', $warnings[0]);

        $validator = $this->validator();
        foreach ($records as $record) {
            $decoded = json_decode(DatasetWriter::encode($record->data), true);
            $this->assertSame([], $validator->validate($decoded, 'shpd.dataset.bankStatement', '1'), 'výpis musí projít schématem obálky');
        }
    }

    public function testExportByIdsFiltersAndEmptyListQueriesNothing(): void
    {
        $sql = [];
        $exporter = new BankStatementExporter($this->db($sql), 'czk');

        $this->assertSame([], $exporter->exportByIds([]));
        $this->assertSame([], $sql);

        $records = $exporter->exportByIds([9, 9]);
        $this->assertCount(1, $records);
        $this->assertSame(9, $records[0]->id);
        $this->assertStringContainsString('AND [s.id] IN %in', $sql[0]);
    }

    public function testAccountWithoutCodeIsSkippedWithWarning(): void
    {
        $sql = [];
        $statements = self::STATEMENTS;
        $statements[0]['account_code'] = '';
        $exporter = new BankStatementExporter($this->db($sql, $statements), 'czk');

        $records = $exporter->exportAll();

        $this->assertSame([9], array_map(static fn($r) => $r->id, $records));
        $this->assertStringContainsString('výpis #5 (2026-02-01): bankovní účet bez kódu', $exporter->getWarnings()[0]);
    }

    public function testExplicitOperationDropsDefaults(): void
    {
        $this->assertNull(BankStatementExporter::explicitOperation('payment.in'));
        $this->assertNull(BankStatementExporter::explicitOperation('payment.out'));
        $this->assertNull(BankStatementExporter::explicitOperation(null));
        $this->assertNull(BankStatementExporter::explicitOperation(''));
        $this->assertSame('fee.out', BankStatementExporter::explicitOperation('fee.out'));
        $this->assertSame('transfer.in', BankStatementExporter::explicitOperation('transfer.in'));
    }
}
