<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use Dibi\Row;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Module\Docs\Core\DocRowsDocument;
use Shipard\Tests\Fixtures\Module\Docs\Core\TestableDocsHeadsDocument;

/**
 * Zapojení pravidel pohybu do validate hooků:
 *   - DocRowsDocument::validate — uložení řádku přes sub-form
 *   - DocDocument::validate — záchytná síť při přechodu do stavu 40
 */
class DocRowOperationsValidateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/shpd_rowopsval_test_' . uniqid();
        mkdir($this->tmpDir . '/config/configuration', 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/config/configuration/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->tmpDir . '/config/configuration');
        rmdir($this->tmpDir . '/config');
        rmdir($this->tmpDir);
    }

    private function buildConfig(): ConfigRuntime
    {
        $items = [
            'docs.core.rowOperations' => [
                'sale.services' => ['name' => 'Prodej služeb', 'docTypes' => [
                    'invno' => ['order' => 100], 'cash' => ['order' => 100, 'cashDir' => 1],
                ]],
                'purchase.goods' => ['name' => 'Nákup zboží', 'docTypes' => [
                    'invni' => ['order' => 100], 'cash' => ['order' => 100, 'cashDir' => 2],
                ]],
                'payment.receivable' => [
                    'name' => 'Úhrada pohledávky',
                    'rowSide' => 0, 'rowPartner' => 1, 'rowPaymentId' => 1, 'identityRequired' => 1,
                    'docTypes' => ['cash' => ['order' => 300, 'cashDir' => 1]],
                ],
                'acc.entry'     => ['name' => 'Účetní položka', 'docTypes' => [
                    'invno' => ['order' => 900], 'invni' => ['order' => 900],
                ]],
                // systémová operace majetku (assets D48) — typ dokladu je tu
                // vedlejší, jde o zapojení markeru a vlajek
                'asset.depreciation' => [
                    'name' => 'Odpis majetku', 'rowSide' => 1, 'rowAccount' => 'direct',
                    'rowAsset' => 1, 'system' => 1,
                    'docTypes' => ['invno' => ['order' => 950]],
                ],
            ],
            'docs.core.docTypes' => [
                'invno' => ['trade_dir' => 1],
                'cash'  => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            ],
        ];
        file_put_contents(
            $this->tmpDir . '/config/configuration/compiled.cs.json',
            json_encode(['_meta' => ['language' => 'cs'], 'items' => $items]),
        );
        return ConfigRuntime::load($this->tmpDir, 'cs');
    }

    // ── DocRowsDocument (sub-form save) ─────────────────────────────────────

    private function rowsDoc(string $docType = 'invno', int $cashDir = 0): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['doc_type' => $docType, 'cash_dir' => $cashDir]));

        $doc = new DocRowsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());
        return $doc;
    }

    public function testRowSaveOnCashDocumentChecksDirection(): void
    {
        // výdajový pohyb na příjmovém pokladním dokladu
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'purchase.goods'];
        $result = $this->rowsDoc('cash', cashDir: 1)->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('operation_not_allowed_for_direction', $result->getErrors()[0]->code);

        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'sale.services'];
        $this->assertTrue($this->rowsDoc('cash', cashDir: 1)->validate($data)->isValid());
    }

    public function testRowSavePaymentWithoutIdentityFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'payment.receivable', 'total_price' => 100];
        $result = $this->rowsDoc('cash', cashDir: 1)->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame(
            ['partner', 'payment_reference'],
            array_map(fn($e) => $e->column, $result->getErrors()),
        );
    }

    public function testRowSaveValidOperationPasses(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'sale.services'];
        $this->assertTrue($this->rowsDoc()->validate($data)->isValid());
    }

    public function testRowSaveMissingOperationFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1];
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('operation', $result->getErrors()[0]->column);
    }

    public function testRowSaveOperationNotAllowedForDocTypeFails(): void
    {
        // purchase pohyb na vydané faktuře (head je invno)
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'purchase.services'];
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
    }

    public function testRowSaveAccEntryWithoutItemFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 1, 'operation' => 'acc.entry'];
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('item', $result->getErrors()[0]->column);
    }

    public function testRowSaveTextRowWithOperationFails(): void
    {
        $data = ['doc_head' => 5, 'row_kind' => 0, 'operation' => 'sale.services'];
        $this->assertFalse($this->rowsDoc()->validate($data)->isValid());
    }

    public function testRowSaveWithoutConfigSkipsDegradedly(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['doc_type' => 'invno']));
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        // bez setConfig — validace se přeskočí, neblokuje
        $data = ['doc_head' => 5, 'row_kind' => 1];
        $this->assertTrue($doc->validate($data)->isValid());
    }

    // ── DocDocument (přechod do 40) ─────────────────────────────────────────

    private function headDoc(string $docType = 'invno'): TestableDocsHeadsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturnCallback(function (...$args) use ($docType): ?Row {
            $sql = (string) ($args[0] ?? '');
            if (str_contains($sql, 'docs_core_number_series')) {
                return new Row([
                    'doc_type'  => $docType,
                    'cash_desk' => $docType === 'cash' ? 7 : null,
                    'warehouse' => null,
                ]);
            }
            return new Row(['id' => 1]); // own company
        });

        $doc = new TestableDocsHeadsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());
        return $doc;
    }

    public function testState40CashDocumentRejectsRowAgainstDirection(): void
    {
        $data = $this->state40Data([
            ['row_kind' => 1, 'operation' => 'sale.services', 'total_price' => 100],
            ['row_kind' => 1, 'operation' => 'purchase.goods', 'total_price' => 50],
        ]);
        $data['doc_type'] = 'cash';
        $data['cash_dir'] = 1;
        $result = $this->headDoc('cash')->validate($data);

        $this->assertFalse($result->isValid());
        $errors = $result->getErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('rows.1.operation', $errors[0]->column);
        $this->assertSame('operation_not_allowed_for_direction', $errors[0]->code);
    }

    public function testState40CashPaymentRowNeedsIdentity(): void
    {
        $data = $this->state40Data([
            ['row_kind' => 1, 'operation' => 'payment.receivable', 'total_price' => 1210, 'partner' => 50],
        ]);
        $data['doc_type'] = 'cash';
        $data['cash_dir'] = 1;
        $result = $this->headDoc('cash')->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('rows.0.payment_reference', $result->getErrors()[0]->column);
    }

    /** @return array<string, mixed> */
    private function state40Data(array $rows): array
    {
        return [
            'docState'         => 40,
            'number_series'    => 1,
            'doc_type'         => 'invno',
            'issue_date'       => '2026-06-10',
            'accounting_date'  => '2026-06-10',
            'partner'          => 50,
            'vat_registration' => 1,
            'vat_mode'         => 1,
            'doc_currency'     => 'czk',
            'home_currency'    => 'czk',
            'rows'             => $rows,
        ];
    }

    public function testState40AllRowsValidPasses(): void
    {
        $data = $this->state40Data([
            ['row_kind' => 1, 'operation' => 'sale.services', 'total_price' => 100],
            ['row_kind' => 0],
        ]);
        $this->assertTrue($this->headDoc()->validate($data)->isValid());
    }

    public function testState40RowWithoutOperationFailsWithRowsIndexConvention(): void
    {
        $data = $this->state40Data([
            ['row_kind' => 1, 'operation' => 'sale.services', 'total_price' => 100],
            ['row_kind' => 1, 'total_price' => 50],
        ]);
        $result = $this->headDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('rows.1.operation', $result->getErrors()[0]->column);
    }

    public function testState80DoesNotEnforceOperations(): void
    {
        // Pohyby řádků se vynucují až při přechodu do 40 — V opravě (80) ne.
        $data = $this->state40Data([['row_kind' => 1, 'total_price' => 100]]);
        $data['docState'] = 80;

        $this->assertTrue($this->headDoc()->validate($data)->isValid());
    }

    // ── Systémové operace (asset.*, assets D48) ─────────────────────────────

    private const SYSTEM_ROW = [
        'row_kind' => 1, 'operation' => 'asset.depreciation', 'asset' => 5, 'account' => 10,
        'acc_side' => 0, 'total_price' => 100,
    ];

    public function testState40SystemRowNeedsServiceMarker(): void
    {
        $data = $this->state40Data([self::SYSTEM_ROW]);
        $result = $this->headDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('rows.0.operation', $result->getErrors()[0]->column);
        $this->assertSame('system_operation', $result->getErrors()[0]->code);

        $data = $this->state40Data([self::SYSTEM_ROW]) + ['_systemOperations' => true];
        $this->assertTrue($this->headDoc()->validate($data)->isValid());
    }

    public function testState40SystemRowWithoutAssetFailsEvenForService(): void
    {
        $row = self::SYSTEM_ROW;
        unset($row['asset']);
        $data = $this->state40Data([$row]) + ['_systemOperations' => true];
        $result = $this->headDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('rows.0.asset', $result->getErrors()[0]->column);
        $this->assertSame('asset_required', $result->getErrors()[0]->code);
    }

    public function testRowSaveOfSystemOperationIsRejected(): void
    {
        // Sub-form řádku marker nemá — systémovou operaci nezaloží.
        $data = ['doc_head' => 5] + self::SYSTEM_ROW;
        $result = $this->rowsDoc()->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('system_operation', $result->getErrors()[0]->code);
    }

    public function testStoredSystemRowCannotBeRewrittenToAnotherOperation(): void
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new Row(['doc_type' => 'invno', 'cash_dir' => 0, 'operation' => 'asset.depreciation']));
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        $doc->setConfig($this->buildConfig());

        $data = ['id' => 77, 'doc_head' => 5, 'row_kind' => 1, 'operation' => 'sale.services'];
        $result = $doc->validate($data);

        $this->assertFalse($result->isValid());
        $this->assertSame('system_operation', $result->getErrors()[0]->code);
    }
}
