<?php

declare(strict_types=1);

namespace Shipard\Tests\Unit\Module\Economy\PeriodicInvoicing;

use PHPUnit\Framework\TestCase;
use Shipard\Core\Module\ModuleDefinition;
use Shipard\Core\Utils\JsoncParser;

/**
 * Modul `economy.periodicInvoicing` (#175 D2): jádro v `install.base`,
 * číselníky periodicit, okamžiků fakturace, stavů a textů období a nic,
 * co by jmenovalo zdroj fakturace.
 */
class PeriodicInvoicingModuleTest extends TestCase
{
    private const MODULES = __DIR__ . '/../../../../../modules';
    private const MODULE = self::MODULES . '/economy/periodicInvoicing';

    public function testModuleIsCoreAndPartOfInstallBase(): void
    {
        $module = ModuleDefinition::fromArray(JsoncParser::parseFile(self::MODULE . '/module.jsonc'));

        $this->assertSame('economy.periodicInvoicing', $module->id);
        $this->assertSame([], $module->tables, 'tabulky období zůstávají u zdrojů (D1)');
        $this->assertSame([], $module->periodicInvoicingSources, 'engine sám žádný zdroj neregistruje');
        foreach (['docs.core', 'core.exchange', 'base.persons', 'core.units', 'economy.codebooks'] as $dependency) {
            $this->assertContains($dependency, $module->dependencies, $dependency);
        }
        $this->assertNotContains('economy.workOrders', $module->dependencies);

        $installBase = JsoncParser::parseFile(self::MODULES . '/install/base/module.jsonc');
        $this->assertContains('economy.periodicInvoicing', $installBase['dependencies']);
    }

    public function testCodebooksMovedFromWorkOrders(): void
    {
        $config = array_column(JsoncParser::parseFile(self::MODULE . '/module.jsonc')['config'], 'file', 'id');
        foreach ([
            'economy.periodicInvoicing.periodicities'  => ['month', 'quarter', 'halfyear', 'year'],
            'economy.periodicInvoicing.invoiceTimings' => ['start', 'end'],
            'economy.periodicInvoicing.periodStates'   => ['planned', 'waiting', 'issued', 'stopped'],
            'economy.periodicInvoicing.periodTexts'    => ['quarter', 'halfyear'],
        ] as $id => $keys) {
            $this->assertArrayHasKey($id, $config);
            $this->assertSame($keys, array_keys(JsoncParser::parseFile(self::MODULE . '/' . $config[$id])), $id);
        }
    }

    public function testEngineSourcesDoNotNameASource(): void
    {
        // Jádro o zdroji neví (#175 Pasti): žádný odkaz na třídy ani tabulky
        // zakázek ve zdrojích enginu.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $code = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('Economy\\WorkOrders', $code, $file->getFilename());
            $this->assertStringNotContainsString("'economy_work_orders_", $code, $file->getFilename());
            $this->assertStringNotContainsString('[economy_work_orders_', $code, $file->getFilename());
        }
    }
}
