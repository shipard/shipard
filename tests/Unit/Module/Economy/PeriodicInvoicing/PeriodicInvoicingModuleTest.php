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

    public function testRunCommandCronAlertsAndSettingsLiveInTheModule(): void
    {
        $raw = JsoncParser::parseFile(self::MODULE . '/module.jsonc');
        $module = ModuleDefinition::fromArray($raw);

        $this->assertSame(
            [['command' => 'periodic-invoicing-run', 'slot' => 'daily', 'order' => 600, 'states' => ['active']]],
            $module->cronJobs,
        );
        $this->assertGreaterThan(ModuleDefinition::CRON_JOB_DEFAULT_ORDER, $module->cronJobs[0]['order'], 'běh až po vat-periods-ensure');

        // Id checků malými písmeny; check sekci nemá — nese ji nález (U4).
        $this->assertSame(
            [
                'economy.periodicinvoicing.invoices_to_review',
                'economy.periodicinvoicing.period_failed',
                'economy.periodicinvoicing.catchup_blocked',
                'economy.periodicinvoicing.period_waiting',
            ],
            array_column($raw['alertChecks'], 'id'),
        );
        foreach ($raw['alertChecks'] as $check) {
            $this->assertArrayNotHasKey('navSection', $check, $check['id']);
            $this->assertTrue(class_exists($check['class']), $check['class']);
        }

        // Stránka nastavení v sekci Účetnictví (U5), klíč pod id modulu.
        $this->assertSame([['page' => 'periodicInvoicing', 'section' => 'accounting', 'order' => 80]], $raw['settingsItems']);
        $this->assertSame(['periodicInvoicing'], array_column($raw['settingsPages'], 'id'));
        $this->assertSame(
            [\Shipard\Module\Economy\PeriodicInvoicing\Checks\PeriodWaitingCheck::SETTING_WAIT_DAYS],
            array_column($raw['settingsPages'][0]['fields'], 'id'),
        );
        $sections = array_column(JsoncParser::parseFile(self::MODULES . '/install/base/config/settingsSections.jsonc')['sections'], 'id');
        $this->assertContains('accounting', $sections);
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
