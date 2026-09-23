<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\ProformasOut;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormRegistry;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\Core\IssuedInvoiceFormBase;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Formulář zálohové faktury vydané (#79 D1): dispatch přes typeColumn,
 * titulky a nedaňový charakter (DUZP a ruční zařazení do KH skryté), který
 * řídí `docTypes[].tax_document` ve sdílené `IssuedInvoiceFormBase`.
 */
class ProformaOutFormTest extends TestCase
{
    private function config(): ConfigRuntime
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
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

    public function testRegistryDispatchesInvpoToProformaOutForm(): void
    {
        $registry = new FormRegistry([
            [
                'table'        => 'docs_core_heads',
                'typeColumn'   => 'doc_type',
                'defaultClass' => DocsHeadsForm::class,
                'classes'      => [
                    'invno' => IssuedInvoiceForm::class,
                    'invpo' => ProformaOutForm::class,
                ],
            ],
        ]);

        $this->assertInstanceOf(
            ProformaOutForm::class,
            $registry->createForm('docs_core_heads', ['doc_type' => 'invpo']),
        );
        $this->assertInstanceOf(
            IssuedInvoiceForm::class,
            $registry->createForm('docs_core_heads', ['doc_type' => 'invno']),
        );
    }

    public function testSharesLayoutBaseWithIssuedInvoice(): void
    {
        $this->assertInstanceOf(IssuedInvoiceFormBase::class, new ProformaOutForm('docs_core_heads'));
        $this->assertInstanceOf(IssuedInvoiceFormBase::class, new IssuedInvoiceForm('docs_core_heads'));
    }

    public function testTitles(): void
    {
        $def = $this->form(ProformaOutForm::class)->buildFormDefinition(['doc_type' => 'invpo'], true);

        $this->assertSame('Zálohová faktura vydaná', $def->title);
        $this->assertSame('Nová zálohová faktura vydaná', $def->titleNew);
    }

    public function testNonTaxTypeHidesDuzpAndControlStatementMode(): void
    {
        $def = $this->form(ProformaOutForm::class)
            ->buildFormDefinition(['doc_type' => 'invpo', 'vat_mode' => 1], true);

        $duzp = $this->findElement($def, 'vat_duzp');
        $this->assertNotNull($duzp);
        $this->assertTrue($duzp->hidden, 'DUZP na nedaňovém dokladu skryté');
        $this->assertTrue($this->findElement($def, 'cs_mode')?->hidden, 'ruční zařazení do KH skryté');
        $this->assertNull($this->findElement($def, 'vat_dppd'), 'FVZ hlavička DPPD nemá');
        $this->assertNull($this->findElement($def, 'vat_period'), 'FVZ hlavička období DPH nemá');

        // Sazby a rekapitulace zůstávají: režim DPH a registrace viditelné.
        $this->assertFalse($this->findElement($def, 'vat_mode')?->hidden);
        $this->assertFalse($this->findElement($def, 'vat_registration')?->hidden);
    }

    public function testTaxTypeKeepsDuzpVisibleOnSharedBase(): void
    {
        $def = $this->form(IssuedInvoiceForm::class)
            ->buildFormDefinition(['doc_type' => 'invno', 'vat_mode' => 1], true);

        $this->assertFalse($this->findElement($def, 'vat_duzp')?->hidden, 'FVB DUZP viditelné (regrese)');
        $this->assertFalse($this->findElement($def, 'cs_mode')?->hidden);
    }

    public function testWithoutConfigBehavesAsTaxDocument(): void
    {
        $def = (new ProformaOutForm('docs_core_heads'))
            ->buildFormDefinition(['doc_type' => 'invpo', 'vat_mode' => 1], true);

        $this->assertFalse($this->findElement($def, 'vat_duzp')?->hidden, 'bez configu = daňový (fail-safe)');
    }

    public function testNewRecordDefaultsDoNotFillDuzpOnNonTaxType(): void
    {
        $data = ['doc_type' => 'invpo', 'vat_mode' => 1];
        $this->form(ProformaOutForm::class)->applyNewRecordDefaults($data);

        $this->assertSame(date('Y-m-d'), $data['issue_date']);
        $this->assertSame($data['issue_date'], $data['accounting_date']);
        $this->assertArrayNotHasKey('vat_duzp', $data, 'DUZP se u nedaňového dokladu nepředvyplňuje');

        $invoice = ['doc_type' => 'invno', 'vat_mode' => 1];
        $this->form(IssuedInvoiceForm::class)->applyNewRecordDefaults($invoice);
        $this->assertSame($invoice['issue_date'], $invoice['vat_duzp'], 'FVB beze změny (regrese)');
    }

    public function testRecalculateIssueDateDoesNotFollowIntoDuzpOnNonTaxType(): void
    {
        $result = $this->form(ProformaOutForm::class)
            ->recalculate('issue_date', ['doc_type' => 'invpo', 'issue_date' => '2026-03-01']);

        $this->assertSame('2026-03-01', $result->data['accounting_date']);
        $this->assertArrayNotHasKey('vat_duzp', $result->data);

        $invoice = $this->form(IssuedInvoiceForm::class)
            ->recalculate('issue_date', ['doc_type' => 'invno', 'issue_date' => '2026-03-01']);
        $this->assertSame('2026-03-01', $invoice->data['vat_duzp'], 'FVB beze změny (regrese)');
    }

    public function testSettingsTabIsAppendedAfterAttachments(): void
    {
        $def = $this->form(ProformaOutForm::class)->buildFormDefinition(['doc_type' => 'invpo'], true);

        $ids = array_map(static fn ($tab) => $tab->id, $def->tabs);
        $this->assertSame('settings', end($ids));
        $this->assertNotNull($this->findElement($def, 'bank_account'));
    }
}
