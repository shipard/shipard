<?php

declare(strict_types=1);

namespace Shipard\Tests\Integration\Accbal;

use Shipard\Module\Economy\Accbal\LedgerViewer;
use Shipard\Tests\Integration\IntegrationTestCase;

/**
 * Viewer saldo pohybů nad reálným DS: filtr období zužuje seznam i footer,
 * akce detailu „Otevřít řádek deníku" nese období pohybu (deník startuje
 * s výchozím aktuálním rokem, cílový řádek ze staršího roku by jinak zmizel
 * ze seznamu). Fixtura = jeden předpis s odkazem na deník.
 */
class LedgerViewerTest extends IntegrationTestCase
{
    private const PARTNER   = 990010;
    private const VS        = 'IT-LV-1';
    private const ACC_DATE  = '2026-06-10';
    private const DOC_HEAD  = 976_000_001;

    private int $fiscalYear = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $fy = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE date_begin <= %s AND date_end >= %s LIMIT 1',
            self::ACC_DATE, self::ACC_DATE,
        );
        if ($fy === null) {
            $this->markTestSkipped('DS nemá fiskální rok pro ' . self::ACC_DATE);
        }
        $this->fiscalYear = (int) $fy['id'];
    }

    protected function onTearDown(): void
    {
        $this->db->getDibiConnection()->delete('economy_accbal_ledger')->where('doc_head = %i', self::DOC_HEAD)->execute();
    }

    private function seedRequest(): int
    {
        $dibi = $this->db->getDibiConnection();
        $dibi->insert('economy_accbal_ledger', [
            'balance'           => $this->balanceId('receivables'),
            'bal_side'          => 0,
            'source_kind'       => 'doc',
            'source_id'         => self::DOC_HEAD,
            'doc_head'          => self::DOC_HEAD,
            'journal_row'       => 1,
            'account_number'    => '311100',
            'fiscal_year'       => $this->fiscalYear,
            'partner'           => self::PARTNER,
            'payment_reference' => self::VS,
            'currency'          => 'czk',
            'home_currency'     => 'czk',
            'amount'            => 750.00,
            'amount_hc'         => 750.00,
        ])->execute();
        return (int) $dibi->getInsertId();
    }

    public function testPeriodFilterNarrowsRowsAndFooter(): void
    {
        $this->seedRequest();
        $viewer = $this->viewer();
        $base = [['id' => 'viewGroup', 'value' => 'receivables'], ['id' => 'payment_reference', 'value' => self::VS]];

        $rows = $viewer->selectRows(null, [...$base, ['id' => 'fiscal_year', 'value' => (string) $this->fiscalYear]], 0);
        $this->assertCount(1, $rows);
        $footer = $viewer->renderGridFooter(null, [...$base, ['id' => 'fiscal_year', 'value' => (string) $this->fiscalYear]]);
        $this->assertSame('750,00 CZK', $footer['case_residual'][1]['text']);

        $other = $this->db->fetchRow(
            'SELECT id FROM economy_codebooks_fiscal_years WHERE id <> %i AND docState != 90 ORDER BY id LIMIT 1',
            $this->fiscalYear,
        );
        if ($other !== null) {
            $this->assertSame([], $viewer->selectRows(null, [...$base, ['id' => 'fiscal_year', 'value' => (string) $other['id']]], 0));
        }
    }

    public function testJournalActionCarriesPeriodOfMovement(): void
    {
        $id = $this->seedRequest();

        $detail = $this->viewer()->renderDetail($id);

        $actions = array_column($detail['actions'], null, 'id');
        $this->assertSame('economy.accounting.journal', $actions['open_journal']['viewerId']);
        $this->assertSame(1, $actions['open_journal']['recordId']);
        $this->assertSame(['fiscal_year' => (string) $this->fiscalYear], $actions['open_journal']['filters']);
        $this->assertArrayNotHasKey('filters', $actions['open_doc'], 'doklady výchozí filtr období nemají');
    }

    private function viewer(): LedgerViewer
    {
        $viewer = new LedgerViewer($this->db, 'economy_accbal_ledger');
        $viewer->setLanguage('cs');
        return $viewer;
    }

    private function balanceId(string $code): int
    {
        $row = $this->db->fetchRow('SELECT id FROM economy_accbal_balances WHERE code = %s', $code);
        if ($row === null) {
            $this->markTestSkipped('DS nemá saldokonto ' . $code);
        }
        return (int) $row['id'];
    }
}
