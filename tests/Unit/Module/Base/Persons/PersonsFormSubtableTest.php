<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Base\Persons;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormTab;
use Shipard\Core\I18n\ConfigLocalizer;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Base\Persons\PersonsForm;
use Shipard\Tests\Fixtures\Core\Config\ConfigRuntimeFactory;

/**
 * Sub-tabulky osoby (Kontakty / Adresy / Bankovní účty) — sloupce podle
 * reálných JSONC definic (lokalizace cs), labely enumů z cfgItemů,
 * skládání ulice, stateStyle archivovaných řádků.
 */
class PersonsFormSubtableTest extends TestCase
{
    private const TABLES_DIR = __DIR__ . '/../../../../../modules/base/persons/tables/';

    private function tableDef(string $table): TableDefinition
    {
        $raw = JsoncParser::parseFile(self::TABLES_DIR . $table . '.jsonc');
        return TableDefinition::fromArray(ConfigLocalizer::localize($raw, 'cs'));
    }

    private function form(): PersonsForm
    {
        $form = new PersonsForm('base_persons_persons');
        $form->setTables([
            'base_persons_contacts'      => $this->tableDef('base_persons_contacts'),
            'base_persons_addresses'     => $this->tableDef('base_persons_addresses'),
            'base_persons_bank_accounts' => $this->tableDef('base_persons_bank_accounts'),
        ]);
        $form->setConfig(ConfigRuntimeFactory::fromItems([
            'base.persons.addressTypes'       => ['1' => ['name' => 'Sídlo'], '2' => ['name' => 'Doručovací adresa']],
            'base.persons.bankAccountSources' => ['0' => ['name' => 'Ruční pořízení'], '2' => ['name' => 'Registr DPH (API)']],
            'world.base.countries'            => ['cz' => ['name' => 'Česko'], 'sk' => ['name' => 'Slovensko']],
            'base.persons.sendPurposes'       => [
                'invoices'  => ['name' => 'Faktury a daňové doklady'],
                'reminders' => ['name' => 'Upomínky'],
            ],
            'core.system.docStatesArchive'    => [
                '10' => ['stateStyle' => 'concept'],
                '40' => ['stateStyle' => 'done'],
                '70' => ['stateStyle' => 'archive'],
                '90' => ['stateStyle' => 'trash'],
            ],
        ]));
        return $form;
    }

    private function tab(string $id, string $table): FormTab
    {
        return new FormTab(id: $id, label: $id, type: 'subtable', subtable: ['table' => $table, 'foreignKey' => 'person']);
    }

    public function testContactsColumnsAndCells(): void
    {
        $rows = [
            ['id' => 1, 'person' => 9, 'name' => 'Jana Ukázková', 'role' => 'účetní', 'email' => 'jana@example.test',
             'phone' => '+420 000 000 000', 'note' => null, 'docState' => 40],
        ];
        $result = $this->form()->renderSubtable($this->tab('contacts', 'base_persons_contacts'), $rows, ['id' => 9]);

        $this->assertSame(['name', 'role', 'email', 'send_purposes', 'phone', 'note'], array_column($result['columns'], 'id'));
        $this->assertSame(
            ['Název', 'Funkce', 'E-mail', 'Účely odesílání', 'Telefon', 'Poznámka'],
            array_column($result['columns'], 'label'),
        );
        $this->assertTrue($result['columns'][0]['grow']);
        $this->assertSame([
            'name'  => 'Jana Ukázková',
            'role'  => 'účetní',
            'email' => 'jana@example.test',
            'phone' => '+420 000 000 000',
        ], $result['rows'][0]['cells']);
        $this->assertSame('done', $result['rows'][0]['stateStyle']);
        $this->assertNull($result['order_column']);
    }

    public function testContactSendPurposesRenderAsBadgesInConfigOrder(): void
    {
        $rows = [
            ['id' => 1, 'person' => 9, 'name' => 'Účtárna', 'email' => 'ucetni@example.test',
             'send_purposes' => '["reminders","invoices"]', 'docState' => 40],
            ['id' => 2, 'person' => 9, 'name' => 'Recepce', 'email' => 'recepce@example.test',
             'send_purposes' => null, 'docState' => 40],
        ];
        $result = $this->form()->renderSubtable($this->tab('contacts', 'base_persons_contacts'), $rows, ['id' => 9]);

        $this->assertSame([
            ['text' => 'Faktury a daňové doklady', 'badge' => 'neutral'],
            ['text' => 'Upomínky', 'badge' => 'neutral'],
        ], $result['rows'][0]['cells']['send_purposes']);
        $this->assertArrayNotHasKey('send_purposes', $result['rows'][1]['cells'], 'kontakt bez účelu má buňku prázdnou');
    }

    public function testAddressesComposeStreetAndResolveEnums(): void
    {
        $rows = [
            ['id' => 1, 'person' => 9, 'address_type' => 1, 'name' => null, 'street' => 'Ukázková',
             'house_number' => '12', 'orientation_number' => '3', 'city' => 'Praha', 'zip' => '110 00',
             'country' => 'cz', 'docState' => 40],
            ['id' => 2, 'person' => 9, 'address_type' => 2, 'name' => 'Sklad', 'street' => null,
             'house_number' => '45', 'orientation_number' => null, 'city' => 'Brno', 'zip' => null,
             'country' => 'xx', 'docState' => 70],
            ['id' => 3, 'person' => 9, 'address_type' => 1, 'street' => null, 'house_number' => null,
             'city' => null, 'country' => null, 'docState' => 90],
        ];
        $result = $this->form()->renderSubtable($this->tab('addresses', 'base_persons_addresses'), $rows, ['id' => 9]);

        $this->assertSame(['address_type', 'name', 'street', 'city', 'zip', 'country'], array_column($result['columns'], 'id'));
        $this->assertSame(['Typ adresy', 'Název', 'Ulice', 'Obec', 'PSČ', 'Země'], array_column($result['columns'], 'label'));
        $this->assertTrue($result['columns'][2]['grow']);

        $this->assertSame([
            'address_type' => 'Sídlo',
            'street'       => 'Ukázková 12/3',
            'city'         => 'Praha',
            'zip'          => '110 00',
            'country'      => 'Česko',
        ], $result['rows'][0]['cells']);
        // „V pořádku" nese stateStyle jako v gridu (globální .docState_done je bez efektu)
        $this->assertSame('done', $result['rows'][0]['stateStyle']);

        // archivovaná adresa: zobrazená, s tlumeným stylem; bez ulice jen číslo; neznámá země = kód
        $this->assertSame([
            'address_type' => 'Doručovací adresa',
            'name'         => 'Sklad',
            'street'       => '45',
            'city'         => 'Brno',
            'country'      => 'xx',
        ], $result['rows'][1]['cells']);
        $this->assertSame('archive', $result['rows'][1]['stateStyle']);

        $this->assertSame(['address_type' => 'Sídlo'], $result['rows'][2]['cells']);
        $this->assertSame('trash', $result['rows'][2]['stateStyle']);
    }

    public function testBankAccountsUppercaseCurrencyAndSourceLabel(): void
    {
        $rows = [
            ['id' => 1, 'person' => 9, 'name' => 'Provozní', 'account_number' => '000000-0000000000/0000',
             'iban' => 'CZ0000000000000000000000', 'bic' => 'TESTCZPP', 'currency' => 'czk', 'source' => 2, 'docState' => 40],
            ['id' => 2, 'person' => 9, 'name' => null, 'account_number' => '1/0000', 'iban' => null, 'bic' => null,
             'currency' => null, 'source' => 0, 'docState' => 10],
        ];
        $result = $this->form()->renderSubtable($this->tab('bank_accounts', 'base_persons_bank_accounts'), $rows, ['id' => 9]);

        $this->assertSame(['name', 'account_number', 'iban', 'bic', 'currency', 'source'], array_column($result['columns'], 'id'));
        $this->assertSame(['Název účtu', 'Číslo účtu', 'IBAN', 'BIC/SWIFT', 'Měna', 'Zdroj'], array_column($result['columns'], 'label'));
        $this->assertTrue($result['columns'][1]['grow']);
        $this->assertSame([
            'name'           => 'Provozní',
            'account_number' => '000000-0000000000/0000',
            'iban'           => 'CZ0000000000000000000000',
            'bic'            => 'TESTCZPP',
            'currency'       => 'CZK',
            'source'         => 'Registr DPH (API)',
        ], $result['rows'][0]['cells']);
        $this->assertSame(['account_number' => '1/0000', 'source' => 'Ruční pořízení'], $result['rows'][1]['cells']);
        $this->assertSame('concept', $result['rows'][1]['stateStyle']);
    }

    public function testWithoutTableRegistryLabelsFallBackToCzech(): void
    {
        $form = new PersonsForm('base_persons_persons');
        $result = $form->renderSubtable(
            $this->tab('addresses', 'base_persons_addresses'),
            [['id' => 1, 'address_type' => 1, 'street' => 'Ukázková', 'house_number' => '1', 'country' => 'cz']],
            ['id' => 9],
        );
        $this->assertSame(['Typ adresy', 'Název', 'Ulice', 'Obec', 'PSČ', 'Země'], array_column($result['columns'], 'label'));
        // bez definice tabulky / configu se enumy vrací surové
        $this->assertSame(['address_type' => '1', 'street' => 'Ukázková 1', 'country' => 'cz'], $result['rows'][0]['cells']);
        $this->assertArrayNotHasKey('stateStyle', $result['rows'][0]);
    }

    public function testUnknownTabFallsBackToDefaultRenderer(): void
    {
        $result = $this->form()->renderSubtable(
            $this->tab('other', 'base_persons_contacts'),
            [['id' => 1, 'name' => 'X', 'docState' => 70]],
            ['id' => 9],
        );
        // default renderer: první sloupce definice bez FK; archiv se propíše
        $this->assertSame('name', $result['columns'][0]['id']);
        $this->assertSame('archive', $result['rows'][0]['stateStyle']);
    }
}
