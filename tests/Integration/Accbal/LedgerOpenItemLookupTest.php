<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Module\Economy\Accbal\LedgerOpenItemLookup;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * LedgerOpenItemLookup nad reálným DS (seed saldokont: receivables 311,
 * payables 321/325/331/336/…, unmatched_payments 261200/261300). Pohyby se
 * seedují přímo do ledgeru (izolace od enginů) — ověřuje se SQL sémantika
 * klíče: přesná shoda, SS, měna, směr → skupiny a všechny jejich předpisové
 * účty, dobropisové řádky mimo hru, clearing mimo hru, vyloučení zdroje.
 */
class LedgerOpenItemLookupTest extends IntegrationTestCase
{
    private const PARTNER = 990003;
    private const VS      = 'IT-OIL-2026';

    /** @var list<int> */
    private array $seededDocs = [];
    /** @var list<int> */
    private array $seededTxs = [];
    /** @var list<int> dočasné řádky nastavení saldokont (balance_accounts) */
    private array $seededSettings = [];
    private int $seq = 0;

    protected function onTearDown(): void
    {
        $dibi = $this->db->getDibiConnection();
        foreach ($this->seededDocs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('doc_head = %i', $id)->execute();
        }
        foreach ($this->seededTxs as $id) {
            $dibi->delete('economy_accbal_ledger')->where('bank_transaction = %i', $id)->execute();
        }
        foreach ($this->seededSettings as $id) {
            $dibi->delete('economy_accbal_balance_accounts')->where('id = %i', $id)->execute();
        }
    }

    public function testExactKeyMatchReturnsRequestAccount(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 1210.00);

        $item = $this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1);

        $this->assertNotNull($item);
        $this->assertSame($recv, $item->balance);
        $this->assertSame('311100', $item->accountNumber);
        $this->assertEqualsWithDelta(1210.00, $item->residual, 0.001);
    }

    public function testDifferentKeyIsMiss(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 1210.00);
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS . 'X', '', 'czk', 1), 'jiný VS');
        $this->assertNull($lookup->findOpenRequest(self::PARTNER + 1, self::VS, '', 'czk', 1), 'jiný partner');
        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'eur', 1), 'jiná měna');
    }

    public function testEmptySpecificSymbolMatchesOnlyEmpty(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 100.00, ['specific_symbol' => '77']);
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1), 'prázdný SS nesedí na 77');
        $this->assertNotNull($lookup->findOpenRequest(self::PARTNER, self::VS, '77', 'czk', 1));
        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '78', 'czk', 1));
    }

    public function testClosedRequestIsNull(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 500.00);
        $this->seedPayment($recv, '311100', 500.00);

        $this->assertNull($this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1));
    }

    public function testDirectionDecidesGroup(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 500.00);
        $lookup = $this->lookup();

        $this->assertNotNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1));
        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 2), 'výdaj hledá v Závazcích');
    }

    public function testPayableRequestForOutgoing(): void
    {
        $pay = $this->balanceId('payables');
        $this->seedRequest($pay, '321100', 800.00);

        $item = $this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 2);

        $this->assertNotNull($item);
        $this->assertSame($pay, $item->balance);
        $this->assertSame('321100', $item->accountNumber);
    }

    public function testPayableRequestOnAnyGroupAccountIsFound(): void
    {
        $pay = $this->balanceId('payables');
        $this->seedRequest($pay, '336101', 2500.00);

        $item = $this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 2);

        $this->assertNotNull($item, 'cílem jsou všechny předpisové účty skupiny, ne jen 321');
        $this->assertSame($pay, $item->balance);
        $this->assertSame('336101', $item->accountNumber);
        $this->assertEqualsWithDelta(2500.00, $item->residual, 0.001);
    }

    public function testReceivableRequestOnSettingsPrefixIsFound(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedBalanceAccount($recv, '315', 0);
        $this->seedRequest($recv, '315100', 900.00);

        $item = $this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1);

        $this->assertNotNull($item, 'prefix cíle plyne z nastavení skupiny, ne z kódu');
        $this->assertSame($recv, $item->balance);
        $this->assertSame('315100', $item->accountNumber);
    }

    public function testRequestOutsideGroupRequestPrefixesIsIgnored(): void
    {
        $recv = $this->balanceId('receivables');
        $pay  = $this->balanceId('payables');
        // Dobropisové řádky (modify_sign): dobropis závazku jako předpis 321
        // v Pohledávkách, dobropis pohledávky jako předpis 311 v Závazcích
        // (ten seed skutečně má). Pro směr se nesmí vybrat — dnešní chování.
        $this->seedRequest($recv, '321100', 400.00);
        $this->seedRequest($pay, '311100', 400.00);
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1), 'příjem: 321 není předpisový účet Pohledávek');
        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 2), 'výdaj: dobropisový řádek 311 v Závazcích mimo hru');
    }

    public function testClearingPaymentDoesNotReduceResidual(): void
    {
        $recv = $this->balanceId('receivables');
        $clearing = $this->balanceId('unmatched_payments');
        $this->seedRequest($recv, '311100', 500.00);
        $this->seedPayment($clearing, '261200', 500.00);

        $item = $this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1);

        $this->assertNotNull($item, 'úhrada na clearingu klíč neuzavírá');
        $this->assertEqualsWithDelta(500.00, $item->residual, 0.001);
    }

    public function testCurrencyComparisonIsCaseInsensitive(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 100.00, ['currency' => 'CZK']);

        $this->assertNotNull($this->lookup()->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1));
    }

    public function testExcludingOwnPaymentRestoresResidual(): void
    {
        $recv = $this->balanceId('receivables');
        $this->seedRequest($recv, '311100', 500.00);
        $txId = $this->seedPayment($recv, '311100', 500.00);
        $lookup = $this->lookup();

        $this->assertNull($lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1), 'bez vyloučení uzavřeno');
        $item = $lookup->findOpenRequest(self::PARTNER, self::VS, '', 'czk', 1, 'bankTransaction', $txId);
        $this->assertNotNull($item, 'vlastní úhrada se nepočítá → reaccount je idempotentní');
        $this->assertEqualsWithDelta(500.00, $item->residual, 0.001);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function lookup(): LedgerOpenItemLookup
    {
        $lookup = new LedgerOpenItemLookup();
        $lookup->setDb($this->db->getDibiConnection());
        return $lookup;
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped("DS nemá naseedované saldokonto '{$code}'");
        }
        return (int) $row['id'];
    }

    /** Dočasný řádek nastavení: předpis skupiny na prefixu, kladné částky bez otočení znaménka. */
    private function seedBalanceAccount(int $balance, string $prefix, int $accSide): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_balance_accounts', [
            'balance'        => $balance,
            'account_number' => $prefix,
            'acc_side'       => $accSide,
            'amounts_sign'   => 1,
            'bal_side'       => 0,
            'modify_sign'    => 0,
            'note'           => 'IT lookup ' . $prefix,
            'sort_order'     => 900,
            'docState'       => 40,
            'docStateMain'   => 3,
        ])->execute();
        $id = (int) $dibi->getInsertId();
        $this->seededSettings[] = $id;
        return $id;
    }

    /** @param array<string, mixed> $over */
    private function seedRequest(int $balance, string $account, float $amount, array $over = []): int
    {
        $docId = 970_000_000 + (++$this->seq);
        $this->seededDocs[] = $docId;
        $this->db->getDibiConnection()->insert('economy_accbal_ledger', array_merge([
            'balance'           => $balance,
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => $docId,
            'doc_head'          => $docId,
            'account_number'    => $account,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
            'due_date'          => '2026-06-30',
        ], $over))->execute();
        return $docId;
    }

    /** @param array<string, mixed> $over */
    private function seedPayment(int $balance, string $account, float $amount, array $over = []): int
    {
        $txId = 971_000_000 + (++$this->seq);
        $this->seededTxs[] = $txId;
        $this->db->getDibiConnection()->insert('economy_accbal_ledger', array_merge([
            'balance'           => $balance,
            'bal_side'          => 1,
            'source_kind'       => 'bankTransaction',
            'source_id'         => $txId,
            'bank_transaction'  => $txId,
            'account_number'    => $account,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => $amount,
            'amount_hc'         => $amount,
        ], $over))->execute();
        return $txId;
    }
}
