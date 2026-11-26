<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\SalesRegistration;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Database\ExtensionDefinition;
use Shipard\Core\Database\TableDefinition;
use Shipard\Core\Database\TableMerger;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Module\ModuleLoader;
use Shipard\Core\Module\ModulePathResolver;
use Shipard\Core\Module\ModuleResolver;
use Shipard\Core\Module\ModuleUiExtensions;
use Shipard\Core\Utils\JsoncParser;

/**
 * Zdroj dat **bez doplňku** evidence tržeb (#171, zadání fáze 3 § 3): doklady
 * se nemění — hlavička nemá sloupce kontaktní platby, formulář nemá záložku,
 * detail záložku ani štítek, seznam štítek, doklad akce a uložení dokladu
 * handler doplňku. Všechno, co doplněk k dokladům přidává, jde přes body
 * rozšíření, které se čtou jen z modulů aktivních ve zdroji dat.
 *
 * Test jde nad skutečným stromem modulů stejnou cestou jako aplikace
 * (rozložení modulů zdroje dat → rozšíření UI, definice tabulek, handlery).
 */
class DocumentsWithoutAddonTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const ADDON = 'economy.salesRegistration';
    private const NAMESPACE = 'Shipard\\Module\\Economy\\SalesRegistration\\';
    private const HEADS = 'docs_core_heads';

    /** @return list<ModuleDefinition> moduly zdroje dat s těmito přímo zapnutými moduly */
    private function resolved(string ...$active): array
    {
        $all = ModuleLoader::loadAllModules(new ModulePathResolver([self::MODULES]));
        $errors = [];
        $resolved = ModuleResolver::resolve($all, $active, $errors);
        $this->assertSame([], $errors);
        return $resolved;
    }

    /** @param list<ModuleDefinition> $modules */
    private function headsDefinition(array $modules): TableDefinition
    {
        $resolver = new ModulePathResolver([self::MODULES]);
        $definition = null;
        foreach ($modules as $module) {
            if (in_array(self::HEADS, $module->tables, true)) {
                $definition = TableDefinition::fromArray(JsoncParser::parseFile($resolver->getPath($module->id) . '/tables/' . self::HEADS . '.jsonc'));
            }
        }
        $this->assertNotNull($definition);
        foreach ($modules as $module) {
            if (in_array(self::HEADS, $module->extensions, true)) {
                $extension = ExtensionDefinition::fromArray(
                    JsoncParser::parseFile($resolver->getPath($module->id) . '/extensions/' . self::HEADS . '.jsonc'),
                );
                $definition = TableMerger::merge($definition, $extension);
            }
        }
        return $definition;
    }

    /**
     * @param list<ModuleDefinition> $modules
     * @return array<string, list<string>> druh rozšíření → třídy doplňku nad hlavičkou dokladu
     */
    private function addonExtensions(array $modules): array
    {
        $extensions = ModuleUiExtensions::fromModules($modules);
        $found = [];
        foreach (array_keys(ModuleUiExtensions::KINDS) as $kind) {
            foreach ($extensions->for($kind, self::HEADS) as $extension) {
                if (str_starts_with($extension->class, self::NAMESPACE)) {
                    $found[$kind][] = substr($extension->class, strlen(self::NAMESPACE));
                }
            }
        }
        return $found;
    }

    /**
     * @param list<ModuleDefinition> $modules
     * @return list<string> handlery událostí hlavičky dokladu z doplňku
     */
    private function addonHandlers(array $modules): array
    {
        $handlers = [];
        foreach ($modules as $module) {
            foreach ($module->documentEventHandlers as $handler) {
                if (($handler['table'] ?? null) === self::HEADS && str_starts_with((string) ($handler['class'] ?? ''), self::NAMESPACE)) {
                    $handlers[] = substr($handler['class'], strlen(self::NAMESPACE));
                }
            }
        }
        return $handlers;
    }

    /** @return list<string> sloupce kontaktní platby v definici hlavičky */
    private function contactPaymentColumns(TableDefinition $definition): array
    {
        $columns = [];
        foreach ($definition->columns as $column) {
            if (str_starts_with($column->id, 'contact_payment')) {
                $columns[] = $column->id;
            }
        }
        return $columns;
    }

    public function testDataSourceWithoutTheAddonKeepsDocumentsUntouched(): void
    {
        $modules = $this->resolved('install.base');

        $this->assertNotContains(self::ADDON, array_map(static fn(ModuleDefinition $m): string => $m->id, $modules));
        $this->assertSame([], $this->contactPaymentColumns($this->headsDefinition($modules)), 'hlavička dokladu nemá sloupce doplňku');
        $this->assertSame([], $this->addonExtensions($modules), 'formulář, detail, seznam ani akce se nemění');
        $this->assertSame([], $this->addonHandlers($modules), 'uložení dokladu handler doplňku nevolá');
    }

    public function testDataSourceWithTheAddonGetsEverythingThroughExtensionPoints(): void
    {
        $modules = $this->resolved('install.base', self::ADDON);

        $this->assertSame(
            ['contact_payment', 'contact_payment_at', 'contact_payment_changed_at'],
            $this->contactPaymentColumns($this->headsDefinition($modules)),
        );
        $this->assertSame(
            [
                'detailExtensions' => ['Docs\\DocSalesDetailExtension'],
                'formTabs'         => ['ContactPayment\\ContactPaymentFormTab'],
                'rowDecorators'    => ['Docs\\DocSalesRowDecorator'],
                'recordActions'    => ['Actions\\ContactPaymentAction', 'Actions\\CancelContactPaymentAction'],
            ],
            $this->addonExtensions($modules),
        );
        $this->assertSame(
            ['Sales\\DocsHeadsSalesHandler', 'ContactPayment\\ContactPaymentSaveHandler'],
            $this->addonHandlers($modules),
        );
    }
}
