<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\Exchange\Document;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Accounting\JournalDimension;
use Shipard\Core\Accounting\JournalDimensionSet;
use Shipard\Module\Core\Exchange\Document\DocumentValidator;
use Shipard\Module\Core\Exchange\Document\Interpret\PaperChecks;
use Shipard\Tests\Fixtures\Core\Database\ModuleTables;

class DocumentValidatorTest extends TestCase
{
    private DocumentValidator $v;

    protected function setUp(): void
    {
        $this->v = new DocumentValidator();
    }

    /** Sada dimenzí jako na DS: středisko s klíčem v definici tabulky, projekt bez něj. */
    private function dimensions(): JournalDimensionSet
    {
        return new JournalDimensionSet([
            JournalDimension::fromArray([
                'id' => 'costCenter', 'rowColumn' => 'cost_center', 'headColumn' => 'cost_center',
                'journalColumn' => 'cost_center', 'table' => 'economy_codebooks_cost_centers',
                'name' => 'Středisko',
            ]),
            JournalDimension::fromArray([
                'id' => 'project', 'rowColumn' => 'project', 'journalColumn' => 'project',
                'table' => 'x_projects', 'name' => 'Projekt',
            ]),
        ]);
    }

    public function testUnknownDimensionOrDimensionWithoutExchangeKeyIsAnError(): void
    {
        // #110 T2: překlep v klíči nesmí hodnotu tiše zahodit.
        $validator = new DocumentValidator($this->dimensions(), ModuleTables::load('economy_codebooks_cost_centers'));
        $issues = PaperChecks::validate([
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-04-15'],
            'dimensions' => ['costCenter' => 'S01', 'costcenter' => 'S01'],
            'rows' => [
                ['rowKind' => 'item', 'dimensions' => ['project' => 'P1']],
                ['rowKind' => 'item', 'dimensions' => ['costCenter' => 'V01']],
            ],
        ], $validator);

        $unknown = array_values(array_filter($issues, static fn(array $i): bool => $i['code'] === 'dimension_unknown'));
        $this->assertSame(['dimensions.costcenter', 'rows.0.dimensions.project'], array_column($unknown, 'path'));
        $this->assertSame(['error', 'error'], array_column($unknown, 'severity'));
        $this->assertStringContainsString('exchangeKey', $unknown[1]['message']);
    }

    public function testWithoutDimensionSetDimensionsAreNotChecked(): void
    {
        // Preflight sady bez DS: neznámé id odhalí až apply.
        $issues = PaperChecks::validate([
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-04-15'],
            'dimensions' => ['whatever' => 'X'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $this->assertNotContains('dimension_unknown', array_column($issues, 'code'));
    }

    public function testMissingIssueDateProducesRequiredError(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $paths = array_column($issues, 'path');
        $this->assertContains('dates.issueDate', $paths);
        $required = $this->findByPath($issues, 'dates.issueDate');
        $this->assertSame('error', $required['severity']);
        $this->assertSame('required', $required['code']);
    }

    public function testEmptyRowsProducesRequiredError(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'Vendor'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [],
        ], $this->v);
        $paths = array_column($issues, 'path');
        $this->assertContains('rows', $paths);
    }

    public function testInvoiceReceivedRequiresSupplier(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $supplierIssue = $this->findByPath($issues, 'supplier');
        $this->assertNotNull($supplierIssue);
        $this->assertSame('error', $supplierIssue['severity']);
    }

    public function testInvoiceIssuedRequiresCustomer(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceIssued',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $customerIssue = $this->findByPath($issues, 'customer');
        $this->assertNotNull($customerIssue);
        $this->assertSame('error', $customerIssue['severity']);
    }

    /** Zálohová faktura vydaná (#79 D1): odběratel povinný jako u vydané faktury. */
    public function testProformaIssuedRequiresCustomer(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'proformaIssued',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $customerIssue = $this->findByPath($issues, 'customer');
        $this->assertNotNull($customerIssue);
        $this->assertSame('error', $customerIssue['severity']);

        $issues = PaperChecks::validate([
            'docType' => 'proformaIssued',
            'customer' => ['name' => 'Odběratel a.s.'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $this->assertNull($this->findByPath($issues, 'customer'));
    }

    /** Zálohová faktura přijatá (#106 D1): dodavatel povinný jako u přijaté faktury. */
    public function testProformaReceivedRequiresSupplier(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'proformaReceived',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $supplierIssue = $this->findByPath($issues, 'supplier');
        $this->assertNotNull($supplierIssue);
        $this->assertSame('error', $supplierIssue['severity']);
        $this->assertSame('required', $supplierIssue['code']);

        $issues = PaperChecks::validate([
            'docType' => 'proformaReceived',
            'supplier' => ['name' => 'Dodavatel a.s.'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
        ], $this->v);
        $this->assertNull($this->findByPath($issues, 'supplier'));
    }

    /** Zálohová faktura přijatá nese číslo dokladu dodavatele jako přijatá faktura. */
    public function testProformaReceivedWarnsAboutMissingPartnerDocNumber(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'proformaReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 40],
        ], $this->v);
        $w = $this->findByCode($issues, 'partner_doc_number_missing');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);

        $issues = PaperChecks::validate([
            'docType' => 'proformaIssued',
            'customer' => ['name' => 'O'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 40],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'partner_doc_number_missing'), 'vydaná strana číslo dodavatele nemá');
    }

    /** Import s vnuceným číslem je pokyn (#166 D22) — v návrhu chyba. */
    public function testImportNumberRequiresInstructionMode(): void
    {
        $canonical = [
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [],
            'applyOptions' => ['importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42]],
        ];

        $issue = $this->findByCode(PaperChecks::validate($canonical, $this->v), 'import_requires_instruction');
        $this->assertNotNull($issue);
        $this->assertSame(['error', 'applyOptions.importNumber'], [$issue['severity'], $issue['path']]);

        $this->assertNull($this->findByCode($this->v->validate($canonical), 'import_requires_instruction'));
        unset($canonical['applyOptions']);
        $this->assertNull($this->findByCode(PaperChecks::validate($canonical, $this->v), 'import_requires_instruction'));
    }

    /** Pokyn (#166 D19) čísla předlohy neposuzuje — heuristiky běží jen v návrhu; výchozí režim je pokyn. */
    public function testHeuristicChecksRunOnlyInProposalMode(): void
    {
        $canonical = [
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [['vatPct' => 21, 'base' => 900.00, 'tax' => 1.00, 'total' => 950.00]],
            'totals' => ['totalAmount' => 1499.50],
        ];
        $heuristics = ['totals_mismatch', 'rows_recap_mismatch', 'vat_mode_suspect', 'vat_recap_inconsistent'];

        $proposal = array_column(PaperChecks::validate($canonical, $this->v), 'code');
        $this->assertNotSame([], array_intersect($heuristics, $proposal));

        $this->assertSame([], array_intersect($heuristics, array_column($this->v->validate($canonical), 'code')));
        $this->assertSame([], array_intersect($heuristics, array_column($this->v->validate($canonical), 'code')));
    }

    public function testTotalsMismatchProducesWarningWhenNoVariantFits(): void
    {
        // None of the variants lands within 0.01 — neither base-sum nor
        // base×(1+pct) and there is no vatRecap.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1499.50],
        ], $this->v);
        $mismatch = $this->findByCode($issues, 'totals_mismatch');
        $this->assertNotNull($mismatch);
        $this->assertSame('warning', $mismatch['severity']);
        // Both computed variants should appear in the detail string.
        $this->assertStringContainsString('1500', $mismatch['message']); // sumBase
        $this->assertStringContainsString('1815', $mismatch['message']); // sumWithVat = 1500 * 1.21
    }

    public function testTotalsOfAccountingDocumentCountDebitSideOnly(): void
    {
        // Kontace: MD 1 000 + 300 proti D 1 300 — celková částka dokladu je
        // obrat strany MD (AccountingDocument), ne součet obou stran (2 600).
        $issues = PaperChecks::validate([
            'docType' => 'accountingDocument',
            'dates' => ['issueDate' => '2026-04-30'],
            'vat' => ['mode' => 'none'],
            'rows' => [
                ['operation' => 'acc.record', 'account' => '521100', 'accSide' => 'debit', 'totalPrice' => 1000.00],
                ['operation' => 'acc.record', 'account' => '524100', 'accSide' => 'debit', 'totalPrice' => 300.00],
                ['operation' => 'acc.record', 'account' => '331100', 'accSide' => 'credit', 'totalPrice' => 1300.00],
            ],
            'totals' => ['totalAmount' => 1300.00],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsBaseSumWithinToleranceProducesNoWarning(): void
    {
        // Doc without VAT — declared total matches the sum of base prices.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00],
            ],
            'totals' => ['totalAmount' => 1000.005],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsWithVatPerRowProducesNoWarning(): void
    {
        // Typical VAT invoice: AI emits row.totalPrice (base only) and
        // row.vat.pct. Declared totalAmount is with VAT. Variant (2) hits.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
                ['totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1815.00], // 1500 * 1.21
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsByVatRecapProducesNoWarning(): void
    {
        // vatRecap.total breakdown is the authoritative variant. Even when
        // row.totalPrice would not add up, vatRecap matching suppresses the
        // warning.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsNoVatRowsProducesNoWarning(): void
    {
        // Cash receipt with no VAT info at all.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 250.00],
                ['totalPrice' => 100.00],
            ],
            'totals' => ['totalAmount' => 350.00],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsAtToleranceEdgeProducesNoWarning(): void
    {
        // Diff 0.005 < tolerance 0.01 — should not fire even with a
        // base-only sum hitting just under the boundary.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00],
            ],
            'totals' => ['totalAmount' => 1000.005],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsRoundedToWholeProducesNoWarning(): void
    {
        // FEINKOST scénář: recap dá 1709.05, deklarováno celých 1709.00 —
        // zaokrouhlení celkové částky, warning nesmí vystřelit.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsNonWholeDeclaredWithSmallDiffStillWarns(): void
    {
        // Necelá deklarovaná částka nemůže být zaokrouhlením na celé
        // jednotky — diff 0.05 nad tolerancí musí dál warnovat.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1209.95], // sumWithVat = 1210.00
        ], $this->v);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsWholeDeclaredDiffAtLeastOneStillWarns(): void
    {
        // Celá deklarovaná částka, ale diff přesně 1.00 — to už není
        // zaokrouhlení (pásmo je ostře < 1.00).
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1209.00], // sumWithVat = 1210.00
        ], $this->v);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsFiveCentDeclaredMatchingRoundedVariantProducesNoWarning(): void
    {
        // SK hotovostní účtenka (total_rounding_mode 5): recap 12,33,
        // deklarováno 12,35 = round5(12,33). Řádky ani base nesedí — chytá
        // to větev pro 0,05 nad vatRecap.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 10.00, 'vat' => ['pct' => 20]],
            ],
            'vatRecap' => [['vatPct' => 20, 'total' => 12.33]],
            'totals' => ['totalAmount' => 12.35],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsOddCentsDeclaredStillWarns(): void
    {
        // 12,36 není násobek 0,05 — rozdíl 0,03 proti recapu musí dál warnovat.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 10.00, 'vat' => ['pct' => 20]],
            ],
            'vatRecap' => [['vatPct' => 20, 'total' => 12.33]],
            'totals' => ['totalAmount' => 12.36],
        ], $this->v);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testTotalsFiveCentDeclaredNotReproducedByRoundingStillWarns(): void
    {
        // Deklarovaná 12,35 je násobek 0,05, ale round5(12,31) = 12,30 —
        // applier by mód 5 nepřidělil, validátor proto nesmí mlčet (užší
        // podmínka než pásmo < 0,05).
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['totalPrice' => 10.00, 'vat' => ['pct' => 20]],
            ],
            'vatRecap' => [['vatPct' => 20, 'total' => 12.31]],
            'totals' => ['totalAmount' => 12.35],
        ], $this->v);
        $this->assertNotNull($this->findByCode($issues, 'totals_mismatch'));
    }

    public function testVatModeSuspectWarnsWhenDerivationLacksData(): void
    {
        // Řádky v cenách s DPH, mode fromBase, ale chybí recap i totalBase
        // → derivace nemá reference, warning musí vystřelit.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1746.00],
        ], $this->v);
        $w = $this->findByCode($issues, 'vat_mode_suspect');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
        $this->assertSame('vat.mode', $w['path']);
    }

    public function testVatModeSuspectSilentWhenDerivationHasRecap(): void
    {
        // S kompletním recapem derivace v applieru koriguje sama —
        // validátor mlčí, jinak by warning dubloval vat_mode_derived.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWhenDerivationHasTotals(): void
    {
        // totalBase + totalAmount stačí derivaci (fallback reference).
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1442.98, 'totalAmount' => 1746.00],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWithoutPositivePct(): void
    {
        // Bez kladné sazby na řádcích není co zdvojit (0 % / bez DPH).
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 0]],
                ['rowKind' => 'item', 'totalPrice' => 100.00],
            ],
            'totals' => ['totalAmount' => 1846.00],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWhenModeIsFromTotal(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromTotal'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1746.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalAmount' => 1746.00],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testVatModeSuspectSilentWhenRowsMatchBaseToo(): void
    {
        // Σ řádků sedí i na totalBase → nerozlišitelné od 0% dokladu.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 1000.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1000.00, 'totalAmount' => 1000.00],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_mode_suspect'));
    }

    public function testFullValidPayloadProducesNoErrors(): void
    {
        $payload = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../../../Fixtures/Exchange/invoiceReceived_happy.json'),
            true,
        );
        $issues = PaperChecks::validate($payload, $this->v);
        $errors = array_filter($issues, static fn($i) => $i['severity'] === 'error');
        $this->assertSame([], $errors, 'Happy fixture should produce no errors: ' . json_encode($errors));
    }

    public function testPartnerDocNumberWarningWhenConfirmingWithoutNumber(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 40],
            // docNumber omitted
        ], $this->v);
        $w = $this->findByCode($issues, 'partner_doc_number_missing');
        $this->assertNotNull($w);
        $this->assertSame('warning', $w['severity']);
    }

    public function testTargetState80WithoutImportNumberIsError(): void
    {
        // Parkovací stav 80 je výhradně migrační — mimo import mode chyba.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 80],
        ], $this->v);
        $e = $this->findByCode($issues, 'target_state_80_requires_import');
        $this->assertNotNull($e);
        $this->assertSame('error', $e['severity']);
    }

    public function testTargetState80WithImportNumberPasses(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => [
                'targetDocState' => 80,
                'importNumber' => ['docNumber' => '2024-0042', 'sequenceNumber' => 42],
            ],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'target_state_80_requires_import'));
    }

    // ── source.ref — klíč idempotence (#166 D46) ─────────────────────────────

    public function testSourceRefWithoutKindIsAnErrorInBothModes(): void
    {
        $base = [
            'docType' => 'invoiceIssued',
            'customer' => ['name' => 'Z'],
            'dates' => ['issueDate' => '2026-10-01'],
            'rows' => [['rowKind' => 'item']],
        ];
        // Pokyn i kontroly papírové podoby (návrh).
        foreach ([
            fn (array $canonical): array => $this->v->validate($canonical),
            fn (array $canonical): array => PaperChecks::validate($canonical, $this->v),
        ] as $validate) {
            $e = $this->findByCode($validate($base + ['source' => ['ref' => 'A-1']]), 'source_kind_missing');
            $this->assertNotNull($e);
            $this->assertSame('error', $e['severity']);
            $this->assertSame('source.ref', $e['path']);

            $this->assertNull($this->findByCode($validate($base + ['source' => ['kind' => 'api', 'ref' => 'A-1']]), 'source_kind_missing'));
            $this->assertNull($this->findByCode($validate($base + ['source' => ['kind' => 'api']]), 'source_kind_missing'));
            $this->assertNull($this->findByCode($validate($base + ['source' => ['ref' => null]]), 'source_kind_missing'));
        }
    }

    // ── applyOptions.replaceConcept (#110 D24, Q2) ───────────────────────────

    public function testReplaceConceptRequiresDraftTargetStateAndNoImportNumber(): void
    {
        $base = [
            'docType' => 'invoiceIssued',
            'customer' => ['name' => 'Z'],
            'dates' => ['issueDate' => '2026-10-01'],
            'rows' => [['rowKind' => 'item']],
        ];
        $issues = PaperChecks::validate($base + ['applyOptions' => ['replaceConcept' => 605, 'targetDocState' => 40]], $this->v);
        $e = $this->findByCode($issues, 'replace_concept_requires_draft');
        $this->assertNotNull($e);
        $this->assertSame('error', $e['severity']);
        $this->assertSame('applyOptions.targetDocState', $e['path']);

        $issues = PaperChecks::validate($base + ['applyOptions' => ['replaceConcept' => 605, 'importNumber' => ['docNumber' => 'X', 'sequenceNumber' => 1]]], $this->v);
        $this->assertNotNull($this->findByCode($issues, 'replace_concept_with_import'));

        $issues = PaperChecks::validate($base + ['applyOptions' => ['replaceConcept' => 605, 'targetDocState' => 10]], $this->v);
        $this->assertNull($this->findByCode($issues, 'replace_concept_requires_draft'));
        $this->assertNull($this->findByCode($issues, 'replace_concept_with_import'));
        $issues = PaperChecks::validate($base + ['applyOptions' => ['replaceConcept' => 605]], $this->v);
        $this->assertNull($this->findByCode($issues, 'replace_concept_requires_draft'));
    }

    public function testPartnerDocNumberNotChalliengedForDraft(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item']],
            'applyOptions' => ['targetDocState' => 10],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'partner_doc_number_missing'));
    }

    public function testRowsRecapMismatchWarnsOnIncompleteRows(): void
    {
        // ARTEX konstelace: model extrahoval 8 z ~57 řádků, recap opsal
        // z dokladu → totals_mismatch mlčí (recap sedí na totalAmount),
        // ale Σ řádků nesedí na Σ základů recapu.
        $issues = PaperChecks::validate([
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
        ], $this->v);
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
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapMismatchWarnsForFromTotalRows(): void
    {
        // Řádky v cenách s DPH (fromTotal), ale neúplné — Σ nesedí na
        // Σ celků recapu.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $w = $this->findByCode($issues, 'rows_recap_mismatch');
        $this->assertNotNull($w);
        $this->assertStringContainsString('celků', $w['message']);
    }

    public function testRowsRecapFromTotalMatchProducesNoWarning(): void
    {
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapSkipsWithoutRecapAndTotals(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapSkipsWhenRowSumUnreliable(): void
    {
        // Řádek bez číselného totalPrice → sumItemRows() vrací null,
        // neúplný součet je pro porovnání nespolehlivý.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapWithinToleranceProducesNoWarning(): void
    {
        // Haléřová odchylka per-řádkového zaokrouhlení: diff 0.01 při
        // 3 řádcích (tolerance max(0.02, 0.03)).
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'));
    }

    public function testRowsRecapFallsBackToTotalsWithoutRecap(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['mode' => 'fromBase'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 500.00, 'vat' => ['pct' => 21]],
            ],
            'totals' => ['totalBase' => 1000.00, 'totalAmount' => 1210.00],
        ], $this->v);
        $w = $this->findByCode($issues, 'rows_recap_mismatch');
        $this->assertNotNull($w);
        $this->assertStringContainsString('základů', $w['message']);
    }

    public function testVatRecapInconsistentWarnsOnBackComputedRecap(): void
    {
        // PLECA konstelace: ceny položek bez DPH, model chybně určil
        // fromTotal a recap dopočítal pozpátku (387 + 81.40 ≠ 468.60).
        // D3 mlčí (Σ řádků sedí na dopočtený total) — chytá D5.
        $issues = PaperChecks::validate([
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
        ], $this->v);
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
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 10330.58, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 10330.58, 'tax' => 2169.42, 'total' => 12500.00],
            ],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));
    }

    /**
     * Faktura za služby z EU ve tvaru dumpu (samovyměření cz-217 s párem
     * cz-207 `isReversePair`): pár nese tentýž základ podruhé a primární
     * řádek má celkem = základ (daň se dodavateli neplatí) — ani jedna
     * kontrola nesmí varovat, stejně jako `DocDocument::validateDeclaredRecap`.
     */
    public function testSelfAssessedRecapPairIsSkippedInBothChecks(): void
    {
        $issues = PaperChecks::validate($this->euServicesDumpDocument(), $this->v);
        $this->assertNull($this->findByCode($issues, 'rows_recap_mismatch'), 'párový řádek do součtu základů nepatří');
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'), 'samovyměření: celkem = základ');
        $this->assertNull($this->findByCode($issues, 'totals_mismatch'));
    }

    /** Pár kryje jen „celkem = základ“ — špatná daň primárního řádku varuje dál, pár sám nikdy. */
    public function testSelfAssessedRecapStillChecksTaxAgainstRate(): void
    {
        $doc = $this->euServicesDumpDocument();
        $doc['vatRecap'][0]['tax'] = 12.00;
        $doc['vatRecap'][1]['tax'] = 12.00;
        $doc['vatRecap'][1]['total'] = 200.00;
        $issues = PaperChecks::validate($doc, $this->v);
        $w = $this->findByCode($issues, 'vat_recap_inconsistent');
        $this->assertNotNull($w);
        $this->assertSame('vatRecap[0]', $w['path']);
        $this->assertStringContainsString('neodpovídá sazbě 21', $w['message']);
        $this->assertStringNotContainsString('≠ celkem', $w['message'], 'celkem = základ je u samovyměření v pořádku');
        $this->assertNull($this->findByPath($issues, 'vatRecap[1]'), 'párový řádek se nekontroluje');
        // Bez páru je týž řádek obyčejný: základ + daň ≠ celkem varuje.
        $plain = $this->euServicesDumpDocument();
        unset($plain['vatRecap'][1]);
        $w = $this->findByCode(PaperChecks::validate($plain, $this->v), 'vat_recap_inconsistent');
        $this->assertNotNull($w);
        $this->assertStringContainsString('≠ celkem 188', $w['message']);
    }

    /**
     * Doklad `invoiceReceived` v EUR tak, jak ho vypisuje `DocumentExporter`
     * (demo sada „IT servis“, dodavatel z EU): řádky s cz-217 / 21 %,
     * rekapitulace primární řádek + pár cz-207, součty základ / 0 / základ.
     *
     * @return array<string, mixed>
     */
    private function euServicesDumpDocument(): array
    {
        return [
            'docType' => 'invoiceReceived',
            'docNumber' => 'INV-2026-18407',
            'selfParty' => 'customer',
            'supplier' => ['name' => 'Noordwolk Backup B.V.', 'country' => 'nl', 'vatId' => 'NL854721039B01'],
            'dates' => ['issueDate' => '2026-01-05', 'taxPointDate' => '2026-01-05'],
            'currency' => 'EUR',
            'exchangeRate' => 25.12,
            'vat' => ['mode' => 'fromBase', 'place' => 'intracom', 'registrationCountry' => 'cz', 'recapSource' => 'computed'],
            'rows' => [
                ['rowKind' => 'item', 'quantity' => 1, 'unitPrice' => 149.00, 'totalPrice' => 149.00,
                 'vat' => ['code' => 'cz-217', 'pct' => 21], 'computed' => ['vatBase' => 149.00, 'vatAmount' => 31.29, 'vatTotal' => 149.00]],
                ['rowKind' => 'item', 'quantity' => 1, 'unitPrice' => 39.00, 'totalPrice' => 39.00,
                 'vat' => ['code' => 'cz-217', 'pct' => 21], 'computed' => ['vatBase' => 39.00, 'vatAmount' => 8.19, 'vatTotal' => 39.00]],
            ],
            'vatRecap' => [
                ['vatCode' => 'cz-217', 'vatPct' => 21, 'base' => 188.00, 'tax' => 39.48, 'total' => 188.00],
                ['vatCode' => 'cz-207', 'vatPct' => 21, 'base' => 188.00, 'tax' => 39.48, 'total' => 227.48, 'isReversePair' => true],
            ],
            'totals' => ['totalBase' => 188.00, 'totalVat' => 0.00, 'totalAmount' => 188.00, 'totalRounding' => 0.0],
            'applyOptions' => ['targetDocState' => 40, 'importNumber' => ['docNumber' => '2260004', 'sequenceNumber' => 4]],
        ];
    }

    public function testVatRecapInconsistentWarnsOnTaxVsPct(): void
    {
        // base + tax = total sedí, ale daň neodpovídá sazbě.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'supplier' => ['name' => 'V'],
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [
                ['rowKind' => 'item', 'totalPrice' => 387.00, 'vat' => ['pct' => 21]],
            ],
            'vatRecap' => [
                ['vatPct' => 21, 'base' => 387.00, 'tax' => 60.00, 'total' => 447.00],
            ],
        ], $this->v);
        $w = $this->findByCode($issues, 'vat_recap_inconsistent');
        $this->assertNotNull($w);
        $this->assertStringContainsString('sazbě', $w['message']);
    }

    public function testVatRecapSkipsReversePairAndZeroPct(): void
    {
        // Reverse-charge pár a 0% řádek mají vlastní pravidla — aritmetika
        // se u nich nekontroluje, i když by naivně nevyšla.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));
    }

    public function testVatRecapToleranceBoundaries(): void
    {
        // base+tax−total přesně 0.02 a tax odchylka přesně max(0.05, …)
        // jsou ještě v toleranci.
        $issues = PaperChecks::validate([
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
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'vat_recap_inconsistent'));

        // Krok za hranici u obou podmínek už warnuje.
        $issues = PaperChecks::validate([
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
        ], $this->v);
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

    // ── Reference (#166 D30, D39) ───────────────────────────────────────────

    /** D39: tři signály návrhu jsou v pokynu chyba s radou; návrh je čte dál. */
    public function testProposalSignalsAreErrorsOnlyInInstruction(): void
    {
        $payload = [
            'docType' => 'invoiceReceived',
            'selfParty' => 'customer',
            'supplier' => ['ref' => 42],
            'dates' => ['issueDate' => '2026-04-15'],
            'vat' => ['registrationCountry' => 'CZ'],
            'rows' => [
                ['rowKind' => 'item', 'item' => ['ourCode' => 'K-001', 'name' => 'Konzultace'], 'account' => '518100'],
                ['rowKind' => 'item', 'item' => ['ref' => '@K-001'], 'account' => '@518100'],
                ['rowKind' => 'item', 'item' => '@K-001', 'account' => 9],
            ],
        ];

        $signals = array_values(array_filter(
            $this->v->validate($payload),
            static fn (array $i): bool => $i['code'] === 'signal_not_allowed',
        ));
        $this->assertSame(
            ['vat.registrationCountry', 'rows.0.item.ourCode', 'rows.0.account'],
            array_column($signals, 'path'),
            'reference na řádcích 1 a 2 signálem nejsou',
        );
        $this->assertSame(['error', 'error', 'error'], array_column($signals, 'severity'));
        $this->assertStringContainsString('vat.registration', $signals[0]['message']);
        $this->assertStringContainsString('"@K-001"', $signals[1]['message']);
        $this->assertStringContainsString('"@518100"', $signals[2]['message']);

        $this->assertNull($this->findByCode(PaperChecks::validate($payload, $this->v), 'signal_not_allowed'));
    }

    /** D30: `partner` na hlavičce faktury je v pokynu chyba, v návrhu varování; účetní a pokladní doklad ho nese. */
    public function testHeadPartnerIsRejectedOnInvoices(): void
    {
        $invoice = [
            'docType' => 'invoiceIssued', 'selfParty' => 'supplier', 'customer' => ['ref' => 42],
            'partner' => '@F00042',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item', 'item' => '@K-001', 'quantity' => 1, 'unitPrice' => 100]],
        ];

        $issue = $this->findByCode($this->v->validate($invoice), 'partner_not_supported');
        $this->assertSame(['error', 'partner'], [$issue['severity'] ?? null, $issue['path'] ?? null]);
        $issue = $this->findByCode(PaperChecks::validate($invoice, $this->v), 'partner_not_supported');
        $this->assertSame('warning', $issue['severity'] ?? null, 'návrh pole ignoruje');

        foreach (['accountingDocument', 'cashDocument', 'cashRegisterDocument'] as $docType) {
            $issues = $this->v->validate([
                'docType' => $docType, 'cashDirection' => 1, 'cashDesk' => '@POK', 'partner' => ['ref' => 42],
                'dates' => ['issueDate' => '2026-04-15'],
                'rows' => [['operation' => 'acc.record', 'account' => '@518100', 'accSide' => 'debit', 'totalPrice' => 100.0, 'partner' => 42]],
            ]);
            $this->assertNull($this->findByCode($issues, 'partner_not_supported'), $docType);
        }
        // Partner řádku faktuře nevadí (úhrada, záloha) — hlídá ho validace dokladu.
        unset($invoice['partner']);
        $invoice['rows'][0]['partner'] = 42;
        $this->assertNull($this->findByCode($this->v->validate($invoice), 'partner_not_supported'));
    }

    /** Strana i pokladna jen referencí projdou povinnými poli návrhu stejně jako objekt. */
    public function testScalarReferencesSatisfyRequiredFields(): void
    {
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived', 'selfParty' => 'customer', 'supplier' => '@F00001',
            'dates' => ['issueDate' => '2026-04-15'],
            'rows' => [['rowKind' => 'item', 'item' => '@K-001', 'quantity' => 1, 'unitPrice' => 100]],
        ], $this->v);
        $this->assertNull($this->findByCode($issues, 'required'));

        foreach (['@POK', 2] as $cashDesk) {
            $issues = PaperChecks::validate([
                'docType' => 'cashDocument', 'cashDirection' => 1, 'cashDesk' => $cashDesk,
                'dates' => ['issueDate' => '2026-04-15'],
            ], $this->v);
            $this->assertSame([], array_filter($issues, static fn (array $i): bool => $i['path'] === 'cashDesk'));
        }
        $withoutCashDesk = [
            'docType' => 'cashDocument', 'cashDirection' => 1, 'cashDesk' => '',
            'dates' => ['issueDate' => '2026-04-15'],
        ];
        foreach ([PaperChecks::validate($withoutCashDesk, $this->v), $this->v->validate($withoutCashDesk)] as $issues) {
            $this->assertCount(1, array_filter($issues, static fn (array $i): bool => $i['path'] === 'cashDesk'));
        }
    }

    /**
     * Pokyn (#166 D45, U7): povinnost polí validátor nehlídá — vyžaduje se
     * jen obálka a `docType`; co chybí, má výchozí hodnotu jako ve formuláři,
     * nebo to ohlásí validace dokladu pro cílový stav. Zůstává, co výchozí
     * hodnotu mít nemůže: pokladna vázaného typu a směr pokladního dokladu.
     */
    public function testInstructionDoesNotCheckRequiredFields(): void
    {
        foreach (['invoiceReceived', 'invoiceIssued', 'proformaIssued', 'proformaReceived', 'accountingDocument'] as $docType) {
            $this->assertNull(
                $this->findByCode($this->v->validate(['docType' => $docType]), 'required'),
                $docType,
            );
        }
        $issues = $this->v->validate(['docType' => 'cashDocument', 'cashDirection' => 1, 'cashDesk' => '@POK']);
        $this->assertNull($this->findByCode($issues, 'required'), 'datum, řádky ani strany se nevyžadují');

        foreach (['cashDocument', 'cashRegisterDocument'] as $docType) {
            $required = array_values(array_filter(
                $this->v->validate(['docType' => $docType, 'cashDirection' => 1]),
                static fn (array $i): bool => $i['code'] === 'required',
            ));
            $this->assertSame(['cashDesk'], array_column($required, 'path'), $docType);
        }
        $issues = $this->v->validate(['docType' => 'cashDocument', 'cashDesk' => '@POK']);
        $this->assertSame('cashDirection', $this->findByCode($issues, 'invalid_value')['path'] ?? null);
    }

    public function testValueFindingsAreAppended(): void
    {
        // #118 D13: čistý validátor hodnot běží z DocumentValidator — warning na cestě pole.
        $issues = PaperChecks::validate([
            'docType' => 'invoiceReceived',
            'dates' => ['issueDate' => '2026-04-15'],
            'supplier' => [
                'name' => 'X', 'country' => 'cz', 'companyId' => '52654150',
                'bankAccount' => ['accountNumber' => null, 'iban' => 'CZ8520100000002308417558'],
            ],
            'rows' => [['rowKind' => 'item', 'quantity' => 1, 'unitPrice' => 100, 'totalPrice' => 100]],
            'totals' => ['totalAmount' => 100],
        ], $this->v);

        $iban = $this->findByCode($issues, 'iban_invalid');
        $this->assertNotNull($iban);
        $this->assertSame('warning', $iban['severity']);
        $this->assertSame('supplier.bankAccount.iban', $iban['path']);
        $this->assertNull($this->findByCode($issues, 'company_id_invalid'));
    }
}
