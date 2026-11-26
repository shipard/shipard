<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleKind;
use Shipard\Core\RecordAction\RecordAction;
use Shipard\Module\Economy\SalesRegistration\Actions\UploadCertificateAction;
use Shipard\Module\Economy\SalesRegistration\Actions\VerifyConnectionAction;
use Shipard\Module\Economy\SalesRegistration\Country\Cz\Eet2Engine;
use Shipard\Module\Economy\SalesRegistration\Engine\SalesRegistrationEngine;
use Shipard\Module\Economy\SalesRegistration\Certificate\KeyFiles;
use Shipard\Core\Server\PermissionSpec;
use Shipard\Core\Utils\JsoncParser;
use Shipard\Module\Economy\SalesRegistration\RegistrationDocument;
use Shipard\Module\Economy\SalesRegistration\RegistrationsForm;
use Shipard\Module\Economy\SalesRegistration\RegistrationsViewer;
use Shipard\Module\Economy\SalesRegistration\SalesRegistrationTypes;
use Shipard\Module\Economy\SalesRegistration\UnitDocument;

/**
 * Deklarace doplňku economy.salesRegistration nad skutečnými soubory
 * (tasks/sales-registration-phase1.md): doplněk mimo install.base, bez
 * závislosti na modulu země, mapa enginů jako cfgItem doplňku.
 */
class SalesRegistrationModuleTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const MODULE = self::MODULES . '/economy/salesRegistration';

    /** @return array<string, mixed> */
    private static function module(): array
    {
        return JsoncParser::parseFile(self::MODULE . '/module.jsonc');
    }

    public function testIsAnAddonWithMinimalDependencies(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $this->assertSame('economy.salesRegistration', $module->id);
        $this->assertSame(ModuleKind::Addon, $module->kind);
        $this->assertSame(['core.system'], $module->dependencies, 'typ se nabízí podle aktivního modulu země, ne přes závislost');
    }

    public function testIsNotPartOfTheBaseInstallation(): void
    {
        $base = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');

        $this->assertNotContains('economy.salesRegistration', $base['dependencies'] ?? []);
    }

    public function testEveryConfigFileExistsAndParses(): void
    {
        foreach (self::module()['config'] ?? [] as $item) {
            $file = self::MODULE . '/' . $item['file'];
            $this->assertFileExists($file, $item['id']);
            $this->assertIsArray(JsoncParser::parseFile($file), $item['id']);
            $this->assertStringStartsWith('economy.salesRegistration.', $item['id']);
        }
    }

    public function testEngineMapIsDeclaredByTheAddon(): void
    {
        $files = array_column(self::module()['config'] ?? [], 'file', 'id');

        $this->assertSame('config/engines.jsonc', $files[SalesRegistrationTypes::ENGINES_CFG] ?? null);
    }

    /** Každý engine z mapy patří typu, který některý modul země deklaruje. */
    public function testEveryMappedTypeIsDeclaredByItsCountryModule(): void
    {
        $engines = JsoncParser::parseFile(self::MODULE . '/config/engines.jsonc');

        foreach ($engines as $typeId => $class) {
            $country = explode('.', (string) $typeId)[0];
            $file = self::MODULES . "/world/{$country}/config/salesRegistrationTypes.jsonc";
            $this->assertFileExists($file, "typ {$typeId}: modul země world.{$country} typy nedeklaruje");
            $this->assertArrayHasKey($typeId, JsoncParser::parseFile($file));
            $this->assertStringStartsWith('Shipard\\Module\\Economy\\SalesRegistration\\', (string) $class);
            $this->assertTrue(class_exists((string) $class), "typ {$typeId}: třída engine {$class} neexistuje");
            $this->assertTrue(is_subclass_of((string) $class, SalesRegistrationEngine::class), "typ {$typeId}: {$class} není engine");
            $this->assertSame(0, (new \ReflectionClass((string) $class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0, 'engine vzniká bez argumentů');
        }
        $this->assertSame(['cz.eet2' => Eet2Engine::class], $engines);
    }

    public function testDeclaresTwoTablesWithUniqueIds(): void
    {
        $module = ModuleDefinition::fromArray(self::module());

        $ids = [];
        foreach ($module->tables as $table) {
            $ids[$table] = TableDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/tables/' . $table . '.jsonc'))->tableId;
        }
        $this->assertSame([RegistrationDocument::TABLE => 468, UnitDocument::TABLE => 469], $ids);

        $seen = [];
        foreach (glob(self::MODULES . '/*/*/tables/*.jsonc') as $file) {
            $id = (int) (JsoncParser::parseFile($file)['tableId'] ?? 0);
            $this->assertArrayNotHasKey($id, $seen, "tableId {$id}: {$file} i " . ($seen[$id] ?? ''));
            $seen[$id] = $file;
        }
    }

    public function testRegistrationColumnsKeepTheKeyEncryptedAndCertificateSystemOnly(): void
    {
        $raw = JsoncParser::parseFile(self::MODULE . '/tables/' . RegistrationDocument::TABLE . '.jsonc');
        $columns = array_column($raw['columns'], null, 'id');

        $this->assertSame('encrypted_text', $columns['cert_key']['type']);
        $this->assertTrue($columns['cert_key']['sensitive']);
        foreach (['taxpayer_id', 'cert_pem', 'cert_key', 'cert_valid_from', 'cert_valid_to', 'cert_serial', 'cert_uploaded_at', 'cert_uploaded_by'] as $column) {
            $this->assertTrue($columns[$column]['system'] ?? false, "{$column}: plní jen nahrání certifikátu");
            $this->assertTrue($columns[$column]['nullable'], $column);
        }
        foreach (['password', 'cert_password', 'passphrase'] as $column) {
            $this->assertArrayNotHasKey($column, $columns, 'heslo k .p12 se neukládá (#171 D11)');
        }

        $this->assertSame('varchar', $columns['type']['type'], 'nabídka typů se skládá ze dvou cfgItemů — hlídá Document');
        $this->assertSame(RegistrationDocument::MODES_CFG, $columns['mode']['cfgItem']);
        $this->assertSame(RegistrationDocument::MODE_VERIFICATION, $columns['mode']['default'], 'nová registrace nic neeviduje');
        $this->assertSame(UnitDocument::TABLE, $columns['default_unit']['reference']);
        $this->assertSame(10, $columns['docState']['default']);

        $def = TableDefinition::fromArray($raw);
        $this->assertSame('core.system.docStatesArchive', $def->docStates?->cfgItem);
        $this->assertArrayNotHasKey('exchangeKey', $raw, 'registrace s certifikátem se mezi zdroji dat nepřenáší');
        foreach ($raw['indexes'] as $index) {
            $this->assertNotSame('unique', $index['type'], 'unikátnost platí jen mezi živými registracemi — hlídá Document');
        }
    }

    public function testModesConfigMatchesDocumentConstants(): void
    {
        $modes = JsoncParser::parseFile(self::MODULE . '/config/modes.jsonc');

        $this->assertSame(RegistrationDocument::MODES, array_keys($modes));
        foreach ($modes as $id => $mode) {
            $this->assertLessThanOrEqual(12, strlen((string) $id), 'délka sloupce mode');
            foreach (['name', 'name:cs', 'name:en'] as $key) {
                $this->assertNotSame('', trim((string) ($mode[$key] ?? '')), "{$id}: {$key}");
            }
        }
    }

    public function testUnitsAreAHiddenChildWithUniqueNumberPerRegistration(): void
    {
        $raw = JsoncParser::parseFile(self::MODULE . '/tables/' . UnitDocument::TABLE . '.jsonc');
        $columns = array_column($raw['columns'], null, 'id');

        $this->assertTrue($raw['hideFromNavigation']);
        $this->assertSame(RegistrationDocument::TABLE, $columns['registration']['reference']);
        $this->assertSame('varchar', $columns['unit_id']['type'], 'tvar čísla říká maska typu, ne sloupec');
        $this->assertSame('unique', $raw['indexes'][0]['type']);
        $this->assertSame([['column' => 'registration'], ['column' => 'unit_id']], $raw['indexes'][0]['columns']);
    }

    public function testViewerLivesInItsOwnSettingsSection(): void
    {
        $module = ModuleDefinition::fromArray(self::module());
        $raw = self::module();

        $this->assertSame('salesRegistration', $raw['settingsSections'][0]['id']);
        $this->assertSame('Evidence tržeb', $raw['settingsSections'][0]['name:cs']);
        $this->assertSame(
            [['viewer' => 'economy.salesRegistration.registrations', 'section' => 'salesRegistration', 'order' => 10]],
            $raw['settingsItems'],
        );

        $viewer = $raw['viewers'][0];
        $this->assertSame('economy.salesRegistration.registrations', $viewer['id']);
        $this->assertSame(RegistrationDocument::TABLE, $viewer['table']);
        $this->assertSame(RegistrationsViewer::class, $viewer['class']);
        $this->assertArrayNotHasKey('navSection', $viewer, 'jen v Nastavení');
        $this->assertCount(1, $module->settingsSections);

        $sections = JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc');
        $existing = array_column($sections['sections'] ?? $sections, 'id');
        $this->assertNotContains('salesRegistration', $existing, 'shodné id sekce je chyba kompilace');
    }

    public function testFormsAndDocumentClassesAreRegistered(): void
    {
        $raw = self::module();

        $this->assertSame(
            [RegistrationDocument::TABLE => RegistrationDocument::class, UnitDocument::TABLE => UnitDocument::class],
            array_column($raw['documentClasses'], 'class', 'table'),
        );
        $forms = array_column($raw['forms'], null, 'table');
        $this->assertSame(RegistrationsForm::class, $forms[RegistrationDocument::TABLE]['class']);
        $this->assertSame(RegistrationsForm::UNITS_FORM_ID, $forms[UnitDocument::TABLE]['id']);
        $this->assertFileExists(self::MODULE . '/forms/' . UnitDocument::TABLE . '.jsonc');
    }

    public function testRecordActionsBelongToTheRegistrationTable(): void
    {
        $actions = array_column(self::module()['recordActions'] ?? [], null, 'id');

        $upload = $actions['uploadCertificate'] ?? null;
        $this->assertNotNull($upload);
        $this->assertSame(RegistrationDocument::TABLE, $upload['table']);
        $this->assertSame(UploadCertificateAction::class, $upload['class']);
        $this->assertTrue($upload['admin'], 'certifikát mění identitu, pod kterou se podepisuje — jen správce');
        $verify = $actions['verifyConnection'] ?? null;
        $this->assertNotNull($verify);
        $this->assertSame(VerifyConnectionAction::class, $verify['class']);
        $this->assertTrue($verify['admin']);
        $this->assertGreaterThan($upload['order'], $verify['order'], 'Ověřit spojení až za Nahrát certifikát');
        foreach ($actions as $id => $action) {
            $this->assertTrue(is_subclass_of($action['class'], RecordAction::class), (string) $id);
        }
    }

    public function testKeyFilesDirectoryIsCoveredByThePermissionContract(): void
    {
        $dsDir = '/opt/shipard/data-sources/aaaa-bbbb-cccc-dddd';
        $paths = array_column((new PermissionSpec('shipard'))->getDataSourceEntries($dsDir), 'path');

        $this->assertContains((new KeyFiles($dsDir))->directory(), $paths, 'doctor a fix-permissions adresář hlídají');
    }
}
