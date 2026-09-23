<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;

class DocumentValidatorTest extends TestCase
{
    private DocumentValidator $v;

    protected function setUp(): void
    {
        $this->v = new DocumentValidator();
    }

    public function testMissingIssueDateProducesRequiredError(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $paths = array_column($issues, 'path');
        $this->assertContains('dates.issueDate', $paths);
        $required = $this->findByPath($issues, 'dates.issueDate');
        $this->assertSame('error', $required['severity']);
        $this->assertSame('required', $required['code']);
    }

    public function testEmptyRowsProducesRequiredError(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [],
        ]);
        $paths = array_column($issues, 'path');
        $this->assertContains('rows', $paths);
    }

    public function testInvoiceReceivedRequiresSupplier(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $supplierIssue = $this->findByPath($issues, 'supplier');
        $this->assertNotNull($supplierIssue);
        $this->assertSame('error', $supplierIssue['severity']);
    }

    public function testInvoiceIssuedRequiresCustomer(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceIssued',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $customerIssue = $this->findByPath($issues, 'customer');
        $this->assertNotNull($customerIssue);
        $this->assertSame('error', $customerIssue['severity']);
    }

    /** Zálohová faktura vydaná (#79 D1): odběratel povinný jako u vydané faktury. */
    public function testProformaIssuedRequiresCustomer(): void
    {
        $issues = $this->v->validate([
            'docType' => 'proformaIssued',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $customerIssue = $this->findByPath($issues, 'customer');
        $this->assertNotNull($customerIssue);
        $this->assertSame('error', $customerIssue['severity']);

        $issues = $this->v->validate([
            'docType' => 'proformaIssued',
            'customer' => ['name' => 'Odběratel a.s.'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ]);
        $this->assertNull($this->findByPath($issues, 'customer'));
    }

    public function testTotalsMismatchProducesWarningWhenNoVariantFits(): void
    {
        // None of the variants lands within 0.01 — neither base-sum nor
        // base×(1+pct) and there is no vatRecap.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1499.50],
        ]);
        $mismatch = $this->findByCode($issues, 'totals_mismatch');
        $this->assertNotNull($mismatch);
        $this->assertSame('warning', $mismatch['severity']);
        // Both computed variants should appear in the detail string.
        $this->assertStringContainsString('1500', $mismatch['message']); // sumBase
        $this->assertStringContainsString('1815', $mismatch['message']); // sumWithVat = 1500 * 1.21
    }

    public function testTotalsBaseSumWithinToleranceProducesNoWarning(): void
    {
        // Doc without VAT — declared total matches the sum of base prices.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00],
            ],
            'totals' => ['totalAmount' => 1000.005],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsWithVatPerRowProducesNoWarning(): void
    {
        // Typical VAT invoice: AI emits row.totalPrice (base only) and
        // row.vat.pct. Declared totalAmount is with VAT. Variant (2) hits.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1815.00], // 1500 * 1.21
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsByVatRecapProducesNoWarning(): void
    {
        // vatRecap.total breakdown is the authoritative variant. Even when
        // row.totalPrice would not add up, vatRecap matching suppresses the
        // warning.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 800.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 200.00, 'vat' => ['pct' => 15]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 800.00, 'tax' => 168.00, 'total' => 968.00],
                ['vatPct' => 15, 'base' => 200.00, 'tax' => 30.00, 'total' => 230.00],
            ],
            'totals' => ['totalAmount' => 1198.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsNoVatRowsProducesNoWarning(): void
    {
        // Cash receipt with no VAT info at all.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 250.00],
                ['totalPrice' => 100.00],
            ],
            'totals' => ['totalAmount' => 350.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsAtToleranceEdgeProducesNoWarning(): void
    {
        // Diff 0.005 < tolerance 0.01 — should not fire even with a
        // base-only sum hitting just under the boundary.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00],
            ],
            'totals' => ['totalAmount' => 1000.005],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsRoundedToWholeProducesNoWarning(): void
    {
        // FEINKOST scénář: recap dá 1709.05, deklarováno celých 1709.00 —
        // zaokrouhlení celkové částky, warning nesmí vystřelit.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 45.00, 'vat' => ['pct' => 12]],
                ['totalPrice' => 1664.05, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 12, 'base' => 40.18, 'tax' => 4.82, 'total' => 45.00],
                ['vatPct' => 21, 'base' => 1375.25, 'tax' => 288.80, 'total' => 1664.05],
            ],
            'totals' => ['totalAmount' => 1709.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsNonWholeDeclaredWithSmallDiffStillWarns(): void
    {
        // Necelá deklarovaná částka nemůže být zaokrouhlením na celé
        // jednotky — diff 0.05 nad tolerancí musí dál warnovat.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1209.95], // sumWithVat = 1210.00
        ]);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsWholeDeclaredDiffAtLeastOneStillWarns(): void
    {
        // Celá deklarovaná částka, ale diff přesně 1.00 — to už není
        // zaokrouhlení (pásmo je ostře < 1.00).
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1209.00], // sumWithVat = 1210.00
        ]);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsFiveCentDeclaredMatchingRoundedVariantProducesNoWarning(): void
    {
        // SK hotovostní účtenka (total_rounding_mode 5): recap 12,33,
        // deklarováno 12,35 = round5(12,33). Řádky ani base nesedí — chytá
        // to větev pro 0,05 nad vatRecap.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 10.00, 'vat' => ['pct' => 20]],
            ],
            'vatRecap' => [['vatPct' => 20, 'total' => 12.33]],
            'totals' => ['totalAmount' => 12.35],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsOddCentsDeclaredStillWarns(): void
    {
        // 12,36 není násobek 0,05 — rozdíl 0,03 proti recapu musí dál warnovat.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 10.00, 'vat' => ['pct' => 20]],
            ],
            'vatRecap' => [['vatPct' => 20, 'total' => 12.33]],
            'totals' => ['totalAmount' => 12.36],
        ]);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsFiveCentDeclaredNotReproducedByRoundingStillWarns(): void
    {
        // Deklarovaná 12,35 je násobek 0,05, ale round5(12,31) = 12,30 —
        // applier by mód 5 nepřidělil, validátor proto nesmí mlčet (užší
        // podmínka než pásmo < 0,05).
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 10.00, 'vat' => ['pct' => 20]],
            ],
            'vatRecap' => [['vatPct' => 20, 'total' => 12.31]],
            'totals' => ['totalAmount' => 12.35],
        ]);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testVatModeSuspectWarnsWhenDerivationLacksData(): void
    {
        // Řádky v cenách s DPH, mode fromBase, ale chybí recap i totalBase
        // → derivace nemá reference, warning musí vystřelit.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1746.00],
        ]);
        $w = $this->findByCode($issues, 'vat_mode_suspect');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
        $this->assertSame('vat.mode', $w['path']);
    }

    public function testVatModeSuspectSilentWhenDerivationHasRecap(): void
    {
        // S kompletním recapem derivace v applieru koriguje sama —
        // validátor mlčí, jinak by warning dubloval vat_mode_derived.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 1442.98, 'tax' => 303.02, 'total' => 1746.00],
            ],
            'totals' => ['totalAmount' => 1746.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWhenDerivationHasTotals(): void
    {
        // totalBase + totalAmount stačí derivaci (fallback reference).
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1442.98, 'totalAmount' => 1746.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWithoutPositivePct(): void
    {
        // Bez kladné sazby na řádcích není co zdvojit (0 % / bez DPH).
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 0]],
                ['rowKind' => 'item', 'totalPrice' => 100.00],
            ],
            'totals' => ['totalAmount' => 1846.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWhenModeIsFromTotal(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromTotal'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1746.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWhenRowsMatchBaseToo(): void
    {
        // Σ řádků sedí i na totalBase → nerozlišitelné od 0% dokladu.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1000.00, 'totalAmount' => 1000.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testFullValidPayloadProducesNoErrors(): void
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $issues = $this->v->validate($payload);
        $errors = array_filter($issues, static fn($i) => $i['severity'] === 'error');
        $this->assertSame([], $errors, 'Happy fixture should produce no errors: ' . json_encode($errors));
    }

    public function testPartnerDocNumberWarningWhenConfirmingWithoutNumber(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 40],
            // docNumber omitted
        ]);
        $w = $this->findByCode($issues, 'partner_doc_number_missing');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
    }

    public function testTargetState80WithoutImportNumberIsError(): void
    {
        // Parkovací stav 80 je výhradně migrační — mimo import mode chyba.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 80],
        ]);
        $e = $this->findByCode($issues, 'target_state_80_requires_import');
        $this->assertNotNull($e);
        $this->assertSame('error', $e['severity']);
    }

    public function testTargetState80WithImportNumberPasses(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => [
                'targetDocState' => 80,
                'importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'target_state_80_requires_import'));
    }

    public function testPartnerDocNumberNotChalliengedForDraft(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 10],
        ]);
        $this->assertNull($this->findByCode($issues, 'partner_doc_number_missing'));
    }

    public function testRowsRecapMismatchWarnsOnIncompleteRows(): void
    {
        // ARTEX konstelace: model extrahoval 8 z ~57 řádků, recap opsal
        // z dokladu → totals_mismatch mlčí (recap sedí na totalAmount),
        // ale Σ řádků nesedí na Σ základů recapu.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 657.78, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 22082.55, 'tax' => 4637.34, 'total' => 26719.89],
            ],
            'totals' => ['totalBase' => 22082.55, 'totalVat' => 4637.34, 'totalAmount' => 26719.89],
        ]);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'), 'recap sedí na totalAmount, totals check mlčí');
        $w = $this->findByCode($issues, 'rows_recap_mismatch');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
        $this->assertSame('rows', $w['path']);
        $this->assertStringContainsString('7657.78', $w['message']);
        $this->assertStringContainsString('22082.55', $w['message']);
        // Recap je vnitřně konzistentní — D5 nesmí přisadit.
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));
    }

    public function testRowsRecapMatchProducesNoWarning(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 800.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 200.00, 'vat' => ['pct' => 15]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 800.00, 'tax' => 168.00, 'total' => 968.00],
                ['vatPct' => 15, 'base' => 200.00, 'tax' => 30.00, 'total' => 230.00],
            ],
            'totals' => ['totalAmount' => 1198.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapMismatchWarnsForFromTotalRows(): void
    {
        // Řádky v cenách s DPH (fromTotal), ale neúplné — Σ nesedí na
        // Σ celků recapu.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromTotal'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 605.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 1000.00, 'tax' => 210.00, 'total' => 1210.00],
            ],
            'totals' => ['totalAmount' => 1210.00],
        ]);
        $w = $this->findByCode($issues, 'rows_recap_mismatch');
        $this->assertNotNull($w);
        $this->assertStringContainsString('celků', $w['message']);
    }

    public function testRowsRecapFromTotalMatchProducesNoWarning(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromTotal'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1210.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 1000.00, 'tax' => 210.00, 'total' => 1210.00],
            ],
            'totals' => ['totalAmount' => 1210.00],
        ]);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapSkipsWithoutRecapAndTotals(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapSkipsWhenRowSumUnreliable(): void
    {
        // Řádek bez číselného totalPrice → sumItemRows() vrací null,
        // neúplný součet je pro porovnání nespolehlivý.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 500.00, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 22082.55, 'tax' => 4637.34, 'total' => 26719.89],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapWithinToleranceProducesNoWarning(): void
    {
        // Haléřová odchylka per-řádkového zaokrouhlení: diff 0.01 při
        // 3 řádcích (tolerance max(0.02, 0.03)).
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromTotal'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 403.33, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 403.33, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 403.33, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 1000.00, 'tax' => 210.00, 'total' => 1210.00],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapFallsBackToTotalsWithoutRecap(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1000.00, 'totalAmount' => 1210.00],
        ]);
        $w = $this->findByCode($issues, 'rows_recap_mismatch');
        $this->assertNotNull($w);
        $this->assertStringContainsString('základů', $w['message']);
    }

    public function testVatRecapInconsistentWarnsOnBackComputedRecap(): void
    {
        // PLECA konstelace: ceny položek bez DPH, model chybně určil
        // fromTotal a recap dopočítal pozpátku (387 + 81.40 ≠ 468.60).
        // D3 mlčí (Σ řádků sedí na dopočtený total) — chytá D5.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromTotal'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 156.20, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 156.20, 'vat' => ['pct' => 21]],
                ['rowKind' => 'item', 'totalPrice' => 156.20, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 387.00, 'tax' => 81.40, 'total' => 468.60],
            ],
            'totals' => ['totalAmount' => 468.60],
        ]);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
        $w = $this->findByCode($issues, 'vat_recap_inconsistent');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
        $this->assertSame('vatRecap[0]', $w['path']);
        $this->assertStringContainsString('387', $w['message']);
        $this->assertStringContainsString('468.6', $w['message']);
    }

    public function testVatRecapConsistentProducesNoWarning(): void
    {
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 10330.58, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.42, 'total' => 12500.00],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));
    }

    public function testVatRecapInconsistentWarnsOnTaxVsPct(): void
    {
        // base + tax = total sedí, ale daň neodpovídá sazbě.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 387.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 387.00, 'tax' => 60.00, 'total' => 447.00],
            ],
        ]);
        $w = $this->findByCode($issues, 'vat_recap_inconsistent');
        $this->assertNotNull($w);
        $this->assertStringContainsString('sazbě', $w['message']);
    }

    public function testVatRecapSkipsReversePairAndZeroPct(): void
    {
        // Reverse-charge pár a 0% řádek mají vlastní pravidla — aritmetika
        // se u nich nekontroluje, i když by naivně nevyšla.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 1000.00, 'tax' => 210.00, 'total' => 0.00, 'isReversePair' => true],
                ['vatPct' => 0, 'base' => 500.00, 'tax' => 50.00, 'total' => 550.00],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));
    }

    public function testVatRecapToleranceBoundaries(): void
    {
        // base+tax−total přesně 0.02 a tax odchylka přesně max(0.05, …)
        // jsou ještě v toleranci.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 110.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.02],
                ['vatPct' => 21, 'base' => 10.00, 'tax' => 2.15, 'total' => 12.15],
            ],
        ]);
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));

        // Krok za hranici u obou podmínek už warnuje.
        $issues = $this->v->validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 110.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 100.00, 'tax' => 21.00, 'total' => 121.03],
                ['vatPct' => 21, 'base' => 10.00, 'tax' => 2.16, 'total' => 12.16],
            ],
        ]);
        $this->assertNotNull($this->findByPath($issues, 'vatRecap[0]'));
        $this->assertNotNull($this->findByPath($issues, 'vatRecap[1]'));
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    private function findByPath(array $issues, string $path): ?array
    {
        foreach ($issues as $issue) {
            if ($issue['path'] === $path) return $issue;
        }
        return null;
    }

    /**
     * @param array<int, array{severity: string, path: string, code: string, message: string}> $issues
     * @return array{severity: string, path: string, code: string, message: string}|null
     */
    private function findByCode(array $issues, string $code): ?array
    {
        foreach ($issues as $issue) {
            if ($issue['code'] === $code) return $issue;
        }
        return null;
    }
}
