<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Docs\CashDocs\CashDocForm;
use Shipard\Module\Docs\CashRegister\CashRegisterForm;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;

/**
 * Terminál / brána, doprava a Plátce v hlavičce (#72 D2/D4/D5) — sdílený
 * helper `DocsHeadsFormBase::addPaymentIntermediaryElements` volá každý
 * per-typ formulář, proto se viditelnost ověřuje napříč všemi pěti.
 */
class PaymentIntermediaryFormTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        $docTypes = [
            'invno'   => ['trade_dir' => 1],
            'invni'   => ['trade_dir' => 2],
            'cash'    => ['trade_dir' => 0, 'trade_dir_column' => 'cash_dir', 'series_binding' => 'cash_desk'],
            'cashreg' => ['trade_dir' => 1, 'series_binding' => 'cash_desk'],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    private function form(string $class): DocsHeadsFormBase
    {
        $form = new $class('docs_core_heads');
        $form->setConfig($this->config());
        return $form;
    }

    private function findElement(FormDefinition $def, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
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

    /** @return list<array{0: class-string<DocsHeadsFormBase>, 1: array<string, mixed>}> */
    public static function salesForms(): array
    {
        return [
            'invno'   => [IssuedInvoiceForm::class, ['doc_type' => 'invno']],
            'cashreg' => [CashRegisterForm::class, ['doc_type' => 'cashreg']],
            'cash+'   => [CashDocForm::class, ['doc_type' => 'cash', 'cash_dir' => 1]],
            'generic' => [DocsHeadsForm::class, ['doc_type' => 'invno']],
        ];
    }

    #[DataProvider('salesForms')]
    public function testTerminalFollowsPaymentMethodOnSalesDocuments(string $class, array $base): void
    {
        $form = $this->form($class);

        $def = $form->buildFormDefinition($base + ['payment_method' => 1], true);
        $terminal = $this->findElement($def, 'payment_terminal');
        $this->assertNotNull($terminal, "{$class} renderuje payment_terminal");
        $this->assertSame('lookup', $terminal->type);
        $this->assertTrue($terminal->hidden, 'převodem → terminál skrytý');
        $this->assertSame('reload', $terminal->triggers);
        $this->assertFalse($this->findElement($def, 'transport')->hidden, 'prodejní směr → doprava viditelná');

        $def = $form->buildFormDefinition($base + ['payment_method' => 2, 'cash_desk' => 7], true);
        $terminal = $this->findElement($def, 'payment_terminal');
        $this->assertFalse($terminal->hidden, 'kartou → terminál viditelný');
        $this->assertSame(['kind' => 0, 'cash_desk' => 7], $terminal->lookup['filter'], 'karta filtruje terminály pokladny');

        $def = $form->buildFormDefinition($base + ['payment_method' => 5], true);
        $terminal = $this->findElement($def, 'payment_terminal');
        $this->assertFalse($terminal->hidden, 'bránou → výběr brány viditelný');
        $this->assertSame(['kind' => 1], $terminal->lookup['filter']);
        $this->assertStringContainsString('brán', (string) $terminal->hint, 'bez brány hint o povinnosti');
    }

    public function testPurchaseAndDisbursementHideIntermediaries(): void
    {
        foreach ([
            [ReceivedInvoiceForm::class, ['doc_type' => 'invni', 'payment_method' => 2]],
            [CashDocForm::class, ['doc_type' => 'cash', 'cash_dir' => 2, 'payment_method' => 2]],
        ] as [$class, $data]) {
            $def = $this->form($class)->buildFormDefinition($data, true);
            $this->assertTrue($this->findElement($def, 'payment_terminal')->hidden, "{$class}: terminál skrytý");
            $this->assertTrue($this->findElement($def, 'transport')->hidden, "{$class}: doprava skrytá");
            $payer = $this->findElement($def, 'partner_balance');
            $this->assertNotNull($payer, "{$class}: ruční plátce k dispozici");
            $this->assertFalse($this->findElement($def, 'partner_balance_manual')->hidden);
        }
    }

    public function testPayerIsReadOnlyUnlessManual(): void
    {
        $form = $this->form(IssuedInvoiceForm::class);

        $def = $form->buildFormDefinition(['doc_type' => 'invno', 'payment_method' => 1, 'partner' => 5], true);
        $payer = $this->findElement($def, 'partner_balance');
        $this->assertTrue($payer->readOnly, 'bez ručního zadání je plátce read-only');
        $manual = $this->findElement($def, 'partner_balance_manual');
        $this->assertSame('checkbox', $manual->inputType);
        $this->assertSame('reload', $manual->triggers, 'checkbox přestaví formulář');
        $this->assertFalse($manual->hidden);

        $def = $form->buildFormDefinition(
            ['doc_type' => 'invno', 'payment_method' => 1, 'partner' => 5, 'partner_balance_manual' => 1],
            true,
        );
        $this->assertFalse($this->findElement($def, 'partner_balance')->readOnly, 'ruční plátce je editovatelný');
    }

    public function testRecalculatePreviewsPayerFromPartner(): void
    {
        $form = $this->form(IssuedInvoiceForm::class);

        $result = $form->recalculate('partner', ['doc_type' => 'invno', 'payment_method' => 1, 'partner' => 5]);
        $this->assertSame(5, $result->data['partner_balance'], 'plátce sleduje partnera');

        $result = $form->recalculate('partner_balance_manual', [
            'doc_type' => 'invno', 'payment_method' => 1, 'partner' => 5,
            'partner_balance' => 77, 'partner_balance_manual' => 1,
        ]);
        $this->assertSame(77, $result->data['partner_balance'], 'ruční plátce zůstává');

        $result = $form->recalculate('partner_balance_manual', [
            'doc_type' => 'invno', 'payment_method' => 1, 'partner' => 5,
            'partner_balance' => 77, 'partner_balance_manual' => 0,
        ]);
        $this->assertSame(5, $result->data['partner_balance'], 'vypnutí ručního zadání vrátí partnera');

        // Přepnutí z karty na převod vyprázdní terminál (mimo kartu / bránu nemá smysl).
        $result = $form->recalculate('payment_method', [
            'doc_type' => 'invno', 'payment_method' => 1, 'partner' => 5, 'payment_terminal' => 9,
        ]);
        $this->assertNull($result->data['payment_terminal']);
    }

    public function testCashRegisterRecalculatePreviewsPayer(): void
    {
        // CashDeskFormBase::recalculate přebírá payment_method sám — náhled plátce musí běžet i tam.
        $form = $this->form(CashRegisterForm::class);
        $result = $form->recalculate('payment_method', ['doc_type' => 'cashreg', 'payment_method' => 2, 'partner' => 5]);
        $this->assertSame(5, $result->data['partner_balance'], 'bez DB/terminálů plátce = partner');
    }
}
