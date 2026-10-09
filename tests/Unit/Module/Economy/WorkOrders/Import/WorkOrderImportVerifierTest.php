<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\WorkOrders\Import;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportCheck;
use Shipard\Module\Economy\WorkOrders\Import\WorkOrderImportVerifier;
use Shipard\Module\Economy\WorkOrders\WorkOrderTypes;

/**
 * Kontrola payloadu zakázky (tasks/work-orders-import.md §1): reference
 * podle id, kódy, pravidla typu podle řady, řada dokladů vs. typ dokladu,
 * číslo a čítač, cesty nálezů do payloadu.
 */
class WorkOrderImportVerifierTest extends TestCase
{
    private const TYPES = [
        'periodic' => ['name' => 'Periodická', 'external' => true, 'oneOff' => false, 'invoicing' => 'periodic'],
        'project'  => ['name' => 'Externí jednorázová', 'external' => true, 'oneOff' => true, 'invoicing' => null],
        'overhead' => ['name' => 'Interní průběžná', 'external' => false, 'oneOff' => false, 'invoicing' => null],
        'internal' => ['name' => 'Interní jednorázová', 'external' => false, 'oneOff' => true, 'invoicing' => null],
    ];

    private const CURRENCIES = ['czk' => ['name' => 'Kč'], 'eur' => ['name' => '€']];

    private const OPERATIONS = [
        'sale.service'   => ['name' => 'Prodej služby', 'docTypes' => ['invno' => ['order' => 1], 'invpo' => ['order' => 1]]],
        'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => ['invni' => ['order' => 1]]],
        'asset.depr'     => ['name' => 'Odpis', 'system' => true, 'docTypes' => ['invno' => ['order' => 9]]],
    ];

    /** Řady: 3 = periodická (roční restart), 1 = externí jednorázová, 2 = interní průběžná (průběžně), 9 = smazaná. */
    private const SERIES = [
        3 => ['id' => 3, 'kind' => 13, 'type' => 'periodic', 'reset_scope' => 'fiscal_year', 'docState' => 40],
        1 => ['id' => 1, 'kind' => 11, 'type' => 'project', 'reset_scope' => 'fiscal_year', 'docState' => 40],
        2 => ['id' => 2, 'kind' => 12, 'type' => 'overhead', 'reset_scope' => 'none', 'docState' => 40],
        4 => ['id' => 4, 'kind' => 14, 'type' => 'internal', 'reset_scope' => 'none', 'docState' => 40],
        9 => ['id' => 9, 'kind' => 11, 'type' => 'project', 'reset_scope' => 'none', 'docState' => 90],
        8 => ['id' => 8, 'kind' => 18, 'type' => 'weird', 'reset_scope' => 'none', 'docState' => 40],
    ];

    private function verifier(bool $withConfig = true): TestableWorkOrderImportVerifier
    {
        $config = null;
        if ($withConfig) {
            $config = $this->createMock(ConfigRuntime::class);
            $config->method('cfgItem')->willReturnMap([
                [WorkOrderTypes::CFG_ITEM, self::TYPES],
                [WorkOrderImportVerifier::CURRENCIES_CFG_ITEM, self::CURRENCIES],
                [WorkOrderImportVerifier::ROW_OPERATIONS_CFG_ITEM, self::OPERATIONS],
            ]);
        }
        $v = new TestableWorkOrderImportVerifier($this->createMock(\Dibi\Connection::class), $config);
        $v->series = self::SERIES;
        $v->kinds = [
            13 => ['id' => 13, 'type' => 'periodic', 'inv_doc_type' => 'invno', 'inv_number_series' => 5, 'inv_due_days' => 14],
            11 => ['id' => 11, 'type' => 'project'],
            12 => ['id' => 12, 'type' => 'overhead'],
            14 => ['id' => 14, 'type' => 'internal'],
        ];
        $v->docSeries = [
            5 => ['id' => 5, 'doc_type' => 'invno', 'docState' => 40],
            6 => ['id' => 6, 'doc_type' => 'invpo', 'docState' => 40],
            7 => ['id' => 7, 'doc_type' => 'invno', 'docState' => 90],
        ];
        $v->references = [
            'economy_codebooks_cost_centers' => [2],
            'base_persons_persons'           => [41],
            'economy_items'                  => [15],
            'economy_codebooks_bank_accounts' => [1],
        ];
        $v->units = ['pcs' => 7, 'ks' => 7, 'hr' => 8];
        $v->vatCodes = ['cz-110', 'cz-112', 'cz-217'];
        $v->workOrders = ['Z260001' => ['id' => 50, 'docState' => 40]];
        return $v;
    }

    /** @return array<string, mixed> periodická zakázka s řádkem, V pořádku */
    private function periodic(array $head = [], ?array $rows = null): array
    {
        return [
            'format'    => 'shpd.workOrders.workOrder.v1',
            'workOrder' => array_merge([
                'number'           => 'S260001',
                'sequenceNumber'   => 1,
                'numberSeries'     => 3,
                'title'            => 'Nájem kanceláře',
                'state'            => 'confirmed',
                'dateStart'        => '2026-01-01',
                'dateEnd'          => null,
                'costCenter'       => 2,
                'internalNote'     => null,
                'customer'         => 41,
                'currency'         => 'czk',
                'paymentReference' => null,
                'parent'           => null,
                'invoicing'        => [
                    'periodicity' => 'month', 'invoiceFrom' => '2026-11-01', 'docText' => null,
                    'docType' => null, 'numberSeries' => null, 'dueDays' => null, 'timing' => null,
                    'vatMode' => null, 'paymentMethod' => null, 'bankAccount' => null,
                ],
            ], $head),
            'rows' => $rows ?? [[
                'item' => 15, 'description' => 'Nájem', 'quantity' => 1, 'unit' => 'pcs',
                'unitPrice' => 4500, 'vatCode' => 'cz-110', 'operation' => null, 'validFrom' => null, 'validTo' => null,
            ]],
        ];
    }

    /** @return array<string, mixed> interní průběžná zakázka bez strany */
    private function overhead(array $head = []): array
    {
        return [
            'format'    => 'shpd.workOrders.workOrder.v1',
            'workOrder' => array_merge([
                'numberSeries' => 2, 'title' => 'Režie', 'state' => 'confirmed', 'dateStart' => '2026-01-01',
            ], $head),
        ];
    }

    /** @return list<string> path:code */
    private function codes(WorkOrderImportCheck $check, string $severity = 'error'): array
    {
        $out = [];
        foreach ($check->issues as $issue) {
            if ($issue['severity'] === $severity) {
                $out[] = $issue['path'] . ':' . $issue['code'];
            }
        }
        return $out;
    }

    // --- platný payload ---------------------------------------------------------

    public function testValidPeriodicPayloadResolvesContext(): void
    {
        $check = $this->verifier()->verify($this->periodic());

        $this->assertTrue($check->isValid());
        $this->assertSame([], $check->issues);
        $this->assertSame(3, $check->series['id']);
        $this->assertSame('periodic', $check->series['type']);
        $this->assertSame(13, $check->kind['id']);
        $this->assertNull($check->parentId);
        $this->assertSame([0 => 7], $check->unitIds);
        $this->assertSame(26, $check->fiscalYearId);
    }

    public function testValidOverheadDraftWithoutAnything(): void
    {
        $check = $this->verifier()->verify($this->overhead(['state' => 'draft', 'dateStart' => null]));
        $this->assertSame([], $check->issues);
        $this->assertNull($check->fiscalYearId);
    }

    // --- řada ---------------------------------------------------------------------

    public function testSeriesMustExistBeAliveAndHaveKnownType(): void
    {
        $v = $this->verifier();
        $this->assertSame(['workOrder.numberSeries:not_found'], $this->codes($v->verify($this->overhead(['numberSeries' => 77]))));
        $this->assertSame(['workOrder.numberSeries:invalid_state'], $this->codes($v->verify($this->overhead(['numberSeries' => 9]))));
        $this->assertSame(['workOrder.numberSeries:type_unknown'], $this->codes($v->verify($this->overhead(['numberSeries' => 8]))));
    }

    // --- pravidla typu ------------------------------------------------------------

    public function testPartyOnlyForExternalType(): void
    {
        $check = $this->verifier()->verify($this->overhead(['customer' => 41, 'currency' => 'czk', 'paymentReference' => '123']));
        $this->assertSame(
            ['workOrder.customer:not_allowed', 'workOrder.currency:not_allowed', 'workOrder.paymentReference:not_allowed'],
            $this->codes($check),
        );
    }

    public function testParentOnlyForOneOffType(): void
    {
        $v = $this->verifier();
        $this->assertSame(['workOrder.parent:not_allowed'], $this->codes($v->verify($this->overhead(['parent' => 'Z260001']))));
        $this->assertSame(['workOrder.parent:not_allowed'], $this->codes($v->verify($this->periodic(['parent' => 'Z260001']))));

        $check = $v->verify($this->overhead(['numberSeries' => 4, 'parent' => 'Z260001']));
        $this->assertSame([], $check->issues);
        $this->assertSame(50, $check->parentId);
    }

    public function testInvoicingAndRowsOnlyForPeriodicType(): void
    {
        $payload = $this->overhead(['invoicing' => ['periodicity' => 'month']]);
        $payload['rows'] = [['description' => 'x']];
        $this->assertSame(['workOrder.invoicing:not_allowed', 'rows:not_allowed'], $this->codes($this->verifier()->verify($payload)));
    }

    public function testPeriodicOutsideDraftNeedsPeriodicityAndRows(): void
    {
        $v = $this->verifier();
        $payload = $this->periodic(['invoicing' => ['periodicity' => null]], []);
        $this->assertSame(['workOrder.invoicing.periodicity:required', 'rows:required'], $this->codes($v->verify($payload)));

        $payload = $this->periodic(['invoicing' => null], []);
        $this->assertSame(['workOrder.invoicing.periodicity:required', 'rows:required'], $this->codes($v->verify($payload)));

        // Koncept smí být neúplný.
        $payload = $this->periodic(['state' => 'draft', 'invoicing' => null, 'number' => null, 'sequenceNumber' => null], []);
        $this->assertSame([], $v->verify($payload)->issues);
    }

    public function testDateStartRequiredOutsideDraft(): void
    {
        $check = $this->verifier()->verify($this->overhead(['dateStart' => null]));
        $this->assertSame(['workOrder.dateStart:required'], $this->codes($check));
    }

    public function testWithoutConfigTypeRulesAreSkipped(): void
    {
        $check = $this->verifier(withConfig: false)->verify($this->overhead(['customer' => 41, 'currency' => 'xxx']));
        $this->assertSame([], $check->issues);
    }

    // --- reference a kódy ---------------------------------------------------------

    public function testHeadReferencesAndCurrency(): void
    {
        $check = $this->verifier()->verify($this->periodic(['costCenter' => 99, 'customer' => 98, 'currency' => 'xxx']));
        $this->assertSame(
            ['workOrder.costCenter:cost_center_not_found', 'workOrder.customer:customer_not_found', 'workOrder.currency:currency_unknown'],
            $this->codes($check),
        );
    }

    public function testCurrencyCodeIsCaseInsensitive(): void
    {
        $this->assertSame([], $this->verifier()->verify($this->periodic(['currency' => 'EUR']))->issues);
    }

    public function testDocSeriesMustExistBeAliveAndMatchEffectiveDocType(): void
    {
        $v = $this->verifier();
        $inv = static fn(array $o): array => array_merge([
            'periodicity' => 'month', 'invoiceFrom' => null, 'docText' => null, 'docType' => null, 'numberSeries' => null,
            'dueDays' => null, 'timing' => null, 'vatMode' => null, 'paymentMethod' => null, 'bankAccount' => null,
        ], $o);

        $this->assertSame(
            ['workOrder.invoicing.numberSeries:not_found'],
            $this->codes($v->verify($this->periodic(['invoicing' => $inv(['numberSeries' => 77])]))),
        );
        $this->assertSame(
            ['workOrder.invoicing.numberSeries:invalid_state'],
            $this->codes($v->verify($this->periodic(['invoicing' => $inv(['numberSeries' => 7])]))),
        );
        // Typ dokladu z druhu (invno), řada zálohových faktur → nesoulad.
        $this->assertSame(
            ['workOrder.invoicing.numberSeries:series_type_mismatch'],
            $this->codes($v->verify($this->periodic(['invoicing' => $inv(['numberSeries' => 6])]))),
        );
        // Přepis typu na zakázce (invpo) + řada zálohových → v pořádku.
        $this->assertSame([], $v->verify($this->periodic(['invoicing' => $inv(['docType' => 'invpo', 'numberSeries' => 6])]))->issues);
        // Bankovní účet podle id.
        $this->assertSame(
            ['workOrder.invoicing.bankAccount:bank_account_not_found'],
            $this->codes($v->verify($this->periodic(['invoicing' => $inv(['bankAccount' => 9])]))),
        );
    }

    public function testRowItemUnitVatCodeAndOperation(): void
    {
        $v = $this->verifier();
        $rows = [
            ['item' => 99, 'description' => 'a', 'quantity' => 1, 'unit' => 'furlong', 'unitPrice' => 1, 'vatCode' => 'cz-999', 'operation' => 'purchase.goods'],
            ['item' => 15, 'description' => 'b', 'quantity' => 1, 'unit' => 'hr', 'unitPrice' => 1, 'vatCode' => 'cz-217', 'operation' => 'asset.depr'],
            ['item' => null, 'description' => 'c', 'quantity' => 1, 'unit' => null, 'unitPrice' => 1, 'vatCode' => null, 'operation' => 'sale.service'],
        ];
        $check = $v->verify($this->periodic([], $rows));

        $this->assertSame(
            [
                'rows.0.item:item_not_found', 'rows.0.unit:unit_not_found', 'rows.0.vatCode:vat_code_unknown', 'rows.0.operation:operation_invalid',
                'rows.1.operation:operation_invalid',
            ],
            $this->codes($check),
        );
        $this->assertSame([1 => 8], $check->unitIds);
        // Kódy DPH se načtou jednou, pro efektivní typ dokladu z druhu.
        $this->assertSame(['invno'], $v->vatCodeQueries);
    }

    public function testVatCodeCheckSkippedWithoutContext(): void
    {
        $v = $this->verifier();
        $v->vatCodes = [];
        $rows = [['item' => 15, 'description' => 'a', 'quantity' => 1, 'unit' => 'pcs', 'unitPrice' => 1, 'vatCode' => 'xx-1']];
        $this->assertSame([], $v->verify($this->periodic([], $rows))->issues);
    }

    public function testOperationAllowedHelper(): void
    {
        $this->assertTrue(WorkOrderImportVerifier::operationAllowed('sale.service', 'invno', self::OPERATIONS));
        $this->assertFalse(WorkOrderImportVerifier::operationAllowed('sale.service', 'invni', self::OPERATIONS));
        $this->assertFalse(WorkOrderImportVerifier::operationAllowed('asset.depr', 'invno', self::OPERATIONS));
        $this->assertFalse(WorkOrderImportVerifier::operationAllowed('nope', 'invno', self::OPERATIONS));
    }

    // --- nadřazená ------------------------------------------------------------------

    public function testParentIsLookedUpByNumber(): void
    {
        $v = $this->verifier();
        $check = $v->verify($this->overhead(['numberSeries' => 1, 'customer' => 41, 'parent' => 'Z999999']));
        $this->assertSame(['workOrder.parent:parent_not_found'], $this->codes($check));
        $this->assertNull($check->parentId);

        $check = $v->verify($this->overhead(['numberSeries' => 1, 'customer' => 41, 'parent' => 'Z260001']));
        $this->assertSame([], $check->issues);
        $this->assertSame(50, $check->parentId);
    }

    // --- číslo a čítač ----------------------------------------------------------------

    public function testExistingNumberIsOnlyLookedUp(): void
    {
        $v = $this->verifier();
        $v->workOrders['S260001'] = ['id' => 31, 'docState' => 40];
        $check = $v->verify($this->periodic());
        $this->assertSame([], $check->issues);
        $this->assertSame(31, $check->existingId);
        $this->assertNull($v->verify($this->periodic(['number' => 'S260002']))->existingId);
    }

    public function testNumberWithoutSequenceIsOnlyAWarning(): void
    {
        $check = $this->verifier()->verify($this->periodic(['sequenceNumber' => null]));
        $this->assertTrue($check->isValid());
        $this->assertSame(['workOrder.sequenceNumber:counter_not_synced'], $this->codes($check, 'warning'));
        $this->assertSame(
            [['code' => 'counter_not_synced', 'message' => 'Číslo S260001 bez pořadí v řadě — čítač řady se nesrovná.', 'path' => 'workOrder.sequenceNumber']],
            $check->warnings(),
        );
        $this->assertNull($check->fiscalYearId);
    }

    public function testSequenceWithoutNumberIsAnError(): void
    {
        $check = $this->verifier()->verify($this->periodic(['number' => null, 'sequenceNumber' => 4]));
        $this->assertSame(['workOrder.sequenceNumber:number_required'], $this->codes($check));
    }

    public function testYearlySeriesNeedsFiscalYearForCounter(): void
    {
        $v = $this->verifier();
        $v->fiscalYearId = null;
        $this->assertSame(['workOrder.dateStart:fiscal_year_missing'], $this->codes($v->verify($this->periodic())));

        // Průběžná řada rok nepotřebuje.
        $check = $v->verify($this->overhead(['number' => 'R-00007', 'sequenceNumber' => 7]));
        $this->assertSame([], $check->issues);
        $this->assertNull($check->fiscalYearId);
    }
}
