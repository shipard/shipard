<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Pokladna v hlavičce faktur (#59 D4): per-typ formuláře mají vlastní
 * buildHeaderTab, proto se chování ověřuje pro všechny čtyři třídy.
 */
class InvoiceFormsCashDeskTest extends TestCase
{
    /** @return list<array{0: DocsHeadsFormBase}> */
    public static function forms(): array
    {
        return [
            'generic' => [new DocsHeadsForm('docs_core_heads')],
            'invno'   => [new IssuedInvoiceForm('docs_core_heads')],
            'invpo'   => [new ProformaOutForm('docs_core_heads')],
            'invni'   => [new ReceivedInvoiceForm('docs_core_heads')],
        ];
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== 'basic') {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                    }
                }
            }
        }
        return null;
    }

    #[DataProvider('forms')]
    public function testCashDeskFollowsPaymentMethod(DocsHeadsFormBase $form): void
    {
        $def = $form->buildFormDefinition([], true);
        $this->assertSame('reload', $this->findElement($def, 'payment_method')?->triggers);
        $cashDesk = $this->findElement($def, 'cash_desk');
        $this->assertNotNull($cashDesk);
        $this->assertSame('lookup', $cashDesk->type);
        $this->assertTrue($cashDesk->hidden, 'převodem → pokladna skrytá');

        $def = $form->buildFormDefinition(['payment_method' => 0, 'doc_currency' => 'eur'], true);
        $cashDesk = $this->findElement($def, 'cash_desk');
        $this->assertFalse($cashDesk->hidden, 'hotovost → pokladna viditelná');
        $this->assertStringContainsString('EUR', (string) $cashDesk->hint);

        $def = $form->buildFormDefinition(['payment_method' => 0, 'cash_desk' => 7], true);
        $this->assertNull($this->findElement($def, 'cash_desk')->hint);
    }

    /**
     * #62: bankovní účet a IBAN partnera na FPB jen při platbě převodem;
     * ostatní způsoby (hotovost, karta, dobírka, zápočet) je skrývají.
     */
    public function testReceivedInvoicePartnerBankFollowsPaymentMethod(): void
    {
        $form = new ReceivedInvoiceForm('docs_core_heads');

        $def = $form->buildFormDefinition([], true);
        $this->assertFalse($this->findElement($def, 'partner_bank')->hidden, 'default převodem → účet viditelný');
        $this->assertFalse($this->findElement($def, 'partner_bank_iban')->hidden, 'default převodem → IBAN viditelný');

        foreach ([0, 2, 3, 4] as $method) {
            $def = $form->buildFormDefinition(['payment_method' => $method], true);
            $this->assertTrue($this->findElement($def, 'partner_bank')->hidden, "payment_method $method → účet skrytý");
            $this->assertTrue($this->findElement($def, 'partner_bank_iban')->hidden, "payment_method $method → IBAN skrytý");
        }

        $def = $form->buildFormDefinition(['payment_method' => '1'], true);
        $this->assertFalse($this->findElement($def, 'partner_bank')->hidden, 'převodem (string) → účet viditelný');
    }

    /** #62 D2: přepnutí na hotovost účet ani IBAN nemaže (na rozdíl od pokladny). */
    public function testReceivedInvoiceSwitchToCashKeepsPartnerBank(): void
    {
        $form = new ReceivedInvoiceForm('docs_core_heads');
        $result = $form->recalculate('payment_method', [
            'payment_method'    => 0,
            'partner_bank'      => 5,
            'partner_bank_iban' => 'CZ0000000000000000000000',
            'cash_desk'         => null,
        ]);
        $this->assertSame(5, $result->data['partner_bank']);
        $this->assertSame('CZ0000000000000000000000', $result->data['partner_bank_iban']);
        $this->assertTrue($this->findElement($result->formDefinition, 'partner_bank')->hidden);
    }
}
