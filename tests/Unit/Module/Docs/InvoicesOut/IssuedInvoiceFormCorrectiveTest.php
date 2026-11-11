<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Docs\InvoicesOut;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Config\ConfigRuntime;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormSection;
use Shipard\Module\Docs\Core\DocsHeadsFormBase;
use Shipard\Module\Docs\InvoicesOut\IssuedInvoiceForm;
use Shipard\Module\Docs\ProformasOut\ProformaOutForm;

/**
 * Sekce „Oprava“ v tabu Nastavení faktury vydané (#92 D1–D3, U8, U9):
 * jen typ s `docTypes[].corrective` (zálohová faktura ne), pole opravy po
 * zapnutí příznaku, číslo a datum plnění původního dokladu jen ke čtení při
 * vyplněném odkazu, filtr lookupu, doplnění po výběru původního dokladu.
 */
class IssuedInvoiceFormCorrectiveTest extends TestCase
{
    private const CORRECTION_FIELDS = ['corrected_doc', 'corrected_doc_number', 'corrected_doc_date', 'correction_reason'];

    private function config(): ConfigRuntime
    {
        $docTypes = [
            'invno' => ['trade_dir' => 1, 'corrective' => true],
            'invpo' => ['trade_dir' => 1, 'tax_document' => false],
        ];
        $config = $this->createMock(ConfigRuntime::class);
        $config->method('cfgItem')->willReturnCallback(
            static fn (string $id): mixed => $id === 'docs.core.docTypes' ? $docTypes : null,
        );
        return $config;
    }

    /** @param array<string, mixed>|null $original původní doklad, který mock DB vrátí */
    private function form(string $class = IssuedInvoiceForm::class, bool $withConfig = true, ?array $original = null): DocsHeadsFormBase
    {
        $form = new $class('docs_core_heads');
        if ($withConfig) {
            $form->setConfig($this->config());
        }
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchSingle')->willReturn(null);
        $db->method('fetchAll')->willReturn([]);
        $db->method('fetchRow')->willReturnCallback(
            static function (mixed ...$args) use ($original): ?array {
                $sql = (string) ($args[0] ?? '');
                return str_contains($sql, '`docs_core_heads`') ? $original : null;
            },
        );
        $form->setDb($db);
        return $form;
    }

    private function findSection(FormDefinition $def, string $title): ?FormSection
    {
        foreach ($def->tabs as $tab) {
            foreach ($tab->sections as $section) {
                if ($section->title === $title) {
                    return $section;
                }
            }
        }
        return null;
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

    /** @return array<string, mixed> data nového opravného dokladu */
    private function data(array $extra = []): array
    {
        return array_merge(['doc_type' => 'invno', 'vat_mode' => 1, 'corrective' => 1], $extra);
    }

    public function testSectionLivesInSettingsTabAndOnlyCheckboxShowsWhileOff(): void
    {
        $def = $this->form()->buildFormDefinition(['doc_type' => 'invno', 'vat_mode' => 1], true);

        $section = $this->findSection($def, 'Oprava');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden, 'faktura vydaná sekci má');
        $settings = array_values(array_filter($def->tabs, static fn ($tab) => $tab->id === 'settings'))[0];
        $this->assertContains($section, $settings->sections, 'sekce je v tabu Nastavení');

        $checkbox = $this->findElement($def, 'corrective');
        $this->assertSame('checkbox', $checkbox?->inputType);
        $this->assertSame('reload', $checkbox->triggers);
        $this->assertFalse($checkbox->hidden);
        foreach (self::CORRECTION_FIELDS as $column) {
            $this->assertTrue($this->findElement($def, $column)?->hidden, "{$column} skryté bez příznaku");
        }
    }

    public function testFlagOnShowsFieldsEditableWithoutLink(): void
    {
        $def = $this->form()->buildFormDefinition($this->data(), true);

        $lookup = $this->findElement($def, 'corrected_doc');
        $this->assertSame('lookup', $lookup?->type);
        $this->assertFalse($lookup->hidden);
        $this->assertSame('reload', $lookup->triggers);
        $this->assertSame('docs_core_heads', $lookup->lookup['table']);
        $this->assertSame(['doc_type' => 'invno'], $lookup->lookup['filter'], 'bez partnera jen typ dokladu');

        $number = $this->findElement($def, 'corrected_doc_number');
        $this->assertSame('input', $number?->type);
        $this->assertFalse($number->hidden);
        $this->assertFalse($number->readOnly, 'bez odkazu ruční číslo (souhrnný doklad)');

        $date = $this->findElement($def, 'corrected_doc_date');
        $this->assertSame('date', $date?->inputType);
        $this->assertFalse($date->readOnly);
        $this->assertStringContainsString('Sazby DPH', (string) $date->hint);

        $reason = $this->findElement($def, 'correction_reason');
        $this->assertSame('textarea', $reason?->inputType);
        $this->assertFalse($reason->hidden);
    }

    public function testLinkLocksNumberAndDateAndFiltersLookupByPartner(): void
    {
        $def = $this->form()->buildFormDefinition($this->data(['corrected_doc' => 50, 'partner' => 5]), false);

        $this->assertTrue($this->findElement($def, 'corrected_doc_number')?->readOnly);
        $this->assertTrue($this->findElement($def, 'corrected_doc_date')?->readOnly);
        $this->assertFalse($this->findElement($def, 'correction_reason')?->readOnly, 'důvod zůstává editovatelný');
        $this->assertSame(
            ['doc_type' => 'invno', 'partner' => 5],
            $this->findElement($def, 'corrected_doc')?->lookup['filter'],
        );
    }

    public function testProformaAndTypeWithoutFlagHideTheSection(): void
    {
        $def = $this->form(ProformaOutForm::class)->buildFormDefinition(['doc_type' => 'invpo', 'corrective' => 1], true);
        $this->assertTrue($this->findSection($def, 'Oprava')?->hidden, 'zálohová faktura opravné doklady nemá (D1)');
        foreach (['corrective', ...self::CORRECTION_FIELDS] as $column) {
            $this->assertNotNull($this->findElement($def, $column), "{$column} existuje (sdílená báze)");
        }
        foreach (self::CORRECTION_FIELDS as $column) {
            $this->assertTrue($this->findElement($def, $column)?->hidden, "{$column} skryté i se zapnutým příznakem");
        }

        $def = $this->form(withConfig: false)->buildFormDefinition($this->data(), true);
        $this->assertTrue($this->findSection($def, 'Oprava')?->hidden, 'bez konfigurace typ bez příznaku');
    }

    public function testRecalculateCorrectedDocFillsNumberDateAndRate(): void
    {
        $original = [
            'doc_number' => '126A0007', 'vat_duzp' => '2026-03-10', 'issue_date' => '2026-03-12',
            'partner' => 5, 'doc_currency' => 'eur', 'exchange_rate' => 24.325,
        ];
        $result = $this->form(original: $original)->recalculate('corrected_doc', $this->data([
            'corrected_doc' => 50, 'corrected_doc_number' => 'ručně', 'corrected_doc_date' => '2020-01-01',
            'doc_currency' => 'eur', 'exchange_rate' => 23.5, 'accounting_date' => '2026-04-15',
        ]));

        $this->assertSame('126A0007', $result->data['corrected_doc_number']);
        $this->assertSame('2026-03-10', $result->data['corrected_doc_date'], 'DUZP původního dokladu');
        $this->assertSame(24.325, $result->data['exchange_rate'], 'kurz z původního dokladu');
        $this->assertSame(5, $result->data['partner'], 'prázdný partner se doplní');
        $this->assertTrue($this->findElement($result->formDefinition, 'corrected_doc_number')?->readOnly);
        $this->assertTrue($this->findElement($result->formDefinition, 'corrected_doc_date')?->readOnly);
    }

    public function testRecalculateKeepsFilledPartnerAndCurrencyAndRateOnOtherCurrency(): void
    {
        $original = [
            'doc_number' => '126A0007', 'vat_duzp' => null, 'issue_date' => '2026-03-12',
            'partner' => 5, 'doc_currency' => 'eur', 'exchange_rate' => 24.325,
        ];
        $result = $this->form(original: $original)->recalculate('corrected_doc', $this->data([
            'corrected_doc' => 50, 'partner' => 9, 'doc_currency' => 'czk', 'exchange_rate' => 1,
        ]));

        $this->assertSame('2026-03-12', $result->data['corrected_doc_date'], 'bez DUZP datum vystavení');
        $this->assertSame(9, $result->data['partner'], 'vyplněný partner se nepřepisuje');
        $this->assertSame('czk', $result->data['doc_currency'], 'vyplněná měna se nepřepisuje');
        $this->assertSame(1, $result->data['exchange_rate'], 'jiná měna: kurz původního dokladu se nebere');
    }

    public function testRecalculateFillsEmptyCurrencyAndThenTakesRate(): void
    {
        $original = [
            'doc_number' => '126A0007', 'vat_duzp' => '2026-03-10', 'issue_date' => '2026-03-10',
            'partner' => 5, 'doc_currency' => 'eur', 'exchange_rate' => 24.325,
        ];
        $result = $this->form(original: $original)->recalculate('corrected_doc', $this->data([
            'corrected_doc' => 50, 'partner' => 5, 'doc_currency' => '',
        ]));

        $this->assertSame('eur', $result->data['doc_currency']);
        $this->assertSame(24.325, $result->data['exchange_rate']);
    }

    public function testClearingLinkKeepsValuesAndUnlocks(): void
    {
        $result = $this->form(original: null)->recalculate('corrected_doc', $this->data([
            'corrected_doc' => null, 'corrected_doc_number' => '126A0007', 'corrected_doc_date' => '2026-03-10',
            'correction_reason' => 'Vrácené zboží',
        ]));

        $this->assertSame('126A0007', $result->data['corrected_doc_number']);
        $this->assertSame('2026-03-10', $result->data['corrected_doc_date']);
        $this->assertFalse($this->findElement($result->formDefinition, 'corrected_doc_number')?->readOnly);
        $this->assertFalse($this->findElement($result->formDefinition, 'corrected_doc_date')?->readOnly);
    }

    public function testMissingOriginalChangesNothing(): void
    {
        $result = $this->form(original: null)->recalculate('corrected_doc', $this->data([
            'corrected_doc' => 999, 'corrected_doc_number' => 'ručně',
        ]));

        $this->assertSame('ručně', $result->data['corrected_doc_number'], 'neexistující odkaz hlásí uložení');
    }
}
