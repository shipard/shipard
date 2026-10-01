<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocRowOperationRules;

class DocRowOperationRulesTest extends TestCase
{
    /** @return array<string, mixed> */
    private function cfg(): array
    {
        return [
            'sale.services' => ['name' => 'Sale of services', 'docTypes' => ['invno' => ['order' => 100]]],
            'purchase.services' => ['name' => 'Purchase of services', 'docTypes' => ['invni' => ['order' => 200]]],
            'acc.entry' => ['name' => 'Accounting entry', 'docTypes' => [
                'invno' => ['order' => 900], 'invni' => ['order' => 900], 'cash' => ['order' => 900],
            ]],
            // pokladní doklad: pohyby per směr + saldokontní úhrada
            'sale.goods' => ['name' => 'Sale of goods', 'docTypes' => ['cash' => ['order' => 200, 'cashDir' => 1]]],
            'purchase.goods' => ['name' => 'Purchase of goods', 'docTypes' => ['cash' => ['order' => 100, 'cashDir' => 2]]],
            'payment.receivable' => [
                'name' => 'Receivable payment',
                'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'identityRequired' => 1,
                'docTypes' => ['cash' => ['order' => 300, 'cashDir' => 1]],
            ],
            // převody peněz (Task D): bez partnera, VS nepovinný, směr per cash_dir
            'transfer.in' => [
                'name' => 'Cash transfer in', 'rowSide' => 0, 'rowPaymentId' => 1,
                'docTypes' => ['cash' => ['order' => 350, 'cashDir' => 1]],
            ],
            'transfer.out' => [
                'name' => 'Cash transfer out', 'rowSide' => 0, 'rowPaymentId' => 1,
                'docTypes' => ['cash' => ['order' => 450, 'cashDir' => 2]],
            ],
            // zálohy v hotovosti (Task E): partner povinný, VS ne
            'advance.received' => [
                'name' => 'Advance received', 'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'partnerRequired' => 1,
                'docTypes' => ['cash' => ['order' => 370, 'cashDir' => 1]],
            ],
        ];
    }

    // ── partnerRequired (zálohy v hotovosti, Task E) ────────────────────────

    public function testPartnerRequiredNeedsPartnerButNotPaymentReference(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'advance.received', 'total_price' => 5000];
        $errors = DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1);
        $this->assertSame([['partner', 'partner_required']], array_map(fn(array $e) => [$e['column'], $e['code']], $errors));

        $row['partner'] = 50;
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1), 'bez VS projde');

        $row['payment_reference'] = 'ZAL 2026/07';
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1));
    }

    public function testAdvanceReceivedOnlyOnReceipt(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'advance.received', 'partner' => 50];
        $errors = DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 2);
        $this->assertSame('operation_not_allowed_for_direction', $errors[0]['code']);
    }

    // ── transfer.* (převody peněz, Task D) ──────────────────────────────────

    public function testTransferRowsNeedNeitherPartnerNorPaymentReference(): void
    {
        $in  = ['row_kind' => 1, 'operation' => 'transfer.in',  'total_price' => 5000];
        $out = ['row_kind' => 1, 'operation' => 'transfer.out', 'total_price' => 20000];

        $this->assertSame([], DocRowOperationRules::validateRow($in, 'cash', $this->cfg(), 1));
        $this->assertSame([], DocRowOperationRules::validateRow($out, 'cash', $this->cfg(), 2));

        // nepovinná identifikace protistrany převodu projde taky
        $in['payment_reference'] = 'TX 2026-06-10 #4711';
        $this->assertSame([], DocRowOperationRules::validateRow($in, 'cash', $this->cfg(), 1));
    }

    public function testTransferDirectionIsBoundToCashDir(): void
    {
        $in  = ['row_kind' => 1, 'operation' => 'transfer.in'];
        $out = ['row_kind' => 1, 'operation' => 'transfer.out'];

        $errors = DocRowOperationRules::validateRow($in, 'cash', $this->cfg(), 2);
        $this->assertSame('operation_not_allowed_for_direction', $errors[0]['code'], 'transfer.in jen na příjmu');

        $errors = DocRowOperationRules::validateRow($out, 'cash', $this->cfg(), 1);
        $this->assertSame('operation_not_allowed_for_direction', $errors[0]['code'], 'transfer.out jen na výdeji');

        // T1: na účetním dokladu převod není
        $errors = DocRowOperationRules::validateRow($in, 'cmnbkp', $this->cfg());
        $this->assertSame('operation_not_allowed', $errors[0]['code']);
    }

    // ── cashDir (pokladní doklad, směr per doklad) ──────────────────────────

    public function testCashDirMatchPasses(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'sale.goods'];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1));
    }

    public function testCashDirMismatchFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'purchase.goods'];
        $errors = DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1);

        $this->assertCount(1, $errors);
        $this->assertSame('operation', $errors[0]['column']);
        $this->assertSame('operation_not_allowed_for_direction', $errors[0]['code']);
    }

    public function testOperationWithoutCashDirAllowsBothDirections(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'acc.entry', 'item' => 3];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1));
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 2));
    }

    public function testUnknownOrZeroCashDirSkipsDirectionCheck(): void
    {
        // null = volající směr nezná (degradace), 0 = neplatný směr hlášený
        // na hlavičce — per-řádkový duplikát by byl šum.
        $row = ['row_kind' => 1, 'operation' => 'purchase.goods'];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg()));
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 0));
    }

    // ── identityRequired (saldokontní úhrady) ───────────────────────────────

    public function testIdentityRequiredNeedsPartnerAndPaymentReference(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'payment.receivable', 'total_price' => 1210];
        $errors = DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1);

        $this->assertSame(
            [['partner', 'partner_required'], ['payment_reference', 'payment_reference_required']],
            array_map(fn(array $e) => [$e['column'], $e['code']], $errors),
        );
    }

    public function testIdentityRequiredSatisfiedPasses(): void
    {
        $row = [
            'row_kind' => 1, 'operation' => 'payment.receivable',
            'partner' => 50, 'payment_reference' => '2026001',
        ];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1));
    }

    public function testIdentityRequiredRejectsBlankReference(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'payment.receivable', 'partner' => 50, 'payment_reference' => '  '];
        $errors = DocRowOperationRules::validateRow($row, 'cash', $this->cfg(), 1);

        $this->assertCount(1, $errors);
        $this->assertSame('payment_reference_required', $errors[0]['code']);
    }

    public function testStandardRowWithValidOperationPasses(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'sale.services'];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invno', $this->cfg()));
    }

    public function testStandardRowWithoutOperationFails(): void
    {
        $row = ['row_kind' => 1];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertCount(1, $errors);
        $this->assertSame('operation', $errors[0]['column']);
        $this->assertSame('required', $errors[0]['code']);
    }

    public function testUnknownOperationFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'stock.in'];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertSame('operation_unknown', $errors[0]['code']);
    }

    public function testOperationNotAllowedForDocTypeFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'purchase.services'];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertSame('operation_not_allowed', $errors[0]['code']);
    }

    public function testTextRowWithOperationFails(): void
    {
        $row = ['row_kind' => 0, 'operation' => 'sale.services'];
        $errors = DocRowOperationRules::validateRow($row, 'invno', $this->cfg());

        $this->assertSame('operation_on_text_row', $errors[0]['code']);
    }

    public function testTextRowWithoutOperationPasses(): void
    {
        $row = ['row_kind' => 0, 'operation' => null];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invno', $this->cfg()));
    }

    public function testAccEntryWithoutItemFails(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'acc.entry'];
        $errors = DocRowOperationRules::validateRow($row, 'invni', $this->cfg());

        $this->assertSame('item', $errors[0]['column']);
        $this->assertSame('item_required_for_acc_entry', $errors[0]['code']);
    }

    public function testAccEntryWithItemPasses(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'acc.entry', 'item' => 42];
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invni', $this->cfg()));
    }

    // ── Majetek: rowAsset + system (assets D48) ─────────────────────────────

    /** @return array<string, mixed> */
    private function assetCfg(): array
    {
        return [
            'asset.depreciation' => [
                'name' => 'Odpis majetku', 'rowSide' => 1, 'rowAccount' => 'direct', 'rowAsset' => 1, 'system' => 1,
                'docTypes' => ['cmnbkp' => ['order' => 930]],
            ],
            'acc.record' => ['name' => 'Účetní zápis', 'rowSide' => 1, 'rowAccount' => 'direct', 'docTypes' => ['cmnbkp' => ['order' => 100]]],
        ];
    }

    public function testSystemOperationIsRejectedWithoutServiceMarker(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'asset.depreciation', 'asset' => 5, 'account' => 10, 'total_price' => 100];

        $errors = DocRowOperationRules::validateRow($row, 'cmnbkp', $this->assetCfg());

        $this->assertSame([['operation', 'system_operation']], array_map(
            static fn(array $e): array => [$e['column'], $e['code']],
            $errors,
        ));
        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cmnbkp', $this->assetCfg(), null, true));
    }

    public function testRowAssetOperationNeedsAsset(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'asset.depreciation', 'account' => 10, 'total_price' => 100];

        $errors = DocRowOperationRules::validateRow($row, 'cmnbkp', $this->assetCfg(), null, true);

        $this->assertSame([['asset', 'asset_required']], array_map(
            static fn(array $e): array => [$e['column'], $e['code']],
            $errors,
        ));
    }

    public function testOptionalRowAssetDoesNotRequireCard(): void
    {
        // D61: řádek pořízení kartu nese, ale nepovinně.
        $cfg = ['purchase.asset' => [
            'name' => 'Pořízení majetku', 'rowAccount' => 'direct', 'rowAsset' => 'optional',
            'docTypes' => ['invni' => ['order' => 600]],
        ]];
        $row = ['row_kind' => 1, 'operation' => 'purchase.asset', 'account' => 10, 'total_price' => 100];

        $this->assertSame([], DocRowOperationRules::validateRow($row, 'invni', $cfg));
        $this->assertSame([], DocRowOperationRules::validateRow(['asset' => 5] + $row, 'invni', $cfg));
        $this->assertFalse(DocRowOperationRules::isAssetRequired($cfg['purchase.asset']));
        $this->assertTrue(DocRowOperationRules::isAssetRequired($this->assetCfg()['asset.depreciation']));
        $this->assertFalse(DocRowOperationRules::isAssetRequired($this->assetCfg()['acc.record']));
        $this->assertFalse(DocRowOperationRules::isAssetRequired(null));
    }

    public function testOrdinaryOperationIsNotAffectedBySystemFlags(): void
    {
        $row = ['row_kind' => 1, 'operation' => 'acc.record', 'account' => 10, 'total_price' => 100];

        $this->assertSame([], DocRowOperationRules::validateRow($row, 'cmnbkp', $this->assetCfg()));
        $this->assertFalse(DocRowOperationRules::isSystem('acc.record', $this->assetCfg()));
        $this->assertTrue(DocRowOperationRules::isSystem('asset.depreciation', $this->assetCfg()));
        $this->assertFalse(DocRowOperationRules::isSystem('unknown', $this->assetCfg()));
    }
}
