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
 *
 * Klíč pohybu = zdroj + platební identita řádku (#69 D13): doklad s více
 * identitami (otevírací doklad, zápočet) dá pohyb per identitu, řádky téže
 * identity se sčítají, `id` je stabilní přes přeúčtování per identita.
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

    // ── Klíč pohybu per platební identita (#69 D13) ─────────────────────────

    /** @return array<string, array<string, mixed>> movement_key → pohyb */
    private function ledgerByKey(string $sourceKind, int $sourceId): array
    {
        $byKey = [];
        foreach ($this->ledgerOf($sourceKind, $sourceId) as $m) {
            $this->assertNotNull($m['movement_key'], 'generátor klíč zapisuje vždy');
            $byKey[(string) $m['movement_key']] = $m;
        }
        return $byKey;
    }

    /** Řádek deníku 311 MD pro partnera a VS (otevírací pohledávka). */
    private function receivableRow(int $partner, string $vs, float $amount, array $over = []): array
    {
        return array_merge([
            'account_number'    => '311100',
            'money_dr'          => $amount,
            'money_dr_cur'      => $amount,
            'partner'           => $partner,
            'payment_reference' => $vs,
            'due_date'          => '2026-07-10',
        ], $over);
    }

    public function testOpeningDocumentGivesMovementPerIdentity(): void
    {
        $recv = $this->balanceId('receivables');
        $docId = $this->newDocId();
        // Otevírací doklad období: tři pohledávky tří partnerů v jednom dokladu.
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2', 200.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(3, 'VS3', 300.00));

        $stats = $this->generator()->generate('doc', $docId);

        $this->assertSame(['inserted' => 3, 'updated' => 0, 'deleted' => 0], $stats);
        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(3, $ledger, 'Tři identity → tři pohyby, ne jeden slitý');
        $byVs = [];
        foreach ($ledger as $m) {
            $this->assertSame($recv, (int) $m['balance']);
            $this->assertSame(0, (int) $m['bal_side']);
            $byVs[$m['payment_reference']] = $m;
        }
        $this->assertSame(['VS1', 'VS2', 'VS3'], array_keys($byVs));
        $this->assertSame(1, (int) $byVs['VS1']['partner']);
        $this->assertEqualsWithDelta(100.00, (float) $byVs['VS1']['amount'], 0.001);
        $this->assertSame(3, (int) $byVs['VS3']['partner']);
        $this->assertEqualsWithDelta(300.00, (float) $byVs['VS3']['amount_hc'], 0.001);
        // Součet pohybů dokladu = obrat dokladu na saldo-účtu.
        $this->assertEqualsWithDelta(600.00, array_sum(array_map(fn($m) => (float) $m['amount'], $ledger)), 0.001);
        $this->assertCount(3, array_unique(array_column($ledger, 'movement_key')), 'movement_key per identita unikátní');
    }

    public function testSameIdentityRowsAggregateIntoOneMovement(): void
    {
        $docId = $this->newDocId();
        // Faktura se dvěma řádky na 311 téže identity (dvě sazby DPH).
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 1210.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 115.00));

        $this->generator()->generate('doc', $docId);

        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(1, $ledger, 'Stejná identita v jednom zdroji = jeden pohyb (regrese)');
        $this->assertEqualsWithDelta(1325.00, (float) $ledger[0]['amount'], 0.001);
        $this->assertEqualsWithDelta(1325.00, (float) $ledger[0]['amount_hc'], 0.001);
    }

    public function testNullAndEmptySpecificSymbolAreSameIdentity(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00, ['specific_symbol' => null]));
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 50.00, ['specific_symbol' => '']));
        $this->insertJournal('doc', $docId, $this->receivableRow(1, ' VS1 ', 25.00, ['specific_symbol' => '  ', 'currency' => 'CZK']));

        $this->generator()->generate('doc', $docId);

        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(1, $ledger, 'NULL, prázdný a mezerový SS po normalizaci = táž identita');
        $this->assertEqualsWithDelta(175.00, (float) $ledger[0]['amount'], 0.001);
        $this->assertNull($ledger[0]['specific_symbol']);
        $this->assertSame('VS1', $ledger[0]['payment_reference']);
    }

    public function testReaccountPreservesIdsPerIdentity(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2', 200.00));
        $this->generator()->generate('doc', $docId);
        $before = $this->ledgerByKey('doc', $docId);
        $this->assertCount(2, $before);

        // Reaccount: deník přepsán v jiném pořadí řádků (nové journal_row id).
        $dibi = $this->db->getDibiConnection();
        $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $docId)->execute();
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2', 200.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $stats = $this->generator()->generate('doc', $docId);

        $this->assertSame(['inserted' => 0, 'updated' => 2, 'deleted' => 0], $stats);
        $after = $this->ledgerByKey('doc', $docId);
        $this->assertSame(array_keys($before), array_keys($after), 'stejné klíče');
        foreach ($before as $key => $m) {
            $this->assertSame((int) $m['id'], (int) $after[$key]['id'], "id pohybu {$m['payment_reference']} přežije reaccount");
            $this->assertNotSame((int) $m['journal_row'], (int) $after[$key]['journal_row'], 'journal_row je denorm, refreshuje se');
        }
    }

    public function testChangedSymbolReplacesOnlyThatMovement(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2', 200.00));
        $this->generator()->generate('doc', $docId);
        $before = $this->ledgerByKey('doc', $docId);
        $keepKey = LedgerGenerator::movementKey($before[array_key_first($before)]);

        // Oprava VS druhé pohledávky → jiná identita.
        $dibi = $this->db->getDibiConnection();
        $dibi->delete('economy_accounting_journal')->where('doc_head = %i', $docId)->execute();
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2-fixed', 200.00));
        $stats = $this->generator()->generate('doc', $docId);

        $this->assertSame(['inserted' => 1, 'updated' => 1, 'deleted' => 1], $stats);
        $after = $this->ledgerByKey('doc', $docId);
        $this->assertCount(2, $after);
        $vs1Before = null;
        $vs2Before = null;
        foreach ($before as $m) {
            $m['payment_reference'] === 'VS1' ? $vs1Before = $m : $vs2Before = $m;
        }
        $ids = array_map(fn($m) => (int) $m['id'], $after);
        $this->assertContains((int) $vs1Before['id'], $ids, 'nedotčená identita drží id');
        $this->assertNotContains((int) $vs2Before['id'], $ids, 'změněná identita = DELETE starého + INSERT nového');
        $this->assertSame(['VS1', 'VS2-fixed'], array_values(array_map(fn($m) => $m['payment_reference'], $this->ledgerOf('doc', $docId))));
        $this->assertArrayHasKey($keepKey, $after, 'movement_key se počítá i z uloženého řádku shodně');
    }

    public function testDryRunCountsWithoutWriting(): void
    {
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2', 200.00));

        $plan = $this->generator()->generate('doc', $docId, true);

        $this->assertSame(['inserted' => 2, 'updated' => 0, 'deleted' => 0], $plan);
        $this->assertSame([], $this->ledgerOf('doc', $docId), 'dry-run nic nezapíše');

        $this->generator()->generate('doc', $docId);
        $again = $this->generator()->generate('doc', $docId, true);
        $this->assertSame(['inserted' => 0, 'updated' => 2, 'deleted' => 0], $again);
    }

    public function testLegacyMovementWithoutKeyIsReplaced(): void
    {
        // Pohyb z doby před D13: po ds-upgrade má movement_key NULL a doklad se
        // dvěma identitami byl slitý do jednoho řádku. Re-derivace ho nahradí.
        $recv = $this->balanceId('receivables');
        $docId = $this->newDocId();
        $this->insertJournal('doc', $docId, $this->receivableRow(1, 'VS1', 100.00));
        $this->insertJournal('doc', $docId, $this->receivableRow(2, 'VS2', 200.00));
        $this->db->getDibiConnection()->insert('economy_accbal_ledger', [
            'balance'           => $recv,
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $docId,
            'doc_head'          => $docId,
            'movement_key'      => null,
            'account_number'    => '311100',
            'partner'           => 1,
            'payment_reference' => 'VS1',
            'currency'          => 'czk',
            'amount'            => 300.00,
            'amount_hc'         => 300.00,
        ])->execute();
        $legacyId = (int) $this->ledgerOf('doc', $docId)[0]['id'];

        $stats = $this->generator()->generate('doc', $docId);

        $this->assertSame(['inserted' => 2, 'updated' => 0, 'deleted' => 1], $stats);
        $ledger = $this->ledgerOf('doc', $docId);
        $this->assertCount(2, $ledger, 'slitý pohyb rozdělen na dva');
        $this->assertNotContains($legacyId, array_map(fn($m) => (int) $m['id'], $ledger));
        $this->assertEqualsWithDelta(300.00, array_sum(array_map(fn($m) => (float) $m['amount'], $ledger)), 0.001);
    }
}
