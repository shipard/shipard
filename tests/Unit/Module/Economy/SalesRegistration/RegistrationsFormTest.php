<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\FormElement;
use Shipard\Module\Economy\SalesRegistration\RegistrationsForm;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Formulář registrace (#171 D3, D10): typ z registru, režimy, výchozí
 * jednotka z jednotek registrace, certifikát jen ke čtení, sub-tabulka
 * evidenčních jednotek. Soukromý klíč formulář nezná.
 */
class RegistrationsFormTest extends TestCase
{
    use SalesRegistrationConfig;

    private const TABLE = 'economy_sales_registration_registrations';

    /**
     * @param list<array<string, mixed>> $units
     * @param array<string, mixed> $configOverride
     */
    private function form(array $units = [], array $configOverride = []): RegistrationsForm
    {
        $form = new class(self::TABLE) extends RegistrationsForm {
            /** @var list<array<string, mixed>> */
            public array $units = [];

            protected function loadUnits(int $registrationId): array
            {
                return $registrationId > 0 ? $this->units : [];
            }
        };
        $form->units = $units;
        $form->setConfig($this->salesRegistrationConfig($configOverride));
        $def = TableDefinition::fromArray(self::repoJsonc('modules/economy/salesRegistration/tables/' . self::TABLE . '.jsonc'));
        $form->setTableDef($def);
        $form->setTables([self::TABLE => $def]);
        $form->setLanguage('cs');
        return $form;
    }

    /** Prvek podle sloupce, i uvnitř skupiny `inline`. */
    private function element(FormDefinition $def, string $column): FormElement
    {
        foreach ($def->tabs[0]->sections as $section) {
            foreach ($section->columns as $col) {
                foreach ($col->elements as $el) {
                    foreach ($el->type === 'inline' ? ($el->elements ?? []) : [$el] as $candidate) {
                        if ($candidate->column === $column) {
                            return $candidate;
                        }
                    }
                }
            }
        }
        $this->fail("Element {$column} not found");
    }

    /** @return list<array<string, mixed>> bloky `content` sekce Certifikát */
    private function certificateBlocks(FormDefinition $def): array
    {
        $blocks = [];
        foreach ($def->tabs[0]->sections[1]->columns as $col) {
            foreach ($col->elements as $el) {
                foreach ($el->blocks ?? [] as $block) {
                    $blocks[] = $block;
                }
            }
        }
        return $blocks;
    }

    public function testNewRegistrationOffersTypesAndModes(): void
    {
        $def = $this->form()->buildFormDefinition([], true);

        $this->assertSame('Nová registrace k evidenci tržeb', $def->titleNew);
        $this->assertSame([['value' => 'cz.eet2', 'label' => 'EET 2.0 (Česko)']], $this->element($def, 'type')->options);
        $this->assertFalse($this->element($def, 'type')->readOnly);
        $this->assertSame(
            [
                ['value' => 'production', 'label' => 'Ostrý'],
                ['value' => 'verification', 'label' => 'Ověřovací'],
                ['value' => 'playground', 'label' => 'Playground'],
            ],
            $this->element($def, 'mode')->options,
        );
        $this->assertTrue($this->element($def, 'default_unit')->hidden, 'jednotky vzniknou až u uložené registrace');
        $this->assertTrue($def->tabs[0]->sections[1]->hidden, 'sekce Certifikát až u uložené registrace');
        $this->assertStringContainsString('pojistka serveru', (string) $this->element($def, 'mode')->hint);
    }

    public function testStoredRegistrationOffersItsUnitsAsDefaultUnit(): void
    {
        $form = $this->form([
            ['id' => 3, 'unit_id' => '181', 'name' => 'Prodejna'],
            ['id' => 4, 'unit_id' => '200', 'name' => null],
        ]);
        $def = $form->buildFormDefinition(['id' => 7, 'type' => 'cz.eet2', 'name' => 'a'], false);

        $unit = $this->element($def, 'default_unit');
        $this->assertFalse($unit->hidden);
        $this->assertFalse($unit->required);
        $this->assertSame(
            [['value' => 3, 'label' => '181 — Prodejna'], ['value' => 4, 'label' => '200']],
            $unit->options,
        );
    }

    public function testUnitsAreASubtableOfTheRegistration(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 7, 'type' => 'cz.eet2'], false);

        $this->assertSame(['basic', 'units'], array_map(static fn($t) => $t->id, $def->tabs));
        $tab = $def->tabs[1];
        $this->assertSame('subtable', $tab->type);
        $this->assertSame(UnitDocument::TABLE, $tab->subtable['table']);
        $this->assertSame('registration', $tab->subtable['foreignKey']);
        $this->assertSame(RegistrationsForm::UNITS_FORM_ID, $tab->subtable['formId']);
    }

    public function testCertificateIsShownReadOnlyAndLocksTheType(): void
    {
        $def = $this->form()->buildFormDefinition([
            'id'               => 7,
            'type'             => 'cz.eet2',
            'name'             => 'a',
            'taxpayer_id'      => 'CZ00000019',
            'cert_pem'         => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n",
            'cert_valid_from'  => '2026-05-14 10:00:00',
            'cert_valid_to'    => '2027-05-14 10:00:00',
            'cert_serial'      => '1A2B',
            'cert_uploaded_at' => '2026-11-26 09:30:00',
        ], false);

        $this->assertTrue($this->element($def, 'type')->readOnly, 'certifikát patří typu');
        $this->assertFalse($def->tabs[0]->sections[1]->hidden);

        [$properties, $hint] = $this->certificateBlocks($def);
        $this->assertSame('properties', $properties['type']);
        $this->assertSame(
            [
                ['label' => 'EIČ', 'value' => 'CZ00000019'],
                ['label' => 'Certifikát platí od', 'value' => '14.05.2026 10:00'],
                ['label' => 'Certifikát platí do', 'value' => '14.05.2027 10:00'],
                ['label' => 'Sériové číslo certifikátu', 'value' => '1A2B'],
                ['label' => 'Certifikát nahrán', 'value' => '26.11.2026 09:30'],
            ],
            $properties['groups'][0]['items'],
        );
        $this->assertSame('hint', $hint['type']);
        $this->assertStringContainsString('Nahrát certifikát', $hint['text']);
    }

    public function testRegistrationWithoutCertificateShowsEmptyState(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 7, 'type' => 'cz.eet2', 'name' => 'a'], false);

        $this->assertSame(
            ['type' => 'empty', 'text' => 'Certifikát zatím není nahraný.'],
            $this->certificateBlocks($def)[0],
        );
    }

    public function testNoElementExposesThePrivateKey(): void
    {
        $def = $this->form()->buildFormDefinition(['id' => 7, 'type' => 'cz.eet2', 'cert_pem' => 'x', 'cert_key' => 'v1:secret'], false);

        $json = json_encode($def->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('cert_key', $json);
        $this->assertStringNotContainsString('v1:secret', $json);
        $this->assertSame([], $this->form()->getEditableSensitiveColumns(), 'klíč zapisuje jen akce Nahrát certifikát');
    }

    public function testStoredTypeMissingFromTheRegistryStaysInTheOffer(): void
    {
        $form = $this->form(configOverride: ['economy.salesRegistration.engines' => []]);
        $def = $form->buildFormDefinition(['id' => 7, 'type' => 'cz.eet2', 'name' => 'a'], false);

        $this->assertSame([['value' => 'cz.eet2', 'label' => 'cz.eet2']], $this->element($def, 'type')->options);
    }
}
