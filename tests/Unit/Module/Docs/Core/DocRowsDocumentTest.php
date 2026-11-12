<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use Dibi\Connection;
use PHPUnit\Framework\TestCase;
use Shipard\Module\Docs\Core\DocRowsDocument;

/**
 * DocRowsDocument::beforeSave — automatické order_pos nového řádku
 * (MAX + 1 v rámci dokladu), explicitní pořadí a update beze změny.
 */
class DocRowsDocumentTest extends TestCase
{
    private function docWithMax(mixed $max, bool $expectQuery = true): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        if ($expectQuery) {
            $db->expects($this->once())->method('fetchSingle')->willReturnCallback(
                function (mixed ...$args) use ($max): mixed {
                    $this->assertStringContainsString('MAX([order_pos])', $args[0]);
                    $this->assertStringContainsString('[doc_head] = %i', $args[0]);
                    $this->assertSame(7, $args[1]);
                    return $max;
                },
            );
        } else {
            $db->expects($this->never())->method('fetchSingle');
        }
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        return $doc;
    }

    public function testNewRowWithoutOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax(3);
        $data = ['doc_head' => 7, 'row_kind' => 1, 'description' => 'Ukázka'];
        $doc->beforeSave($data, null);
        $this->assertSame(4, $data['order_pos']);
    }

    public function testNewRowWithZeroOrderGetsMaxPlusOne(): void
    {
        $doc = $this->docWithMax('12');
        $data = ['doc_head' => 7, 'order_pos' => 0];
        $doc->beforeSave($data, null);
        $this->assertSame(13, $data['order_pos']);
    }

    public function testFirstRowOfEmptyDocumentGetsOne(): void
    {
        // MAX() nad prázdnou skupinou vrací NULL
        $doc = $this->docWithMax(null);
        $data = ['doc_head' => 7, 'order_pos' => null];
        $doc->beforeSave($data, null);
        $this->assertSame(1, $data['order_pos']);
    }

    public function testExplicitOrderIsKept(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['doc_head' => 7, 'order_pos' => 2];
        $doc->beforeSave($data, null);
        $this->assertSame(2, $data['order_pos']);
    }

    public function testUpdateDoesNotTouchOrder(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['id' => 55, 'doc_head' => 7, 'order_pos' => 0];
        $doc->beforeSave($data, ['id' => 55, 'doc_head' => 7, 'order_pos' => 0]);
        $this->assertSame(0, $data['order_pos']);
    }

    public function testRowWithoutHeadIsLeftAlone(): void
    {
        $doc = $this->docWithMax(9, expectQuery: false);
        $data = ['description' => 'x'];
        $doc->beforeSave($data, null);
        $this->assertArrayNotHasKey('order_pos', $data);
    }


    // ── Předmět plnění tuzemského přenesení (#89 D7) ───────────────────────

    /** Dokument nad hlavičkou plátce DPH se zemí cz a skutečným číselníkem. */
    private function docWithVatHead(string $duzp = '2026-05-06'): DocRowsDocument
    {
        $db = $this->createMock(Connection::class);
        $db->method('fetch')->willReturn(new \Dibi\Row([
            'vat_registration' => 1, 'vat_mode' => 1, 'vat_duzp' => $duzp, 'issue_date' => $duzp,
            'corrective' => 0, 'corrected_doc_date' => null, 'doc_type' => 'invno', 'cash_dir' => 0,
        ]));
        $db->method('fetchSingle')->willReturnCallback(
            static fn (mixed ...$args): mixed => str_contains((string) $args[0], 'vat_registrations') ? 'cz' : 1,
        );
        $doc = new DocRowsDocument();
        $doc->setDb($db);
        $doc->setConfig(\Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory::fromItems([
            'world.vat.cz' => \Shipard\Core\Utils\JsoncParser::parseFile(
                dirname(__DIR__, 5) . '/modules/world/vat/config/vat-cz.jsonc',
            ),
        ]));
        return $doc;
    }

    public function testSubjectIsKeptOnReverseChargeCodeAndClearedOnOtherCode(): void
    {
        $doc = $this->docWithVatHead();

        $kept = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-150', 'reverse_charge_code' => ' 4a '];
        $doc->beforeSave($kept, null);
        $this->assertSame('4a', $kept['reverse_charge_code']);

        $cleared = ['doc_head' => 7, 'order_pos' => 1, 'row_kind' => 1, 'vat_code' => 'cz-120', 'reverse_charge_code' => '4'];
        $doc->beforeSave($cleared, null);
        $this->assertNull($cleared['reverse_charge_code']);

        // Změna kódu bez sloupce předmětu v datech: uložený předmět nesmí zůstat.
        $partial = ['id' => 3, 'doc_head' => 7, 'vat_code' => 'cz-120'];
        $doc->beforeSave($partial, ['id' => 3]);
        $this->assertArrayHasKey('reverse_charge_code', $partial);
        $this->assertNull($partial['reverse_charge_code']);
    }

    public function testRowValidationRejectsUnknownOrNotYetValidSubjectButNotMissingOne(): void
    {
        $errors = function (DocRowsDocument $doc, array $row): array {
            $row += ['doc_head' => 7, 'row_kind' => 1];
            return array_values(array_filter(
                array_map(static fn ($e): array => $e->toArray(), $doc->validate($row)->getErrors()),
                static fn (array $e): bool => $e['column'] === 'reverse_charge_code',
            ));
        };

        $doc = $this->docWithVatHead();
        $this->assertSame([], $errors($doc, ['vat_code' => 'cz-150']), 'chybějící předmět hlídá potvrzení dokladu');
        $this->assertSame([], $errors($doc, ['vat_code' => 'cz-150', 'reverse_charge_code' => '14']));
        $this->assertSame([], $errors($doc, ['vat_code' => 'cz-120', 'reverse_charge_code' => '99']), 'jiný kód: nuluje se');

        $unknown = $errors($doc, ['vat_code' => 'cz-150', 'reverse_charge_code' => '99']);
        $this->assertCount(1, $unknown);
        $this->assertSame('invalid_value', $unknown[0]['code']);

        // 4a platí od 1. 7. 2017.
        $early = $errors($this->docWithVatHead('2017-06-30'), ['vat_code' => 'cz-150', 'reverse_charge_code' => '4a']);
        $this->assertCount(1, $early);
        $this->assertStringContainsString('2017-06-30', $early[0]['message']);
    }
}
