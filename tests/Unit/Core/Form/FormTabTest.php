<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormColumn;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormSection;
use Shipard\Core\Form\FormTab;

/** Validace a serializace tabu typu `subtable` — `orderColumn` (fáze 3). */
class FormTabTest extends TestCase
{
    public function testOrderColumnIsSerializedAsOrderColumn(): void
    {
        $tab = new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'formId' => null, 'sort' => null, 'orderColumn' => 'order_pos'],
        );
        $arr = $tab->toArray();
        $this->assertSame('order_pos', $arr['subtable']['order_column']);
        $this->assertArrayNotHasKey('sort', $arr['subtable']);
    }

    public function testWithoutOrderColumnKeyIsAbsent(): void
    {
        $tab = new FormTab(
            id: 'contacts',
            label: 'Kontakty',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'formId' => null, 'sort' => 'name:asc', 'orderColumn' => null],
        );
        $arr = $tab->toArray();
        $this->assertArrayNotHasKey('order_column', $arr['subtable']);
        $this->assertSame('name:asc', $arr['subtable']['sort']);
    }

    public function testOrderColumnCannotBeCombinedWithSort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be combined');
        new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'sort' => 'name:asc', 'orderColumn' => 'order_pos'],
        );
    }

    public function testEmptyOrderColumnIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'child', 'foreignKey' => 'parent', 'orderColumn' => ''],
        );
    }

    // ── sendFlag (#94 D6) ────────────────────────────────────────────────────

    public function testAttachmentsTabCarriesSendFlagOnlyWhenEnabled(): void
    {
        $plain = new FormTab(id: 'attachments', label: 'Přílohy', type: 'attachments', tableId: 501);
        $this->assertArrayNotHasKey('send_flag', $plain->toArray());

        $sendable = new FormTab(id: 'attachments', label: 'Přílohy', type: 'attachments', tableId: 501, sendFlag: true);
        $this->assertTrue($sendable->toArray()['send_flag']);
    }

    public function testSendFlagIsAllowedOnlyOnAttachmentsTab(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sendFlag is only allowed for type "attachments"');

        new FormTab(
            id: 'rows',
            label: 'Řádky',
            type: 'subtable',
            subtable: ['table' => 'docs_core_rows', 'foreignKey' => 'doc_head', 'formId' => null],
            sendFlag: true,
        );
    }

    public function testWithIdKeepsEverythingButTheId(): void
    {
        $tab = new FormTab('sales', 'Sales', [new FormSection([new FormColumn([new FormElement('separator')])])], icon: 'money');

        $renamed = $tab->withId('economy.sales.sales');

        $this->assertSame('economy.sales.sales', $renamed->id);
        $this->assertSame('sales', $tab->id, 'původní záložka se nemění');
        $this->assertSame(['id' => 'economy.sales.sales'] + $tab->toArray(), $renamed->toArray());
    }
}
