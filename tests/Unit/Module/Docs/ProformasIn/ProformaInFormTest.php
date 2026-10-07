<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasIn;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormRegistry;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\Core\ReceivedInvoiceFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\ProformasIn\ProformaInForm;

/**
 * Formulář zálohové faktury přijaté (#106 D1): dispatch přes typeColumn,
 * titulky a nedaňový charakter (DUZP, DPPD a ruční zařazení do KH skryté),
 * který řídí `docTypes[].tax_document` ve sdílené `ReceivedInvoiceFormBase`.
 * FPB se na téže bázi vykresluje beze změny (regrese).
 */
class ProformaInFormTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        $docTypes = [
            'invni' => ['trade_dir' => 2],
            'invpi' => ['trade_dir' => 2, 'tax_document' => false],
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

    public function testRegistryDispatchesInvpiToProformaInForm(): void
    {
        $registry = new FormRegistry([
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => DocsHeadsForm::class,
                'classes'      => [
                    'invni' => ReceivedInvoiceForm::class,
                    'invpi' => ProformaInForm::class,
                ],
            ],
        ]);

        $this->assertInstanceOf(
            ProformaInForm::class,
            $registry->createForm('docs_core_heads', ['doc_type' => 'invpi']),
        );
        $this->assertInstanceOf(
            ReceivedInvoiceForm::class,
            $registry->createForm('docs_core_heads', ['doc_type' => 'invni']),
        );
    }

    public function testSharesLayoutBaseWithReceivedInvoice(): void
    {
        $this->assertInstanceOf(ReceivedInvoiceFormBase::class, new ProformaInForm('docs_core_heads'));
        $this->assertInstanceOf(ReceivedInvoiceFormBase::class, new ReceivedInvoiceForm('docs_core_heads'));
    }

    public function testTitles(): void
    {
        $def = $this->form(ProformaInForm::class)->buildFormDefinition(['doc_type' => 'invpi'], true);

        $this->assertSame('Zálohová faktura přijatá', $def->title);
        $this->assertSame('Nová zálohová faktura přijatá', $def->titleNew);
    }

    public function testNonTaxTypeHidesDuzpDppdAndControlStatementMode(): void
    {
        $def = $this->form(ProformaInForm::class)
            ->buildFormDefinition(['doc_type' => 'invpi', 'vat_mode' => 1], true);

        $duzp = $this->findElement($def, 'vat_duzp');
        $this->assertNotNull($duzp);
        $this->assertTrue($duzp->hidden, 'DUZP na nedaňovém dokladu skryté');
        $this->assertTrue($this->findElement($def, 'vat_dppd')?->hidden, 'DPPD na nedaňovém dokladu skryté');
        $this->assertTrue($this->findElement($def, 'cs_mode')?->hidden, 'ruční zařazení do KH skryté');
        $this->assertNull($this->findElement($def, 'vat_period'), 'FPZ hlavička období DPH nemá');

        // Sazby a rekapitulace zůstávají: režim DPH, registrace a výpočet viditelné.
        $this->assertFalse($this->findElement($def, 'vat_mode')?->hidden);
        $this->assertFalse($this->findElement($def, 'vat_registration')?->hidden);
        $this->assertFalse($this->findElement($def, 'vat_calc_source')?->hidden);
        // Bankovní spojení dodavatele (výzva je podklad k platbě) zůstává.
        $this->assertFalse($this->findElement($def, 'partner_bank')?->hidden);
        $this->assertNotNull($this->findElement($def, 'partner_doc_number'));
    }

    public function testTaxTypeKeepsDuzpAndDppdVisibleOnSharedBase(): void
    {
        $def = $this->form(ReceivedInvoiceForm::class)
            ->buildFormDefinition(['doc_type' => 'invni', 'vat_mode' => 1], true);

        $this->assertFalse($this->findElement($def, 'vat_duzp')?->hidden, 'FPB DUZP viditelné (regrese)');
        $this->assertFalse($this->findElement($def, 'vat_dppd')?->hidden, 'FPB DPPD viditelné (regrese)');
        $this->assertFalse($this->findElement($def, 'cs_mode')?->hidden);
    }

    public function testWithoutVatHidesDuzpAndDppdOnTaxTypeToo(): void
    {
        $def = $this->form(ReceivedInvoiceForm::class)
            ->buildFormDefinition(['doc_type' => 'invni', 'vat_mode' => 0], true);

        $this->assertTrue($this->findElement($def, 'vat_duzp')?->hidden, 'bez DPH DUZP skryté jako dřív');
        $this->assertTrue($this->findElement($def, 'vat_dppd')?->hidden);
    }

    public function testWithoutConfigBehavesAsTaxDocument(): void
    {
        $def = (new ProformaInForm('docs_core_heads'))
            ->buildFormDefinition(['doc_type' => 'invpi', 'vat_mode' => 1], true);

        $this->assertFalse($this->findElement($def, 'vat_duzp')?->hidden, 'bez configu = daňový (fail-safe)');
    }

    public function testNewRecordDefaultsDoNotFillDuzpOnNonTaxType(): void
    {
        $data = ['doc_type' => 'invpi', 'vat_mode' => 1];
        $this->form(ProformaInForm::class)->applyNewRecordDefaults($data);

        $this->assertSame(date('Y-m-d'), $data['issue_date']);
        $this->assertSame($data['issue_date'], $data['accounting_date']);
        $this->assertArrayNotHasKey('vat_duzp', $data, 'DUZP se u nedaňového dokladu nepředvyplňuje');

        $invoice = ['doc_type' => 'invni', 'vat_mode' => 1];
        $this->form(ReceivedInvoiceForm::class)->applyNewRecordDefaults($invoice);
        $this->assertSame($invoice['issue_date'], $invoice['vat_duzp'], 'FPB beze změny (regrese)');
    }

    public function testRecalculateIssueDateDoesNotFollowIntoDuzpOnNonTaxType(): void
    {
        $result = $this->form(ProformaInForm::class)
            ->recalculate('issue_date', ['doc_type' => 'invpi', 'issue_date' => '2026-03-01']);

        $this->assertSame('2026-03-01', $result->data['accounting_date']);
        $this->assertArrayNotHasKey('vat_duzp', $result->data);

        $invoice = $this->form(ReceivedInvoiceForm::class)
            ->recalculate('issue_date', ['doc_type' => 'invni', 'issue_date' => '2026-03-01']);
        $this->assertSame('2026-03-01', $invoice->data['vat_duzp'], 'FPB beze změny (regrese)');
    }

    public function testSettingsTabIsAppendedAfterAttachments(): void
    {
        $def = $this->form(ProformaInForm::class)->buildFormDefinition(['doc_type' => 'invpi'], true);

        $ids = array_map(static fn ($tab) => $tab->id, $def->tabs);
        $this->assertSame('settings', end($ids));
        $this->assertNotNull($this->findElement($def, 'bank_account'));
        $this->assertNotNull($this->findElement($def, 'constant_symbol'));
    }
}
