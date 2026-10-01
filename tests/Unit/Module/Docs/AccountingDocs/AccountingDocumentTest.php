<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\AccountingDocs;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\AccountingDocs\AccountingDocument;

/**
 * Subclass cmnbkp — vyrovnanost, nepovinný hlavičkový partner, součty z řádků.
 * Bez DB: validate s db=null přeskočí kontrolu vlastní firmy; resolveRowsForCompute
 * čte řádky z $data['rows']. Testy samovyvažujících operací (FX) injektují
 * ConfigRuntime s docs.core.rowOperations — bez configu vlajka selfBalancing
 * vyjde false a chování je původní.
 */
class AccountingDocumentTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir === null) {
            return;
        }
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
        $this->tmpDir = null;
    }

    /** Testovací subclass zpřístupňující protected sumTotals + applyDomesticAmounts. */
    private function doc(): AccountingDocument
    {
        return new class extends AccountingDocument {
            public function sumTotalsPub(array &$data, array $rows): void
            {
                $this->sumTotals($data, [], $rows);
            }

            public function applyDomesticAmountsPub(array &$data, array &$rows, array $recap): void
            {
                $this->applyDomesticAmounts($data, $rows, $recap);
            }
        };
    }

    /**
     * Dokument s injektovaným configem — rowOperations s FX operací
     * (selfBalancing: 1) a běžnými kontačními operacemi (vzor
     * DocRowOperationsValidateTest::buildConfig).
     */
    private function docWithConfig(): AccountingDocument
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_accdoc_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);

        $items = [
            'docs.core.rowOperations' => [
                'acc.record' => [
                    'name' => 'Účetní zápis', 'rowSide' => 1,
                    'rowAccount' => 'direct',
                    'docTypes' => ['cmnbkp' => ['order' => 100]],
                ],
                'acc.fxLossReceivable' => [
                    'name' => 'Kurzová ztráta — pohledávka',
                    'rowSide' => 0, 'selfBalancing' => 1,
                    'docTypes' => ['cmnbkp' => ['order' => 500]],
                ],
                'asset.depreciation' => [
                    'name' => 'Odpis majetku', 'rowSide' => 1, 'rowAccount' => 'direct',
                    'rowAsset' => 1, 'system' => 1,
                    'docTypes' => ['cmnbkp' => ['order' => 930]],
                ],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $items]),
        );

        $doc = $this->doc();
        $doc->setConfig(ConfigRuntime::load($this->tmpDir, 'cs'));
        return $doc;
    }

    /**
     * Hlavička ve stavu 40 s povinnými poli; jen řádky se mění per test.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function head40(array $rows): array
    {
        return [
            'doc_type'        => 'cmnbkp',
            'docState'        => 40,
            'number_series'   => 1,
            'issue_date'      => '2026-06-10',
            'accounting_date' => '2026-06-10',
            'rows'            => $rows,
        ];
    }

    /** @return list<string> */
    private function codes(\Shipard\Core\Document\ValidationResult $r): array
    {
        return array_map(fn($e) => $e->code, $r->getErrors());
    }

    /** @return list<string> */
    private function columns(\Shipard\Core\Document\ValidationResult $r): array
    {
        return array_map(fn($e) => $e->column, $r->getErrors());
    }

    public function testBalancedDocWithoutHeadPartnerPasses(): void
    {
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 0, 'account' => 10, 'total_price' => 1000.0],
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 1, 'account' => 20, 'total_price' => 1000.0],
        ]);

        $result = $this->doc()->validate($data);

        // Vyrovnáno → žádná unbalanced chyba; partner nepovinný → žádná partner chyba.
        $this->assertNotContains('unbalanced', $this->codes($result));
        $this->assertNotContains('partner', $this->columns($result));
    }

    public function testUnbalancedDocFailsAt40(): void
    {
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 0, 'account' => 10, 'total_price' => 1000.0],
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 1, 'account' => 20, 'total_price' => 600.0],
        ]);

        $result = $this->doc()->validate($data);

        $this->assertContains('unbalanced', $this->codes($result));
    }

    public function testRowLevelRequirements(): void
    {
        $data = $this->head40([
            // chybí acc_side, acc.record bez účtu, nulová částka
            ['row_kind' => 1, 'operation' => 'acc.record', 'total_price' => 0.0],
            // acc.item bez položky
            ['row_kind' => 1, 'operation' => 'acc.item', 'acc_side' => 1, 'total_price' => 100.0],
        ]);

        $result = $this->doc()->validate($data);
        $codes = $this->codes($result);

        $this->assertContains('acc_side_required', $codes);
        $this->assertContains('account_required', $codes);
        $this->assertContains('amount_required', $codes);
        $this->assertContains('item_required', $codes);
    }

    // ── Samovyvažující operace (FX, selfBalancing: 1) ───────────────────────

    public function testSelfBalancingFxRowAloneIsBalanced(): void
    {
        // FX řádek stranu nenese (rowSide: 0) — obě strany účtují kroky
        // předpisu, kontrola ho počítá do MD i DAL.
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.fxLossReceivable', 'total_price' => 50806.73],
        ]);

        $result = $this->docWithConfig()->validate($data);

        $this->assertNotContains('unbalanced', $this->codes($result));
        $this->assertNotContains('acc_side_required', $this->codes($result));
    }

    public function testSelfBalancingFxRowIgnoresStoredAccSide(): void
    {
        // Migrace může poslat acc_side ze zdroje — vlajka má přednost,
        // řádek se dál počítá do obou stran.
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.fxLossReceivable', 'acc_side' => 1, 'total_price' => 100.0],
        ]);

        $result = $this->docWithConfig()->validate($data);

        $this->assertNotContains('unbalanced', $this->codes($result));
    }

    public function testSelfBalancingFxRowCombinesWithContationRows(): void
    {
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 0, 'account' => 10, 'total_price' => 1000.0],
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 1, 'account' => 20, 'total_price' => 1000.0],
            ['row_kind' => 1, 'operation' => 'acc.fxLossReceivable', 'total_price' => 500.0],
        ]);

        $result = $this->docWithConfig()->validate($data);

        $this->assertNotContains('unbalanced', $this->codes($result));
    }

    public function testSelfBalancingFxRowZeroAmountFails(): void
    {
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.fxLossReceivable', 'total_price' => 0.0],
        ]);

        $result = $this->docWithConfig()->validate($data);

        $this->assertContains('amount_required', $this->codes($result));
    }

    public function testAssetRowsNeedAccountAndServiceMarker(): void
    {
        // Operace s přímým účtem (rowAccount: direct) musí mít účet — nejen
        // acc.record; systémová operace navíc projde jen s markerem služby.
        $rows = [
            ['row_kind' => 1, 'operation' => 'asset.depreciation', 'asset' => 5, 'acc_side' => 0, 'total_price' => 500.0],
            ['row_kind' => 1, 'operation' => 'asset.depreciation', 'asset' => 5, 'acc_side' => 1, 'account' => 20, 'total_price' => 500.0],
        ];

        $manual = $this->head40($rows);
        $this->assertContains('system_operation', $this->codes($this->docWithConfig()->validate($manual)));

        $service = $this->head40($rows) + ['_systemOperations' => true];
        $result = $this->docWithConfig()->validate($service);
        $this->assertNotContains('system_operation', $this->codes($result));
        $this->assertContains('account_required', $this->codes($result));
        $this->assertContains('rows.0.account', $this->columns($result));
        $this->assertNotContains('unbalanced', $this->codes($result));
    }

    public function testOneSidedAccRecordStillFailsWithConfig(): void
    {
        // Kontrola se vlajkou nesmí otupit: jednostranná kontace bez
        // protistrany dál padá na unbalanced.
        $data = $this->head40([
            ['row_kind' => 1, 'operation' => 'acc.record', 'acc_side' => 1, 'account' => 20, 'total_price' => 600.0],
        ]);

        $result = $this->docWithConfig()->validate($data);

        $this->assertContains('unbalanced', $this->codes($result));
    }

    public function testSumTotalsCountsSelfBalancingOnceRegardlessOfAccSide(): void
    {
        // Bez vlajky by řádek s acc_side = 1 do Σ MD nespadl → total_amount 0.
        $doc = $this->docWithConfig();
        $data = [];
        $rows = [
            ['row_kind' => 1, 'operation' => 'acc.fxLossReceivable', 'acc_side' => 1, 'total_price' => 50806.73],
        ];

        $doc->sumTotalsPub($data, $rows);

        $this->assertSame(50806.73, $data['total_amount']);
    }

    public function testSumTotalsFromDebitRows(): void
    {
        $data = [];
        $rows = [
            ['row_kind' => 1, 'acc_side' => 0, 'total_price' => 1000.0],
            ['row_kind' => 1, 'acc_side' => 0, 'total_price' => 250.0],
            ['row_kind' => 1, 'acc_side' => 1, 'total_price' => 1250.0],
        ];

        $this->doc()->sumTotalsPub($data, $rows);

        $this->assertSame(1250.0, $data['total_amount']);
        $this->assertSame(1250.0, $data['total_base']);
        $this->assertSame(0.0, $data['total_vat']);
        $this->assertSame(0.0, $data['total_rounding']);
    }

    /**
     * cmnbkp má hook headTotalsIncludeRowsOutsideRecap()=false, takže Z1
     * fallback v applyDomesticAmounts nesmí sečíst obě strany kontace do
     * total_base_dom (base-class by z prázdného recapu přičetl všechny řádky).
     */
    public function testDomesticAmountsDoNotIncludeRowsOutsideRecap(): void
    {
        $data = ['total_amount' => 1000.0, 'exchange_rate' => 1.0];
        // Dvě kontační strany, obě s vat_base — kdyby se fallback aplikoval,
        // total_base_dom by bylo 2000 (MD+DAL), ne 0.
        $rows = [
            ['row_kind' => 1, 'acc_side' => 0, 'vat_base' => 1000.0, 'vat_amount' => 0.0],
            ['row_kind' => 1, 'acc_side' => 1, 'vat_base' => 1000.0, 'vat_amount' => 0.0],
        ];
        $this->doc()->applyDomesticAmountsPub($data, $rows, []);

        $this->assertSame(0.0, $data['total_base_dom']);
        $this->assertSame(0.0, $data['total_vat_dom']);
    }
}
