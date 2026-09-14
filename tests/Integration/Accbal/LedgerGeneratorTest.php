<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Core\Settings\SettingsStore;
use Shipard\Module\Economy\Accbal\AccbalSourceCleanupHandler;
use Shipard\Module\Economy\Accbal\LedgerGenerator;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * LedgerGenerator nad reálným DS se seedovaným nastavením saldokont
 * (Fáze 1 provisioner: receivables 311, payables 311 záporně, unmatched_payments
 * 261200/261300).
 *
 * Vstupem generátoru je účetní deník — testy ho seedují přímo (izolace od
 * účtovacího enginu), generují pohyby a ověřují skupinu/stranu/částky/idempotenci.
 */
class LedgerGeneratorTest extends IntegrationTestCase
{
    private const ACC_DATE = '2026-06-10';

    /** @var list<int> */
    private array $docIds = [];
    /** @var list<int> */
    private array $txIds = [];

    private int $seq = 0;

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->docIds as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->txIds as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
            $dibi->delete('economy_accounting_journal')->where('bank_transaction = %i', $id)->execute();
        }
    }

    private function generator(): LedgerGenerator
    {
        // Stejné odvození jako JournalLedgerHandler — settings, ne main.json.
        $value = (new SettingsStore($this->db))->get('economy.homeCurrency');
        return new LedgerGenerator(
            $this->db->getDibiConnection(),
            null,
            is_string($value) && $value !== '' ? $value : null,
        );
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    /** Vloží řádek deníku zdroje. $over přepisuje cokoliv. */
    private function insertJournal(string $sourceKind, int $sourceId, array $over): void
    {
        $col = $sourceKind === 'bankTransaction' ? 'bank_transaction' : 'doc_head';
        $row = array_merge([
            'source_kind'     => $sourceKind,
            $col              => $sourceId,
            'accounting_date' => self::ACC_DATE,
            'account_number'  => '311100',
            'money_dr'        => 0,
            'money_cr'        => 0,
            'money_dr_cur'    => 0,
            'money_cr_cur'    => 0,
            'currency'        => 'czk',
            'is_error'        => 0,
        ], $over);
        $this->db->getDibiConnection()->insert('economy_accounting_journal', $row)->execute();
    }

    private function newDocId(): int
    {
        $id = 900_000_000 + (++$this->seq);
        $this->docIds[] = $id;
        return $id;
    }

    private function newTxId(): int
    {
        $id = 910_000_000 + (++$this->seq);
        $this->txIds[] = $id;
        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function ledgerOf(string $sourceKind, int $sourceId): array
    {
        $col = $sourceKind === 'bankTransaction' ? 'bank_transaction' : 'doc_head';
        $rows = $this->db->getDibiConnection()->fetchAll(
            'SELECT * FROM economy_accbal_ledger WHERE source_kind = %s AND [' . $col . '] = %i ORDER BY id',
            $sourceKind,
            $sourceId,
        );
        return array_map(fn($r) => $r->toArray(), $rows);
    }

    public function testInvoiceCreatesReceivableRequest(): void
    {
        $recv = $this->balanceId('receivables');
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, [
            'account_number'    => '311100',
            'money_dr'          => 1210.00,
            'money_dr_cur'      => 1210.00,
            'payment_reference' => '12345',
            'due_date'          => '2026-07-10',
        ]);

        $this->generator()->generate('doc', $docId);

        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(1, $ledger, 'Faktura → právě 1 předpis na Pohledávkách');
        $m = $ledger[0];
        $this->assertSame($recv, (int) $m['balance']);
        $this->assertSame(0, (int) $m['bal_side'], 'Předpis');
        $this->assertEqualsWithDelta(1210.00, (float) $m['amount'], 0.001);
        $this->assertEqualsWithDelta(1210.00, (float) $m['amount_hc'], 0.001);
        $this->assertSame('311100', $m['account_number']);
        $this->assertSame('12345', $m['payment_reference']);
    }

    public function testCaseKeyIsNormalizedOnWrite(): void
    {
        // D10: symboly TRIM, prázdné → NULL, měna malými písmeny — rovnost
        // klíče případu pak jde přes idx_case bez funkcí ve WHERE.
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, [
            'account_number'    => '311100',
            'money_dr'          => 100.00,
            'money_dr_cur'      => 100.00,
            'payment_reference' => ' 123 ',
            'specific_symbol'   => '',
            'constant_symbol'   => '  ',
            'currency'          => 'CZK',
        ]);

        $this->generator()->generate('doc', $docId);

        $m = $this->ledgerOf('doc', $docId)[0];
        $this->assertSame('123', $m['payment_reference']);
        $this->assertNull($m['specific_symbol'], 'prázdný SS = NULL');
        $this->assertNull($m['constant_symbol']);
        $this->assertSame('czk', $m['currency']);
    }

    public function testCreditNoteGoesToPayablesWithModifySign(): void
    {
        $pay = $this->balanceId('payables');
        $docId = $this->newDocId();
        // Dobropis vydané faktury: 311 záporně (negativní MD).
        $this->insertJournal('doc', $docId, [
            'account_number' => '311100',
            'money_dr'       => -500.00,
            'money_dr_cur'   => -500.00,
        ]);

        $this->generator()->generate('doc', $docId);

        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(1, $ledger, 'Záporná pohledávka → 1 pohyb na Závazcích');
        $m = $ledger[0];
        $this->assertSame($pay, (int) $m['balance']);
        $this->assertSame(0, (int) $m['bal_side'], 'Předpis (závazek vzniká)');
        // modify_sign obrátí znaménko → kladná částka na Závazcích.
        $this->assertEqualsWithDelta(500.00, (float) $m['amount'], 0.001);
        $this->assertEqualsWithDelta(500.00, (float) $m['amount_hc'], 0.001);
    }

    public function testBankIncomingGoesToUnmatchedClearing(): void
    {
        $clearing = $this->balanceId('unmatched_payments');
        $txId = $this->newTxId();
        // Bankovní příjem nespárovaný: clearing 261200 na DAL.
        $this->insertJournal('bankTransaction', $txId, [
            'account_number' => '261200',
            'money_cr'       => 1210.00,
            'money_cr_cur'   => 1210.00,
        ]);

        $this->generator()->generate('bankTransaction', $txId);

        $ledger = $this->ledgerOf('bankTransaction', $txId);
        $this->assertCount(1, $ledger);
        $m = $ledger[0];
        $this->assertSame($clearing, (int) $m['balance']);
        $this->assertSame(1, (int) $m['bal_side'], 'Úhrada');
        $this->assertEqualsWithDelta(1210.00, (float) $m['amount'], 0.001);
        $this->assertSame('bankTransaction', $m['source_kind']);
        $this->assertSame($txId, (int) $m['bank_transaction']);
    }

    public function testForeignCurrencyKeepsBothAmounts(): void
    {
        $this->balanceId('receivables');
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, [
            'account_number' => '311100',
            'money_dr'       => 2528.50,   // domácí (CZK)
            'money_dr_cur'   => 100.00,    // měna dokladu (EUR)
            'currency'       => 'eur',
        ]);

        $this->generator()->generate('doc', $docId);

        $m = $this->ledgerOf('doc', $docId)[0];
        $this->assertEqualsWithDelta(100.00, (float) $m['amount'], 0.001, 'amount = měna dokladu');
        $this->assertEqualsWithDelta(2528.50, (float) $m['amount_hc'], 0.001, 'amount_hc = domácí');
        $this->assertSame('eur', $m['currency']);
    }

    public function testErrorJournalRowProducesNoLedgerMove(): void
    {
        // Chybový řádek deníku (is_error=1, nedohledaný účet) nesmí vyrobit
        // saldo pohyb — jinak by fantomový pohyb maskoval účetní chybu.
        $txId = $this->newTxId();
        $this->insertJournal('bankTransaction', $txId, [
            'account_number' => '261200',   // maska clearing účtu, který v osnově není
            'is_error'       => 1,
            'money_cr'       => 1210.00,
            'money_cr_cur'   => 1210.00,
        ]);

        $this->generator()->generate('bankTransaction', $txId);

        $this->assertSame([], $this->ledgerOf('bankTransaction', $txId), 'Z chybového řádku nevzniká saldo pohyb');
    }

    public function testReaccountPreservesLedgerId(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, ['account_number' => '311100', 'money_dr' => 1210.00, 'money_dr_cur' => 1210.00]);
        $this->generator()->generate('doc', $docId);
        $firstId = (int) $this->ledgerOf('doc', $docId)[0]['id'];

        // Reaccount: deník přepsán (nové journal_row id), zdroj stejný.
        $dibi = $this->db->getDibiConnection();
        $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $docId)->execute();
        $this->insertJournal('doc', $docId, ['account_number' => '311100', 'money_dr' => 1210.00, 'money_dr_cur' => 1210.00]);
        $this->generator()->generate('doc', $docId);

        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(1, $ledger, 'Reaccount nesmí zdvojit pohyb');
        $this->assertSame($firstId, (int) $ledger[0]['id'], 'id pohybu přežije reaccount (stabilní klíč)');
    }

    public function testClearRemovesLedger(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, ['account_number' => '311100', 'money_dr' => 1210.00, 'money_dr_cur' => 1210.00]);
        $this->generator()->generate('doc', $docId);
        $this->assertCount(1, $this->ledgerOf('doc', $docId));

        // Odchod ze stavu 40: deník vymazán → generate s prázdným deníkem.
        $this->db->getDibiConnection()->delete('economy_accounting_journal')->where('doc_head = %i', $docId)->execute();
        $this->generator()->generate('doc', $docId);

        $this->assertSame([], $this->ledgerOf('doc', $docId), 'Prázdný deník → pohyby zdroje smazány');
    }

    public function testBeforeDeleteCleanupRemovesLedger(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, ['account_number' => '311100', 'money_dr' => 1210.00, 'money_dr_cur' => 1210.00]);
        $this->generator()->generate('doc', $docId);
        $this->assertCount(1, $this->ledgerOf('doc', $docId));

        $handler = new AccbalSourceCleanupHandler();
        $handler->setDb($this->db->getDibiConnection());
        $handler->onBeforeDelete('docs_core_heads', ['id' => $docId]);

        $this->assertSame([], $this->ledgerOf('doc', $docId), 'beforeDelete smaže pohyby zdroje');
    }
}
