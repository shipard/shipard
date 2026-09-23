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
                        ['id' => 'a.b', 'type' => 'select'],                  // nepodporovaný typ
                        ['id' => 'a.c'],                                      // type default = text
                        ['id' => 'a.d', 'type' => 'shell'],                   // podporovaný typ (UI shells Fáze 4)
                    ],
                ],
            ],
        ]);

        $this->assertCount(1, $def->settingsPages);
        $this->assertSame('valid', $def->settingsPages[0]['id']);
        $this->assertCount(2, $def->settingsPages[0]['fields']);
        $this->assertSame('a.c', $def->settingsPages[0]['fields'][0]['id']);
        $this->assertSame('shell', $def->settingsPages[0]['fields'][1]['type']);
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
}
