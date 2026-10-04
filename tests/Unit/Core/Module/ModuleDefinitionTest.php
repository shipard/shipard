<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Core\Module;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;

class ModuleDefinitionTest extends TestCase
{
    public function testFromArrayValid(): void
    {
        $data = [
            'id' => 'economy.docs',
            'name' => 'Documents',
            'description' => 'Invoices and orders',
            'dependencies' => ['core.system', 'base.persons'],
            'tables' => ['economy_docs_heads', 'economy_docs_rows'],
            'extensions' => [['table' => 'base_persons_contacts', 'file' => 'extensions/ext.jsonc']],
            'config' => [['id' => 'economy.docs.vatRates', 'file' => 'config/vatRates.jsonc']],
        ];

        $def = ModuleDefinition::fromArray($data);

        $this->assertSame('economy.docs', $def->id);
        $this->assertSame('Documents', $def->name);
        $this->assertSame('Invoices and orders', $def->description);
        $this->assertSame(['core.system', 'base.persons'], $def->dependencies);
        $this->assertSame(['economy_docs_heads', 'economy_docs_rows'], $def->tables);
        $this->assertSame([['table' => 'base_persons_contacts', 'file' => 'extensions/ext.jsonc']], $def->extensions);
        $this->assertSame([['id' => 'economy.docs.vatRates', 'file' => 'config/vatRates.jsonc']], $def->config);
    }

    public function testMissingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['name' => 'Test']);
    }

    public function testEmptyIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => '', 'name' => 'Test']);
    }

    public function testMissingNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'core.system']);
    }

    public function testInvalidIdFormatNoDotThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'nodot', 'name' => 'Test']);
    }

    public function testInvalidIdFormatUppercaseGroupThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'A.b', 'name' => 'Test']);
    }

    public function testIdFormatCamelCaseModulePartAllowed(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'docs.invoicesOut', 'name' => 'Issued']);
        $this->assertSame('docs.invoicesOut', $def->id);
    }

    public function testIdFormatModulePartMustStartLowercaseThrows(): void
    {
        // First char of module part must be lowercase (PSR-4-friendly directory name).
        $this->expectException(\InvalidArgumentException::class);
        ModuleDefinition::fromArray(['id' => 'docs.InvoicesOut', 'name' => 'Issued']);
    }

    public function testOptionalFieldsDefault(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'core.system', 'name' => 'System']);

        $this->assertSame('', $def->description);
        $this->assertSame([], $def->dependencies);
        $this->assertSame([], $def->tables);
        $this->assertSame([], $def->extensions);
        $this->assertSame([], $def->config);
        $this->assertSame([], $def->documentClasses);
    }

    public function testDocumentClassesSingleClass(): void
    {
        $documentClasses = [
            ['table' => 'base_persons_persons', 'class' => 'Shipard\\Module\\Base\\Persons\\PersonDocument'],
        ];

        $def = ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'documentClasses' => $documentClasses,
        ]);

        $this->assertSame($documentClasses, $def->documentClasses);
    }

    public function testDocumentClassesWithTypeColumn(): void
    {
        $documentClasses = [
            [
                'table' => 'economy_docs_heads',
                'typeColumn' => 'doc_type',
                'classes' => [
                    'inv_issued' => 'Shipard\\Module\\Economy\\Docs\\IssuedInvoiceDocument',
                    'inv_received' => 'Shipard\\Module\\Economy\\Docs\\ReceivedInvoiceDocument',
                ],
                'defaultClass' => 'Shipard\\Module\\Economy\\Docs\\GenericDocDocument',
            ],
        ];

        $def = ModuleDefinition::fromArray([
            'id' => 'economy.docs',
            'name' => 'Documents',
            'documentClasses' => $documentClasses,
        ]);

        $this->assertSame($documentClasses, $def->documentClasses);
    }

    public function testWithoutDocumentClassesDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'economy.docs', 'name' => 'Documents']);

        $this->assertSame([], $def->documentClasses);
    }

    public function testSettingsItemsParsedCorrectly(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['viewer' => 'economy.codebooks.cashDesks', 'section' => 'accounting'],
                ['viewer' => 'economy.codebooks.warehouses', 'section' => 'warehouses', 'order' => 5],
            ],
        ]);

        $this->assertCount(2, $def->settingsItems);
        $this->assertSame('economy.codebooks.cashDesks', $def->settingsItems[0]['viewer']);
        $this->assertNull($def->settingsItems[0]['table']);
        $this->assertSame('accounting', $def->settingsItems[0]['section']);
        $this->assertNull($def->settingsItems[0]['subsection']);
        $this->assertNull($def->settingsItems[0]['order']);
        $this->assertNull($def->settingsItems[0]['visibilityClass']);
        $this->assertSame(5, $def->settingsItems[1]['order']);
    }

    public function testSettingsItemsVisibilityClassParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                [
                    'viewer'          => 'economy.codebooks.vatRegistrations',
                    'section'         => 'accounting',
                    'visibilityClass' => 'Shipard\\Module\\Economy\\Codebooks\\VatAgendaNavGate',
                ],
            ],
        ]);

        $this->assertSame(
            'Shipard\\Module\\Economy\\Codebooks\\VatAgendaNavGate',
            $def->settingsItems[0]['visibilityClass'],
        );
    }

    public function testSettingsItemsSubsectionParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.mail',
            'name' => 'Mail',
            'settingsItems' => [
                ['table' => 'core_mail_mailboxes', 'section' => 'other', 'subsection' => 'other.mail', 'order' => 10],
                ['table' => 'core_attachments_files', 'section' => 'other'],
            ],
        ]);

        $this->assertSame('other.mail', $def->settingsItems[0]['subsection']);
        // Bez subsection → null (zpětná kompatibilita — položka padne přímo do sekce).
        $this->assertNull($def->settingsItems[1]['subsection']);
    }

    public function testSettingsItemsMissingSectionIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['viewer' => 'economy.codebooks.cashDesks'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsBothViewerAndTableIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['viewer' => 'economy.codebooks.cashDesks', 'table' => 'some_table', 'section' => 'accounting'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsNeitherViewerNorTableIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.codebooks',
            'name' => 'Codebooks',
            'settingsItems' => [
                ['section' => 'accounting'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'economy.docs', 'name' => 'Documents']);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsItemsPageParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsItems' => [
                ['page' => 'appSettings', 'section' => 'app', 'order' => 10],
            ],
        ]);

        $this->assertCount(1, $def->settingsItems);
        $this->assertSame('appSettings', $def->settingsItems[0]['page']);
        $this->assertNull($def->settingsItems[0]['viewer']);
        $this->assertNull($def->settingsItems[0]['table']);
        $this->assertSame('app', $def->settingsItems[0]['section']);
        $this->assertSame(10, $def->settingsItems[0]['order']);
    }

    public function testSettingsItemsPageCombinedWithViewerIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsItems' => [
                ['page' => 'appSettings', 'viewer' => 'core.units', 'section' => 'app'],
            ],
        ]);

        $this->assertSame([], $def->settingsItems);
    }

    public function testSettingsPagesParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsPages' => [
                [
                    'id'      => 'appSettings',
                    'name'    => 'Application',
                    'name:cs' => 'Aplikace',
                    'icon'    => 'settings',
                    'fields'  => [
                        ['id' => 'app.name', 'type' => 'text', 'name' => 'Name', 'maxLength' => 120],
                        ['id' => 'app.icon', 'type' => 'image', 'slot' => 'icon', 'name' => 'Icon'],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $def->settingsPages);
        $page = $def->settingsPages[0];
        $this->assertSame('appSettings', $page['id']);
        $this->assertSame('Aplikace', $page['name:cs']);
        $this->assertCount(2, $page['fields']);
        $this->assertSame('app.name', $page['fields'][0]['id']);
        $this->assertSame('image', $page['fields'][1]['type']);
        $this->assertSame('icon', $page['fields'][1]['slot']);
    }

    public function testSettingsPagesInvalidEntriesSkipped(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsPages' => [
                'not-an-object',
                ['name' => 'Missing id', 'fields' => []],
                ['id' => 'noFields'],
                [
                    'id'     => 'valid',
                    'fields' => [
                        ['type' => 'text'],                                  // chybí id pole
                        ['id' => 'a.b', 'type' => 'toggle'],                  // nepodporovaný typ
                        ['id' => 'a.c'],                                      // type default = text
                        ['id' => 'a.d', 'type' => 'shell'],                   // podporovaný typ (UI shells Fáze 4)
                        ['id' => 'a.e', 'type' => 'select'],                  // select bez nabídky
                        ['id' => 'a.f', 'type' => 'select', 'options' => [
                            'junk',
                            ['label' => 'no value'],
                            ['value' => 'year', 'label' => 'Yearly', 'label:cs' => 'Ročně'],
                        ]],
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $def->settingsPages);
        $this->assertSame('valid', $def->settingsPages[0]['id']);
        $this->assertCount(3, $def->settingsPages[0]['fields']);
        $this->assertSame('a.c', $def->settingsPages[0]['fields'][0]['id']);
        $this->assertSame('shell', $def->settingsPages[0]['fields'][1]['type']);
        // select drží jen platné položky nabídky
        $this->assertSame('a.f', $def->settingsPages[0]['fields'][2]['id']);
        $this->assertSame([['value' => 'year', 'label' => 'Yearly', 'label:cs' => 'Ročně']], $def->settingsPages[0]['fields'][2]['options']);
    }

    public function testSettingsPagesAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'economy.docs', 'name' => 'Documents']);

        $this->assertSame([], $def->settingsPages);
    }

    public function testSettingsPagesScopeDefaultsToDs(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsPages' => [
                ['id' => 'appSettings', 'fields' => [['id' => 'app.name', 'type' => 'text']]],
            ],
        ]);

        $this->assertSame('ds', $def->settingsPages[0]['scope']);
    }

    public function testSettingsPagesScopeUserPreserved(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsPages' => [
                ['id' => 'accountBasic', 'scope' => 'user', 'fields' => [['id' => 'account.theme', 'type' => 'theme']]],
            ],
        ]);

        $this->assertSame('user', $def->settingsPages[0]['scope']);
    }

    public function testSettingsPagesUnknownScopeFallsBackToDs(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsPages' => [
                ['id' => 'p', 'scope' => 'bogus', 'fields' => [['id' => 'a.b', 'type' => 'text']]],
            ],
        ]);

        $this->assertSame('ds', $def->settingsPages[0]['scope']);
    }

    public function testSettingsPagesThemeAndLanguageFieldTypesAccepted(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'settingsPages' => [
                [
                    'id'     => 'accountBasic',
                    'scope'  => 'user',
                    'fields' => [
                        ['id' => 'account.theme', 'type' => 'theme'],
                        ['id' => 'account.language', 'type' => 'language'],
                        ['id' => 'account.bogus', 'type' => 'select'],  // nepodporovaný → zahozeno
                    ],
                ],
            ],
        ]);

        $fields = $def->settingsPages[0]['fields'];
        $this->assertCount(2, $fields);
        $this->assertSame('theme', $fields[0]['type']);
        $this->assertSame('language', $fields[1]['type']);
    }

    public function testAccountItemsParsedLikeSettingsItems(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'core.system',
            'name' => 'System',
            'accountItems' => [
                ['page' => 'accountBasic', 'section' => 'basic', 'order' => 10],
            ],
        ]);

        $this->assertCount(1, $def->accountItems);
        $this->assertSame('accountBasic', $def->accountItems[0]['page']);
        $this->assertSame('basic', $def->accountItems[0]['section']);
        $this->assertSame(10, $def->accountItems[0]['order']);
        $this->assertNull($def->accountItems[0]['viewer']);
    }

    public function testAccountItemsAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'core.system', 'name' => 'System']);

        $this->assertSame([], $def->accountItems);
    }

    public function testLookupsParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'base.persons',
            'name' => 'Persons',
            'lookups' => [
                ['table' => 'base_persons_persons', 'class' => 'Foo\\Bar\\PersonsLookup'],
                ['table' => 'base_persons_addresses', 'class' => 'Foo\\Bar\\AddressesLookup'],
            ],
        ]);

        $this->assertCount(2, $def->lookups);
        $this->assertSame('base_persons_persons', $def->lookups[0]['table']);
        $this->assertSame('Foo\\Bar\\PersonsLookup', $def->lookups[0]['class']);
    }

    public function testLookupsMissingTableIgnored(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'base.persons',
            'name' => 'Persons',
            'lookups' => [
                ['class' => 'Foo\\Bar\\Lookup'],
                ['table' => 't', 'class' => 'Foo\\Bar\\Lookup'],
            ],
        ]);

        $this->assertCount(1, $def->lookups);
    }

    public function testLookupsAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->lookups);
    }

    public function testAlertChecksAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->alertChecks);
    }

    public function testAlertChecksRawPassthrough(): void
    {
        $raw = [
            [
                'id' => 'base.persons.missing_own_person',
                'name' => 'Own Person is missing',
                'name:cs' => 'Chybí vlastní Osoba',
                'class' => 'Foo\\Bar',
                'interval' => '1h',
            ],
            [
                'id' => 'base.persons.duplicate_own',
                'name' => 'Multiple own persons',
                'class' => 'Foo\\Baz',
                'interval' => '4h',
            ],
        ];

        $def = ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => $raw,
        ]);

        // Module-level passthrough — :cs suffix zůstává až do ConfigLocalizeru.
        $this->assertCount(2, $def->alertChecks);
        $this->assertSame('base.persons.missing_own_person', $def->alertChecks[0]['id']);
        $this->assertSame('Chybí vlastní Osoba', $def->alertChecks[0]['name:cs']);
    }

    public function testAlertChecksMissingIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing 'id'");
        ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => [
                ['name' => 'x', 'class' => 'Y', 'interval' => '1h'],
            ],
        ]);
    }

    public function testAlertChecksDuplicateIdWithinModuleThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate alertChecks id');
        ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => [
                ['id' => 'x.y', 'name' => 'a', 'class' => 'A', 'interval' => '1h'],
                ['id' => 'x.y', 'name' => 'b', 'class' => 'B', 'interval' => '2h'],
            ],
        ]);
    }

    public function testAlertChecksNonArrayEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be an object');
        ModuleDefinition::fromArray([
            'id' => 'base.persons',
            'name' => 'Persons',
            'alertChecks' => ['not-an-array'],
        ]);
    }

    public function testKeepOnResetParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'     => 'core.system',
            'name'   => 'System',
            'tables' => ['core_system_users', 'core_system_sessions'],
            'keepOnReset' => ['core_system_users', 'core_system_sessions'],
        ]);

        $this->assertSame(['core_system_users', 'core_system_sessions'], $def->keepOnReset);
    }

    public function testKeepOnResetAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->keepOnReset);
    }

    public function testKeepOnResetForeignTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a table owned by this module');
        ModuleDefinition::fromArray([
            'id'     => 'core.system',
            'name'   => 'System',
            'tables' => ['core_system_users'],
            'keepOnReset' => ['base_persons_persons'],
        ]);
    }

    public function testKeepOnResetEmptyStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a non-empty string');
        ModuleDefinition::fromArray([
            'id'     => 'core.system',
            'name'   => 'System',
            'tables' => ['core_system_users'],
            'keepOnReset' => [''],
        ]);
    }

    public function testKeepOnResetNonStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a non-empty string');
        ModuleDefinition::fromArray([
            'id'     => 'core.system',
            'name'   => 'System',
            'tables' => ['core_system_users'],
            'keepOnReset' => [123],
        ]);
    }

    public function testKeepOnResetNotListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a JSON array of table names');
        ModuleDefinition::fromArray([
            'id'     => 'core.system',
            'name'   => 'System',
            'tables' => ['core_system_users'],
            'keepOnReset' => ['key' => 'core_system_users'],
        ]);
    }

    public function testNavigationProvidersParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'navigationProviders' => [
                ['class' => 'Shipard\\Module\\Economy\\Accbal\\BalancesNavigationProvider'],
            ],
        ]);

        $this->assertSame(
            [['class' => 'Shipard\\Module\\Economy\\Accbal\\BalancesNavigationProvider']],
            $def->navigationProviders,
        );
    }

    public function testNavigationProvidersAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->navigationProviders);
    }

    public function testNavigationProvidersMissingClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("navigationProviders[0] requires 'class'");
        ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'navigationProviders' => [['klass' => 'Foo\\Bar']],
        ]);
    }

    // ── documentLockProviders (#55 D24) ─────────────────────────────────────

    public function testDocumentLockProvidersParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.vat',
            'name' => 'VAT',
            'documentLockProviders' => [
                ['table' => 'docs_core_heads', 'class' => 'Foo\\VatPeriodLockProvider', 'extra' => 'ignored'],
            ],
        ]);

        $this->assertSame(
            [['table' => 'docs_core_heads', 'class' => 'Foo\\VatPeriodLockProvider']],
            $def->documentLockProviders,
        );
    }

    public function testDocumentLockProvidersAbsentDefaultsToEmpty(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->documentLockProviders);
    }

    public function testDocumentLockProvidersMissingTableThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("documentLockProviders[0] requires 'table' and 'class'");
        ModuleDefinition::fromArray([
            'id'   => 'economy.vat',
            'name' => 'VAT',
            'documentLockProviders' => [['class' => 'Foo\\Bar']],
        ]);
    }

    // ── openItemLookup (#69 D3/D8) ──────────────────────────────────────────

    public function testOpenItemLookupParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'openItemLookup' => 'Shipard\\Module\\Economy\\Accbal\\LedgerOpenItemLookup',
        ]);

        $this->assertSame('Shipard\\Module\\Economy\\Accbal\\LedgerOpenItemLookup', $def->openItemLookup);
    }

    public function testOpenItemLookupAbsentDefaultsToNull(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertNull($def->openItemLookup);
    }

    public function testOpenItemLookupEmptyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('openItemLookup must be a non-empty class name');
        ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'openItemLookup' => '',
        ]);
    }

    public function testOpenItemLookupNonStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('openItemLookup must be a non-empty class name');
        ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'openItemLookup' => ['class' => 'Foo\\Bar'],
        ]);
    }

    // ── journalContributors (#79 D3b) ───────────────────────────────────────

    public function testJournalContributorsParsedInOrder(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'journalContributors' => [
                'Shipard\\Module\\Economy\\Accbal\\CaseClosureContributor',
                'Shipard\\Module\\Economy\\Accbal\\OtherContributor',
            ],
        ]);

        $this->assertSame([
            'Shipard\\Module\\Economy\\Accbal\\CaseClosureContributor',
            'Shipard\\Module\\Economy\\Accbal\\OtherContributor',
        ], $def->journalContributors);
    }

    public function testJournalContributorsAbsentDefaultsToEmptyList(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->journalContributors);
    }

    public function testJournalContributorsNotAListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('journalContributors must be a JSON array of class names');
        ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'journalContributors' => 'Shipard\\Module\\Economy\\Accbal\\CaseClosureContributor',
        ]);
    }

    public function testJournalContributorsEmptyClassThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('journalContributors[1] must be a non-empty class name');
        ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'journalContributors' => ['Shipard\\Module\\Economy\\Accbal\\CaseClosureContributor', ''],
        ]);
    }

    public function testJournalContributorsObjectEntryThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('journalContributors[0] must be a non-empty class name');
        ModuleDefinition::fromArray([
            'id'   => 'economy.accbal',
            'name' => 'Open items',
            'journalContributors' => [['class' => 'Foo\\Bar']],
        ]);
    }

    // ── recordSenderProviders (#90 D39) ─────────────────────────────────────

    public function testRecordSenderProvidersParsed(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'docs.core',
            'name' => 'Docs',
            'recordSenderProviders' => [['table' => 'docs_core_heads', 'class' => 'Foo\\Provider', 'ignored' => 1]],
        ]);

        $this->assertSame([['table' => 'docs_core_heads', 'class' => 'Foo\\Provider']], $def->recordSenderProviders);
        $this->assertSame([], ModuleDefinition::fromArray(['id' => 'docs.core', 'name' => 'Docs'])->recordSenderProviders);
    }

    public function testRecordSenderProviderWithoutClassIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/recordSenderProviders\\[0\\] requires 'table' and 'class'/");

        ModuleDefinition::fromArray([
            'id'   => 'docs.core',
            'name' => 'Docs',
            'recordSenderProviders' => [['table' => 'docs_core_heads']],
        ]);
    }

    // ── sendPurposes (#90 D34) ──────────────────────────────────────────────

    public function testSendPurposesParsedWithLocalizedNamesAndDefaultOrder(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'base.persons',
            'name' => 'Persons',
            'sendPurposes' => [
                ['id' => 'invoices', 'name' => 'Invoices', 'name:cs' => 'Faktury', 'order' => 10, 'ignored' => 'x'],
                ['id' => 'offersOrders', 'name' => 'Offers and orders'],
            ],
        ]);

        $this->assertSame([
            ['id' => 'invoices', 'order' => 10, 'name' => 'Invoices', 'name:cs' => 'Faktury'],
            ['id' => 'offersOrders', 'order' => 1000, 'name' => 'Offers and orders'],
        ], $def->sendPurposes);
    }

    public function testSendPurposesAbsentDefaultsToEmptyList(): void
    {
        $this->assertSame([], ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons'])->sendPurposes);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidSendPurposes')]
    public function testInvalidSendPurposesAreRejected(mixed $purposes, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches($message);

        ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons', 'sendPurposes' => $purposes]);
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidSendPurposes(): array
    {
        return [
            'not a list'    => [['invoices' => ['name' => 'Invoices']], '/must be a JSON array/'],
            'not an object' => [['invoices'], '/sendPurposes\[0\] must be an object/'],
            'bad id'        => [[['id' => 'Faktury a doklady', 'name' => 'X']], '/id must be an identifier/'],
            'missing name'  => [[['id' => 'invoices']], "/requires 'name'/"],
            'bad order'     => [[['id' => 'invoices', 'name' => 'X', 'order' => 'first']], '/order must be an integer/'],
            'duplicate id'  => [
                [['id' => 'invoices', 'name' => 'X'], ['id' => 'invoices', 'name' => 'Y']],
                "/duplicate id 'invoices'/",
            ],
        ];
    }

    // ── journalDimensions (assets D47) ──────────────────────────────────────

    /** @return array<string, mixed> */
    private function assetDimension(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => null,
            'journalColumn' => 'asset', 'table' => 'economy_assets_assets',
            'name' => 'Asset', 'name:cs' => 'Majetek',
        ];
    }

    public function testJournalDimensionsParsedWithLocalizedNames(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.assets',
            'name' => 'Assets',
            'journalDimensions' => [$this->assetDimension(['headColumn' => 'asset', 'rowFlag' => 'rowAsset', 'ignored' => 'x'])],
        ]);

        $this->assertSame([[
            'id' => 'asset', 'rowColumn' => 'asset', 'headColumn' => 'asset',
            'journalColumn' => 'asset', 'table' => 'economy_assets_assets',
            'rowFlag' => 'rowAsset',
            'name' => 'Asset', 'name:cs' => 'Majetek',
        ]], $def->journalDimensions);
    }

    public function testJournalDimensionsAbsentDefaultsToEmptyList(): void
    {
        $def = ModuleDefinition::fromArray(['id' => 'base.persons', 'name' => 'Persons']);

        $this->assertSame([], $def->journalDimensions);
    }

    public function testJournalDimensionMissingRequiredKeyThrows(): void
    {
        $dimension = $this->assetDimension();
        unset($dimension['journalColumn']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("journalDimensions[0] requires 'journalColumn'");
        ModuleDefinition::fromArray(['id' => 'economy.assets', 'name' => 'Assets', 'journalDimensions' => [$dimension]]);
    }

    public function testJournalDimensionColumnMustBeIdentifier(): void
    {
        // Sloupce a tabulka jdou do SQL — nic než identifikátor.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('journalDimensions[0].rowColumn must be an identifier');
        ModuleDefinition::fromArray([
            'id' => 'economy.assets', 'name' => 'Assets',
            'journalDimensions' => [$this->assetDimension(['rowColumn' => 'asset`; DROP'])],
        ]);
    }

    public function testJournalDimensionDuplicateIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("duplicate id 'asset'");
        ModuleDefinition::fromArray([
            'id' => 'economy.assets', 'name' => 'Assets',
            'journalDimensions' => [$this->assetDimension(), $this->assetDimension()],
        ]);
    }

    public function testJournalDimensionsNotAListThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('journalDimensions must be a JSON array');
        ModuleDefinition::fromArray([
            'id' => 'economy.assets', 'name' => 'Assets',
            'journalDimensions' => $this->assetDimension(),
        ]);
    }

    public function testJournalDimensionFormsParsedWithDefaults(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.assets',
            'name' => 'Assets',
            'journalDimensions' => [$this->assetDimension([
                'headColumn' => 'asset',
                'forms' => [
                    'docTypes' => ['invni', 'cash', 'invni'],
                    'head' => true,
                    'enabledBySetting' => 'economy.assets.trackExpenses',
                    'ignored' => 1,
                ],
            ])],
        ]);

        $this->assertSame([
            'docTypes' => ['invni', 'cash'],
            'head' => true,
            'rows' => false,
            'enabledBySetting' => 'economy.assets.trackExpenses',
        ], $def->journalDimensions[0]['forms']);
    }

    /** @return iterable<string, array{array<string, mixed>, mixed, string}> */
    public static function invalidJournalDimensionForms(): iterable
    {
        yield 'list'           => [[], ['invni'], 'forms must be an object'];
        yield 'no docTypes'    => [[], ['rows' => true], 'forms.docTypes must be a non-empty array'];
        yield 'empty docType'  => [[], ['docTypes' => ['']], 'forms.docTypes must contain non-empty strings'];
        yield 'rows not bool'  => [[], ['docTypes' => ['invni'], 'rows' => 1], 'forms.rows must be a boolean'];
        yield 'setting'        => [[], ['docTypes' => ['invni'], 'enabledBySetting' => ''], 'forms.enabledBySetting must be'];
        // Pole na hlavičce nemá bez headColumn kam uložit hodnotu.
        yield 'head no column' => [['headColumn' => null], ['docTypes' => ['invni'], 'head' => true], 'forms.head requires headColumn'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidJournalDimensionForms')]
    public function testJournalDimensionFormsAreValidated(array $overrides, mixed $forms, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ModuleDefinition::fromArray([
            'id' => 'economy.assets', 'name' => 'Assets',
            'journalDimensions' => [$this->assetDimension($overrides + ['headColumn' => 'asset', 'forms' => $forms])],
        ]);
    }

    // ── settingsPages: select s optionsProvider (assets D54) ────────────────

    public function testSelectFieldMayTakeOptionsFromProvider(): void
    {
        $def = ModuleDefinition::fromArray([
            'id'   => 'economy.assets',
            'name' => 'Assets',
            'settingsPages' => [[
                'id' => 'p', 'name' => 'P',
                'fields' => [
                    ['id' => 'a.series', 'type' => 'select', 'name' => 'Series', 'optionsProvider' => 'Foo\\SeriesOptions'],
                    ['id' => 'a.static', 'type' => 'select', 'name' => 'Static', 'options' => [['value' => 'x', 'label' => 'X']]],
                    // Bez nabídky i bez provideru pole nejde uložit → zahodí se.
                    ['id' => 'a.none', 'type' => 'select', 'name' => 'None'],
                    ['id' => 'a.empty', 'type' => 'select', 'name' => 'Empty', 'optionsProvider' => ''],
                ],
            ]],
        ]);

        $fields = array_column($def->settingsPages[0]['fields'], null, 'id');
        $this->assertSame(['a.series', 'a.static'], array_keys($fields));
        $this->assertSame('Foo\\SeriesOptions', $fields['a.series']['optionsProvider']);
        $this->assertSame([], $fields['a.series']['options']);
        $this->assertArrayNotHasKey('optionsProvider', $fields['a.static']);
    }

    // ── prints: deklarace tisků v JSONC souborech (#90 D4) ──────────────────

    public function testPrintsDefaultToEmptyAndParseFileEntries(): void
    {
        $this->assertSame([], ModuleDefinition::fromArray(['id' => 'docs.core', 'name' => 'Docs'])->prints);

        $def = ModuleDefinition::fromArray([
            'id'     => 'docs.invoicesOut',
            'name'   => 'Issued invoices',
            'prints' => [['file' => 'config/prints.jsonc', 'ignored' => true]],
        ]);
        $this->assertSame([['file' => 'config/prints.jsonc']], $def->prints);
    }

    public function testPrintsEntryWithoutFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("prints[0] requires 'file'");
        ModuleDefinition::fromArray([
            'id'     => 'docs.invoicesOut',
            'name'   => 'Issued invoices',
            'prints' => [['path' => 'config/prints.jsonc']],
        ]);
    }
}
