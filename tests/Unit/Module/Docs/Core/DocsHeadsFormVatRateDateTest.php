<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Docs\CashDocs\CashDocForm;
use Shipard\Module\Docs\CashRegister\CashRegisterForm;
use Shipard\Module\Docs\Core\DocsHeadsForm;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesIn\ReceivedInvoiceForm;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasIn\ProformaInForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Přepínač „Sazby DPH podle“ a vlastní datum sazby dokladu ve formulářích
 * hlavičky (#159 D2, D4, U10): přepínač má každý formulář daňového dokladu
 * s DPH (`triggers: 'reload'`), datum je na hlavním tabu jen při zdroji
 * „vlastní datum dokladu“. Zálohové faktury (nedaňové) nemají ani jedno.
 */
class DocsHeadsFormVatRateDateTest extends TestCase
{
    private const CONFIG_DIR = __DIR__ . '/../../../../../modules/docs/core/config/';

    /** @return array<string, array{0: class-string<DocsHeadsFormBase>, 1: string, 2: string}> třída, typ, tab přepínače */
    public static function taxForms(): array
    {
        return [
            'generic' => [DocsHeadsForm::class, 'invno', 'basic'],
            'invno'   => [IssuedInvoiceForm::class, 'invno', 'settings'],
            'invni'   => [ReceivedInvoiceForm::class, 'invni', 'settings'],
            'cash'    => [CashDocForm::class, 'cash', 'settings'],
            'cashreg' => [CashRegisterForm::class, 'cashreg', 'settings'],
        ];
    }

    /** @return array<string, array{0: class-string<DocsHeadsFormBase>, 1: string}> */
    public static function nonTaxForms(): array
    {
        return [
            'invpo' => [ProformaOutForm::class, 'invpo'],
            'invpi' => [ProformaInForm::class, 'invpi'],
        ];
    }

    /** @param class-string<DocsHeadsFormBase> $class */
    private function form(string $class): DocsHeadsFormBase
    {
        $form = new $class('docs_core_heads');
        $form->setConfig(ConfigRuntimeFactory::fromItems([
            'docs.core.docTypes'       => JsoncParser::parseFile(self::CONFIG_DIR . 'docTypes.jsonc'),
            'docs.core.vatRateSources' => JsoncParser::parseFile(self::CONFIG_DIR . 'vatRateSources.jsonc'),
        ]));
        return $form;
    }

    /** @return array{0: ?FormElement, 1: ?string} prvek a id tabu, ve kterém je */
    private function find(FormDefinition $def, string $column): array
    {
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return [$el, $tab->id];
                        }
                    }
                }
            }
        }
        return [null, null];
    }

    /** @param class-string<DocsHeadsFormBase> $class */
    #[DataProvider('taxForms')]
    public function testTaxDocumentFormHasRateSourceSwitch(string $class, string $docType, string $switchTab): void
    {
        $form = $this->form($class);
        [$switch, $tab] = $this->find($form->buildFormDefinition(['doc_type' => $docType, 'cash_dir' => 2], true), 'vat_rate_source');

        $this->assertNotNull($switch, 'přepínač „Sazby DPH podle“');
        $this->assertSame($switchTab, $tab);
        $this->assertSame('select', $switch->type);
        $this->assertSame('reload', $switch->triggers, 'přepnutí ukáže / skryje datum dokladu');
        $this->assertFalse($switch->hidden);
        $this->assertSame([0, 1, 2], array_column($switch->options, 'value'));

        [$switch] = $this->find(
            $form->buildFormDefinition(['doc_type' => $docType, 'cash_dir' => 2, 'vat_mode' => 0], true),
            'vat_rate_source',
        );
        $this->assertTrue($switch->hidden, 'doklad bez DPH přepínač nemá');
    }

    /** @param class-string<DocsHeadsFormBase> $class */
    #[DataProvider('taxForms')]
    public function testHeadRateDateOnlyWithDocumentSource(string $class, string $docType): void
    {
        $form = $this->form($class);
        $base = ['doc_type' => $docType, 'cash_dir' => 2];

        [$date, $tab] = $this->find($form->buildFormDefinition($base + ['vat_rate_source' => 1], true), 'vat_rate_date');
        $this->assertNotNull($date);
        $this->assertSame('basic', $tab, 'datum sazby je na hlavním tabu u DUZP / DPPD');
        $this->assertSame('date', $date->inputType);
        $this->assertFalse($date->hidden);
        $this->assertNotNull($date->hint);

        foreach ([0, 2] as $source) {
            [$date] = $this->find($form->buildFormDefinition($base + ['vat_rate_source' => $source], true), 'vat_rate_date');
            $this->assertTrue($date->hidden, "zdroj {$source}: vlastní datum dokladu se nezadává");
        }

        [$date] = $this->find(
            $form->buildFormDefinition($base + ['vat_rate_source' => 1, 'vat_mode' => 0], true),
            'vat_rate_date',
        );
        $this->assertTrue($date->hidden, 'doklad bez DPH má vždy automatiku');
    }

    /** @param class-string<DocsHeadsFormBase> $class */
    #[DataProvider('nonTaxForms')]
    public function testNonTaxDocumentHasNeitherSwitchNorDate(string $class, string $docType): void
    {
        $def = $this->form($class)->buildFormDefinition(['doc_type' => $docType, 'vat_rate_source' => 1], true);

        [$switch] = $this->find($def, 'vat_rate_source');
        $this->assertTrue($switch === null || $switch->hidden, 'zálohová faktura není daňový doklad');
        [$date] = $this->find($def, 'vat_rate_date');
        $this->assertTrue($date === null || $date->hidden);
    }
}
