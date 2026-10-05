<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Core\System;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\JsoncFormLoader;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Core\System\UsersViewer;

/**
 * Uživatel ↔ Osoba (#93 D6): sloupec `person` přidává rozšíření
 * z base.persons — formulář i detail uživatele ho ukazují jen na zdroji
 * dat, který ho má.
 */
class UsersPersonTest extends TestCase
{
    private const TABLE = 'core_system_users';

    private static function root(): string
    {
        return dirname(__DIR__, 5);
    }

    private function usersDef(bool $withPerson): TableDefinition
    {
        $def = JsoncParser::parseFile(self::root() . '/modules/core/system/tables/core_system_users.jsonc');
        if ($withPerson) {
            $ext = JsoncParser::parseFile(self::root() . '/modules/base/persons/extensions/core_system_users.jsonc');
            $def['columns'] = array_merge($def['columns'], $ext['columns']);
        }
        return TableDefinition::fromArray($def);
    }

    private function form(bool $withPerson): FormDefinition
    {
        return (new JsoncFormLoader())->load(
            self::root() . '/modules/core/system/forms/core_system_users.jsonc',
            $this->usersDef($withPerson),
            null,
            self::TABLE,
            'cs',
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function elements(FormDefinition $form): array
    {
        $out = [];
        foreach ($form->tabs as $tab) {
            foreach ($tab->sections as $section) {
                foreach ($section->columns as $column) {
                    foreach ($column->elements as $element) {
                        if ($element->column !== null) {
                            $out[$element->column] = $element->toArray();
                        }
                    }
                }
            }
        }
        return $out;
    }

    public function testFormOffersPersonLookupWhenColumnExists(): void
    {
        $elements = $this->elements($this->form(withPerson: true));

        $this->assertSame('lookup', $elements['person']['type']);
        $this->assertSame('Hledat osobu…', $elements['person']['placeholder']);
        $this->assertSame('base_persons_persons', $elements['person']['lookup']['table']);
    }

    public function testFormWithoutPersonsModuleHasNoPersonField(): void
    {
        $elements = $this->elements($this->form(withPerson: false));

        $this->assertArrayNotHasKey('person', $elements);
        $this->assertSame(
            ['login', 'full_name', 'email', 'is_active', 'is_admin', 'is_system'],
            array_keys($elements),
        );
    }

    public function testFormNeverOffersPassword(): void
    {
        $this->assertArrayNotHasKey('password_hash', $this->elements($this->form(withPerson: true)));
    }

    /** @param array<string, mixed>|null $person */
    private function detail(bool $withPerson, ?int $personId = null, ?array $person = null): array
    {
        $db = $this->createMock(DataSourceConnection::class);
        $db->method('fetchRow')->willReturnCallback(
            function (string $sql) use ($withPerson, $personId, $person): ?array {
                if (str_contains($sql, 'base_persons_persons')) {
                    return $person;
                }
                $this->assertSame($withPerson, str_contains($sql, '`person`'));
                $row = [
                    'id' => 5, 'login' => 'jana', 'full_name' => 'Jana Příkladová', 'email' => 'jana@example.test',
                    'is_active' => 1, 'is_admin' => 0, 'is_system' => 0, 'has_password' => 1,
                ];
                return $withPerson ? $row + ['person' => $personId] : $row;
            },
        );

        $viewer = new UsersViewer($db, self::TABLE);
        $viewer->setLanguage('cs');
        if ($withPerson) {
            $viewer->setTables([self::TABLE => $this->usersDef(true)]);
        }
        return $viewer->renderDetail(5);
    }

    /** @return array<string, string> */
    private function identity(array $detail): array
    {
        $items = $detail['tabs'][0]['content']['groups'][0]['items'];
        return array_column($items, 'value', 'label');
    }

    public function testDetailShowsLinkedPersonName(): void
    {
        $identity = $this->identity($this->detail(true, 12, ['full_name' => 'Příkladová Jana']));

        $this->assertSame('Příkladová Jana', $identity['Osoba']);
    }

    public function testDetailShowsEmptyPersonForMissingOrDanglingLink(): void
    {
        $this->assertSame('', $this->identity($this->detail(true, null))['Osoba']);
        // Po ds-reset: uživatel zůstal, Osoba s tímto id už není.
        $this->assertSame('', $this->identity($this->detail(true, 12, null))['Osoba']);
    }

    public function testDetailWithoutPersonColumnHasNoPersonRow(): void
    {
        $this->assertArrayNotHasKey('Osoba', $this->identity($this->detail(false)));
    }
}
