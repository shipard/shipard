<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Core\Form\FormSection;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Persons\PersonsForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

class PersonsFormTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';

    private function createForm(): PersonsForm
    {
        return new PersonsForm('base_persons_persons');
    }

    private function findElement(FormDefinition $def, string $tabId, string $column): ?FormElement
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== $tabId) {
                continue;
            }
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $col) {
                    foreach ($col->elements as $el) {
                        if ($el->column === $column) {
                            return $el;
                        }
                        // Also look inside inline groups.
                        if ($el->type === 'inline' && $el->elements !== null) {
                            foreach ($el->elements as $inner) {
                                if ($inner->column === $column) {
                                    return $inner;
                                }
                            }
                        }
                    }
                }
            }
        }
        return null;
    }

    private function findSection(FormDefinition $def, string $tabId, ?string $title): ?FormSection
    {
        foreach ($def->tabs as $tab) {
            if ($tab->id !== $tabId) {
                continue;
            }
            foreach ($tab->sections as $section) {
                if ($section->title === $title) {
                    return $section;
                }
            }
        }
        return null;
    }

    private function getTabIds(FormDefinition $def): array
    {
        return array_map(fn($tab) => $tab->id, $def->tabs);
    }

    // ── Form-level ───────────────────────────────────────────────────────────

    public function testFormHasSixTabs(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $this->assertSame(
            ['basic', 'contacts', 'addresses', 'bank_accounts', 'attachments', 'settings'],
            $this->getTabIds($def),
        );
    }

    public function testFormTitles(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $this->assertSame('Osoba', $def->title);
        $this->assertSame('Nová osoba', $def->titleNew);
        $this->assertSame('base_persons_persons', $def->table);
    }

    // ── Basic tab section layout ─────────────────────────────────────────────

    public function testBasicTabHasFiveSections(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $basicTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'basic') {
                $basicTab = $tab;
                break;
            }
        }

        $this->assertNotNull($basicTab);
        $this->assertCount(5, $basicTab->sections);

        $titles = array_map(fn($s) => $s->title, $basicTab->sections);
        $this->assertSame(
            [null, 'Identifikace firmy', 'Jméno', 'Osobní údaje', 'Kontakt'],
            $titles,
        );
    }

    public function testSettingsTabHasFourSections(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $settingsTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'settings') {
                $settingsTab = $tab;
                break;
            }
        }

        $this->assertNotNull($settingsTab);
        $this->assertCount(4, $settingsTab->sections);

        $titles = array_map(fn($s) => $s->title, $settingsTab->sections);
        $this->assertSame(
            ['Identifikace', 'Identifikace firmy - doplňující', 'Obchodní podmínky', 'Dokumenty'],
            $titles,
        );
    }

    // ── Section visibility — Company ─────────────────────────────────────────

    public function testCompanyShowsIdentifikaceFirmy(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Identifikace firmy');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testCompanyHidesJmenoSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Jméno');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testCompanyHidesOsobniUdajeSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Osobní údaje');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testCompanyShowsIdentifikaceFirmyDoplnujici(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'settings', 'Identifikace firmy - doplňující');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testCompanyFullNameRequiredAndVisible(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'basic', 'full_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
        $this->assertFalse($el->hidden);
    }

    public function testIsOwnCheckboxIsInSettingsIdentifikaceFirmyDoplnujici(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        // is_own moved to settings tab → "Identifikace firmy - doplňující" section.
        $section = $this->findSection($def, 'settings', 'Identifikace firmy - doplňující');
        $this->assertNotNull($section);

        $columns = [];
        foreach ($section->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->column !== null) {
                    $columns[] = $el->column;
                }
            }
        }

        $this->assertContains('is_own', $columns);
        $this->assertContains('vat_id', $columns);
        $this->assertContains('court_registration', $columns);
    }

    public function testCompanyIdAndTaxIdAreInlinedInIdentifikaceFirmy(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'basic', 'Identifikace firmy');
        $this->assertNotNull($section);

        $foundInline = null;
        foreach ($section->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->type === 'inline') {
                    $foundInline = $el;
                    break 2;
                }
            }
        }

        $this->assertNotNull($foundInline, 'Inline group expected in Identifikace firmy section');
        $this->assertNotNull($foundInline->elements);
        $columns = array_map(fn($e) => $e->column, $foundInline->elements);
        $this->assertSame(['company_id', 'tax_id'], $columns);
    }

    // ── Section visibility — Person ──────────────────────────────────────────

    public function testPersonHidesIdentifikaceFirmy(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Identifikace firmy');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testPersonShowsJmenoSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Jméno');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testPersonShowsOsobniUdajeSection(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Osobní údaje');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testPersonHidesIdentifikaceFirmyDoplnujici(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'settings', 'Identifikace firmy - doplňující');
        $this->assertNotNull($section);
        $this->assertTrue($section->hidden);
    }

    public function testPersonFirstNameRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'first_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
    }

    public function testPersonLastNameRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'last_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
    }

    public function testPersonFullNameHiddenAndNotRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $el = $this->findElement($def, 'basic', 'full_name');
        $this->assertNotNull($el);
        $this->assertTrue($el->hidden);
        $this->assertFalse($el->required);
    }

    public function testPersonNamesAreInsideInlineGroup(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $jmenoSection = $this->findSection($def, 'basic', 'Jméno');
        $this->assertNotNull($jmenoSection);

        $foundInline = null;
        foreach ($jmenoSection->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->type === 'inline') {
                    $foundInline = $el;
                    break 2;
                }
            }
        }

        $this->assertNotNull($foundInline, 'Inline group expected in Jméno section');
        $this->assertNotNull($foundInline->elements);
        $columns = array_map(fn($e) => $e->column, $foundInline->elements);
        $this->assertSame(['first_name', 'middle_name', 'last_name'], $columns);
    }

    public function testPersonBirthDateAndNationalIdAreInlined(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'basic', 'Osobní údaje');
        $this->assertNotNull($section);

        $foundInline = null;
        foreach ($section->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->type === 'inline') {
                    $foundInline = $el;
                    break 2;
                }
            }
        }

        $this->assertNotNull($foundInline, 'Inline group expected in Osobní údaje section');
        $this->assertNotNull($foundInline->elements);
        $columns = array_map(fn($e) => $e->column, $foundInline->elements);
        $this->assertSame(['birth_date', 'national_id'], $columns);
    }

    // ── Section visibility — Undefined ───────────────────────────────────────

    public function testUndefinedHidesAllConditionalSections(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $sIdent  = $this->findSection($def, 'basic', 'Identifikace firmy');
        $sJmeno  = $this->findSection($def, 'basic', 'Jméno');
        $sOsobni = $this->findSection($def, 'basic', 'Osobní údaje');
        $sIdentDoplnujici = $this->findSection($def, 'settings', 'Identifikace firmy - doplňující');

        $this->assertTrue($sIdent->hidden);
        $this->assertTrue($sJmeno->hidden);
        $this->assertTrue($sOsobni->hidden);
        $this->assertTrue($sIdentDoplnujici->hidden);
    }

    public function testUndefinedWithExplicitZeroBehavesSame(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 0], true);

        $this->assertTrue($this->findSection($def, 'basic', 'Identifikace firmy')->hidden);
        $this->assertTrue($this->findSection($def, 'basic', 'Jméno')->hidden);
        $this->assertTrue($this->findSection($def, 'basic', 'Osobní údaje')->hidden);
        $this->assertTrue($this->findSection($def, 'settings', 'Identifikace firmy - doplňující')->hidden);
    }

    // ── Person type select ───────────────────────────────────────────────────

    public function testPersonTypeSelectHasTriggers(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $el = $this->findElement($def, 'basic', 'person_type');
        $this->assertNotNull($el);
        $this->assertSame('select', $el->type);
        $this->assertSame('reload', $el->triggers);
    }

    // ── Kontakt section in basic tab ─────────────────────────────────────────

    public function testKontaktSectionIsAlwaysVisible(): void
    {
        $form = $this->createForm();

        foreach ([[], ['person_type' => 1], ['person_type' => 2]] as $data) {
            $def     = $form->buildFormDefinition($data, true);
            $section = $this->findSection($def, 'basic', 'Kontakt');
            $this->assertNotNull($section, 'Kontakt section must exist for data ' . json_encode($data));
            $this->assertFalse($section->hidden, 'Kontakt section must not be hidden');
        }
    }

    public function testContactInputTypesAreSemantic(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $this->assertSame('email', $this->findElement($def, 'basic', 'email')->inputType);
        $this->assertSame('tel',   $this->findElement($def, 'basic', 'phone')->inputType);
        $this->assertSame('url',   $this->findElement($def, 'basic', 'web')->inputType);
    }

    public function testEmailAndPhoneAreInlinedInKontakt(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $section = $this->findSection($def, 'basic', 'Kontakt');
        $this->assertNotNull($section);

        $foundInline = null;
        foreach ($section->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->type === 'inline') {
                    $foundInline = $el;
                    break 2;
                }
            }
        }

        $this->assertNotNull($foundInline, 'Inline group expected in Kontakt section');
        $this->assertNotNull($foundInline->elements);
        $columns = array_map(fn($e) => $e->column, $foundInline->elements);
        $this->assertSame(['email', 'phone'], $columns);
    }

    // ── Settings tab ─────────────────────────────────────────────────────────

    public function testPersonIdLivesInSettingsAndIsRequired(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        // person_id was moved from basic to settings tab.
        $this->assertNull($this->findElement($def, 'basic', 'person_id'));

        $el = $this->findElement($def, 'settings', 'person_id');
        $this->assertNotNull($el);
        $this->assertTrue($el->required);
    }

    public function testPaymentTermDaysIsInSettingsObchodniPodminky(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 2], false);

        $section = $this->findSection($def, 'settings', 'Obchodní podmínky');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);

        $columns = [];
        foreach ($section->columns as $col) {
            foreach ($col->elements as $el) {
                if ($el->column !== null) {
                    $columns[] = $el->column;
                }
            }
        }

        $this->assertContains('payment_term_days', $columns);
    }

    public function testObchodniPodminkySectionVisibleEvenForPerson(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'settings', 'Obchodní podmínky');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    // ── Dokumenty (#94 D8) ───────────────────────────────────────────────────

    /** Formulář s konfigurací a s definicí tabulky včetně extension docs.core. */
    private function createFormWithDocuments(bool $withMergeColumn = true): PersonsForm
    {
        $raw = JsoncParser::parseFile(self::MODULES . '/base/persons/tables/base_persons_persons.jsonc');
        if ($withMergeColumn) {
            $extension = JsoncParser::parseFile(self::MODULES . '/docs/core/extensions/base_persons_persons.jsonc');
            $raw['columns'] = array_merge($raw['columns'], $extension['columns']);
        }

        $form = $this->createForm();
        $form->setTableDef(TableDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs')));
        $form->setConfig(ConfigRuntimeFactory::fromItems([
            'world.base.documentLanguages' => [
                'cs' => ['name' => 'čeština'],
                'en' => ['name' => 'angličtina'],
                'sk' => ['name' => 'slovenština'],
                'de' => ['name' => 'němčina'],
            ],
            'base.persons.formLabels' => ['languageAuto' => ['name' => 'Automaticky (podle země)']],
        ]));
        return $form;
    }

    public function testLanguageSelectOffersDocumentLanguagesAndAutomaticOption(): void
    {
        $def = $this->createFormWithDocuments()->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'settings', 'language');
        $this->assertNotNull($el);
        $this->assertSame('select', $el->type);
        $this->assertSame('Jazyk dokumentů', $el->label);
        $this->assertFalse($el->required, 'jazyk je výjimka — prázdná volba = automaticky');
        $this->assertSame('Automaticky (podle země)', $el->placeholder);
        $this->assertSame(
            [
                ['value' => 'cs', 'label' => 'čeština'],
                ['value' => 'en', 'label' => 'angličtina'],
                ['value' => 'sk', 'label' => 'slovenština'],
                ['value' => 'de', 'label' => 'němčina'],
            ],
            $el->options,
        );
    }

    public function testDokumentySectionIsVisibleForPersonToo(): void
    {
        $def = $this->createFormWithDocuments()->buildFormDefinition(['person_type' => 1], false);

        $section = $this->findSection($def, 'settings', 'Dokumenty');
        $this->assertNotNull($section);
        $this->assertFalse($section->hidden);
    }

    public function testMergeAttachmentsCheckboxFollowsTheExtensionColumn(): void
    {
        $with = $this->createFormWithDocuments()->buildFormDefinition(['person_type' => 2], false);
        $el   = $this->findElement($with, 'settings', 'send_attachments_merged');
        $this->assertNotNull($el);
        $this->assertSame('checkbox', $el->inputType);
        $this->assertSame('Přílohy dokladu připojit do PDF dokladu', $el->label);

        // Bez modulu docs.core sloupec není — pole ve formuláři také ne.
        $without = $this->createFormWithDocuments(withMergeColumn: false)->buildFormDefinition(['person_type' => 2], false);
        $this->assertNull($this->findElement($without, 'settings', 'send_attachments_merged'));
        $this->assertNotNull($this->findElement($without, 'settings', 'language'));
    }

    public function testLanguageAutoLabelFallsBackToEnglishWithoutConfig(): void
    {
        $def = $this->createForm()->buildFormDefinition(['person_type' => 2], false);

        $el = $this->findElement($def, 'settings', 'language');
        $this->assertSame('Automatic (by country)', $el?->placeholder);
        $this->assertSame([], $el?->options);
    }

    // ── Recalculate ──────────────────────────────────────────────────────────

    public function testRecalculateToPersonComputesFullName(): void
    {
        $form   = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 1,
            'first_name'  => 'Jan',
            'last_name'   => 'Novák',
            'full_name'   => '',
        ]);

        $this->assertSame('Jan Novák', $result->data['full_name']);
        $this->assertNotNull($result->formDefinition);
    }

    public function testRecalculateToCompanyKeepsFullName(): void
    {
        $form   = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 2,
            'full_name'   => 'ACME s.r.o.',
        ]);

        $this->assertSame('ACME s.r.o.', $result->data['full_name']);
    }

    public function testRecalculateFormDefinitionReflectsNewType(): void
    {
        $form   = $this->createForm();
        $result = $form->recalculate('person_type', [
            'person_type' => 1,
            'first_name'  => 'Jan',
            'last_name'   => 'Novák',
        ]);

        // Switched to Person → Identifikace firmy hidden, Jméno visible.
        $this->assertTrue(
            $this->findSection($result->formDefinition, 'basic', 'Identifikace firmy')->hidden,
        );
        $this->assertFalse(
            $this->findSection($result->formDefinition, 'basic', 'Jméno')->hidden,
        );
        // Also Identifikace firmy - doplňující in settings tab toggles.
        $this->assertTrue(
            $this->findSection($result->formDefinition, 'settings', 'Identifikace firmy - doplňující')->hidden,
        );
    }

    // ── Subtable tabs ────────────────────────────────────────────────────────

    public function testContactsSubtableTab(): void
    {
        $form = $this->createForm();
        $def  = $form->buildFormDefinition([], true);

        $contactsTab = null;
        foreach ($def->tabs as $tab) {
            if ($tab->id === 'contacts') {
                $contactsTab = $tab;
                break;
            }
        }

        $this->assertNotNull($contactsTab);
        $this->assertSame('subtable', $contactsTab->type);
        $this->assertSame('base_persons_contacts', $contactsTab->subtable['table']);
        $this->assertSame('person', $contactsTab->subtable['foreignKey']);
        $this->assertSame('base.persons.contacts', $contactsTab->subtable['formId']);
    }
}
